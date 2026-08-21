<?php

namespace App\Domain\Patient\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatientAdministrationService
{
    private const DEMOGRAPHIC_FIELDS = ['full_name', 'date_of_birth', 'sex', 'nationality_code'];

    private const CONTACT_FIELDS = ['mobile_phone', 'email'];

    private const ADDRESS_FIELDS = ['address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'country_code'];

    public function __construct(
        private PatientIdentityService $identity,
        private PatientNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Patient
    {
        Gate::forUser($actor)->authorize('create', Patient::class);
        $normalized = $this->validatePatient($this->identity->normalizePatient($attributes));
        $identifiers = $this->normalizeIdentifierList($attributes['identifiers'] ?? []);

        if ($this->identity->possibleDuplicates($actor->organisation_id, $normalized)->isNotEmpty()
            && ! ($attributes['duplicate_override'] ?? false)) {
            throw ValidationException::withMessages([
                'duplicate_override' => 'Possible matching patient records were found. Review them before confirming a separate patient.',
            ]);
        }

        try {
            return DB::transaction(function () use ($actor, $normalized, $identifiers, $attributes): Patient {
                $patient = new Patient;
                $patient->forceFill([
                    'organisation_id' => $actor->organisation_id,
                    'patient_number' => $this->numbers->next($actor->organisation),
                    ...Arr::only($normalized, [...self::DEMOGRAPHIC_FIELDS, ...self::CONTACT_FIELDS, ...self::ADDRESS_FIELDS, 'search_name']),
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                    'lock_version' => 1,
                ])->save();

                foreach ($identifiers as $identifier) {
                    $this->insertIdentifier($patient, $identifier, $actor);
                    $this->audit->record('patient.identifier.added', $patient, [
                        'identifier_type' => $identifier['identifier_type'],
                        'issuing_country_code' => $identifier['issuing_country_code'],
                        'record_version' => 1,
                    ], $actor, organisationId: $patient->organisation_id);
                }

                $this->audit->record('patient.created', $patient, [
                    'record_version' => 1,
                    'identifier_types' => array_values(array_unique(array_column($identifiers, 'identifier_type'))),
                    'soft_duplicate_override' => (bool) ($attributes['duplicate_override'] ?? false),
                ], $actor, organisationId: $patient->organisation_id);

                return $patient->refresh()->load('identifiers');
            });
        } catch (QueryException $exception) {
            $this->throwControlledConflict($exception);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(Patient $patient, array $attributes, User $actor): Patient
    {
        Gate::forUser($actor)->authorize('update', $patient);
        $normalized = $this->validatePatient($this->identity->normalizePatient($attributes));
        $expectedVersion = (int) ($attributes['lock_version'] ?? 0);

        return DB::transaction(function () use ($patient, $actor, $normalized, $expectedVersion): Patient {
            $locked = Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $this->assertSameOrganisation($actor, $locked);

            if ($expectedVersion < 1 || $locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This patient record changed after it was opened. Reload and review the latest details.',
                ]);
            }

            $before = $locked->only([...self::DEMOGRAPHIC_FIELDS, ...self::CONTACT_FIELDS, ...self::ADDRESS_FIELDS]);
            $locked->forceFill([
                ...Arr::only($normalized, [...self::DEMOGRAPHIC_FIELDS, ...self::CONTACT_FIELDS, ...self::ADDRESS_FIELDS, 'search_name']),
                'updated_by_user_id' => $actor->id,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $after = $locked->only([...self::DEMOGRAPHIC_FIELDS, ...self::CONTACT_FIELDS, ...self::ADDRESS_FIELDS]);

            $this->auditChangedGroup($locked, $actor, 'patient.demographics.updated', self::DEMOGRAPHIC_FIELDS, $before, $after);
            $this->auditChangedGroup($locked, $actor, 'patient.contact.updated', self::CONTACT_FIELDS, $before, $after);
            $this->auditChangedGroup($locked, $actor, 'patient.address.updated', self::ADDRESS_FIELDS, $before, $after);

            return $locked->refresh()->load('identifiers');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function addIdentifier(Patient $patient, array $attributes, User $actor): PatientIdentifier
    {
        Gate::forUser($actor)->authorize('update', $patient);
        $identifier = $this->identity->normalizeIdentifier($attributes);

        try {
            return DB::transaction(function () use ($patient, $actor, $identifier): PatientIdentifier {
                $locked = Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
                $this->assertSameOrganisation($actor, $locked);
                $hasTypeHistory = $locked->identifiers()->where('identifier_type', $identifier['identifier_type'])->exists();

                if ($hasTypeHistory && ! $actor->can('patients.identifiers.manage.organisation')) {
                    throw new AuthorizationException('Identifier correction requires additional authority.');
                }

                $created = $this->insertIdentifier($locked, $identifier, $actor);
                $this->audit->record('patient.identifier.added', $locked, [
                    'identifier_type' => $identifier['identifier_type'],
                    'issuing_country_code' => $identifier['issuing_country_code'],
                    'record_version' => $locked->lock_version,
                ], $actor, organisationId: $locked->organisation_id);

                return $created;
            });
        } catch (QueryException $exception) {
            $this->throwControlledConflict($exception);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function replaceIdentifier(Patient $patient, PatientIdentifier $current, array $attributes, User $actor): PatientIdentifier
    {
        Gate::forUser($actor)->authorize('manageIdentifiers', $patient);
        $replacement = $this->identity->normalizeIdentifier($attributes);

        try {
            return DB::transaction(function () use ($patient, $current, $replacement, $actor): PatientIdentifier {
                $lockedPatient = Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
                $lockedIdentifier = PatientIdentifier::query()
                    ->whereKey($current->id)
                    ->where('patient_id', $lockedPatient->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertSameOrganisation($actor, $lockedPatient);

                if ($lockedIdentifier->retired_at !== null) {
                    throw ValidationException::withMessages(['identifier' => 'Only a current identifier can be replaced.']);
                }

                if ($replacement['identifier_type'] !== $lockedIdentifier->identifier_type) {
                    throw ValidationException::withMessages(['identifier_type' => 'A correction must retain the identifier type.']);
                }

                $lockedIdentifier->forceFill(['retired_at' => now(), 'updated_by_user_id' => $actor->id])->save();
                $this->audit->record('patient.identifier.retired', $lockedPatient, [
                    'identifier_type' => $lockedIdentifier->identifier_type,
                    'issuing_country_code' => $lockedIdentifier->issuing_country_code,
                    'record_version' => $lockedPatient->lock_version,
                ], $actor, organisationId: $lockedPatient->organisation_id);

                $created = $this->insertIdentifier($lockedPatient, $replacement, $actor);
                $this->audit->record('patient.identifier.added', $lockedPatient, [
                    'identifier_type' => $created->identifier_type,
                    'issuing_country_code' => $created->issuing_country_code,
                    'record_version' => $lockedPatient->lock_version,
                ], $actor, organisationId: $lockedPatient->organisation_id);

                return $created;
            });
        } catch (QueryException $exception) {
            $this->throwControlledConflict($exception);
        }
    }

    public function retireIdentifier(Patient $patient, PatientIdentifier $identifier, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageIdentifiers', $patient);

        DB::transaction(function () use ($patient, $identifier, $actor): void {
            $lockedPatient = Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $lockedIdentifier = PatientIdentifier::query()->whereKey($identifier->id)
                ->where('patient_id', $lockedPatient->id)->lockForUpdate()->firstOrFail();
            $this->assertSameOrganisation($actor, $lockedPatient);

            if ($lockedIdentifier->retired_at === null) {
                $lockedIdentifier->forceFill(['retired_at' => now(), 'updated_by_user_id' => $actor->id])->save();
                $this->audit->record('patient.identifier.retired', $lockedPatient, [
                    'identifier_type' => $lockedIdentifier->identifier_type,
                    'issuing_country_code' => $lockedIdentifier->issuing_country_code,
                    'record_version' => $lockedPatient->lock_version,
                ], $actor, organisationId: $lockedPatient->organisation_id);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatePatient(array $attributes): array
    {
        return Validator::make($attributes, [
            'full_name' => ['required', 'string', 'max:255'],
            'search_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['female', 'male', 'indeterminate', 'unknown'])],
            'nationality_code' => ['nullable', 'string', 'size:2'],
            'mobile_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ])->validate();
    }

    /** @return list<array{identifier_type: string, issuing_country_code: string, normalized_value: string}> */
    private function normalizeIdentifierList(mixed $identifiers): array
    {
        if (! is_array($identifiers)) {
            return [];
        }

        $normalized = [];
        foreach ($identifiers as $identifier) {
            if (is_array($identifier) && filled($identifier['value'] ?? null)) {
                $normalized[] = $this->identity->normalizeIdentifier($identifier);
            }
        }

        if (count(array_filter($normalized, fn (array $item) => $item['identifier_type'] === 'nric')) > 1) {
            throw ValidationException::withMessages(['identifiers' => 'Only one current NRIC may be supplied.']);
        }

        return $normalized;
    }

    /** @param array{identifier_type: string, issuing_country_code: string, normalized_value: string} $identifier */
    private function insertIdentifier(Patient $patient, array $identifier, User $actor): PatientIdentifier
    {
        $model = new PatientIdentifier;
        $model->forceFill([
            'organisation_id' => $patient->organisation_id,
            'patient_id' => $patient->id,
            ...$identifier,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ])->save();

        return $model;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function auditChangedGroup(Patient $patient, User $actor, string $event, array $fields, array $before, array $after): void
    {
        $changed = array_values(array_filter($fields, fn (string $field) => ($before[$field] ?? null) !== ($after[$field] ?? null)));
        if ($changed !== []) {
            $this->audit->record($event, $patient, [
                'changed_fields' => $changed,
                'record_version' => $patient->lock_version,
            ], $actor, organisationId: $patient->organisation_id);
        }
    }

    private function assertSameOrganisation(User $actor, Patient $patient): void
    {
        if ($actor->organisation_id !== $patient->organisation_id) {
            throw new AuthorizationException('The patient is outside your organisation.');
        }
    }

    private function throwControlledConflict(QueryException $exception): never
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        if ($sqlState === '23505' || ($sqlState === '23000' && DB::connection()->getDriverName() === 'sqlite')) {
            throw ValidationException::withMessages([
                'identifiers' => 'This patient identifier is already reserved by an existing patient record.',
            ]);
        }

        throw $exception;
    }
}
