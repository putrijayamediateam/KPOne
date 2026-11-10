<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-11a clinic licences (docs/teams/hr/HR-11-DESIGN.md section 5). The clinic enters its own licence types (KPOne
 * ships none); a licence is one series per type and branch (or the whole clinic) and each renewal is an append-only
 * version. A wrong version is marked entered in error, never edited. Scans and photos are encrypted on the private disk
 * under a random name. Nothing here blocks clinical work. Audit holds ids and codes only: never a reference, a note or a
 * file name.
 */
final class LicenceService
{
    public const DISK = 'local';

    public const MAX_FILES = 3;

    public const SUGGESTED_WARN_DAYS = 60;

    public const CORRECTION_REASONS = ['wrong_dates' => 'Wrong dates', 'wrong_licence' => 'Wrong licence', 'duplicate' => 'Entered twice', 'other' => 'Other'];

    public function __construct(private ComplianceSupport $support) {}

    /**
     * Adds a licence type, or changes the name and warning days of one. Whether a type expires is fixed when it is added.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveType(User $actor, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $typeId = $this->optionalUuid($input['type_id'] ?? null, 'type_id');
        $name = $this->support->text($input['name'] ?? null, 'name', 120, required: true);
        if ($name === null || mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => 'Enter the name (2 to 120 characters).']);
        }
        $expires = null;
        if ($typeId === null) {
            $expires = filter_var($input['expires'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($expires === null) {
                throw ValidationException::withMessages(['expires' => 'Choose whether it expires.']);
            }
        }
        $warnInput = $input['warn_from_days'] ?? null;
        $warn = filter_var($warnInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if (($typeId === null ? $expires === true : true) && $warn === false && $warnInput !== null && $warnInput !== '') {
            throw ValidationException::withMessages(['warn_from_days' => 'Enter the warning days, a whole number from 1 to 365.']);
        }
        if ($typeId === null && $expires === true && $warn === false) {
            throw ValidationException::withMessages(['warn_from_days' => 'Enter the warning days, a whole number from 1 to 365.']);
        }

        return DB::transaction(function () use ($actor, $input, $key, $typeId, $name, $expires, $warn): string {
            $actor = $this->support->lock($actor, CompliancePermissions::LICENCES_MANAGE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['compliance_licence_types:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'licence_type', $typeId, $name, $expires, $warn]);
            if (($replay = $this->support->replay($actor, $key, 'licence_type', $hash)) !== null) {
                return $replay;
            }
            $type = null;
            if ($typeId !== null) {
                $type = DB::table('compliance_licence_types')->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->lockForUpdate()->first();
                abort_if($type === null, 404);
                $this->support->assertLock($type, $input);
                if (! $type->is_active) {
                    throw ValidationException::withMessages(['compliance' => 'This type was archived. Reload the page.']);
                }
                $expires = (bool) $type->expires;
                if ($expires && $warn === false) {
                    throw ValidationException::withMessages(['warn_from_days' => 'Enter the warning days, a whole number from 1 to 365.']);
                }
            }
            $clash = DB::table('compliance_licence_types')->where('organisation_id', $actor->organisation_id)->where('is_active', true)
                ->whereRaw('lower(name) = lower(?)', [$name])->when($type !== null, fn ($q) => $q->where('id', '<>', $type->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['name' => 'Another licence type already has this name.']);
            }
            $warnValue = $expires ? (int) $warn : null;
            if ($type === null) {
                $publicId = (string) Str::uuid();
                DB::table('compliance_licence_types')->insert(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'name' => $name, 'expires' => $expires,
                    'warn_from_days' => $warnValue, 'is_active' => true, 'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                $id = (int) DB::table('compliance_licence_types')->where('public_id', $publicId)->value('id');
            } else {
                DB::table('compliance_licence_types')->where('id', $type->id)->update(['name' => $name, 'warn_from_days' => $warnValue,
                    'lock_version' => $type->lock_version + 1, 'updated_at' => now()->utc()]);
                $publicId = (string) $type->public_id;
                $id = (int) $type->id;
            }
            $this->support->remember($actor, $key, 'licence_type', $hash, $publicId);
            $this->support->audit->record('compliance.licence_type_saved', null, ['type_id' => $id, 'created' => $type === null, 'expires' => $expires], $actor, null, $actor->organisation_id);

            return $publicId;
        }, 3);
    }

    /**
     * Archives a type: it takes no new licences; licences already kept stay and keep their status.
     *
     * @param  array<string, mixed>  $input
     */
    public function archiveType(User $actor, string $typeId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $typeId = $this->optionalUuid($typeId, 'type_id') ?? abort(404);

        return DB::transaction(function () use ($actor, $typeId, $input, $key): string {
            $actor = $this->support->lock($actor, CompliancePermissions::LICENCES_MANAGE);
            $hash = $this->support->hash([$actor->id, 'archive_type', $typeId]);
            if (($replay = $this->support->replay($actor, $key, 'archive_type', $hash)) !== null) {
                return $replay;
            }
            $type = DB::table('compliance_licence_types')->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->lockForUpdate()->first();
            abort_if($type === null, 404);
            $this->support->assertLock($type, $input);
            if ($type->is_active) {
                DB::table('compliance_licence_types')->where('id', $type->id)->update(['is_active' => false, 'lock_version' => $type->lock_version + 1, 'updated_at' => now()->utc()]);
                $this->support->audit->record('compliance.licence_type_archived', null, ['type_id' => $type->id], $actor, null, $actor->organisation_id);
            }
            $this->support->remember($actor, $key, 'archive_type', $hash, (string) $type->public_id);

            return (string) $type->public_id;
        }, 3);
    }

