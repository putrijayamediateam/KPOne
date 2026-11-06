<?php

namespace App\Domain\HR\Sops\Services;

use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\HR\Sops\SopPermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-5c SOPs (HR-PLAN 12.3 rule 8). Director and HR Manager publish an SOP as a PDF to chosen roles; a new file is a new
 * version, never an edit. Everyone in those roles opens it (recorded before any byte is sent) and then presses "I have
 * read and understood", once per version. Withdrawing an SOP keeps every version and acknowledgement. Audit holds ids,
 * versions and counts only, never titles or file contents.
 */
final class SopService
{
    public const DISK = 'local';

    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private RecordSupport $support) {}

    /** @return array{content:string, kind:string, bytes:int, sha256:string} a checked PDF; anything else is refused */
    public static function prepare(mixed $file): array
    {
        // Only PDFs, so every refusal names only PDFs (Suresh, HR-5c M3).
        if (! $file instanceof UploadedFile || ! $file->isValid() || (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()) !== 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'Use a PDF file.']);
        }
        $prepared = LeaveEvidenceFiles::prepare($file, 'file', self::MAX_BYTES);
        if ($prepared['kind'] !== 'pdf') {
            throw ValidationException::withMessages(['file' => 'Use a PDF file.']);
        }

        return $prepared;
    }

    /**
     * A new SOP, published as version 1.
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared
     * @param  array<string, mixed>  $input
     */
    public function publish(User $actor, array $prepared, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        [$title, $roles] = $this->validated($input);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $prepared, $key, $title, $roles, &$written): string {
                $this->forget($written);
                $actor = $this->support->lock($actor, SopPermissions::MANAGE);
                $hash = $this->support->hash([$actor->id, 'sop_publish', $prepared['sha256'], $title, $roles]);
                if (($replay = $this->support->replay($actor, $key, 'sop_publish', $hash)) !== null) {
                    return $replay;
                }
                $publicId = (string) Str::uuid();
                $sopId = DB::table('hr_sops')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'current_version' => 1,
                    'is_active' => true, 'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                $versionId = $this->storeVersion($actor, $sopId, 1, $title, $roles, $prepared, $written);
                $this->support->remember($actor, $key, 'sop_publish', $hash, $publicId);
                $this->support->audit->record('hr.sops.published', null, ['sop_id' => $sopId, 'sop_version_id' => $versionId, 'version' => 1,
                    'roles' => count($roles), 'bytes' => $prepared['bytes']], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            $this->forget($written);
            throw $e;
        }
    }

    /**
     * A new version of an SOP: a new file, title and roles. Earlier versions and their acknowledgements stay; everyone
     * in the new roles acknowledges the new version again.
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared
     * @param  array<string, mixed>  $input
     */
    public function replace(User $actor, string $sopId, array $prepared, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        [$title, $roles] = $this->validated($input);
        $written = [];
        try {
            DB::transaction(function () use ($actor, $sopId, $prepared, $input, $key, $title, $roles, &$written): void {
                $this->forget($written);
                $actor = $this->support->lock($actor, SopPermissions::MANAGE);
                $hash = $this->support->hash([$actor->id, 'sop_replace', $sopId, $prepared['sha256'], $title, $roles]);
                if ($this->support->replay($actor, $key, 'sop_replace', $hash) !== null) {
                    return;
                }
                $sop = $this->sop($actor, $sopId, true);
                $this->support->assertLock($sop, $input);
                if (! $sop->is_active) {
                    throw ValidationException::withMessages(['sop' => 'This SOP was withdrawn. Publish a new SOP instead.']);
                }
                $current = DB::table('hr_sop_versions')->where('sop_id', $sop->id)->where('version', $sop->current_version)->first(['sha256']);
                if ($current !== null && $current->sha256 === $prepared['sha256']) {
                    throw ValidationException::withMessages(['file' => 'This is the same file as the current version. Choose the new file.']);
                }
                $next = (int) $sop->current_version + 1;
                $versionId = $this->storeVersion($actor, (int) $sop->id, $next, $title, $roles, $prepared, $written);
                DB::table('hr_sops')->where('id', $sop->id)->update(['current_version' => $next, 'lock_version' => (int) $sop->lock_version + 1, 'updated_at' => now()->utc()]);
                $this->support->remember($actor, $key, 'sop_replace', $hash, $sopId);
                $this->support->audit->record('hr.sops.published', null, ['sop_id' => (int) $sop->id, 'sop_version_id' => $versionId, 'version' => $next,
                    'roles' => count($roles), 'bytes' => $prepared['bytes']], $actor, null, $actor->organisation_id);
            }, 3);
        } catch (\Throwable $e) {
            $this->forget($written);
            throw $e;
        }
    }

    /** @param array<string, mixed> $input */
    public function withdraw(User $actor, string $sopId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $sopId, $input, $key): void {
            $actor = $this->support->lock($actor, SopPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'sop_withdraw', $sopId]);
            if ($this->support->replay($actor, $key, 'sop_withdraw', $hash) !== null) {
                return;
            }
            $sop = $this->sop($actor, $sopId, true);
            $this->support->assertLock($sop, $input);
            if (! $sop->is_active) {
                throw ValidationException::withMessages(['sop' => 'This SOP was already withdrawn. Reload the page.']);
            }
            DB::table('hr_sops')->where('id', $sop->id)->update(['is_active' => false, 'withdrawn_by_user_id' => $actor->id, 'withdrawn_at' => now()->utc(),
                'lock_version' => (int) $sop->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'sop_withdraw', $hash, $sopId);
            $this->support->audit->record('hr.sops.withdrawn', null, ['sop_id' => (int) $sop->id, 'version' => (int) $sop->current_version],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * Records the open, then returns the decrypted PDF. A manager opens any version; anyone else opens the current
     * version of a published SOP for one of their roles. Nothing is returned if the record cannot be written.
     *
     * @return array{content:string, filename:string}
     */
    public function open(User $actor, string $versionId): array
    {
        $version = DB::transaction(function () use ($actor, $versionId): \stdClass {
            $manager = $actor->can(SopPermissions::MANAGE);
            $actor = $this->support->authority->lock($actor, $manager ? SopPermissions::MANAGE : SopPermissions::ACKNOWLEDGE_OWN);
            $version = $this->version($actor, $versionId);
            if (! $manager) {
                $this->assertReader($actor, $version);
            }
            DB::table('hr_sop_opens')->insert(['organisation_id' => $actor->organisation_id, 'sop_version_id' => $version->id, 'user_id' => $actor->id,
                'created_at' => now()->utc()]);
            $this->support->audit->record('hr.sops.opened', null, ['sop_id' => (int) $version->sop_id, 'sop_version_id' => (int) $version->id,
                'version' => (int) $version->version], $actor, null, $actor->organisation_id);

            return $version;
        }, 3);
        $stored = Storage::disk(self::DISK)->exists($version->stored_path) ? Storage::disk(self::DISK)->get($version->stored_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('HR SOP could not be decrypted', ['sop_version_id' => $version->id]);
            abort(404);
        }

        return ['content' => $content, 'filename' => 'sop-'.substr((string) $version->public_id, 0, 8).'-v'.$version->version.'.pdf'];
    }

    /**
     * "I have read and understood": once per person and version, only for the current version of a published SOP for
     * one of the person's roles, and only after the person opened that version.
     *
     * @param  array<string, mixed>  $input
     */
    public function acknowledge(User $actor, string $versionId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $versionId, $key): void {
            $actor = $this->support->lock($actor, SopPermissions::ACKNOWLEDGE_OWN);
            $hash = $this->support->hash([$actor->id, 'sop_ack', $versionId]);
            if ($this->support->replay($actor, $key, 'sop_ack', $hash) !== null) {
                return;
            }
            $version = $this->version($actor, $versionId);
            // A replace or withdrawal waits for this acknowledgement, and an acknowledgement after it sees the change.
            $this->assertReader($actor, $version, true);
            if (DB::table('hr_sop_acknowledgements')->where('sop_version_id', $version->id)->where('user_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['sop' => 'You already acknowledged this version.']);
            }
            if (! DB::table('hr_sop_opens')->where('sop_version_id', $version->id)->where('user_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['sop' => 'Open the SOP first.']);
            }
            DB::table('hr_sop_acknowledgements')->insert(['organisation_id' => $actor->organisation_id, 'sop_version_id' => $version->id,
                'user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'sop_ack', $hash, $versionId);
            $this->support->audit->record('hr.sops.acknowledged', null, ['sop_id' => (int) $version->sop_id, 'sop_version_id' => (int) $version->id,
                'version' => (int) $version->version], $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * The roles an SOP version is published to that this account holds. Empty when none.
     *
     * @return list<string>
     */
    public static function humanRoles(User $user): array
    {
        return array_values(array_intersect(RosterPermissions::HUMAN_ROLES, $user->roles->pluck('name')->all()));
    }

    /**
     * @param  list<string>  $roles
     * @param  array{content:string, kind:string, bytes:int, sha256:string}  $prepared
     * @param  list<string>  $written
     */
    private function storeVersion(User $actor, int $sopId, int $version, string $title, array $roles, array $prepared, array &$written): int
    {
        $publicId = (string) Str::uuid();
        $path = "hr-sops/{$actor->organisation_id}/{$publicId}.bin";
        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($prepared['content']))) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
        }
        $written[] = $path;
        $versionId = DB::table('hr_sop_versions')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'sop_id' => $sopId,
            'version' => $version, 'title' => $title, 'bytes' => $prepared['bytes'], 'sha256' => $prepared['sha256'], 'stored_path' => $path,
            'published_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
        DB::table('hr_sop_audiences')->insert(array_map(fn (string $role): array => ['sop_version_id' => $versionId, 'role' => $role], $roles));

        return $versionId;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0:string, 1:list<string>}
     */
    private function validated(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['title'])) : '';
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
            throw ValidationException::withMessages(['title' => 'Enter a title of 3 to 120 characters.']);
        }
        $roles = $input['roles'] ?? [];
        if (! is_array($roles) || $roles === [] || array_diff($roles, array_keys(RosterRules::ROLE_LABELS)) !== []
            || array_diff($roles, RosterPermissions::HUMAN_ROLES) !== []) {
            throw ValidationException::withMessages(['roles' => 'Choose at least one role that must read it.']);
        }
        $roles = array_values(array_unique(array_map('strval', $roles)));
        sort($roles);

        return [$title, $roles];
    }

    private function sop(User $actor, string $sopId, bool $lock): \stdClass
    {
        if (! Str::isUuid($sopId)) {
            abort(404);
        }
        $query = DB::table('hr_sops')->where('organisation_id', $actor->organisation_id)->where('public_id', $sopId);
        $sop = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if(! $sop instanceof \stdClass, 404);

        return $sop;
    }

    private function version(User $actor, string $versionId): \stdClass
    {
        if (! Str::isUuid($versionId)) {
            abort(404);
        }
        $version = DB::table('hr_sop_versions')->where('organisation_id', $actor->organisation_id)->where('public_id', $versionId)->first();
        abort_if(! $version instanceof \stdClass, 404);

        return $version;
    }

    /** The version is the current one of a published SOP, published to one of the actor's roles. */
    private function assertReader(User $actor, \stdClass $version, bool $lockSop = false): void
    {
        $query = DB::table('hr_sops')->where('id', $version->sop_id);
        $sop = ($lockSop ? $query->sharedLock() : $query)->first(['is_active', 'current_version']);
        if ($sop === null || ! $sop->is_active || (int) $sop->current_version !== (int) $version->version) {
            throw ValidationException::withMessages(['sop' => 'This SOP has changed. Reload the page.']);
        }
        $roles = DB::table('hr_sop_audiences')->where('sop_version_id', $version->id)->pluck('role')->all();
        if (array_intersect($roles, self::humanRoles($actor)) === []) {
            throw new AuthorizationException('This SOP is not published to your role.');
        }
    }

    /** @param list<string> $paths */
    private function forget(array &$paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
        $paths = [];
    }
}
