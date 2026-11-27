<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PatientEmergencyContactService
{
    /** @return array{values: array<string,string>|null, lock_version:int} */
    public function contact(User $actor, Patient $patient): array
    {
        Gate::forUser($actor)->authorize('view', $patient);
        abort_unless($actor->is_active && $actor->organisation_id === $patient->organisation_id, 403);
        $row = DB::table('patient_emergency_contacts')->where('organisation_id', $actor->organisation_id)->where('patient_id', $patient->id)->first();

        return ['values' => $row?->encrypted_contact === null ? null : json_decode(Crypt::decryptString($row->encrypted_contact), true, 512, JSON_THROW_ON_ERROR), 'lock_version' => (int) ($row->lock_version ?? 0)];
    }

    /** @return array<string,mixed> */
    public function validate(mixed $input): array
    {
        $data = Validator::make(['emergency_contact_command' => $input], [
            'emergency_contact_command' => ['required', 'array:operation,lock_version,idempotency_key,full_name,relationship,phone,phone_country'],
            'emergency_contact_command.operation' => ['required', Rule::in(['set', 'clear'])],
            'emergency_contact_command.lock_version' => ['required', 'integer', 'min:0'],
            'emergency_contact_command.idempotency_key' => ['required', 'uuid'],
            'emergency_contact_command.full_name' => ['required_if:emergency_contact_command.operation,set', 'prohibited_if:emergency_contact_command.operation,clear', 'string', 'max:200'],
            'emergency_contact_command.relationship' => ['required_if:emergency_contact_command.operation,set', 'prohibited_if:emergency_contact_command.operation,clear', 'string', 'max:80'],
            'emergency_contact_command.phone' => ['required_if:emergency_contact_command.operation,set', 'prohibited_if:emergency_contact_command.operation,clear', 'string', 'max:64'],
            'emergency_contact_command.phone_country' => ['sometimes', 'string', 'size:2'],
        ])->validate()['emergency_contact_command'];
        $data['lock_version'] = (int) $data['lock_version'];
        if ($data['operation'] === 'set') {
            foreach (['full_name', 'relationship'] as $field) {
                $data[$field] = trim($data[$field]);
                if ($data[$field] === '') {
                    throw ValidationException::withMessages(["emergency_contact_command.$field" => 'Complete all three emergency contact fields.']);
                }
            }
            try {
                $data['phone'] = app(PatientPhoneNormalizer::class)->normalize($data['phone'], $data['phone_country'] ?? 'MY', true);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['emergency_contact_command.phone' => 'Enter a valid emergency contact phone number.']);
            }
        }
        unset($data['phone_country']);
        ksort($data);

        return $data;
    }

    /** Standalone Patient details update, with actor -> patient -> contact lock order.
     * @param  array<string,mixed>  $input
     * @return array{values: array<string,string>|null, lock_version:int}
     */
    public function update(User $actor, Patient $patient, array $input): array
    {
        Gate::forUser($actor)->authorize('update', $patient);
        $data = $this->validate($input['emergency_contact_command'] ?? null);
        $authority = app(TransactionalActorAuthority::class);
        $branch = $authority->branch($actor, $input['expected_branch_id'] ?? null);

        return DB::transaction(function () use ($actor, $patient, $data, $authority, $branch): array {
            $lockedActor = $authority->lock($actor, $branch, 'patients.update.organisation');
            $lockedPatient = Patient::query()->whereKey($patient->id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            $this->saveLocked($lockedActor, $lockedPatient, $data);

            return $this->contact($lockedActor, $lockedPatient);
        }, 3);
    }

    /** Caller owns actor/patient locks in its outer transaction. No contact values enter audit/search/TV.
     * @param  array<string,mixed>  $data  validated command
     * @param  array<string,mixed>  $context
     */
    public function saveLocked(User $actor, Patient $patient, array $data, bool $creating = false, array $context = []): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        Gate::forUser($actor)->authorize($creating ? 'create' : 'update', $creating ? Patient::class : $patient);
        Gate::forUser($actor)->authorize('view', $patient);
        abort_unless($actor->is_active && $actor->organisation_id === $patient->organisation_id && ! $patient->isMergedAway(), 403);
        if ($this->command($actor, $data['idempotency_key'])) {
            $this->assertCommand($actor, $patient, $data, $context);

            return;
        }
        $row = DB::table('patient_emergency_contacts')->where('organisation_id', $actor->organisation_id)->where('patient_id', $patient->id)->lockForUpdate()->first();
        if ($data['lock_version'] !== (int) ($row->lock_version ?? 0)) {
            throw ValidationException::withMessages(['emergency_contact_command.lock_version' => 'The emergency contact changed. Reload before saving.']);
        }
        $values = ['encrypted_contact' => $data['operation'] === 'clear' ? null : Crypt::encryptString(json_encode(array_intersect_key($data, array_flip(['full_name', 'relationship', 'phone'])), JSON_THROW_ON_ERROR)), 'lock_version' => (int) ($row->lock_version ?? 0) + 1, 'updated_by_user_id' => $actor->id, 'updated_at' => now()->utc()];
        if ($row) {
            DB::table('patient_emergency_contacts')->where('id', $row->id)->update($values);
        } else {
            DB::table('patient_emergency_contacts')->insert([...$values, 'organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id, 'created_at' => now()->utc()]);
        }
        abort_if(app(AuditRecorder::class)->record('patient.emergency_contact.updated', $patient, ['operation' => $data['operation'], 'record_version' => $values['lock_version'], 'changed_fields' => ['full_name', 'relationship', 'phone']], $actor, organisationId: $patient->organisation_id) === null, 503);
        DB::table('patient_emergency_contact_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'patient_id' => $patient->id, 'retry_key' => $data['idempotency_key'], 'payload_hash' => $this->hash($patient, $data, $context), 'created_at' => now()->utc()]);
    }

    /** Every registration replay path calls this; a retry cannot add, omit or alter its contact command.
     * @param  array<string,mixed>  $attributes
     */
    public function assertRegistrationReplay(User $actor, Visit $visit, array $attributes): void
    {
        $data = $attributes['emergency_contact_command'] ?? null;
        $recorded = $this->command($actor, $visit->idempotency_key);
        if ($data === null && $recorded === null) {
            return;
        }
        if ($data === null || $recorded === null || $visit->registered_by_user_id !== $actor->id) {
            throw ValidationException::withMessages(['emergency_contact_command' => 'This registration retry has different emergency contact details.']);
        }
        $fresh = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($fresh && $fresh->is_active, 403);
        $patient = $visit->patient;
        Gate::forUser($fresh)->authorize(filled($attributes['patient_number'] ?? null) ? 'update' : 'create', filled($attributes['patient_number'] ?? null) ? $patient : Patient::class);
        Gate::forUser($fresh)->authorize('view', $patient);
        $this->assertCommand($fresh, $patient, $data, $this->registrationContext($attributes));
    }

    /** Bind registration keys to both the selected patient and creation mode; never persist this payload.
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function registrationContext(array $attributes): array
    {
        return filled($attributes['patient_number'] ?? null)
            ? ['registration_patient' => $attributes['patient_number']]
            : ['registration_quick_patient' => $attributes['quick_patient'] ?? null];
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    private function canonical(array $values): array
    {
        ksort($values);
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $values;
    }

    private function command(User $actor, string $key): ?\stdClass
    {
        return DB::table('patient_emergency_contact_commands')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $key)->first();
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $context
     */
    private function assertCommand(User $actor, Patient $patient, array $data, array $context = []): void
    {
        $row = $this->command($actor, $data['idempotency_key']);
        if (! $row || (int) $row->patient_id !== $patient->id || ! hash_equals($row->payload_hash, $this->hash($patient, $data, $context))) {
            throw ValidationException::withMessages(['emergency_contact_command' => 'This retry key was used for different emergency contact details.']);
        }
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $context
     */
    private function hash(Patient $patient, array $data, array $context = []): string
    {
        ksort($data);

        return hash_hmac('sha256', json_encode(['patient_id' => $patient->id, 'command' => $data, 'context' => $this->canonical($context)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
