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

final class WebsitePeopleService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit) {}

    public static function permission(string $type): string
    {
        abort_unless(in_array($type, WebsitePeopleFields::TYPES, true), 404);

        return $type === 'doctor_profile' ? WebsitePermissions::DOCTORS : WebsitePermissions::CAREERS;
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function page(User $actor, string $type, ?string $publicId, array $filters = []): array
    {
        $permission = self::permission($type);
        $filters = Validator::make($filters, ['search' => ['nullable', 'string', 'max:150'], 'branch_id' => ['nullable', 'integer'], 'archived' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'history_page' => ['sometimes', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $type, $publicId, $filters, $permission): array {
            [$actor, $branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::VIEW);
            $branches = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
            if (! empty($filters['branch_id'])) {
                abort_unless($branches->contains('id', (int) $filters['branch_id']), 403);
            }
            $query = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', $type);
            if (! ($filters['archived'] ?? false)) {
                $query->whereNull('archived_at');
            }
            if (! empty($filters['branch_id'])) {
                $query->whereIn('id', DB::table('website_content_item_branches')->select('item_id')->where('branch_id', $filters['branch_id'])->where('organisation_id', $actor->organisation_id));
            }
            if (($filters['search'] ?? '') !== '') {
                // Search only website content, never staff or clinical sources. Parameters remain bound.
                $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
                $query->whereIn('id', DB::table('website_content_revisions as r')->select('r.item_id')->where('r.organisation_id', $actor->organisation_id)->whereRaw('r.content->>? ILIKE ?', [$type === 'doctor_profile' ? 'display_name' : 'title', $search])->whereRaw('r.revision_number = (SELECT MAX(r2.revision_number) FROM website_content_revisions r2 WHERE r2.item_id = r.item_id AND r2.language = r.language)'));
            }
            $rows = $query->orderByDesc('id')->paginate(20, page: (int) ($filters['page'] ?? 1));
            $item = $publicId && $publicId !== 'create' ? DB::table('website_content_items')->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->where('type', $type)->first() : null;
            if ($publicId && $publicId !== 'create') {
                abort_unless($item !== null, 404);
            }
            $drafts = [];
            foreach (['en', 'bm'] as $language) {
                $revision = $item ? DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->orderByDesc('revision_number')->first() : null;
                $drafts[$language] = $revision ? $this->revision($revision, $type) : ['id' => null, 'number' => 0, 'language' => $language, 'content' => WebsitePeopleFields::empty($type), 'portraitPublicId' => null, 'missing' => WebsitePeopleFields::missing($type, WebsitePeopleFields::empty($type)), 'createdAt' => null];
            }
            $history = DB::table('website_content_revisions')->where('item_id', $item->id ?? 0)->orderByDesc('id')->paginate(12, page: (int) ($filters['history_page'] ?? 1));
            $assets = DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereNull('retired_at')->where('identifiable_person', true)->where('consent_reference', '<>', '')->orderByDesc('id')->get(['public_id', 'alt_en', 'alt_bm']);

            return [...WebsiteSharedReferences::notices($item), 'type' => $type, 'branchId' => $branch->id, 'branches' => $branches, 'canManage' => $actor->getAllPermissions()->pluck('name')->contains($permission), 'filters' => $filters, 'items' => array_map(fn ($row) => $this->item($row), $rows->items()), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'editing' => $publicId !== null, 'item' => $item ? $this->item($item) : null, 'drafts' => $drafts, 'assets' => $assets, 'history' => array_map(fn ($r) => $this->revision($r, $type), $history->items()), 'historyPage' => $history->currentPage(), 'historyPages' => $history->lastPage(), 'textLimits' => $type === 'doctor_profile' ? WebsitePeopleFields::DOCTOR_TEXT : WebsitePeopleFields::CAREER_TEXT, 'listLimits' => $type === 'doctor_profile' ? WebsitePeopleFields::DOCTOR_LISTS : WebsitePeopleFields::CAREER_LISTS, 'employmentTypes' => WebsitePeopleFields::EMPLOYMENT];
        });
    }

    /** @return array<string,mixed> */
    private function item(\stdClass $item): array
    {
        $revision = DB::table('website_content_revisions')->where('item_id', $item->id)->orderByDesc('id')->first();
        $content = $revision ? json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR) : [];

        return ['publicId' => $item->public_id, 'slug' => $item->slug, 'lockVersion' => $item->lock_version, 'archived' => $item->archived_at !== null, 'title' => $content['display_name'] ?? $content['title'] ?? 'Incomplete draft', 'branchIds' => DB::table('website_content_item_branches')->where('item_id', $item->id)->orderBy('branch_id')->pluck('branch_id')->all()];
    }

    /** @return array<string,mixed> */
    private function revision(\stdClass $row, string $type): array
    {
        $content = json_decode($row->content, true, 512, JSON_THROW_ON_ERROR);
        $associated = $content['_branch_ids'] ?? [];
        unset($content['_branch_ids']);

        return ['branchIds' => $associated, 'id' => $row->id, 'number' => $row->revision_number, 'language' => $row->language, 'content' => $content, 'portraitPublicId' => $row->hero_asset_id ? DB::table('website_media_assets')->where('id', $row->hero_asset_id)->where('organisation_id', $row->organisation_id)->value('public_id') : null, 'missing' => WebsitePeopleFields::missing($type, $content), 'createdAt' => CarbonImmutable::parse($row->created_at)->utc()->toIso8601String()];
    }

    /** @param array<string,mixed> $input */
    public function mutate(User $actor, string $type, ?string $publicId, string $operation, array $input): string
    {
        $permission = self::permission($type);
        abort_unless(in_array($operation, ['save', 'archive', 'unarchive'], true), 404);
        $rules = ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid']];
        if ($operation === 'save') {
            $rules += ['language' => ['required', 'in:en,bm'], 'content' => ['required', 'array'], 'branch_ids' => ['required', 'array', 'min:1', 'max:100'], 'branch_ids.*' => ['required', 'integer', 'distinct'], 'portrait_public_id' => ['nullable', 'uuid']];
        }
        $data = Validator::make($input, $rules)->validate();
        $content = $operation === 'save' ? WebsitePeopleFields::validate($type, $data['content']) : null;

        return DB::transaction(function () use ($actor, $type, $publicId, $operation, $data, $content, $permission): string {
            [$actor, $branch] = $this->access->lock($actor, $data['expected_branch_id'], $permission);
            $hash = hash_hmac('sha256', json_encode([$type, $publicId, $operation, $data, $content], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $retry = DB::table('website_draft_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->operation !== 'people_'.$operation || ! hash_equals($retry->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This retry key was used for different details.']);
                }

                return DB::table('website_content_items')->where('id', $retry->result_id)->where('organisation_id', $actor->organisation_id)->value('public_id');
            }
            $item = $publicId ? DB::table('website_content_items')->where('public_id', $publicId)->where('type', $type)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first() : null;
            if ($publicId) {
                abort_unless($item !== null, 404);
            }
            if (($item->lock_version ?? 0) !== (int) $data['lock_version'] || ($operation !== 'save' && ! $item)) {
                self::stale();
            }
            $portrait = null;
            $branchIds = [];
            if ($operation === 'save') {
                if ($item?->archived_at !== null) {
                    throw ValidationException::withMessages(['archive' => 'Unarchive this item before editing.']);
                }
                $branchIds = array_map('intval', $data['branch_ids']);
                sort($branchIds);
                $associatedBranches = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get()->all();
                if (count($associatedBranches) !== count($branchIds)) {
                    throw ValidationException::withMessages(['branch_ids' => 'Choose active branches from this organisation.']);
                }
                if (! empty($data['portrait_public_id'])) {
                    $portrait = DB::table('website_media_assets')->where('public_id', $data['portrait_public_id'])->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
                    if ($type !== 'doctor_profile' || ! $portrait || $portrait->retired_at !== null || ! $portrait->identifiable_person || trim($portrait->consent_reference) === '') {
                        throw ValidationException::withMessages(['portrait_public_id' => 'Choose an active sanitised portrait with an identifiable-person consent reference.']);
                    }
                }
                if ($portrait && $item) {
                    $otherLanguage = DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $data['language'] === 'en' ? 'bm' : 'en')->orderByDesc('revision_number')->first();
                    if ($otherLanguage && $otherLanguage->hero_asset_id !== null && $otherLanguage->hero_asset_id !== $portrait->id) {
                        throw ValidationException::withMessages(['portrait_public_id' => 'Only one portrait asset may be used per profile. Clear the other language portrait before changing it.']);
                    }
                }
                if (! $item) {
                    $uuid = (string) Str::uuid();
                    $id = DB::table('website_content_items')->insertGetId(['public_id' => $uuid, 'organisation_id' => $actor->organisation_id, 'branch_id' => null, 'type' => $type, 'slug' => str_replace('_', '-', $type).'-'.$uuid, 'lock_version' => 1, 'created_at' => now()->utc()->toIso8601String()]);
                    $item = DB::table('website_content_items')->where('id', $id)->first();
                } else {
                    DB::table('website_content_items')->where('id', $item->id)->update(['lock_version' => $item->lock_version + 1]);
                }
                $appended = WebsiteSharedReferences::append($item, $data['language'], $content, $branchIds, $portrait?->id, $actor->id);
                $number = $appended['number'];
                DB::table('website_content_item_branches')->where('item_id', $item->id)->delete();
                foreach ($branchIds as $branchId) {
                    DB::table('website_content_item_branches')->insert(['organisation_id' => $actor->organisation_id, 'item_id' => $item->id, 'branch_id' => $branchId]);
                }
                $fields = [...array_keys($content), 'branch_ids', 'portrait_asset_id'];
            } else {
                if (($item->archived_at !== null) !== ($operation === 'unarchive')) {
                    self::stale();
                }
                DB::table('website_content_items')->where('id', $item->id)->update(['archived_at' => $operation === 'archive' ? now()->utc()->toIso8601String() : null, 'lock_version' => $item->lock_version + 1]);
                $fields = ['archived_at'];
            }
            DB::table('website_draft_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'operation' => 'people_'.$operation, 'payload_hash' => $hash, 'result_id' => $item->id, 'created_at' => now()->utc()->toIso8601String()]);
            if ($this->audit->record('website.people.'.$operation, null, ['item_id' => $item->id, 'version' => max(1, (int) $data['lock_version'] + 1), 'revision_number' => $number ?? null, 'language' => $data['language'] ?? null, 'field_names' => $fields, ...((isset($appended) && count($appended['languages']) > 1) ? ['languages' => $appended['languages']] : [])], $actor, $branch) === null) {
                throw new \RuntimeException('Website audit could not be recorded.');
            }

            return $item->public_id;
        }, 3);
    }

    private static function stale(): never
    {
        throw ValidationException::withMessages(['version' => 'This draft changed. Reload before continuing.']);
    }
}
