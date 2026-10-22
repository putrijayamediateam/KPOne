<?php

namespace App\Domain\Organisation\Website;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WebsiteDraftService
{
    public function __construct(private WebsiteAccess $access, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function profile(User $actor, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $page): array {
            [$actor,$branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::VIEW);
            $item = DB::table('website_content_items')->where('branch_id', $branch->id)->where('organisation_id', $actor->organisation_id)->first();
            $drafts = [];
            foreach (['en', 'bm'] as $language) {
                $revision = $item ? DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $language)->orderByDesc('revision_number')->first() : null;
                $drafts[$language] = $revision ? $this->revision($revision) : ['id' => null, 'language' => $language, 'number' => 0, 'content' => WebsiteProfileFields::empty(), 'heroAssetPublicId' => null, 'isSeedCopy' => false, 'createdAt' => null, 'missing' => WebsiteProfileFields::missing(WebsiteProfileFields::empty())];
            }
            $history = DB::table('website_content_revisions')->where('item_id', $item->id ?? 0)->orderByDesc('id')->paginate(12, page: $page);
            $assets = DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->whereNull('retired_at')->orderByDesc('id')->get(['public_id', 'alt_en', 'alt_bm'])->all();

            return ['branch' => ['id' => $branch->id, 'name' => $branch->name], 'lockVersion' => $item->lock_version ?? 0, 'slug' => $item?->slug, 'drafts' => $drafts, 'history' => ['data' => array_map(fn ($r) => $this->revision($r), $history->items()), 'page' => $history->currentPage(), 'pages' => $history->lastPage()], 'assets' => $assets, 'limits' => WebsiteProfileFields::LIMITS, 'canManage' => $actor->getAllPermissions()->pluck('name')->contains(WebsitePermissions::MANAGE)];
        });
    }

    /** @return array<string,mixed> */
    private function revision(\stdClass $revision): array
    {
        $content = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
        $asset = $revision->hero_asset_id ? DB::table('website_media_assets')->where('id', $revision->hero_asset_id)->where('organisation_id', $revision->organisation_id)->value('public_id') : null;

        return ['id' => $revision->id, 'language' => $revision->language, 'number' => $revision->revision_number, 'content' => $content, 'heroAssetPublicId' => $asset, 'isSeedCopy' => (bool) $revision->is_seed_copy, 'createdAt' => CarbonImmutable::parse($revision->created_at)->utc()->toIso8601String(), 'missing' => WebsiteProfileFields::missing($content)];
    }

    /** @param array<string,mixed> $input */
    public function save(User $actor, array $input): void
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'language' => ['required', 'in:en,bm'], 'content' => ['required', 'array'], 'hero_asset_public_id' => ['nullable', 'uuid']])->validate();
        $content = WebsiteProfileFields::validate($data['content']);
        DB::transaction(function () use ($actor, $data, $content): void {
            [$actor,$branch] = $this->access->lock($actor, $data['expected_branch_id'], WebsitePermissions::MANAGE);
            $hash = $this->fingerprint([$data, $content]);
            if ($this->replayed($actor, $data, 'save', $hash)) {
                return;
            }
            $item = DB::table('website_content_items')->where('branch_id', $branch->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            if (($item->lock_version ?? 0) !== (int) $data['lock_version']) {
                self::stale();
            }
            if (! $item) {
                $id = DB::table('website_content_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'type' => 'branch_public_profile', 'slug' => Str::lower($branch->code), 'lock_version' => 1, 'created_at' => now()->utc()->toIso8601String()]);
                $item = DB::table('website_content_items')->where('id', $id)->first();
            }
            $hero = null;
            if (! empty($data['hero_asset_public_id'])) {
                $hero = DB::table('website_media_assets')->where('public_id', $data['hero_asset_public_id'])->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
                if (! $hero || $hero->retired_at !== null) {
                    throw ValidationException::withMessages(['hero_asset_public_id' => 'Choose an active sanitised asset from this organisation.']);
                }
            }
            $number = (int) DB::table('website_content_revisions')->where('item_id', $item->id)->where('language', $data['language'])->max('revision_number') + 1;
            $revision = DB::table('website_content_revisions')->insertGetId(['organisation_id' => $actor->organisation_id, 'item_id' => $item->id, 'language' => $data['language'], 'revision_number' => $number, 'content' => json_encode($content, JSON_THROW_ON_ERROR), 'hero_asset_id' => $hero?->id, 'is_seed_copy' => false, 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()->toIso8601String()]);
            DB::table('website_content_items')->where('id', $item->id)->update(['lock_version' => (int) $data['lock_version'] + 1]);
            $this->command($actor, $data, 'save', $hash, $revision);
            $audit = $this->audit->record('website.draft.saved', null, ['item_id' => $item->id, 'revision_number' => $number, 'language' => $data['language'], 'field_names' => [...array_keys($content), 'hero_asset_id']], $actor, $branch);
            if ($audit === null) {
                throw new \RuntimeException('Website audit could not be recorded.');
            }
        }, 3);
    }

    /** @return array<string,mixed> */
    public function media(User $actor, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $page): array {
            [$actor,$branch] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::ASSETS);
            $rows = DB::table('website_media_assets')->where('organisation_id', $actor->organisation_id)->orderByDesc('id')->paginate(24, page: $page);

            return ['branchId' => $branch->id, 'limits' => ['bytes' => WebsiteImage::MAX_BYTES, 'dimension' => WebsiteImage::MAX_DIMENSION, 'pixels' => WebsiteImage::MAX_PIXELS], 'assets' => array_map(fn ($r) => $this->assetView($r), $rows->items()), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()];
        });
    }

    /** @return array<string,mixed> */
    private function assetView(\stdClass $asset): array
    {
        return ['publicId' => $asset->public_id, 'altEn' => $asset->alt_en, 'altBm' => $asset->alt_bm, 'rightsReference' => $asset->rights_reference, 'identifiablePerson' => (bool) $asset->identifiable_person, 'consentReference' => $asset->consent_reference, 'retired' => $asset->retired_at !== null, 'lockVersion' => $asset->lock_version, 'mime' => $asset->mime, 'bytes' => $asset->bytes, 'width' => $asset->width, 'height' => $asset->height, 'sha256' => $asset->sha256];
    }

    /** @param array<string,mixed> $input */
    public function upload(User $actor, array $input, UploadedFile $file): void
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'in:0'], 'idempotency_key' => ['required', 'uuid'], 'alt_en' => ['required', 'string'], 'alt_bm' => ['required', 'string'], 'rights_reference' => ['required', 'string'], 'identifiable_person' => ['required', 'boolean'], 'consent_reference' => ['nullable', 'string']])->validate();
        foreach (['alt_en', 'alt_bm', 'rights_reference', 'consent_reference'] as $field) {
            $data[$field] = WebsiteProfileFields::cleanText($data[$field] ?? '', $field, 200);
        }
        foreach (['alt_en', 'alt_bm', 'rights_reference'] as $field) {
            if ($data[$field] === '') {
                throw ValidationException::withMessages([$field => 'This field is required.']);
            }
        }
        $data['identifiable_person'] = filter_var($data['identifiable_person'], FILTER_VALIDATE_BOOLEAN);
        if ($data['identifiable_person'] && $data['consent_reference'] === '') {
            throw ValidationException::withMessages(['consent_reference' => 'An identifiable person requires a consent reference.']);
        }
        $image = WebsiteImage::sanitise($file);
        $path = null;
        try {
            DB::transaction(function () use ($actor, $data, $image, &$path): void {
                [$actor,$branch] = $this->access->lock($actor, $data['expected_branch_id'], WebsitePermissions::ASSETS);
                $hash = $this->fingerprint([$data, $image['sha256']]);
                if ($this->replayed($actor, $data, 'upload', $hash)) {
                    return;
                }
                $uuid = $path ? pathinfo($path, PATHINFO_FILENAME) : (string) Str::uuid();
                $path ??= $actor->organisation_id.'/'.$uuid.'.'.$image['extension'];
                if (! Storage::disk('website_media')->put($path, $image['content'])) {
                    throw new \RuntimeException('Private website image could not be stored.');
                }
                $id = DB::table('website_media_assets')->insertGetId(['public_id' => $uuid, 'organisation_id' => $actor->organisation_id, 'path' => $path, 'mime' => $image['mime'], 'sha256' => $image['sha256'], 'bytes' => $image['bytes'], 'width' => $image['width'], 'height' => $image['height'], 'alt_en' => $data['alt_en'], 'alt_bm' => $data['alt_bm'], 'rights_reference' => $data['rights_reference'], 'identifiable_person' => $data['identifiable_person'], 'consent_reference' => $data['consent_reference'], 'uploaded_by_user_id' => $actor->id, 'retired_at' => null, 'lock_version' => 1, 'created_at' => now()->utc()->toIso8601String()]);
                $this->command($actor, $data, 'upload', $hash, $id);
                $audit = $this->audit->record('website.asset.uploaded', null, ['asset_id' => $id, 'version' => 1, 'field_names' => ['image', 'alt_en', 'alt_bm', 'rights_reference', 'identifiable_person', 'consent_reference']], $actor, $branch);
                if ($audit === null) {
                    throw new \RuntimeException('Website audit could not be recorded.');
                }
            }, 3);
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk('website_media')->delete($path);
            }throw $e;
        }
    }

    /** @param array<string,mixed> $input */
    public function retire(User $actor, string $publicId, array $input): void
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']])->validate();
        DB::transaction(function () use ($actor, $publicId, $data): void {
            [$actor,$branch] = $this->access->lock($actor, $data['expected_branch_id'], WebsitePermissions::ASSETS);
            $hash = $this->fingerprint([$publicId, $data]);
            if ($this->replayed($actor, $data, 'retire', $hash)) {
                return;
            }
            $asset = DB::table('website_media_assets')->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            abort_unless($asset !== null, 404);
            if ($asset->lock_version !== (int) $data['lock_version'] || $asset->retired_at !== null) {
                self::stale();
            }
            DB::table('website_media_assets')->where('id', $asset->id)->update(['retired_at' => now()->utc()->toIso8601String(), 'lock_version' => $asset->lock_version + 1]);
            $this->command($actor, $data, 'retire', $hash, $asset->id);
            $audit = $this->audit->record('website.asset.retired', null, ['asset_id' => $asset->id, 'version' => $asset->lock_version + 1, 'field_names' => ['retired_at']], $actor, $branch);
            if ($audit === null) {
                throw new \RuntimeException('Website audit could not be recorded.');
            }
        }, 3);
    }

    /** @return array{content:string,mime:string} */
    public function image(User $actor, string $publicId): array
    {
        return DB::transaction(function () use ($actor, $publicId): array {
            [$actor] = $this->access->lock($actor, $this->branches->activeBranch($actor)?->id, WebsitePermissions::VIEW);
            $asset = DB::table('website_media_assets')->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->first();
            abort_unless($asset !== null, 404);
            $content = Storage::disk('website_media')->get($asset->path);
            abort_unless(hash_equals($asset->sha256, hash('sha256', $content)), 404);

            return ['content' => $content, 'mime' => $asset->mime];
        });
    }

    /** @param array<mixed> $data */
    private function fingerprint(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string,mixed> $data */
    private function replayed(User $actor, array $data, string $operation, string $hash): bool
    {
        $row = DB::table('website_draft_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
        if (! $row) {
            return false;
        }
        if ($row->actor_user_id !== $actor->id || $row->operation !== $operation || ! hash_equals($row->payload_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This retry key was used for different details. Reload before continuing.']);
        }

        return true;
    }

    /** @param array<string,mixed> $data */
    private function command(User $actor, array $data, string $operation, string $hash, int $id): void
    {
        DB::table('website_draft_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'], 'operation' => $operation, 'payload_hash' => $hash, 'result_id' => $id, 'created_at' => now()->utc()->toIso8601String()]);
    }

    private static function stale(): never
    {
        throw ValidationException::withMessages(['version' => 'This draft or asset changed. Reload before saving.']);
    }
}