    /**
     * Records a licence: the first version of a new series, or a renewal of an existing one (then `licence_id` and its
     * `expected_lock_version` are sent). Files were checked by content before this call.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array{content:string, kind:string, bytes:int, sha256:string}>  $files
     * @return string the licence's public id
     */
    public function record(User $actor, array $input, array $files): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $typeId = $this->optionalUuid($input['type_id'] ?? null, 'type_id') ?? throw ValidationException::withMessages(['type_id' => 'Choose a licence type.']);
        $licenceId = $this->optionalUuid($input['licence_id'] ?? null, 'licence_id');
        $branchInput = $input['branch_id'] ?? null;
        $reference = $this->support->text($input['reference'] ?? null, 'reference', 120);
        $note = $this->support->text($input['note'] ?? null, 'note', 200);
        $issued = $this->support->date($input['issued_on'] ?? null, 'issued_on');
        $expires = $this->support->date($input['expires_on'] ?? null, 'expires_on');
        if ($issued !== null && $expires !== null && $expires <= $issued) {
            throw ValidationException::withMessages(['expires_on' => 'The expiry date must be after the issue date.']);
        }
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'Add at most '.self::MAX_FILES.' files.']);
        }
        $paths = array_map(fn (): string => 'compliance/licences/'.Str::uuid()->toString(), $files);
        $written = [];

        try {
            return DB::transaction(function () use ($actor, $input, $key, $typeId, $licenceId, $branchInput, $reference, $note, $issued, $expires, $files, $paths, &$written): string {
                $actor = $this->support->lock($actor, CompliancePermissions::LICENCES_MANAGE);
                $branchId = $this->branchId($actor, $branchInput);
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['compliance_licence:'.$actor->organisation_id.':'.$typeId.':'.($branchId ?? 0)]);
                $hash = $this->support->hash([$actor->id, 'licence', $typeId, $licenceId, $branchId, $reference, $note, $issued, $expires, array_map(fn (array $f): string => $f['sha256'], $files)]);
                if (($replay = $this->support->replay($actor, $key, 'licence', $hash)) !== null) {
                    return $replay;
                }
                $type = DB::table('compliance_licence_types')->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->lockForUpdate()->first();
                abort_if($type === null, 404);
                if (! $type->is_active) {
                    throw ValidationException::withMessages(['type_id' => 'This type was archived. Choose another.']);
                }
                if ($type->expires && $expires === null) {
                    throw ValidationException::withMessages(['expires_on' => 'Enter the expiry date.']);
                }
                if (! $type->expires && $expires !== null) {
                    throw ValidationException::withMessages(['expires_on' => 'This type does not expire, so leave the expiry date empty.']);
                }
                $series = DB::table('compliance_licences')->where('organisation_id', $actor->organisation_id)->where('type_id', $type->id)
                    ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))->lockForUpdate()->first();
                if ($licenceId !== null) {
                    if ($series === null || (string) $series->public_id !== $licenceId) {
                        throw ValidationException::withMessages(['compliance' => 'This licence changed. Reload the page.']);
                    }
                    $this->support->assertLock($series, $input);
                } elseif ($series !== null) {
                    throw ValidationException::withMessages(['type_id' => 'This licence is already recorded for this branch. Renew it from its row.']);
                }
                if ($series !== null) {
                    $inForce = $this->inForce((int) $series->id);
                    $before = $inForce === null ? null : $this->dayOf(data_get($inForce, 'expires_on'));
                    if ($before !== null && $expires !== null && $expires < $before) {
                        throw ValidationException::withMessages(['expires_on' => "This expiry is earlier than the one in force ({$before}). Check the date, or mark the older entry as entered in error first."]);
                    }
                    $version = (int) $series->current_version + 1;
                    DB::table('compliance_licences')->where('id', $series->id)->update(['current_version' => $version, 'lock_version' => $series->lock_version + 1, 'updated_at' => now()->utc()]);
                    $seriesId = (int) $series->id;
                    $publicId = (string) $series->public_id;
                } else {
                    $version = 1;
                    $publicId = (string) Str::uuid();
                    $seriesId = (int) DB::table('compliance_licences')->insertGetId(['public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'type_id' => $type->id,
                        'branch_id' => $branchId, 'current_version' => 1, 'created_by_user_id' => $actor->id, 'lock_version' => 1, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                }
                $versionId = (int) DB::table('compliance_licence_versions')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'licence_id' => $seriesId,
                    'version' => $version, 'reference_encrypted' => $reference === null ? null : Crypt::encryptString($reference), 'issued_on' => $issued, 'expires_on' => $expires,
                    'note_encrypted' => $note === null ? null : Crypt::encryptString($note), 'entered_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
                foreach ($files as $index => $file) {
                    if (! Storage::disk(self::DISK)->put($paths[$index], Crypt::encryptString($file['content']))) {
                        throw ValidationException::withMessages(['files' => "This file couldn't be saved. Try again."]);
                    }
                    $written[] = $paths[$index];
                    DB::table('compliance_licence_files')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'version_id' => $versionId,
                        'label' => ($file['kind'] === 'pdf' ? 'Scan ' : 'Photo ').($index + 1), 'kind' => $file['kind'], 'bytes' => $file['bytes'], 'sha256' => $file['sha256'],
                        'stored_path' => $paths[$index], 'added_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
                }
                $this->support->remember($actor, $key, 'licence', $hash, $publicId);
                $this->support->audit->record('compliance.licence_recorded', null, ['licence_id' => $seriesId, 'type_id' => $type->id, 'branch_id' => $branchId,
                    'version' => $version, 'file_count' => count($files)], $actor, null, $actor->organisation_id);

                return $publicId;
            }, 3);
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Marks one version entered in error. The older version in force before it comes back into force; nothing is
     * deleted and the entry stays readable.
     *
     * @param  array<string, mixed>  $input
     */
    public function markInError(User $actor, string $versionId, array $input): string
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $versionId = $this->optionalUuid($versionId, 'version_id') ?? abort(404);
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        if (! array_key_exists($reason, self::CORRECTION_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason.']);
        }

        return DB::transaction(function () use ($actor, $versionId, $input, $key, $reason): string {
            $actor = $this->support->lock($actor, CompliancePermissions::LICENCES_MANAGE);
            $hash = $this->support->hash([$actor->id, 'licence_error', $versionId, $reason]);
            if (($replay = $this->support->replay($actor, $key, 'licence_error', $hash)) !== null) {
                return $replay;
            }
            $version = DB::table('compliance_licence_versions')->where('organisation_id', $actor->organisation_id)->where('public_id', $versionId)->first();
            abort_if($version === null, 404);
            $series = DB::table('compliance_licences')->where('id', $version->licence_id)->lockForUpdate()->first();
            abort_if($series === null, 404);
            $this->support->assertLock($series, $input);
            if (DB::table('compliance_licence_corrections')->where('version_id', $version->id)->exists()) {
                throw ValidationException::withMessages(['compliance' => 'This entry was already marked as entered in error.']);
            }
            DB::table('compliance_licence_corrections')->insert(['organisation_id' => $actor->organisation_id, 'version_id' => $version->id, 'reason_code' => $reason,
                'corrected_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            DB::table('compliance_licences')->where('id', $series->id)->update(['lock_version' => $series->lock_version + 1, 'updated_at' => now()->utc()]);
            $this->support->remember($actor, $key, 'licence_error', $hash, (string) $series->public_id);
            $this->support->audit->record('compliance.licence_marked_in_error', null, ['licence_id' => $series->id, 'version' => $version->version, 'reason' => $reason], $actor, null, $actor->organisation_id);

            return (string) $series->public_id;
        }, 3);
    }

    /**
     * One scan or photo, decrypted, after the open is audited (the audit row and the permission check come first).
     *
     * @return array{content:string, mime:string, inline:bool, filename:string}
     */
    public function open(User $actor, string $fileId): array
    {
        $fileId = $this->optionalUuid($fileId, 'file_id') ?? abort(404);
        $file = DB::transaction(function () use ($actor, $fileId): object {
            $actor = $this->support->lock($actor, CompliancePermissions::LICENCES_MANAGE);
            $file = DB::table('compliance_licence_files')->where('organisation_id', $actor->organisation_id)->where('public_id', $fileId)->first();
            abort_if($file === null, 404);
            $this->support->audit->record('compliance.licence_file_opened', null, ['file_id' => $file->id, 'version_id' => $file->version_id], $actor, null, $actor->organisation_id);

            return $file;
        }, 3);
        $stored = Storage::disk(self::DISK)->exists($file->stored_path) ? Storage::disk(self::DISK)->get($file->stored_path) : null;
        abort_if(! is_string($stored), 404);
        try {
            $content = Crypt::decryptString($stored);
        } catch (DecryptException) {
            Log::error('Clinic licence file could not be decrypted', ['file_id' => $file->id]);
            abort(404);
        }
        $mime = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$file->kind] ?? 'application/octet-stream';

        return ['content' => $content, 'mime' => $mime, 'inline' => $file->kind !== 'pdf',
            'filename' => 'licence-'.substr((string) $file->public_id, 0, 8).'.'.($file->kind === 'jpeg' ? 'jpg' : $file->kind)];
    }

    /** The newest version of a series that was not marked in error. */
    public function inForce(int $licenceId): ?object
    {
        return DB::table('compliance_licence_versions as v')->leftJoin('compliance_licence_corrections as c', 'c.version_id', '=', 'v.id')
            ->where('v.licence_id', $licenceId)->whereNull('c.id')->orderByDesc('v.version')->first(['v.id', 'v.version', 'v.issued_on', 'v.expires_on']);
    }

    private function branchId(User $actor, mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'clinic') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ok = $id !== false && DB::table('branches')->where('id', $id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a branch, or the whole clinic.']);
        }

        return (int) $id;
    }

    private function optionalUuid(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages([$field => 'Refresh this page and try again.']);
        }

        return $value;
    }

    private function dayOf(mixed $value): ?string
    {
        return ComplianceStatus::day($value);
    }
}
