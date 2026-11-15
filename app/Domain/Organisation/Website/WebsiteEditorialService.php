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

final class WebsiteEditorialService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit) {}

    private function type(string $type): void
    {
        abort_unless(in_array($type, WebsiteEditorialFields::TYPES, true), 404);
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function page(User $actor, string $type, ?string $publicId, array $filters = []): array
    {
        $this->type($type);
        $publicId = $publicId === null ? null : Str::lower($publicId);
        $filters = Validator::make($filters, ['search' => ['nullable', 'string', 'max:150'], 'branch_id' => ['nullable', 'integer'], 'archived' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'history_page' => ['sometimes', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $type, $publicId, $filters): array {
            [$actor, $branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::VIEW);
            $branches = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
            if (! empty($filters['branch_id'])) {
                abort_unless($type !== 'faq' && $branches->contains('id', (int) $filters['branch_id']), 403);
            }
            $linkedServices = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', 'public_service')->whereNull('archived_at')->orderBy('id')->get()->map(fn ($row) => $this->item($row));
            $query = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', $type);
            if (! ($filters['archived'] ?? false)) {
                $query->whereNull('archived_at');
            }
            if (! empty($filters['branch_id'])) {
                $query->whereIn('id', DB::table('website_content_item_branches')->select('item_id')->where('organisation_id', $actor->organisation_id)->where('branch_id', $filters['branch_id']));
            }
            if (($filters['search'] ?? '') !== '') {
                $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
                $query->whereIn('id', DB::table('website_content_revisions as r')->select('r.item_id')->where('r.organisation_id', $actor->organisation_id)->whereRaw("COALESCE(r.content->>'title',r.content->>'question') ILIKE ?", [$search])->whereRaw('r.revision_number = (SELECT MAX(r2.revision_number) FROM website_content_revisions r2 WHERE r2.item_id=r.item_id AND r2.language=r.language)'));
            }
            $rows = $query->orderByDesc('id')->paginate(20, page: (int) ($filters['page'] ?? 1));
            $item = $publicId && $publicId !== 'create' ? DB::table('website_content_items')->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->where('type', $type)->first() : null;
            if ($publicId && $publicId !== 'create') {
                abort_unless($item !== null, 404);
            }
            $drafts = [];
            foreach (['en', 'bm'] as $language) {
                $revision = $item ? DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->orderByDesc('revision_number')->first() : null;
                $drafts[$language] = $revision ? $this->revision($revision, $type) : ['id' => null, 'number' => 0, 'language' => $language, 'content' => WebsiteEditorialFields::empty($type), 'branchIds' => [], 'missing' => WebsiteEditorialFields::missing($type, WebsiteEditorialFields::empty($type)), 'createdAt' => null];
            }
            foreach (['en' => 'bm', 'bm' => 'en'] as $language => $otherLanguage) {
                if ($drafts[$language]['number'] === 0 && $drafts[$otherLanguage]['number'] > 0) {
                    $drafts[$language]['branchIds'] = $drafts[$otherLanguage]['branchIds'];
                    if ($type === 'faq') {
                        $drafts[$language]['content']['linked_service_public_id'] = $drafts[$otherLanguage]['content']['linked_service_public_id'];
                    }
                    $drafts[$language]['missing'] = WebsiteEditorialFields::missing($type, $drafts[$language]['content'], $drafts[$language]['branchIds']);
                }
            }
            $history = DB::table('website_content_revisions')->where('item_id', $item->id ?? 0)->orderByDesc('id')->paginate(12, page: (int) ($filters['history_page'] ?? 1));
            $permissions = $actor->getAllPermissions()->pluck('name');
            $assets = $permissions->contains(WebsitePermissions::ASSETS) ? DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereNull('retired_at')->where(fn ($q) => $q->where('identifiable_person', false)->orWhere('consent_reference', '<>', ''))->orderByDesc('id')->get(['public_id', 'alt_en', 'alt_bm']) : collect();

            return [...WebsiteSharedReferences::notices($item), 'type' => $type, 'branchId' => $branch->id, 'timezone' => $branch->timezone, 'branches' => $branches, 'linkedServices' => $linkedServices, 'canManage' => $permissions->contains(WebsitePermissions::MANAGE), 'canSelectMedia' => $permissions->contains(WebsitePermissions::ASSETS), 'filters' => $filters, 'items' => array_map(fn ($row) => $this->item($row), $rows->items()), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'editing' => $publicId !== null, 'item' => $item ? $this->item($item) : null, 'drafts' => $drafts, 'assets' => $assets, 'history' => array_map(fn ($r) => $this->revision($r, $type), $history->items()), 'historyPage' => $history->currentPage(), 'historyPages' => $history->lastPage(), 'textLimits' => WebsiteEditorialFields::TEXT[$type], 'dateFields' => WebsiteEditorialFields::DATES[$type]];
        });
    }

    /** @return array<string,mixed> */
    private function item(\stdClass $item): array
    {
        $revisions = DB::table('website_content_revisions')->where('item_id', $item->id)->orderByDesc('id')->get()->unique('language');
        $content = $revisions->first() ? json_decode($revisions->first()->content, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['publicId' => $item->public_id, 'slug' => $item->slug, 'lockVersion' => $item->lock_version, 'archived' => $item->archived_at !== null, 'title' => $content['title'] ?? $content['question'] ?? $content['name'] ?? 'Incomplete draft'];
    }

    /** @return array<string,mixed> */
    private function revision(\stdClass $row, string $type): array
    {
        $content = json_decode($row->content, true, 512, JSON_THROW_ON_ERROR);
        $branches = $content['_branch_ids'] ?? [];
        unset($content['_branch_ids']);

        return ['id' => $row->id, 'number' => $row->revision_number, 'language' => $row->language, 'content' => $content, 'branchIds' => $branches, 'missing' => WebsiteEditorialFields::missing($type, $content, $branches), 'createdAt' => CarbonImmutable::parse($row->created_at)->utc()->toIso8601String()];
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
        $content = $operation === 'save' ? WebsiteEditorialFields::validate($type, $data['content']) : null;

        return DB::transaction(function () use ($actor, $type, $publicId, $operation, $data, $content): string {
            [$actor, $branch] = $this->access->lock($actor, $data['expected_branch_id'], WebsitePermissions::MANAGE);
            $hash = hash_hmac('sha256', json_encode([$type, $publicId, $operation, $data, $content], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $retry = DB::table('website_draft_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->operation !== 'editorial_'.$operation || ! hash_equals($retry->payload_hash, $hash)) {
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
                if ($type === 'faq' && $branchIds !== []) {
                    self::refuse('branch_ids', 'FAQs are organisation-wide and have no branch associations.');
                }
                $associatedBranches = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get()->all();
                if (count($associatedBranches) !== count($branchIds)) {
                    self::refuse('branch_ids', 'Choose active branches from this organisation.');
                }
                if (($content['linked_service_public_id'] ?? null) !== null) {
                    $linked = DB::table('website_content_items')->where('public_id', $content['linked_service_public_id'])->where('organisation_id', $actor->organisation_id)->where('type', 'public_service')->whereNull('archived_at')->lockForUpdate()->first();
                    if (! $linked) {
                        self::refuse('content.linked_service_public_id', 'Choose an active public service from this organisation.');
                    }
                }
                $assetIds = array_values(array_unique(array_filter([$content['cover_public_id'] ?? null, ...($content['gallery_public_ids'] ?? [])])));
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
                $appended = WebsiteSharedReferences::append($item, $data['language'], $content, $branchIds, $assets->firstWhere('public_id', $content['cover_public_id'] ?? null)?->id, $actor->id);
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
                DB::table('website_content_items')->where('id', $item->id)->update(['archived_at' => $operation === 'archive' ? now()->utc()->toIso8601String() : null, 'lock_version' => $item->lock_version + 1]);
                $fields = ['archived_at'];
            }
            DB::table('website_draft_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'operation' => 'editorial_'.$operation, 'payload_hash' => $hash, 'result_id' => $item->id, 'created_at' => now()->utc()->toIso8601String()]);
            if ($this->audit->record('website.editorial.'.$operation, null, ['item_id' => $item->id, 'version' => (int) $data['lock_version'] + 1, 'revision_number' => $number ?? null, 'language' => $data['language'] ?? null, 'field_names' => $fields, ...((isset($appended) && count($appended['languages']) > 1) ? ['languages' => $appended['languages']] : [])], $actor, $branch) === null) {
                throw new \RuntimeException('Website audit could not be recorded.');
            }

            return $item->public_id;
        }, 3);
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
