<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebsiteServicesService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit) {}

    private function type(string $type): void
    {
        abort_unless(in_array($type, WebsiteServiceFields::TYPES, true), 404);
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function page(User $actor, string $type, ?string $publicId, array $filters = []): array
    {
        $this->type($type);
        $publicId = $publicId === null ? null : Str::lower($publicId);
        $filters = Validator::make($filters, ['search' => ['nullable', 'string', 'max:150'], 'branch_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'uuid'], 'archived' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'history_page' => ['sometimes', 'integer', 'min:1']])->validate();

        if (isset($filters['category_id'])) {
            $filters['category_id'] = Str::lower($filters['category_id']);
        }

        return DB::transaction(function () use ($actor, $type, $publicId, $filters): array {
            [$actor, $branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::VIEW);
            $branches = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
            if (! empty($filters['branch_id'])) {
                abort_unless($type === 'public_service' && $branches->contains('id', (int) $filters['branch_id']), 403);
            }
            $categories = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', 'service_category')->whereNull('archived_at')->orderBy('id')->get()->map(fn ($row) => $this->item($row));
            if (! empty($filters['category_id'])) {
                abort_unless($categories->contains('publicId', $filters['category_id']), 403);
            }
            $query = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', $type);
            if (! ($filters['archived'] ?? false)) {
                $query->whereNull('archived_at');
            }
            if (! empty($filters['branch_id'])) {
                $query->whereIn('id', DB::table('website_content_item_branches')->select('item_id')->where('organisation_id', $actor->organisation_id)->where('branch_id', $filters['branch_id']));
            }
            if (($filters['search'] ?? '') !== '') {
                $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
                $query->whereIn('id', DB::table('website_content_revisions as r')->select('r.item_id')->where('r.organisation_id', $actor->organisation_id)->whereRaw("r.content->>'name' ILIKE ?", [$search])->whereRaw('r.revision_number = (SELECT MAX(r2.revision_number) FROM website_content_revisions r2 WHERE r2.item_id=r.item_id AND r2.language=r.language)'));
            }
            if (! empty($filters['category_id'])) {
                $query->whereIn('id', DB::table('website_content_revisions as r')->select('r.item_id')->where('r.organisation_id', $actor->organisation_id)->whereRaw("r.content->>'parent_public_id' = ?", [$filters['category_id']])->whereRaw('r.revision_number = (SELECT MAX(r2.revision_number) FROM website_content_revisions r2 WHERE r2.item_id=r.item_id AND r2.language=r.language)'));
            }
            $rows = $query->orderByDesc('id')->paginate(20, page: (int) ($filters['page'] ?? 1));
            $item = $publicId && $publicId !== 'create' ? DB::table('website_content_items')->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->where('type', $type)->first() : null;
            if ($publicId && $publicId !== 'create') {
                abort_unless($item !== null, 404);
            }
            $drafts = [];
            foreach (['en', 'bm'] as $language) {
                $revision = $item ? DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->orderByDesc('revision_number')->first() : null;
                $drafts[$language] = $revision ? $this->revision($revision, $type) : ['id' => null, 'number' => 0, 'language' => $language, 'content' => WebsiteServiceFields::empty($type), 'branchIds' => [], 'missing' => WebsiteServiceFields::missing($type, WebsiteServiceFields::empty($type)), 'createdAt' => null];
            }
            foreach (['en' => 'bm', 'bm' => 'en'] as $language => $otherLanguage) {
                if ($drafts[$language]['number'] === 0 && $drafts[$otherLanguage]['number'] > 0) {
                    $drafts[$language]['branchIds'] = $drafts[$otherLanguage]['branchIds'];
                    $drafts[$language]['content']['parent_public_id'] = $drafts[$otherLanguage]['content']['parent_public_id'];
                    $drafts[$language]['missing'] = WebsiteServiceFields::missing($type, $drafts[$language]['content'], $drafts[$language]['branchIds']);
                }
            }
            $history = DB::table('website_content_revisions')->where('item_id', $item->id ?? 0)->orderByDesc('id')->paginate(12, page: (int) ($filters['history_page'] ?? 1));
            $permissions = $actor->getAllPermissions()->pluck('name');
            $assets = $permissions->contains(WebsitePermissions::ASSETS) ? DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereNull('retired_at')->where(fn ($q) => $q->where('identifiable_person', false)->orWhere('consent_reference', '<>', ''))->orderByDesc('id')->get(['public_id', 'alt_en', 'alt_bm']) : collect();

            return [...WebsiteSharedReferences::notices($item), 'type' => $type, 'branchId' => $branch->id, 'branches' => $branches, 'categories' => $categories, 'canManage' => $permissions->contains(WebsitePermissions::MANAGE), 'canSelectMedia' => $permissions->contains(WebsitePermissions::ASSETS), 'filters' => $filters, 'items' => array_map(fn ($row) => $this->item($row), $rows->items()), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'editing' => $publicId !== null, 'item' => $item ? $this->item($item) : null, 'drafts' => $drafts, 'assets' => $assets, 'history' => array_map(fn ($r) => $this->revision($r, $type), $history->items()), 'historyPage' => $history->currentPage(), 'historyPages' => $history->lastPage(), 'textLimits' => $type === 'service_category' ? WebsiteServiceFields::CATEGORY_TEXT : WebsiteServiceFields::SERVICE_TEXT, 'listLimits' => $type === 'public_service' ? WebsiteServiceFields::LISTS : []];
        });
    }

    /** @return array<string,mixed> */
    private function item(\stdClass $item): array
    {
        $revisions = DB::table('website_content_revisions')->where('item_id', $item->id)->orderByDesc('id')->get()->unique('language');
        $parents = $revisions->map(fn ($r) => json_decode($r->content, true, 512, JSON_THROW_ON_ERROR)['parent_public_id'] ?? null)->filter()->unique()->values()->all();
        $content = $revisions->first() ? json_decode($revisions->first()->content, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['publicId' => $item->public_id, 'slug' => $item->slug, 'lockVersion' => $item->lock_version, 'archived' => $item->archived_at !== null, 'title' => $content['name'] ?? 'Incomplete draft', 'parentIds' => $parents];
    }

    /** @return array<string,mixed> */
    private function revision(\stdClass $row, string $type): array
    {
        $content = json_decode($row->content, true, 512, JSON_THROW_ON_ERROR);
        $branches = $content['_branch_ids'] ?? [];
        unset($content['_branch_ids']);

        return ['id' => $row->id, 'number' => $row->revision_number, 'language' => $row->language, 'content' => $content, 'branchIds' => $branches, 'missing' => WebsiteServiceFields::missing($type, $content, $branches), 'createdAt' => CarbonImmutable::parse($row->created_at)->utc()->toIso8601String()];
    }

    /** @param array<string,mixed> $input */
    public function mutate(User $actor, string $type, ?string $publicId, string $operation, array $input): string
    {
        $this->type($type);
        $publicId = $publicId === null ? null : Str::lower($publicId);
        abort_unless(in_array($operation, ['save', 'archive', 'unarchive'], true), 404);
        $rules = ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid']];
        if ($operation === 'save') {
            $rules += ['language' => ['required', 'in:en,bm'], 'content' => ['required', 'array'], 'branch_ids' => ['present', 'array', 'max:100'], 'branch_ids.*' => ['required', 'integer', 'distinct']];
        }
        $data = Validator::make($input, $rules)->validate();
        $content = $operation === 'save' ? WebsiteServiceFields::validate($type, $data['content']) : null;

        return DB::transaction(function () use ($actor, $type, $publicId, $operation, $data, $content): string {
            [$actor, $branch] = $this->access->lock($actor, $data['expected_branch_id'], WebsitePermissions::MANAGE);
            $hash = hash_hmac('sha256', json_encode([$type, $publicId, $operation, $data, $content], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $retry = DB::table('website_draft_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->operation !== 'services_'.$operation || ! hash_equals($retry->payload_hash, $hash)) {
                    self::refuse('idempotency_key', 'This retry key was used for different details.');
                }

                return DB::table('website_content_items')->where('id', $retry->result_id)->where('organisation_id', $actor->organisation_id)->value('public_id');
            }
            $item = $publicId ? DB::table('website_content_items')->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->where('type', $type)->lockForUpdate()->first() : null;
            if ($publicId) {
                abort_unless($item !== null, 404);
            }
            if (($item->lock_version ?? 0) !== (int) $data['lock_version'] || ($operation !== 'save' && ! $item)) {
                self::refuse('version', 'This draft changed. Reload before continuing.');
            }
            if ($operation === 'save') {
                if ($item?->archived_at !== null) {
                    self::refuse('archive', 'Unarchive this item before editing.');
                }
                $branchIds = array_map('intval', $data['branch_ids']);
                sort($branchIds);
                if ($type === 'service_category' && $branchIds !== []) {
                    self::refuse('branch_ids', 'Categories are organisation-wide and have no branch associations.');
                }
                $associatedBranches = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get()->all();
                if (count($associatedBranches) !== count($branchIds)) {
                    self::refuse('branch_ids', 'Choose active branches from this organisation.');
                }
                $this->parent($actor->organisation_id, $type, $publicId, $content['parent_public_id']);
                $assetIds = array_values(array_unique(array_filter([$content['cover_public_id'], ...$content['gallery_public_ids']])));
                if ($assetIds !== []) {
                    abort_unless($actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::ASSETS), 403);
                }
                $assets = DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereIn('public_id', $assetIds)->orderBy('id')->lockForUpdate()->get();
                if ($assets->count() !== count($assetIds) || $assets->contains(fn ($a) => $a->retired_at !== null || ($a->identifiable_person && trim($a->consent_reference) === ''))) {
                    self::refuse('content.cover_public_id', 'Choose active sanitised media from this organisation, with consent for identifiable people.');
                }
                if (! $item) {
                    $uuid = (string) Str::uuid();
                    $id = DB::table('website_content_items')->insertGetId(['public_id' => $uuid, 'organisation_id' => $actor->organisation_id, 'branch_id' => null, 'type' => $type, 'slug' => str_replace('_', '-', $type).'-'.$uuid, 'lock_version' => 1, 'created_at' => now()->utc()->toIso8601String()]);
                    $item = DB::table('website_content_items')->where('id', $id)->first();
                } else {
                    DB::table('website_content_items')->where('id', $item->id)->update(['lock_version' => $item->lock_version + 1]);
                }
                $appended = WebsiteSharedReferences::append($item, $data['language'], $content, $branchIds, $assets->firstWhere('public_id', $content['cover_public_id'])?->id, $actor->id);
                $number = $appended['number'];
                DB::table('website_content_item_branches')->where('item_id', $item->id)->delete();
                foreach ($branchIds as $branchId) {
                    DB::table('website_content_item_branches')->insert(['organisation_id' => $actor->organisation_id, 'item_id' => $item->id, 'branch_id' => $branchId]);
                }
                $fields = [...array_keys($content), 'branch_ids'];
            } else {
                if (($item->archived_at !== null) !== ($operation === 'unarchive')) {
                    self::refuse('version', 'This draft changed. Reload before continuing.');
                }
                if ($operation === 'archive' && $type === 'service_category' && $this->children($publicId)) {
                    self::refuse('archive', 'Archive active children before archiving this category.');
                }
                if ($operation === 'archive' && $type === 'public_service' && ($faqCount = WebsiteSharedReferences::activeFaqCount($publicId)) > 0) {
                    self::refuse('archive', "Cannot archive: {$faqCount} active FAQs link to this service. Clear their links or archive those FAQs first.");
                }
                if ($operation === 'unarchive') {
                    foreach (DB::table('website_content_revisions')->where('item_id', $item->id)->orderByDesc('revision_number')->get()->unique('language') as $revision) {
                        $this->parent($actor->organisation_id, $type, $publicId, json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR)['parent_public_id'] ?? null);
                    }
                }
                DB::table('website_content_items')->where('id', $item->id)->update(['archived_at' => $operation === 'archive' ? now()->utc()->toIso8601String() : null, 'lock_version' => $item->lock_version + 1]);
                $fields = ['archived_at'];
            }
            DB::table('website_draft_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'operation' => 'services_'.$operation, 'payload_hash' => $hash, 'result_id' => $item->id, 'created_at' => now()->utc()->toIso8601String()]);
            if ($this->audit->record('website.services.'.$operation, null, ['item_id' => $item->id, 'version' => (int) $data['lock_version'] + 1, 'revision_number' => $number ?? null, 'language' => $data['language'] ?? null, 'field_names' => $fields, ...((isset($appended) && count($appended['languages']) > 1) ? ['languages' => $appended['languages']] : [])], $actor, $branch) === null) {
                throw new \RuntimeException('Website audit could not be recorded.');
            }

            return $item->public_id;
        }, 3);
    }

    private function children(string $publicId, bool $categoriesOnly = false): bool
    {
        return (bool) DB::selectOne('SELECT website_service_has_children(?, ?) AS present', [$publicId, $categoriesOnly])->present;
    }

    private function parent(int $organisation, string $type, ?string $publicId, ?string $parent): void
    {
        if ($parent === null) {
            return; // An incomplete private draft is allowed; completeness records the missing parent.
        }
        $row = DB::table('website_content_items')->where('public_id', $parent)->where('organisation_id', $organisation)->where('type', 'service_category')->whereNull('archived_at')->lockForUpdate()->first();
        if (! $row || $parent === $publicId) {
            self::refuse('content.parent_public_id', 'Choose an active category from this organisation.');
        }
        if ($type === 'service_category') {
            $nested = DB::table('website_content_revisions')->where('item_id', $row->id)->orderByDesc('revision_number')->get()->unique('language')->contains(fn ($r) => ! empty(json_decode($r->content, true, 512, JSON_THROW_ON_ERROR)['parent_public_id']));
            if ($nested || ($publicId && $this->children($publicId, true))) {
                self::refuse('content.parent_public_id', 'Only two category levels are allowed. Reparent active subcategories first.');
            }
        }
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
