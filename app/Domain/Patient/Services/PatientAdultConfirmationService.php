<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PatientAdultConfirmationService
{
    /** @param array<string,mixed> $input */
    public function required(array $input, ?Patient $patient = null): bool
    {
        return blank($input['date_of_birth'] ?? null) && ($patient === null || $patient->date_of_birth !== null);
    }

    /** @param array<string,mixed> $input */
    public function validate(array $input, bool $required, bool $dobRequired): void
    {
        if (! $required) {
            return;
        }
        if ($dobRequired) {
            throw ValidationException::withMessages(['date_of_birth' => 'Date of birth is required by Registration settings.']);
        }
        Validator::make($input, [
            'adult_confirmed' => ['required', 'accepted'],
            'adult_confirmation_key' => ['required', 'uuid'],
        ], ['adult_confirmed.required' => 'Enter a date of birth or confirm that the patient is an adult (18 or older).',
            'adult_confirmed.accepted' => 'Enter a date of birth or confirm that the patient is an adult (18 or older).'])->validate();
    }

    /** Called inside the owning transaction, before locking Patient.
     * @param  array<string,mixed>  $input
     */
    public function authority(User $actor, array $input, bool $update): User
    {
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, $input['expected_branch_id'] ?? null);

        return $authority->lock($actor, $branch, $update ? 'patients.update.organisation' : 'patients.create.organisation');
    }

    /** @param array<string,mixed> $input */
    public function retry(User $actor, array $input, ?Patient $patient = null): ?Patient
    {
        $key = $input['adult_confirmation_key'] ?? null;
        if (! is_string($key) || ! Str::isUuid($key)) {
            return null;
        }
        $row = DB::table('patient_adult_confirmations')->where('organisation_id', $actor->organisation_id)->where('confirmed_by_user_id', $actor->id)->where('retry_key', $key)->first();
        if (! $row) {
            return null;
        }
        if (($patient && $row->patient_id !== $patient->id) || $row->source !== ($patient ? 'staff_edit' : 'staff_create') || ! hash_equals($row->payload_hash, $this->hash($input))) {
            throw ValidationException::withMessages(['adult_confirmation_key' => 'This confirmation key was used with different details. Reload before saving.']);
        }

        return Patient::query()->whereKey($row->patient_id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
    }

    /** @param array<string,mixed> $input */
    public function record(User $actor, Patient $patient, array $input, bool $update): void
    {
        $id = DB::table('patient_adult_confirmations')->insertGetId([
            'organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id,
            'branch_id' => (int) $input['expected_branch_id'], 'confirmed_by_user_id' => $actor->id,
            'confirmed_at' => now()->utc(), 'source' => $update ? 'staff_edit' : 'staff_create',
            'patient_lock_version' => $patient->lock_version, 'retry_key' => $input['adult_confirmation_key'],
            'payload_hash' => $this->hash($input),
        ]);
        abort_if(app(AuditRecorder::class)->record('patient.adult_confirmed', $patient, ['confirmation_id' => $id], $actor, organisationId: $patient->organisation_id) === null, 503);
    }

    /** @param array<string,mixed> $input */
    private function hash(array $input): string
    {
        return hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
