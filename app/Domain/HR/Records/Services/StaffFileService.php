<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Records\Models\BankDetail;
use App\Domain\HR\Records\Models\EmploymentPeriod;
use App\Domain\HR\Records\Models\PersonalDetails;
use App\Domain\HR\Records\Models\RecordCorrection;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-4a staff files. Opening a file, adding a locum, employment periods, position history, personal details and the
 * audited Show. HR reads accounts, roles and branch assignments and never writes them. Every change writes a change row
 * with field names only; values never reach audit.
 */
final class StaffFileService
{
    public const TYPES = ['permanent' => 'Permanent', 'contract' => 'Contract', 'part_time' => 'Part-time', 'locum' => 'Locum', 'intern' => 'Intern'];

    public const END_REASONS = ['resigned' => 'Resigned', 'contract_ended' => 'Contract ended', 'dismissed' => 'Dismissed', 'retired' => 'Retired', 'other' => 'Other'];

    public const EDIT_REASONS = ['wrong_date' => 'Wrong date', 'wrong_type' => 'Wrong type', 'other' => 'Other'];

    /** Fields a manager may Show; each view is audited before the value leaves the server. */
    public const SHOWABLE = ['identity' => 'identity number', 'legal_name' => 'legal name', 'date_of_birth' => 'date of birth', 'address' => 'address',
        'personal_phone' => 'personal phone', 'emergency_contact' => 'emergency contact', 'bank_account' => 'account number', 'locum_phone' => 'contact phone', 'correction' => 'requested value'];

    public function __construct(private RecordSupport $support) {}

