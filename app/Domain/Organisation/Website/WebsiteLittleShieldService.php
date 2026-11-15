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

final class WebsiteLittleShieldService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function page(User $actor, array $filters = []): array
    {
        $filters = Validator::make($filters, ['history_page' => ['sometimes', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $filters): array {
            [$actor, $branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::VIEW);
            $item = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', 'little_shield')->first();
            $drafts = [];
            foreach (['en', 'bm'] as $language) {
                $row = $item ? DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->orderByDesc('revision_number')->first() : null;
                $drafts[$language] = $row ? $this->revision($row) : ['id' => null, 'number' => 0, 'language' => $language, 'content' => WebsiteLittleShieldFields::empty(), 'branchIds' => [], 'missing' => WebsiteLittleShieldFields::missing(WebsiteLittleShieldFields::empty(), []), 'createdAt' => null];
            }
            foreach (['en' => 'bm', 'bm' => 'en'] as $language => $other) {
                if ($drafts[$language]['number'] === 0) {
                    $drafts[$language]['branchIds'] = $drafts[$other]['branchIds'];
                    $drafts[$language]['missing'] = WebsiteLittleShieldFields::missing($drafts[$language]['content'], $drafts[$language]['branchIds']);
                }
            }
            $history = DB::table('website_content_revisions')->where('item_id', $item->id ?? 0)->orderByDesc('id')->paginate(12, page: (int) ($filters['history_page'] ?? 1));
            $permissions = $actor->getAllPermissions()->pluck('name');
            $assets = $permissions->contains(WebsitePermissions::ASSETS) ? DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereNull('retired_at')->where(fn ($q) => $q->where('identifiable_person', false)->orWhere('consent_reference', '<>', ''))->orderByDesc('id')->get(['public_id', 'alt_en', 'alt_bm']) : collect();

            return ['branchId' => $branch->id, 'item' => $item ? ['publicId' => $item->public_id, 'lockVersion' => $item->lock_version] : null, 'drafts' => $drafts, 'history' => array_map(fn ($row) => $this->revision($row), $history->items()), 'historyPage' => $history->currentPage(), 'historyPages' => $history->lastPage(), 'branches' => DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'assets' => $assets, 'canManage' => $permissions->contains(WebsitePermissions::MANAGE), 'canSelectMedia' => $permissions->contains(WebsitePermissions::ASSETS), 'textLimits' => WebsiteLittleShieldFields::TEXT, 'listLimits' => WebsiteLittleShieldFields::LISTS, 'cardLimits' => WebsiteLittleShieldFields::CARDS];
        });
    }

    /** @return array<string,mixed> */
    private function revision(\stdClass $row): array
    {
        $content = json_decode($row->content, true, 512, JSON_THROW_ON_ERROR);
        $branches = $content['_branch_ids'] ?? [];
        unset($content['_branch_ids']);

        return ['id' => $row->id, 'number' => $row->revision_number, 'language' => $row->language, 'content' => $content, 'branchIds' => $branches, 'missing' => WebsiteLittleShieldFields::missing($content, $branches), 'createdAt' => CarbonImmutable::parse($row->created_at)->utc()->toIso8601String()];
    }

    /** @param array<string,mixed> $input */
    public function save(User $actor, array $input): string
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'item_public_id' => ['nullable', 'uuid'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'language' => ['required', 'in:en,bm'], 'content' => ['required', 'array'], 'branch_ids' => ['present', 'array', 'max:100'], 'branch_ids.*' => ['required', 'integer', 'distinct']])->validate();
        $content = WebsiteLittleShieldFields::validate($data['content']);

        return DB::transaction(function () use ($actor, $data, $content): string {
            [$actor, $branch] = $this->access->lock($actor, $data['expected_branch_id'], WebsitePermissions::MANAGE);
            $hash = hash_hmac('sha256', json_encode([$data, $content], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $retry = DB::table('website_draft_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retry) {
                if ($retry->actor_user_id !== $actor->id || $retry->operation !== 'little_save' || ! hash_equals($retry->payload_hash, $hash)) {
                    self::refuse('idempotency_key', 'This retry key was used for different details.');
                }

                return DB::table('website_content_items')->where('id', $retry->result_id)->where('organisation_id', $actor->organisation_id)->value('public_id');
            }
            $item = DB::table('website_content_items')->where('organisation_id', $actor->organisation_id)->where('type', 'little_shield')->lockForUpdate()->first();
            if (! empty($data['item_public_id'])) {
                abort_unless($item && $item->public_id === strtolower($data['item_public_id']), 404);
            }
            if (($item->lock_version ?? 0) !== (int) $data['lock_version'] || ($item && empty($data['item_public_id']))) {
                self::refuse('version', 'This draft changed. Reload before continuing. Only one Little Shield page is allowed.');
            }
            $branchIds = array_map('intval', $data['branch_ids']);
            sort($branchIds);
            $associated = DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get();
            if ($associated->count() !== count($branchIds)) {
                self::refuse('branch_ids', 'Choose active branches from this organisation.');
            }
            $assetIds = array_values(array_unique(array_filter([$content['cover_public_id'], ...$content['gallery_public_ids']])));
            if ($assetIds !== []) {
                abort_unless($actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::ASSETS), 403);
            }
            $assets = DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereIn('public_id', $assetIds)->orderBy('id')->lockForUpdate()->get();
            if ($assets->count() !== count($assetIds) || $assets->contains(fn ($asset) => $asset->retired_at !== null || ($asset->identifiable_person && trim($asset->consent_reference) === ''))) {
                self::refuse('content.cover_public_id', 'Choose active sanitised media from this organisation, with consent for identifiable people.');
            }
            if (! $item) {
                $uuid = (string) Str::uuid();
                $id = DB::table('website_content_items')->insertGetId(['public_id' => $uuid, 'organisation_id' => $actor->organisation_id, 'branch_id' => null, 'type' => 'little_shield', 'slug' => 'little-shield-'.$uuid, 'lock_version' => 1, 'created_at' => now()->utc()->toIso8601String()]);
                $item = DB::table('website_content_items')->where('id', $id)->first();
            } else {
                DB::table('website_content_items')->where('id', $item->id)->update(['lock_version' => $item->lock_version + 1]);
            }
            $appended = WebsiteSharedReferences::append($item, $data['language'], $content, $branchIds, $assets->firstWhere('public_id', $content['cover_public_id'])?->id, $actor->id);
            DB::table('website_content_item_branches')->where('item_id', $item->id)->delete();
            foreach ($branchIds as $branchId) {
                DB::table('website_content_item_branches')->insert(['organisation_id' => $actor->organisation_id, 'item_id' => $item->id, 'branch_id' => $branchId]);
            }
            DB::table('website_draft_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'operation' => 'little_save', 'payload_hash' => $hash, 'result_id' => $item->id, 'created_at' => now()->utc()->toIso8601String()]);
            if ($this->audit->record('website.little_shield.save', null, ['item_id' => $item->id, 'version' => (int) $data['lock_version'] + 1, 'revision_number' => $appended['number'], 'languages' => $appended['languages'], 'field_names' => [...array_keys($content), 'branch_ids']], $actor, $branch) === null) {
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
