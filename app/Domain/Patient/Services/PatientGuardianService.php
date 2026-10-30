<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatientGuardianService
{
    public const RELATIONSHIPS = ['mother', 'father', 'legal_guardian', 'other_family_member'];

    public function today(User $actor): string
    {
        // This lookup belongs only to guardian forms/validation. Never resolve an active
        // branch here: that resolver can cache a fallback and write session state.
        $selected = session(BranchAccessService::SESSION_KEY);
        $zone = $selected ? Branch::query()->whereKey($selected)->where('organisation_id', $actor->organisation_id)->value('timezone') : null;
        $zone ??= 'UTC';

        return now()->setTimezone($zone)->toDateString();
    }

    public function minor(mixed $dob, User $actor): bool
    {
        return self::minorOn($dob, $this->today($actor));
    }

    public static function minorOn(mixed $dob, string $today): bool
    {
        if (! is_string($dob) || $dob === '') {
            return false;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dob);
        } catch (\Throwable) {
            return false;
        }
        if (! $date || $date->format('Y-m-d') !== $dob || $dob > $today) {
            return false;
        }

        // Compare calendar birthdays directly; subtracting years from leap day can overflow into March.
        $years = (int) substr($today, 0, 4) - (int) substr($dob, 0, 4) - (substr($today, 5) < substr($dob, 5) ? 1 : 0);

        return $years < 18;
    }

    /** @return array<string,mixed>|null */
    public function contact(Patient $patient): ?array
    {
        $row = DB::table('patient_guardian_contacts')->where('organisation_id', $patient->organisation_id)->where('patient_id', $patient->id)->first();
        if (! $row) {
            return null;
        }

        return [...json_decode(Crypt::decryptString($row->encrypted_contact), true, 512, JSON_THROW_ON_ERROR), 'relationship' => $row->relationship, 'lock_version' => $row->lock_version];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    public function validate(User $actor, array $input, ?Patient $patient = null): ?array
    {
        $current = $patient ? $this->contact($patient) : null;
        $raw = $input['guardian'] ?? null;
        $dobChanged = $patient && ($input['date_of_birth'] ?? null) !== $patient->date_of_birth?->format('Y-m-d');
        $changed = ($current !== null && array_key_exists('guardian', $input) && $raw === null) || is_array($raw) && array_diff_assoc(array_intersect_key($raw, array_flip(['relationship', 'full_name', 'phone', 'phone_country', 'email'])), $current ?? []) !== [];
        $minor = $this->minor($input['date_of_birth'] ?? null, $actor);
        $required = $minor && (! $patient || $dobChanged || $changed);
        if ($current !== null && array_key_exists('guardian', $input) && $raw === null) {
            throw ValidationException::withMessages(['guardian' => 'Guardian contact removal is not available in this slice.']);
        }
        if (! $raw && ! $required) {
            return null;
        }
        if ($patient && ! $changed && ! $dobChanged) {
            return null;
        }
        if (! $minor && ! $current && $raw) {
            throw ValidationException::withMessages(['guardian' => 'A new guardian contact is only available for a known minor.']);
        }
        $data = Validator::make(['guardian' => $raw], [
            'guardian' => ['required', 'array:relationship,full_name,phone,phone_country,email,lock_version,retry_key'],
            'guardian.relationship' => ['required', Rule::in(self::RELATIONSHIPS)],
            'guardian.full_name' => ['required', 'string', 'max:255', 'not_regex:/[\p{C}]/u'],
            'guardian.phone' => ['required', 'string', 'max:64'],
            'guardian.phone_country' => ['required', 'string', 'size:2'],
            'guardian.email' => ['nullable', 'email', 'max:255'],
            'guardian.lock_version' => ['required', 'integer', 'min:0'],
            'guardian.retry_key' => ['required', 'uuid'],
        ], [], [
            'guardian' => 'Guardian contact',
            'guardian.relationship' => 'Guardian relationship',
            'guardian.full_name' => 'Guardian name',
            'guardian.phone' => 'Guardian phone',
            'guardian.phone_country' => 'Guardian phone country',
            'guardian.email' => 'Guardian email',
            'guardian.lock_version' => 'Guardian contact version',
            'guardian.retry_key' => 'Guardian request reference',
        ])->validate()['guardian'];
        $data['full_name'] = trim($data['full_name']);
        if ($data['full_name'] === '') {
            throw ValidationException::withMessages(['guardian.full_name' => 'Guardian name is required.']);
        }
        try {
            $data['phone'] = (new PatientPhoneNormalizer)->normalize($data['phone'], $data['phone_country'], required: true);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['guardian.phone' => 'Enter a valid guardian phone number for the selected country.']);
        }
        $data['email'] = filled($data['email'] ?? null) ? trim($data['email']) : null;

        if ($current && array_intersect_key($data, array_flip(['relationship', 'full_name', 'phone', 'phone_country', 'email'])) == array_intersect_key($current, array_flip(['relationship', 'full_name', 'phone', 'phone_country', 'email']))) {
            return null;
        }

        return $data;
    }

    /** Called within the owning patient/acceptance transaction before patient locking.
     * @param  array<string,mixed>  $input
     */
    public function authority(User $actor, array $input, string $permission): User
    {
        $authority = app(TransactionalActorAuthority::class);
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        abort_unless($branch !== null, 403);
        $branch = $authority->branch($actor, $input['expected_branch_id'] ?? $branch->id);

        return $authority->lock($actor, $branch, $permission);
    }

    /** @param array<string,mixed> $input */
    public function retry(User $actor, array $input, ?Patient $patient = null): ?Patient
    {
        $key = data_get($input, 'guardian.retry_key');
        if (! is_string($key)) {
            return null;
        }
        $row = DB::table('patient_guardian_commands')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $key)->first();
        if (! $row) {
            return null;
        }
        if (($patient && $row->patient_id !== $patient->id) || ! hash_equals($row->payload_hash, $this->hash($input))) {
            throw ValidationException::withMessages(['guardian' => 'This retry key was used for different patient contact details.']);
        }

        $fresh = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($fresh && $fresh->is_active, 403);
        Gate::forUser($fresh)->authorize($patient ? 'update' : 'create', $patient ?? Patient::class);

        return Patient::query()->whereKey($row->patient_id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $input
     */
    public function save(User $actor, Patient $patient, array $data, array $input, string $choice = 'staff'): void
    {
        Gate::forUser($actor)->authorize('view', $patient);
        $row = DB::table('patient_guardian_contacts')->where('organisation_id', $actor->organisation_id)->where('patient_id', $patient->id)->lockForUpdate()->first();
        if ((int) $data['lock_version'] !== (int) ($row->lock_version ?? 0)) {
            throw ValidationException::withMessages(['guardian.lock_version' => 'The guardian contact changed. Reload before saving.']);
        }
        $contact = array_intersect_key($data, array_flip(['full_name', 'phone', 'phone_country', 'email']));
        $values = ['relationship' => $data['relationship'], 'encrypted_contact' => Crypt::encryptString(json_encode($contact, JSON_THROW_ON_ERROR)), 'updated_by_user_id' => $actor->id, 'lock_version' => ($row->lock_version ?? 0) + 1, 'updated_at' => now()->utc()];
        if ($row) {
            DB::table('patient_guardian_contacts')->where('id', $row->id)->update($values);
            $id = $row->id;
        } else {
            $id = DB::table('patient_guardian_contacts')->insertGetId([...$values, 'organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id, 'created_at' => now()->utc()]);
        }
        abort_if(app(AuditRecorder::class)->record('patient.guardian.saved', $patient, ['guardian_id' => $id, 'changed_fields' => ['relationship', 'full_name', 'phone', 'email'], 'choice' => $choice], $actor, organisationId: $patient->organisation_id) === null, 503);
        DB::table('patient_guardian_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'patient_id' => $patient->id, 'retry_key' => $data['retry_key'], 'payload_hash' => $this->hash($input), 'created_at' => now()->utc()]);
    }

    /** @param array<string,mixed> $input */
    private function hash(array $input): string
    {
        return hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