    /** @param array<string, mixed> $input */
    public function openFile(User $actor, array $input): Worker
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false || ! User::query()->whereKey($userId)->where('organisation_id', $actor->organisation_id)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Choose a staff member.']);
        }

        return $this->transaction(function () use ($actor, $key, $userId): Worker {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$userId]);
            $hash = $this->support->hash([$actor->id, 'open', $userId]);
            if (($replay = $this->support->replay($actor, $key, 'records_open', $hash)) !== null) {
                return Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
            }
            $subject = User::query()->whereKey($userId)->firstOrFail();
            if ($subject->hasRole('queue_display') && $subject->getRoleNames()->count() === 1) {
                throw ValidationException::withMessages(['user_id' => 'The TV account has no staff file.']);
            }
            if ($subject->hasRole(PermissionCatalogue::LOCUM_ROLE)) {
                throw ValidationException::withMessages(['user_id' => 'A locum sign-in uses the locum\'s own file. Add the locum, then ask for a sign-in there.']);
            }
            if (Worker::query()->where('user_id', $userId)->exists()) {
                throw ValidationException::withMessages(['user_id' => "{$subject->name} already has a staff file."]);
            }
            $worker = new Worker;
            $worker->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => $userId, 'kind' => 'staff',
                'created_by_user_id' => $actor->id])->save();
            $this->support->assertManages($actor, $worker);
            $this->support->change($actor, $worker, 'worker', ['opened']);
            $this->support->remember($actor, $key, 'records_open', $hash, $worker->public_id);
            $this->support->audit->record('hr.records.file_opened', $worker, ['subject_user_id' => $userId], $actor, null, $actor->organisation_id);

            return $worker;
        });
    }

    /**
     * A locum: a worker record with no KPOne sign-in (decision 8). They cannot be rostered until HR-1 is extended.
     *
     * @param  array<string, mixed>  $input
     */
    public function addLocum(User $actor, array $input): Worker
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $name = $this->text($input['display_name'] ?? null, 'display_name', 'Enter the locum\'s name.', 2, 120);
        $phone = $this->phone($input['phone'] ?? null, 'phone', true);

        return $this->transaction(function () use ($actor, $key, $name, $phone): Worker {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'locum', $name, $phone]);
            if (($replay = $this->support->replay($actor, $key, 'records_locum', $hash)) !== null) {
                return Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
            }
            $worker = new Worker;
            $worker->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'user_id' => null, 'kind' => 'locum',
                'display_name' => $name, 'locum_phone_encrypted' => $phone, 'created_by_user_id' => $actor->id])->save();
            $this->support->change($actor, $worker, 'worker', $phone === null ? ['opened', 'display_name'] : ['opened', 'display_name', 'contact_phone']);
            $this->support->remember($actor, $key, 'records_locum', $hash, $worker->public_id);
            $this->support->audit->record('hr.records.locum_added', $worker, [], $actor, null, $actor->organisation_id);

            return $worker;
        });
    }

    /** @param array<string, mixed> $input */
    public function addPeriod(User $actor, string $workerId, array $input): EmploymentPeriod
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $values = $this->periodValues($input, null);

        return $this->transaction(function () use ($actor, $workerId, $key, $values): EmploymentPeriod {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $hash = $this->support->hash([$actor->id, 'period', $workerId, ...array_values(array_map(fn ($v) => $v instanceof CarbonImmutable ? $v->toDateString() : $v, $values))]);
            if (($replay = $this->support->replay($actor, $key, 'records_period', $hash)) !== null) {
                return EmploymentPeriod::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $replay)->firstOrFail();
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            $this->assertNoOverlap($worker, null, $values['starts_on'], $values['ends_on']);
            $period = new EmploymentPeriod;
            $period->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, ...$values,
                'created_by_user_id' => $actor->id])->save();
            $this->support->change($actor, $worker, 'employment', array_keys(array_filter($values, fn ($v): bool => $v !== null)));
            $this->support->remember($actor, $key, 'records_period', $hash, $period->public_id);
            $this->support->audit->record('hr.records.period_added', $period, ['worker_id' => $worker->id, 'type' => $values['type'], 'ended' => $values['ends_on'] !== null],
                $actor, null, $actor->organisation_id);

            return $period;
        });
    }

    /**
     * Changing a period's start or type, or anything on a period that has already ended, needs an edit reason. Ending
     * a period needs an end reason and changes nothing on the account.
     *
     * @param  array<string, mixed>  $input
     */
    public function updatePeriod(User $actor, string $workerId, string $periodId, array $input): EmploymentPeriod
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);

        return $this->transaction(function () use ($actor, $workerId, $periodId, $input, $key): EmploymentPeriod {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $worker = $this->support->lockWorker($actor, $workerId);
            $period = EmploymentPeriod::query()->where('worker_id', $worker->id)->where('public_id', $periodId)->lockForUpdate()->firstOrFail();
            $values = $this->periodValues($input, $period);
            $hash = $this->support->hash([$actor->id, 'period_update', $periodId, ...array_values(array_map(fn ($v) => $v instanceof CarbonImmutable ? $v->toDateString() : $v, $values)),
                is_scalar($input['edit_reason'] ?? null) ? (string) $input['edit_reason'] : '']);
            if (($replay = $this->support->replay($actor, $key, 'records_period_update', $hash)) !== null) {
                return EmploymentPeriod::query()->where('public_id', $replay)->firstOrFail();
            }
            $this->support->assertManages($actor, $worker);
            $this->support->assertLock($period, $input);
            $changed = [];
            foreach ($values as $field => $value) {
                $old = $period->{$field};
                $oldValue = $old instanceof CarbonImmutable ? $old->toDateString() : $old;
                $newValue = $value instanceof CarbonImmutable ? $value->toDateString() : $value;
                if ($oldValue !== $newValue) {
                    $changed[] = $field;
                }
            }
            if ($changed === []) {
                throw ValidationException::withMessages(['records' => 'Nothing was changed.']);
            }
            $needsReason = $period->ends_on !== null || array_intersect($changed, ['type', 'starts_on']) !== [];
            $reason = $input['edit_reason'] ?? null;
            if ($needsReason && (! is_string($reason) || ! array_key_exists($reason, self::EDIT_REASONS))) {
                throw ValidationException::withMessages(['edit_reason' => 'Choose why this is being changed.']);
            }
            $this->assertNoOverlap($worker, $period, $values['starts_on'], $values['ends_on']);
            $period->forceFill([...$values, 'lock_version' => $period->lock_version + 1])->save();
            $this->support->change($actor, $worker, 'employment', $changed, $needsReason ? (string) $reason : null);
            $this->support->remember($actor, $key, 'records_period_update', $hash, $period->public_id);
            $this->support->audit->record('hr.records.period_changed', $period, ['worker_id' => $worker->id, 'fields' => $changed,
                'reason_code' => $needsReason ? $reason : null], $actor, null, $actor->organisation_id);

            return $period;
        });
    }

    /** @param array<string, mixed> $input */
    public function addPosition(User $actor, string $workerId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $title = $this->text($input['title'] ?? null, 'title', 'Enter the title.', 2, 120);
        $from = $this->date($input['from_date'] ?? null, 'from_date', 'Choose the date it applies from.');

        $this->transaction(function () use ($actor, $workerId, $input, $key, $title, $from): void {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $department = $input['department_id'] ?? null;
            $department = in_array($department, [null, ''], true) ? null : filter_var($department, FILTER_VALIDATE_INT);
            if ($department === false || ($department !== null && ! DB::table('departments')->where('id', $department)->where('organisation_id', $actor->organisation_id)->exists())) {
                throw ValidationException::withMessages(['department_id' => 'Choose a department from the list.']);
            }
            $hash = $this->support->hash([$actor->id, 'position', $workerId, $title, $from->toDateString(), $department, is_scalar($input['reports_to'] ?? null) ? (string) $input['reports_to'] : '']);
            if ($this->support->replay($actor, $key, 'records_position', $hash) !== null) {
                return;
            }
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            $reportsTo = null;
            if (! in_array($input['reports_to'] ?? null, [null, ''], true)) {
                $reports = is_string($input['reports_to']) && Str::isUuid($input['reports_to'])
                    ? Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $input['reports_to'])->first() : null;
                if ($reports === null || $reports->id === $worker->id) {
                    throw ValidationException::withMessages(['reports_to' => 'Choose someone else with a staff file.']);
                }
                $reportsTo = $reports->id;
            }
            if (DB::table('hr_positions')->where('worker_id', $worker->id)->whereDate('from_date', $from->toDateString())->exists()) {
                throw ValidationException::withMessages(['from_date' => 'There is already a position from that date.']);
            }
            DB::table('hr_positions')->insert(['organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, 'title' => $title, 'department_id' => $department,
                'reports_to_worker_id' => $reportsTo, 'from_date' => $from->toDateString(), 'created_by_user_id' => $actor->id, 'created_at' => now()->utc()]);
            $this->support->change($actor, $worker, 'position', array_values(array_filter(['title', 'from_date', $department !== null ? 'department' : null, $reportsTo !== null ? 'reports_to' : null])));
            $this->support->remember($actor, $key, 'records_position', $hash, $worker->public_id);
            $this->support->audit->record('hr.records.position_added', $worker, [], $actor, null, $actor->organisation_id);
        });
    }

    /**
     * A manager saves personal details. Fields left out are kept; an empty value clears a field.
     *
     * @param  array<string, mixed>  $input
     */
    public function savePersonal(User $actor, string $workerId, array $input): PersonalDetails
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $values = $this->personalValues($input, ['legal_name', 'preferred_name', 'identity', 'date_of_birth', 'nationality', 'address', 'personal_phone', 'emergency_contact']);

        return $this->transaction(function () use ($actor, $workerId, $input, $key, $values): PersonalDetails {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE, [$this->support->workerUserId($actor, $workerId)]);
            $worker = $this->support->lockWorker($actor, $workerId);
            $this->support->assertManages($actor, $worker);
            if ($worker->kind === 'locum') {
                throw ValidationException::withMessages(['records' => 'A locum file holds a name, a contact phone and employment periods only.']);
            }

            return $this->writePersonal($actor, $worker, $input, $key, $values, 'records_personal');
        });
    }

    /**
     * The staff member changes their own address, personal phone and emergency contact (decision 36).
     *
     * @param  array<string, mixed>  $input
     */
    public function saveOwnContact(User $actor, array $input): PersonalDetails
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        $values = $this->personalValues($input, ['address', 'personal_phone', 'emergency_contact']);

        return $this->transaction(function () use ($actor, $input, $key, $values): PersonalDetails {
            $actor = $this->support->lock($actor, RecordPermissions::VIEW_OWN);
            $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($worker === null) {
                throw ValidationException::withMessages(['records' => 'HR has not opened your staff file yet.']);
            }

            return $this->writePersonal($actor, $worker, $input, $key, $values, 'records_contact');
        });
    }

    /**
     * Show one masked value. The audit row commits first; only then is the value returned (HR-PLAN 11.3 rule 7).
     *
     * @param  array<string, mixed>  $input
     */
    public function show(User $actor, string $workerId, array $input): string
    {
        $field = $input['field'] ?? null;
        if (! is_string($field) || ! array_key_exists($field, self::SHOWABLE)) {
            throw ValidationException::withMessages(['field' => 'Choose what to show.']);
        }

        return DB::transaction(function () use ($actor, $workerId, $field, $input): string {
            $actor = $this->support->authority->lock($actor, RecordPermissions::VIEW_ORGANISATION);
            if (! Str::isUuid($workerId)) {
                abort(404);
            }
            $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $workerId)->firstOrFail();
            $value = $this->value($worker, $field, $field === 'correction' ? ($input['correction_id'] ?? null) : ($input['bank_id'] ?? null));
            if ($value === null) {
                throw ValidationException::withMessages(['field' => 'There is nothing to show.']);
            }
            // Ids only: which bank row or correction was shown, never the value (Suresh, M2).
            $row = $field === 'bank_account' ? ['bank_id' => BankDetail::query()->where('worker_id', $worker->id)->where('public_id', (string) ($input['bank_id'] ?? ''))->value('id')]
                : ($field === 'correction' ? ['correction_id' => RecordCorrection::query()->where('worker_id', $worker->id)->where('public_id', (string) ($input['correction_id'] ?? ''))->value('id')] : []);
            $this->support->audit->record('hr.records.value_shown', $worker, ['field' => $field, ...$row], $actor, null, $actor->organisation_id);

            return $value;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $values
     */
    private function writePersonal(User $actor, Worker $worker, array $input, string $key, array $values, string $action): PersonalDetails
    {
        $hash = $this->support->hash([$actor->id, $action, $worker->public_id, json_encode($values, JSON_THROW_ON_ERROR)]);
        $details = PersonalDetails::query()->where('worker_id', $worker->id)->lockForUpdate()->first();
        if ($this->support->replay($actor, $key, $action, $hash) !== null) {
            return $details ?? throw ValidationException::withMessages(['records' => 'Reload the page.']);
        }
        if ($details !== null) {
            $this->support->assertLock($details, $input);
        }
        [$details, $fields] = $this->persistPersonal($actor, $worker, $details, $values);
        $this->support->change($actor, $worker, 'personal', $fields);
        $this->support->remember($actor, $key, $action, $hash, $worker->public_id);
        $this->support->audit->record($action === 'records_contact' ? 'hr.records.own_contact_changed' : 'hr.records.personal_changed', $worker, ['fields' => $fields],
            $actor, null, $actor->organisation_id);

        return $details;
    }

    /**
     * Writes validated personal values onto the (locked) details row and returns the field names that changed. Used by
     * the manager form, the self-service form and an applied correction.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: PersonalDetails, 1: list<string>}
     */
    public function persistPersonal(User $actor, Worker $worker, ?PersonalDetails $details, array $values): array
    {
        $details ??= (new PersonalDetails)->forceFill(['organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id, 'lock_version' => 0]);
        $columns = $this->columns($values);
        $changed = [];
        foreach ($columns as $column => $value) {
            if ($details->{$column} !== $value) {
                $changed[] = $column;
            }
        }
        if ($changed === [] && $details->exists) {
            throw ValidationException::withMessages(['records' => 'Nothing was changed.']);
        }
        if (isset($columns['identity_hash'])
            && PersonalDetails::query()->where('organisation_id', $worker->organisation_id)->where('identity_hash', $columns['identity_hash'])->where('worker_id', '<>', $worker->id)->exists()) {
            throw ValidationException::withMessages(['identity_number' => 'Another staff file already has this identity number.']);
        }
        $details->forceFill([...$columns, 'updated_by_user_id' => $actor->id, 'lock_version' => $details->lock_version + 1])->save();
        $fields = array_values(array_unique(array_map(fn (string $c): string => match ($c) {
            'identity_kind', 'identity_encrypted', 'identity_hash', 'identity_last4', 'passport_country' => 'identity',
            default => str_replace('_encrypted', '', $c),
        }, $changed)));

        return [$details, $fields];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> column => stored value (the model encrypts)
     */
    private function columns(array $values): array
    {
        $out = [];
        foreach ($values as $field => $value) {
            match ($field) {
                'legal_name' => $out['legal_name_encrypted'] = $value,
                'preferred_name' => $out['preferred_name'] = $value,
                'date_of_birth' => $out['date_of_birth_encrypted'] = $value,
                'nationality' => $out['nationality'] = $value,
                'address' => $out['address_encrypted'] = $value,
                'personal_phone' => $out['personal_phone_encrypted'] = $value,
                'emergency_contact' => $out['emergency_contact_encrypted'] = $value,
                'identity' => $out += $value === null
                    ? ['identity_kind' => null, 'identity_encrypted' => null, 'identity_hash' => null, 'identity_last4' => null, 'passport_country' => null]
                    : ['identity_kind' => $value['kind'], 'identity_encrypted' => $value['number'], 'identity_hash' => self::identityHash($value['kind'], $value['number'], $value['country']),
                        'identity_last4' => RecordSupport::last4($value['number']), 'passport_country' => $value['country']],
                default => null,
            };
        }

        return $out;
    }

    /** A keyed hash, so a duplicate identity number is refused without decrypting anyone (HR-PLAN 11.3 rule 5). */
    public static function identityHash(string $kind, string $number, ?string $country = null): string
    {
        // A passport number is unique only with its issuing country (Suresh, M6).
        return hash_hmac('sha256', $kind.'|'.($kind === 'passport' ? (string) $country.'|' : '').$number, 'kpone-hr-identity|'.(string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     * @return array<string, mixed> only the fields present in the input
     */
    private function personalValues(array $input, array $allowed): array
    {
        $out = [];
        if (in_array('legal_name', $allowed, true) && array_key_exists('legal_name', $input)) {
            $out['legal_name'] = $this->optionalText($input['legal_name'], 'legal_name', 150);
        }
        if (in_array('preferred_name', $allowed, true) && array_key_exists('preferred_name', $input)) {
            $out['preferred_name'] = $this->optionalText($input['preferred_name'], 'preferred_name', 80);
        }
        if (in_array('identity', $allowed, true) && array_key_exists('identity_kind', $input)) {
            $out['identity'] = $this->identity($input['identity_kind'] ?? null, $input['identity_number'] ?? null, $input['passport_country'] ?? null);
        }
        if (in_array('date_of_birth', $allowed, true) && array_key_exists('date_of_birth', $input)) {
            $out['date_of_birth'] = in_array($input['date_of_birth'], [null, ''], true) ? null : $this->dateOfBirth($input['date_of_birth']);
        }
        if (in_array('nationality', $allowed, true) && array_key_exists('nationality', $input)) {
            $out['nationality'] = $this->optionalText($input['nationality'], 'nationality', 60);
        }
        if (in_array('address', $allowed, true) && array_key_exists('address', $input)) {
            $out['address'] = $this->address($input['address']);
        }
        if (in_array('personal_phone', $allowed, true) && array_key_exists('personal_phone', $input)) {
            $out['personal_phone'] = $this->phone($input['personal_phone'], 'personal_phone', true);
        }
        if (in_array('emergency_contact', $allowed, true) && array_key_exists('emergency_contact', $input)) {
            $out['emergency_contact'] = $this->emergency($input['emergency_contact']);
        }
        if ($out === []) {
            throw ValidationException::withMessages(['records' => 'Nothing was changed.']);
        }

        return $out;
    }

    /** @return array{kind: string, number: string, country: string|null}|null */
    public function identity(mixed $kind, mixed $number, mixed $country): ?array
    {
        if ($kind === null || $kind === '') {
            return null;
        }
        if ($kind === 'ic') {
            $digits = is_string($number) ? preg_replace('/[\s-]/', '', $number) : null;
            if (! is_string($digits) || preg_match('/\A\d{12}\z/', $digits) !== 1) {
                throw ValidationException::withMessages(['identity_number' => 'Enter the 12-digit IC number.']);
            }

            return ['kind' => 'ic', 'number' => $digits, 'country' => null];
        }
        if ($kind === 'passport') {
            $clean = is_string($number) ? strtoupper(preg_replace('/\s/', '', $number) ?? '') : '';
            if (preg_match('/\A[A-Z0-9]{5,20}\z/', $clean) !== 1) {
                throw ValidationException::withMessages(['identity_number' => 'Enter the passport number (5 to 20 letters and digits).']);
            }
            $code = is_string($country) ? strtoupper(trim($country)) : '';
            if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
                throw ValidationException::withMessages(['passport_country' => 'Enter the issuing country as a 2-letter code.']);
            }

            return ['kind' => 'passport', 'number' => $clean, 'country' => $code];
        }
        throw ValidationException::withMessages(['identity_kind' => 'Choose IC or passport.']);
    }

    public function dateOfBirth(mixed $value): string
    {
        $date = $this->date($value, 'date_of_birth', 'Enter the date of birth.');
        if ($date->greaterThan(CarbonImmutable::now()) || $date->year < 1900) {
            throw ValidationException::withMessages(['date_of_birth' => 'Enter a real date of birth.']);
        }

        return $date->toDateString();
    }

    /** @return array<string, string>|null */
    private function address(mixed $value): ?array
    {
        if ($value === null || $value === '' || (is_array($value) && array_filter($value, fn ($v): bool => is_string($v) && trim($v) !== '') === [])) {
            return null;
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages(['address.line1' => 'Enter the address.']);
        }
        $out = [];
        foreach (['line1' => 150, 'line2' => 150, 'city' => 80, 'state' => 80, 'postcode' => 10, 'country' => 60] as $part => $max) {
            $v = isset($value[$part]) && is_string($value[$part]) ? trim($value[$part]) : '';
            if (mb_strlen($v) > $max) {
                throw ValidationException::withMessages(["address.{$part}" => "Use at most {$max} characters."]);
            }
            $out[$part] = $v;
        }
        if ($out['line1'] === '') {
            throw ValidationException::withMessages(['address.line1' => 'Enter the first line of the address.']);
        }

        return $out;
    }

    /** @return array<string, string>|null */
    private function emergency(mixed $value): ?array
    {
        if ($value === null || $value === '' || (is_array($value) && array_filter($value, fn ($v): bool => is_string($v) && trim($v) !== '') === [])) {
            return null;
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages(['emergency_contact.name' => 'Enter the contact\'s name.']);
        }
        $name = $this->text($value['name'] ?? null, 'emergency_contact.name', 'Enter the contact\'s name.', 2, 120);
        $relationship = $this->text($value['relationship'] ?? null, 'emergency_contact.relationship', 'Enter how they are related.', 2, 60);
        $phone = $this->phone($value['phone'] ?? null, 'emergency_contact.phone', false) ?? '';

        return ['name' => $name, 'relationship' => $relationship, 'phone' => $phone];
    }

    private function phone(mixed $value, string $field, bool $optional): ?string
    {
        if ($optional && ($value === null || $value === '')) {
            return null;
        }
        $clean = is_string($value) ? preg_replace('/[\s()-]/', '', $value) : null;
        if (! is_string($clean) || preg_match('/\A\+?\d{7,15}\z/', $clean) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a phone number (7 to 15 digits).']);
        }

        return $clean;
    }

    private function text(mixed $value, string $field, string $message, int $min, int $max): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) < $min) {
            throw ValidationException::withMessages([$field => $message]);
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => "Use at most {$max} characters."]);
        }

        return $text;
    }

    private function optionalText(mixed $value, string $field, int $max): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->text($value, $field, 'Enter a value.', 1, $max);
    }

    private function date(mixed $value, string $field, string $message): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function periodValues(array $input, ?EmploymentPeriod $period): array
    {
        $pick = fn (string $field) => array_key_exists($field, $input) ? $input[$field] : ($period === null ? null : ($period->{$field} instanceof CarbonImmutable ? $period->{$field}->toDateString() : $period->{$field}));
        $type = $pick('type');
        if (! is_string($type) || ! array_key_exists($type, self::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Choose the employment type.']);
        }
        $starts = $this->date($pick('starts_on'), 'starts_on', 'Choose the start date.');
        $optionalDate = fn (string $field, string $message): ?CarbonImmutable => in_array($pick($field), [null, ''], true) ? null : $this->date($pick($field), $field, $message);
        $probation = $optionalDate('probation_ends_on', 'Choose the probation end date.');
        $confirmed = $optionalDate('confirmed_on', 'Choose the confirmation date.');
        $ends = $optionalDate('ends_on', 'Choose the end date.');
        foreach (['probation_ends_on' => $probation, 'confirmed_on' => $confirmed, 'ends_on' => $ends] as $field => $date) {
            if ($date !== null && $date->lessThan($starts)) {
                throw ValidationException::withMessages([$field => 'Choose a date on or after the start date.']);
            }
        }
        $reason = $pick('end_reason');
        $reason = in_array($reason, [null, ''], true) ? null : $reason;
        if ($ends !== null && (! is_string($reason) || ! array_key_exists($reason, self::END_REASONS))) {
            throw ValidationException::withMessages(['end_reason' => 'Choose why the employment ended.']);
        }
        if ($ends === null && $reason !== null) {
            throw ValidationException::withMessages(['ends_on' => 'Choose the end date.']);
        }
        $note = array_key_exists('note', $input) ? $this->support->note($input['note']) : $period?->note_encrypted;
        if ($reason === 'other' && $note === null) {
            throw ValidationException::withMessages(['note' => 'Add a short note for Other.']);
        }

        return ['type' => $type, 'starts_on' => $starts, 'probation_ends_on' => $probation, 'confirmed_on' => $confirmed, 'ends_on' => $ends,
            'end_reason' => $ends === null ? null : $reason, 'note_encrypted' => $note];
    }

    private function assertNoOverlap(Worker $worker, ?EmploymentPeriod $period, CarbonImmutable $starts, ?CarbonImmutable $ends): void
    {
        $others = EmploymentPeriod::query()->where('worker_id', $worker->id)->when($period !== null, fn ($q) => $q->whereKeyNot($period?->id))->get();
        foreach ($others as $other) {
            $otherEnds = $other->ends_on;
            if (($ends === null || $other->starts_on->lessThanOrEqualTo($ends)) && ($otherEnds === null || $starts->lessThanOrEqualTo($otherEnds))) {
                throw ValidationException::withMessages(['starts_on' => 'This overlaps another employment period. End that one first.']);
            }
        }
    }

    private function value(Worker $worker, string $field, mixed $bankId): ?string
    {
        if ($field === 'correction') {
            $correction = is_string($bankId) && Str::isUuid($bankId)
                ? RecordCorrection::query()->where('worker_id', $worker->id)->where('public_id', $bankId)->where('status', 'pending')->first() : null;
            if ($correction === null) {
                return null;
            }
            $v = $correction->new_value_encrypted;

            return match ($correction->kind) {
                'identity' => trim(($v['kind'] === 'ic' ? 'IC ' : 'Passport ').($v['number'] ?? '').(isset($v['country']) ? ' ('.$v['country'].')' : '')),
                'bank' => implode(' · ', [$v['bank_name'] ?? '', $v['holder'] ?? '', $v['account'] ?? '']),
                'date_of_birth' => CarbonImmutable::parse((string) ($v['value'] ?? ''))->format('j M Y'),
                default => (string) ($v['value'] ?? ''),
            };
        }
        if ($field === 'locum_phone') {
            return $worker->locum_phone_encrypted;
        }
        if ($field === 'bank_account') {
            $bank = is_string($bankId) && Str::isUuid($bankId)
                ? BankDetail::query()->where('worker_id', $worker->id)->where('public_id', $bankId)->whereIn('status', ['confirmed', 'waiting'])->first() : null;

            return $bank?->account_encrypted;
        }
        $details = PersonalDetails::query()->where('worker_id', $worker->id)->first();
        if ($details === null) {
            return null;
        }

        return match ($field) {
            'identity' => $details->identity_encrypted,
            'legal_name' => $details->legal_name_encrypted,
            'date_of_birth' => $details->date_of_birth_encrypted === null ? null : CarbonImmutable::parse($details->date_of_birth_encrypted)->format('j M Y'),
            'address' => $details->address_encrypted === null ? null : implode(', ', array_filter($details->address_encrypted)),
            'personal_phone' => $details->personal_phone_encrypted,
            'emergency_contact' => $details->emergency_contact_encrypted === null ? null
                : implode(' · ', array_filter([$details->emergency_contact_encrypted['name'] ?? null, $details->emergency_contact_encrypted['relationship'] ?? null, $details->emergency_contact_encrypted['phone'] ?? null])),
            default => null,
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction(fn () => $callback(), 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw ValidationException::withMessages(['starts_on' => 'This overlaps another employment period. End that one first.']);
            }
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['records' => 'This already exists. Reload the page.']);
            }
            throw $e;
        }
    }
}
