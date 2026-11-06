<?php

namespace App\Domain\HR\Credentials\Services;

use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Credentials\Models\CredentialRecord;
use App\Domain\HR\Credentials\Models\CredentialType;
use App\Domain\HR\Records\Models\Document;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-5a credential register (HR-PLAN 12.3). Types are clinic-entered and versioned; a person's credential is versioned,
 * and each new version waits until someone other than the person and other than whoever entered it accepts it. Evidence
 * goes into the HR-4b vault as a Certificate. Nothing here blocks clinical work (TEAM.md decision 4), and nothing touches
 * DOC-02a's clinician credentials. Audit holds ids, type codes and dates only; reference numbers never reach it.
 */
final class CredentialService
{
    public const REJECT_REASONS = ['wrong_dates' => 'Wrong dates', 'not_readable' => 'Not readable', 'wrong_person' => 'Wrong person', 'other' => 'Other'];

    public const SUGGESTED_WARN_DAYS = 60;

    public function __construct(private RecordSupport $support, private DocumentService $documents) {}

    /**
     * Adds a type, or saves a new version of one (name, warning days, roles). Whether a type expires or is a health item
     * is fixed when it is added.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveType(User $actor, array $input): CredentialType
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $typeId = is_string($input['type_id'] ?? null) && $input['type_id'] !== '' ? $input['type_id'] : null;
        $name = is_string($input['name'] ?? null) ? trim(preg_replace('/\s+/', ' ', $input['name']) ?? '') : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter the name (2 to 120 characters).']);
        }
        $warn = filter_var($input['warn_from_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($warn === false) {
            throw ValidationException::withMessages(['warn_from_days' => 'Enter the warning days, a whole number from 1 to 365.']);
        }
        $roles = $input['roles'] ?? [];
        if (! is_array($roles) || array_diff($roles, RosterPermissions::HUMAN_ROLES) !== []) {
            throw ValidationException::withMessages(['roles' => 'Choose roles from the list.']);
        }
        $roles = array_values(array_unique(array_map('strval', $roles)));
        sort($roles);
        $new = null;
        if ($typeId === null) {
            $code = is_string($input['code'] ?? null) ? strtoupper(trim($input['code'])) : '';
            if (preg_match('/\A[A-Z0-9][A-Z0-9-]{1,15}\z/', $code) !== 1) {
                throw ValidationException::withMessages(['code' => 'Enter a short code of 2 to 16 letters, digits or hyphens, for example APC.']);
            }
            $expires = filter_var($input['expires'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $health = filter_var($input['health_item'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($expires === null) {
                throw ValidationException::withMessages(['expires' => 'Choose whether it expires.']);
            }
            if ($health === null) {
                throw ValidationException::withMessages(['health_item' => 'Choose whether it is a health item.']);
            }
            if ($health && $expires) {
                throw ValidationException::withMessages(['expires' => 'A health item records only Done or Not done and a date, so it does not expire.']);
            }
            $new = ['code' => $code, 'expires' => $expires, 'health' => $health];
        }

        return DB::transaction(function () use ($actor, $input, $key, $typeId, $name, $warn, $roles, $new): CredentialType {
            $actor = $this->support->lock($actor, CredentialPermissions::MANAGE);
            // One save at a time per organisation keeps names unique and versions in order.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_credential_types:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'credential_type', $typeId, $new, $name, $warn, $roles]);
            if (($replay = $this->support->replay($actor, $key, 'credentials_type', $hash)) !== null) {
                return CredentialType::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
            }
            $type = null;
            if ($typeId !== null) {
                if (! Str::isUuid($typeId)) {
                    abort(404);
                }
                $type = CredentialType::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->lockForUpdate()->firstOrFail();
                $this->support->assertLock($type, $input);
                if (! $type->is_active) {
                    throw ValidationException::withMessages(['credentials' => 'This type was archived. Reload the page.']);
                }
            }
            $clash = DB::table('hr_credential_types as t')->join('hr_credential_type_versions as v', fn ($j) => $j->on('v.type_id', '=', 't.id')->on('v.version', '=', 't.current_version'))
                ->where('t.organisation_id', $actor->organisation_id)->where('t.is_active', true)->whereRaw('lower(v.name) = lower(?)', [$name])
                ->when($type !== null, fn ($q) => $q->where('t.id', '<>', $type->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['name' => 'Another credential type already has this name.']);
            }
            if ($type === null) {
                if (CredentialType::query()->where('organisation_id', $actor->organisation_id)->where('code', $new['code'])->exists()) {
                    throw ValidationException::withMessages(['code' => 'This code is already used, also by archived types. Choose another.']);
                }
                $type = new CredentialType;
                $type->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'code' => $new['code'], 'expires' => $new['expires'],
                    'health_item' => $new['health'], 'current_version' => 1, 'is_active' => true, 'created_by_user_id' => $actor->id, 'lock_version' => 1])->save();
                $version = 1;
            } else {
                /** @var CredentialType $type */
                $version = $type->current_version + 1;
                $type->forceFill(['current_version' => $version, 'lock_version' => $type->lock_version + 1])->save();
            }
            $versionId = DB::table('hr_credential_type_versions')->insertGetId(['organisation_id' => $actor->organisation_id, 'type_id' => $type->id, 'version' => $version,
                'name' => $name, 'warn_from_days' => $warn, 'set_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            if ($roles !== []) {
                DB::table('hr_credential_type_roles')->insert(array_map(fn (string $role): array => ['type_version_id' => $versionId, 'role' => $role], $roles));
            }
            $this->support->remember($actor, $key, 'credentials_type', $hash, $type->public_id);
            $this->support->audit->record('hr.credentials.type_saved', null, ['type_id' => $type->id, 'code' => $type->code, 'version' => $version,
                'role_count' => count($roles)], $actor, null, $actor->organisation_id);

            return $type;
        }, 3);
    }

    /**
     * Archives a type: it leaves the register and takes no new records; records already kept stay.
     *
     * @param  array<string, mixed>  $input
     */
    public function archiveType(User $actor, string $typeId, array $input): CredentialType
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $typeId, $input, $key): CredentialType {
            $actor = $this->support->lock($actor, CredentialPermissions::MANAGE);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_credential_types:'.$actor->organisation_id]);
            $hash = $this->support->hash([$actor->id, 'credential_type_archive', $typeId]);
            if ($this->support->replay($actor, $key, 'credentials_type_archive', $hash) !== null) {
                return CredentialType::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->firstOrFail();
            }
            if (! Str::isUuid($typeId)) {
                abort(404);
            }
            $type = CredentialType::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->lockForUpdate()->firstOrFail();
            $this->support->assertLock($type, $input);
            if (! $type->is_active) {
                throw ValidationException::withMessages(['credentials' => 'This type was already archived. Reload the page.']);
            }
            $type->forceFill(['is_active' => false, 'lock_version' => $type->lock_version + 1])->save();
            $this->support->remember($actor, $key, 'credentials_type_archive', $hash, $type->public_id);
            $this->support->audit->record('hr.credentials.type_archived', null, ['type_id' => $type->id, 'code' => $type->code], $actor, null, $actor->organisation_id);

            return $type;
        }, 3);
    }

    /**
     * A staff member sends their own record (a first one or a renewal). It waits until a manager accepts it.
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}|null  $prepared
     * @param  array<string, mixed>  $input
     */
    public function sendOwn(User $actor, ?array $prepared, array $input): CredentialRecord
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $prepared, $input, $key, &$written): CredentialRecord {
                $this->documents->forget($written);
                $actor = $this->support->lock($actor, CredentialPermissions::VIEW_OWN);
                $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->lockForUpdate()->first();
                if ($worker === null) {
                    throw ValidationException::withMessages(['credentials' => 'HR has not opened your staff file yet. Ask HR.']);
                }

                return $this->persist($actor, $worker, $prepared, $input, $key, $written);
            }, 3);
        } catch (\Throwable $e) {
            $this->documents->forget($written);
            throw $e;
        }
    }

    /**
     * A manager enters a record for someone else. Another manager accepts it, never the one who entered it.
     *
     * @param  array{content:string, kind:string, bytes:int, sha256:string}|null  $prepared
     * @param  array<string, mixed>  $input
     */
    public function enterFor(User $actor, string $workerId, ?array $prepared, array $input): CredentialRecord
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $workerId, $prepared, $input, $key, &$written): CredentialRecord {
                $this->documents->forget($written);
                $actor = $this->support->lock($actor, CredentialPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
                $worker = $this->support->lockWorker($actor, $workerId);
                $this->support->assertManages($actor, $worker);

                return $this->persist($actor, $worker, $prepared, $input, $key, $written);
            }, 3);
        } catch (\Throwable $e) {
            $this->documents->forget($written);
            throw $e;
        }
    }

    /**
     * Accepts or rejects a waiting record. The decider is a manager who is neither the person nor whoever entered it;
     * accepting replaces the version in force, a rejection is kept with its reason.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, string $recordId, array $input): CredentialRecord
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $decision = $input['decision'] ?? null;
        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw ValidationException::withMessages(['credentials' => 'Choose accept or reject.']);
        }
        $reason = $input['reason'] ?? null;
        if ($decision === 'reject' && (! is_string($reason) || ! array_key_exists($reason, self::REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason' => 'Choose why it is rejected.']);
        }
        if (! Str::isUuid($recordId)) {
            abort(404);
        }
        $subject = DB::table('hr_credential_records as r')->join('hr_workers as w', 'w.id', '=', 'r.worker_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.public_id', $recordId)->first(['w.public_id', 'w.user_id']);
        abort_if($subject === null, 404);

        $deletePath = null;
        $result = DB::transaction(function () use ($actor, $recordId, $input, $key, $decision, $reason, $subject, &$deletePath): CredentialRecord {
            $deletePath = null;
            $actor = $this->support->lock($actor, CredentialPermissions::MANAGE, [$subject->user_id === null ? null : (int) $subject->user_id]);
            $hash = $this->support->hash([$actor->id, 'credential_decide', $recordId, $decision, $reason]);
            if ($this->support->replay($actor, $key, 'credentials_decide', $hash) !== null) {
                return CredentialRecord::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $recordId)->firstOrFail();
            }
            $worker = $this->support->lockWorker($actor, (string) $subject->public_id);
            $this->support->assertManages($actor, $worker);
            $record = CredentialRecord::query()->where('worker_id', $worker->id)->where('public_id', $recordId)->lockForUpdate()->firstOrFail();
            $this->support->assertLock($record, $input);
            if ($record->status !== 'waiting') {
                throw ValidationException::withMessages(['credentials' => 'This record was already checked. Reload the page.']);
            }
            if ($record->entered_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['credentials' => 'Someone else checks this. You entered it.']);
            }
            $type = CredentialType::query()->whereKey($record->type_id)->firstOrFail();
            $now = now()->utc();
            $removedPath = null;
            if ($decision === 'accept') {
                $current = CredentialRecord::query()->where('worker_id', $worker->id)->where('type_id', $record->type_id)->where('status', 'accepted')->lockForUpdate()->first();
                $current?->forceFill(['status' => 'replaced', 'lock_version' => $current->lock_version + 1])->save();
                $record->forceFill(['status' => 'accepted', 'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'lock_version' => $record->lock_version + 1])->save();
            } else {
                $record->forceFill(['status' => 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => $now, 'decision_reason' => $reason,
                    'lock_version' => $record->lock_version + 1])->save();
            }
            // Evidence this record brought in goes when it is rejected as the wrong person or not readable, so nobody keeps
            // someone else's certificate in their file (Suresh, S1). A document linked from the file is left alone.
            if ($decision === 'reject' && in_array($reason, ['wrong_person', 'not_readable'], true) && $record->document_created && $record->document_id !== null) {
                $document = Document::query()->where('worker_id', $worker->id)->whereKey($record->document_id)->lockForUpdate()->first();
                if ($document !== null && $document->status === 'active') {
                    $removedPath = $this->documents->removeLocked($actor, $worker, $document, (string) $reason);
                }
            }
            $this->support->change($actor, $worker, 'credential', [$decision === 'accept' ? 'accepted' : 'rejected', $type->health_item ? 'health_item' : $type->code], $decision === 'reject' ? $reason : null);
            $this->support->remember($actor, $key, 'credentials_decide', $hash, $record->public_id);
            $this->support->audit->record($decision === 'accept' ? 'hr.credentials.record_accepted' : 'hr.credentials.record_rejected', $worker,
                // A health item's code stays out of audit too (Suresh, M6): only the type id says what it was.
                ['record_id' => $record->id, 'type_id' => $type->id, 'code' => $type->health_item ? null : $type->code, 'version' => $record->version,
                    'reason_code' => $decision === 'reject' ? $reason : null, 'document_removed' => $removedPath !== null],
                $actor, null, $actor->organisation_id);

            $deletePath = $removedPath;

            return $record;
        }, 3);
        // The bytes go only once the rejection is committed, as in DocumentService::remove().
        if ($deletePath !== null && ! Storage::disk(DocumentService::DISK)->delete($deletePath)) {
            Log::warning('HR credential evidence file could not be deleted after rejection', ['record_id' => $result->id]);
        }

        return $result;
    }

    /** The full reference number, audited before it is returned (HR-4 Show). The person sees their own; managers anyone's. */
    public function showReference(User $actor, string $recordId): string
    {
        if (! Str::isUuid($recordId)) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $recordId): string {
            $record = CredentialRecord::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $recordId)->firstOrFail();
            $worker = Worker::query()->whereKey($record->worker_id)->firstOrFail();
            $own = $worker->user_id === $actor->id;
            $actor = $this->support->authority->lock($actor, $own ? CredentialPermissions::VIEW_OWN : CredentialPermissions::MANAGE);
            $value = $record->reference_encrypted;
            if ($value === null) {
                throw ValidationException::withMessages(['credentials' => 'There is nothing to show.']);
            }
            $this->support->audit->record('hr.credentials.value_shown', $worker, ['record_id' => $record->id, 'own' => $own], $actor, null, $actor->organisation_id);

            return $value;
        }, 3);
    }

    /**
     * @param  array{content:string, kind:string, bytes:int, sha256:string}|null  $prepared
     * @param  array<string, mixed>  $input
     * @param  list<string>  $written
     */
    private function persist(User $actor, Worker $worker, ?array $prepared, array $input, string $key, array &$written): CredentialRecord
    {
        $typeId = $input['type_id'] ?? null;
        if (! is_string($typeId) || ! Str::isUuid($typeId)) {
            throw ValidationException::withMessages(['type_id' => 'Choose the credential.']);
        }
        $values = [
            'issued_on' => $input['issued_on'] ?? null, 'expires_on' => $input['expires_on'] ?? null,
            'issuing_body' => is_string($input['issuing_body'] ?? null) ? trim($input['issuing_body']) : null,
            'reference' => is_string($input['reference'] ?? null) ? trim($input['reference']) : null,
            'health' => $input['health'] ?? null,
        ];
        $hash = $this->support->hash([$actor->id, 'credential_send', $worker->public_id, $typeId, $values, $prepared['sha256'] ?? null]);
        if (($replay = $this->support->replay($actor, $key, 'credentials_send', $hash)) !== null) {
            return CredentialRecord::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
        }
        // Shared lock: an archive waits for this record, and two people sending for different staff do not wait on each other.
        $type = CredentialType::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $typeId)->sharedLock()->first();
        if ($type === null || ! $type->is_active) {
            throw ValidationException::withMessages(['type_id' => 'This credential type is not in use. Reload the page.']);
        }
        $today = $this->today($actor->organisation_id);
        $row = $this->validated($type, $values, $prepared !== null, $today);
        if (CredentialRecord::query()->where('worker_id', $worker->id)->where('type_id', $type->id)->where('status', 'waiting')->exists()) {
            throw ValidationException::withMessages(['credentials' => 'A record for this credential is already waiting for check.']);
        }
        $documentId = null;
        $created = false;
        if ($prepared !== null) {
            // The same certificate already in the staff file is linked, not stored twice.
            $existing = Document::query()->where('worker_id', $worker->id)->where('status', 'active')->where('sha256', $prepared['sha256'])->first();
            if ($existing !== null && $existing->category !== 'certificate') {
                throw ValidationException::withMessages(['file' => 'This file is already in the staff file under another type.']);
            }
            $documentId = $existing !== null ? $existing->id
                : $this->documents->storeFor($actor, $worker, $prepared, 'certificate', $row['issued_on'], $row['expires_on'], $written)->id;
            $created = $existing === null;
        }
        $version = (int) CredentialRecord::query()->where('worker_id', $worker->id)->where('type_id', $type->id)->max('version') + 1;
        $record = new CredentialRecord;
        $record->forceFill([...$row, 'public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'type_id' => $type->id, 'version' => $version, 'document_id' => $documentId, 'document_created' => $created, 'status' => 'waiting', 'entered_by_user_id' => $actor->id, 'lock_version' => 1])->save();
        $own = $worker->user_id === $actor->id;
        $this->support->change($actor, $worker, 'credential', [$own ? 'sent' : 'entered', $type->health_item ? 'health_item' : $type->code]);
        $this->support->remember($actor, $key, 'credentials_send', $hash, $record->public_id);
        // Dates only for ordinary credentials; a health item's Done or Not done and its date stay out of audit.
        $this->support->audit->record('hr.credentials.record_sent', $worker, ['record_id' => $record->id, 'type_id' => $type->id, 'code' => $type->health_item ? null : $type->code,
            'version' => $version, 'own' => $own,
            'document_id' => $documentId, 'issued_on' => $type->health_item ? null : $row['issued_on'], 'expires_on' => $type->health_item ? null : $row['expires_on']],
            $actor, null, $actor->organisation_id);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{issued_on: ?string, expires_on: ?string, issuing_body: ?string, reference_encrypted: ?string, reference_last4: ?string, health_done: ?bool}
     */
    private function validated(CredentialType $type, array $values, bool $hasFile, string $today): array
    {
        $blank = fn (mixed $v): bool => $v === null || $v === '';
        if ($type->health_item) {
            if (! $blank($values['expires_on']) || ! $blank($values['issuing_body']) || ! $blank($values['reference']) || $hasFile) {
                throw ValidationException::withMessages(['health' => 'A health item records only Done or Not done and the date.']);
            }
            if (! in_array($values['health'], ['done', 'not_done'], true)) {
                throw ValidationException::withMessages(['health' => 'Choose Done or Not done.']);
            }
            $done = $values['health'] === 'done';
            $issued = $done ? $this->date($values['issued_on'], 'issued_on', 'Choose the date it was done.') : null;
            if (! $done && ! $blank($values['issued_on'])) {
                throw ValidationException::withMessages(['issued_on' => 'Leave the date empty when it is not done.']);
            }
            if ($issued !== null && $issued > $today) {
                throw ValidationException::withMessages(['issued_on' => 'Choose a date that is not in the future.']);
            }

            return ['issued_on' => $issued, 'expires_on' => null, 'issuing_body' => null, 'reference_encrypted' => null, 'reference_last4' => null, 'health_done' => $done];
        }
        if (! $blank($values['health'])) {
            throw ValidationException::withMessages(['health' => 'Done or Not done is only for health items.']);
        }
        $issued = $this->date($values['issued_on'], 'issued_on', 'Choose the issue date.');
        if ($issued > $today) {
            throw ValidationException::withMessages(['issued_on' => 'Choose an issue date that is not in the future.']);
        }
        $expires = null;
        if ($type->expires) {
            $expires = $this->date($values['expires_on'], 'expires_on', 'Choose the expiry date.');
            if ($expires <= $issued) {
                throw ValidationException::withMessages(['expires_on' => 'Choose an expiry date after the issue date.']);
            }
        } elseif (! $blank($values['expires_on'])) {
            throw ValidationException::withMessages(['expires_on' => 'This credential does not expire. Leave the expiry date empty.']);
        }
        $body = $blank($values['issuing_body']) ? null : (string) $values['issuing_body'];
        if ($body !== null && mb_strlen($body) > 120) {
            throw ValidationException::withMessages(['issuing_body' => 'Use at most 120 characters.']);
        }
        $reference = $blank($values['reference']) ? null : (string) $values['reference'];
        if ($reference !== null && (mb_strlen($reference) > 40 || strlen(RecordSupport::last4($reference)) < 4)) {
            throw ValidationException::withMessages(['reference' => 'Enter the reference number (at least 4 letters or digits, at most 40 characters).']);
        }

        return ['issued_on' => $issued, 'expires_on' => $expires, 'issuing_body' => $body, 'reference_encrypted' => $reference,
            'reference_last4' => $reference === null ? null : RecordSupport::last4($reference), 'health_done' => null];
    }

    private function date(mixed $value, string $field, string $message): string
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    public function today(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->toDateString();
    }
}
