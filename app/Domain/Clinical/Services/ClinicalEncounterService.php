<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClinicalEncounterService
{
    private const VITAL_FIELDS = [
        'systolic_bp',
        'diastolic_bp',
        'pulse_bpm',
        'temperature_celsius',
        'spo2_percent',
        'weight_kg',
        'height_cm',
    ];

    public function __construct(
        private BranchAccessService $branches,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function start(User $actor, Visit $visit, array $attributes): ClinicalEncounter
    {
        Gate::forUser($actor)->authorize('start', [ClinicalEncounter::class, $visit]);
        $branch = $this->activeBranch($actor, $visit, $attributes);
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        try {
            return DB::transaction(function () use ($actor, $visit, $branch, $validated): ClinicalEncounter {
                $lockedActor = $this->lockClinician($actor, $branch, 'encounters.start.own');
                $lockedVisit = $this->lockVisit($visit, $lockedActor, $branch);
                $lockedQueue = QueueEntry::query()
                    ->where('visit_id', $lockedVisit->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertServingConsultation(
                    $lockedActor,
                    $lockedVisit,
                    $lockedQueue,
                    (int) $validated['visit_lock_version'],
                    (int) $validated['queue_lock_version'],
                );

                $existing = ClinicalEncounter::query()
                    ->where('visit_id', $lockedVisit->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertEncounterOwner($existing, $lockedActor);

                    return $existing;
                }

                $encounter = new ClinicalEncounter;
                $encounter->forceFill([
                    'organisation_id' => $lockedVisit->organisation_id,
                    'branch_id' => $lockedVisit->branch_id,
                    'visit_id' => $lockedVisit->id,
                    'attending_clinician_user_id' => $lockedActor->id,
                    'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
                    'clinical_note' => null,
                    'started_at' => now()->utc(),
                    'updated_by_user_id' => $lockedActor->id,
                    'lock_version' => 1,
                ])->save();

                $this->audit->record('encounter.started', $encounter, [
                    'to_state' => ClinicalEncounter::STATUS_IN_PROGRESS,
                    'record_version' => 1,
                ], $lockedActor, $branch, $lockedActor->organisation_id);

                return $encounter->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $existing = ClinicalEncounter::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('visit_id', $visit->id)
                ->first();
            if ($existing) {
                Gate::forUser($actor)->authorize('view', $existing);

                return $existing;
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Visit $visit, array $attributes): ClinicalEncounter
    {
        $encounter = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('visit_id', $visit->id)
            ->where('attending_clinician_user_id', $actor->id)
            ->firstOrFail();
        Gate::forUser($actor)->authorize('update', $encounter);
        $branch = $this->activeBranch($actor, $visit, $attributes);
        $validated = $this->validateAggregate($attributes);

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): ClinicalEncounter {
            $lockedActor = $this->lockClinician($actor, $branch, 'encounters.update.own');
            $lockedVisit = $this->lockVisit($visit, $lockedActor, $branch);
            $lockedQueue = QueueEntry::query()
                ->where('visit_id', $lockedVisit->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedEncounter = ClinicalEncounter::query()
                ->where('visit_id', $lockedVisit->id)
                ->lockForUpdate()
                ->firstOrFail();
            $vitals = EncounterVitalObservation::query()
                ->where('clinical_encounter_id', $lockedEncounter->id)
                ->lockForUpdate()
                ->first();
            $diagnoses = EncounterDiagnosis::query()
                ->where('clinical_encounter_id', $lockedEncounter->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertServingConsultation($lockedActor, $lockedVisit, $lockedQueue);
            $this->assertEncounterOwner($lockedEncounter, $lockedActor);
            if ($lockedEncounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'encounter' => 'This clinical Encounter is no longer in progress.',
                ]);
            }
            if ($lockedEncounter->lock_version !== (int) $validated['lock_version']) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This clinical record changed after it was opened. Review the latest record before saving again.',
                ]);
            }

            $changedSections = $this->changedSections($lockedEncounter, $vitals, $diagnoses, $validated);
            if ($changedSections === []) {
                return $lockedEncounter;
            }

            if (in_array('clinical_note', $changedSections, true)) {
                $lockedEncounter->clinical_note = $validated['clinical_note'];
            }
            if (in_array('vitals', $changedSections, true)) {
                if ($this->hasVitalMeasurement($validated['vitals'])) {
                    $vitals ??= new EncounterVitalObservation;
                    $vitals->forceFill([
                        'organisation_id' => $lockedEncounter->organisation_id,
                        'branch_id' => $lockedEncounter->branch_id,
                        'clinical_encounter_id' => $lockedEncounter->id,
                        ...$validated['vitals'],
                        'observed_at' => $vitals->observed_at ?? now()->utc(),
                        'recorded_by_user_id' => $vitals->recorded_by_user_id ?? $lockedActor->id,
                        'updated_by_user_id' => $lockedActor->id,
                    ])->save();
                } elseif ($vitals) {
                    $vitals->delete();
                }
            }
            if (in_array('diagnoses', $changedSections, true)) {
                EncounterDiagnosis::query()
                    ->where('clinical_encounter_id', $lockedEncounter->id)
                    ->delete();
                foreach ($validated['diagnoses'] as $index => $diagnosis) {
                    $row = new EncounterDiagnosis;
                    $row->forceFill([
                        'organisation_id' => $lockedEncounter->organisation_id,
                        'branch_id' => $lockedEncounter->branch_id,
                        'clinical_encounter_id' => $lockedEncounter->id,
                        ...$diagnosis,
                        'position' => $index + 1,
                        'recorded_by_user_id' => $lockedActor->id,
                        'updated_by_user_id' => $lockedActor->id,
                    ])->save();
                }
            }

            $lockedEncounter->forceFill([
                'updated_by_user_id' => $lockedActor->id,
                'lock_version' => $lockedEncounter->lock_version + 1,
            ])->save();
            $this->audit->record('encounter.updated', $lockedEncounter, [
                'record_version' => $lockedEncounter->lock_version,
                'changed_sections' => $changedSections,
            ], $lockedActor, $branch, $lockedActor->organisation_id);

            return $lockedEncounter->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateAggregate(array $attributes): array
    {
        $validator = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'clinical_note' => ['nullable', 'string', 'max:20000'],
            'vitals' => ['required', 'array'],
            'vitals.systolic_bp' => ['nullable', 'integer', 'min:1', 'required_with:vitals.diastolic_bp'],
            'vitals.diastolic_bp' => ['nullable', 'integer', 'min:1', 'required_with:vitals.systolic_bp'],
            'vitals.pulse_bpm' => ['nullable', 'integer', 'min:1'],
            'vitals.temperature_celsius' => ['nullable', 'numeric', 'gt:0'],
            'vitals.spo2_percent' => ['nullable', 'numeric', 'between:0,100'],
            'vitals.weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'vitals.height_cm' => ['nullable', 'numeric', 'gt:0'],
            'diagnoses' => ['present', 'array', 'max:20'],
            'diagnoses.*.diagnosis_text' => ['required', 'string', 'max:500'],
            'diagnoses.*.diagnosis_code' => ['nullable', 'string', 'max:50', 'required_with:diagnoses.*.code_system'],
            'diagnoses.*.code_system' => ['nullable', 'string', 'max:50', 'required_with:diagnoses.*.diagnosis_code'],
            'diagnoses.*.is_primary' => ['required', 'boolean'],
        ], [
            'vitals.systolic_bp.required_with' => 'Enter both systolic and diastolic blood pressure, or leave both blank.',
            'vitals.diastolic_bp.required_with' => 'Enter both systolic and diastolic blood pressure, or leave both blank.',
            'diagnoses.*.diagnosis_code.required_with' => 'Enter both the diagnosis code and code system, or leave both blank.',
            'diagnoses.*.code_system.required_with' => 'Enter both the diagnosis code and code system, or leave both blank.',
        ]);
        $validator->after(function ($validator) use ($attributes): void {
            $primary = 0;
            $diagnoses = $attributes['diagnoses'] ?? [];
            if (is_array($diagnoses)) {
                foreach ($diagnoses as $diagnosis) {
                    if (is_array($diagnosis)
                        && filter_var($diagnosis['is_primary'] ?? false, FILTER_VALIDATE_BOOL)) {
                        $primary++;
                    }
                }
            }
            if ($primary > 1) {
                $validator->errors()->add('diagnoses', 'Select no more than one primary diagnosis.');
            }
        });
        $validated = $validator->validate();
        $validated['clinical_note'] = $this->nullableTrim($validated['clinical_note'] ?? null);
        foreach (self::VITAL_FIELDS as $field) {
            $validated['vitals'][$field] = $validated['vitals'][$field] ?? null;
        }
        $normalizedDiagnoses = [];
        $diagnoses = $validated['diagnoses'];
        if (is_array($diagnoses)) {
            foreach ($diagnoses as $diagnosis) {
                if (! is_array($diagnosis)) {
                    continue;
                }
                $normalizedDiagnoses[] = [
                    'diagnosis_text' => trim($diagnosis['diagnosis_text']),
                    'diagnosis_code' => $this->nullableTrim($diagnosis['diagnosis_code'] ?? null),
                    'code_system' => $this->nullableTrim($diagnosis['code_system'] ?? null),
                    'is_primary' => (bool) $diagnosis['is_primary'],
                ];
            }
        }
        $validated['diagnoses'] = $normalizedDiagnoses;

        return $validated;
    }

    /** @param array<string, mixed> $attributes */
    private function activeBranch(User $actor, Visit $visit, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $visit->branch_id
            || (int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed or does not own this consultation. Review and retry.',
            ]);
        }

        return $branch;
    }

    private function lockClinician(User $actor, Branch $branch, string $permission): User
    {
        $locked = User::query()
            ->whereKey($actor->id)
            ->where('organisation_id', $branch->organisation_id)
            ->lockForUpdate()
            ->firstOrFail();
        $locked->load(['roles.permissions', 'permissions']);
        $profile = StaffProfile::query()
            ->where('user_id', $locked->id)
            ->lockForUpdate()
            ->first();
        $assignments = $profile
            ? StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->lockForUpdate()
                ->get()
            : collect();
        $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();
        $assigned = $assignments->contains(fn (StaffBranchAssignment $assignment): bool => $assignment->branch_id === $branch->id
            && $assignment->valid_from->toDateString() <= $effectiveDate
            && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $effectiveDate));

        if (! $locked->is_active || ! $profile || ! $locked->hasRole('resident_doctor')
            || ! $locked->can($permission) || ! $assigned) {
            throw new AuthorizationException('You may not access this clinical Encounter.');
        }

        return $locked;
    }

    private function lockVisit(Visit $visit, User $actor, Branch $branch): Visit
    {
        return Visit::query()
            ->whereKey($visit->id)
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertServingConsultation(
        User $actor,
        Visit $visit,
        QueueEntry $queue,
        ?int $visitVersion = null,
        ?int $queueVersion = null,
    ): void {
        if (($visitVersion !== null && $visit->lock_version !== $visitVersion)
            || ($queueVersion !== null && $queue->lock_version !== $queueVersion)) {
            throw ValidationException::withMessages([
                'lock_version' => 'The Visit or Queue changed after it was opened. Review the latest state.',
            ]);
        }
        if ($visit->status !== Visit::STATUS_REGISTERED || $visit->visit_type !== 'consultation'
            || $queue->status !== QueueEntry::STATUS_SERVING) {
            throw ValidationException::withMessages([
                'encounter' => 'Only a registered Consultation currently Serving can use a clinical Encounter.',
            ]);
        }
        if ($visit->assigned_doctor_user_id !== $actor->id) {
            throw new AuthorizationException('Only the assigned doctor may access this clinical Encounter.');
        }
    }

    private function assertEncounterOwner(ClinicalEncounter $encounter, User $actor): void
    {
        if ($encounter->attending_clinician_user_id !== $actor->id
            || $encounter->organisation_id !== $actor->organisation_id) {
            throw new AuthorizationException('Only the attending clinician may access this clinical Encounter.');
        }
    }

    /** @param Collection<int, EncounterDiagnosis> $diagnoses
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function changedSections(
        ClinicalEncounter $encounter,
        ?EncounterVitalObservation $vitals,
        Collection $diagnoses,
        array $validated,
    ): array {
        $changed = [];
        if ($encounter->clinical_note !== $validated['clinical_note']) {
            $changed[] = 'clinical_note';
        }
        if (collect(self::VITAL_FIELDS)->contains(
            fn (string $field): bool => $this->comparable($vitals?->getAttribute($field)) !== $this->comparable($validated['vitals'][$field]),
        )) {
            $changed[] = 'vitals';
        }
        $beforeDiagnoses = $diagnoses->map(fn (EncounterDiagnosis $diagnosis): array => [
            'diagnosis_text' => $diagnosis->diagnosis_text,
            'diagnosis_code' => $diagnosis->diagnosis_code,
            'code_system' => $diagnosis->code_system,
            'is_primary' => $diagnosis->is_primary,
        ])->values()->all();
        if ($beforeDiagnoses !== $validated['diagnoses']) {
            $changed[] = 'diagnoses';
        }

        return $changed;
    }

    /** @param array<string, mixed> $vitals */
    private function hasVitalMeasurement(array $vitals): bool
    {
        return collect(self::VITAL_FIELDS)
            ->contains(fn (string $field): bool => ($vitals[$field] ?? null) !== null);
    }

    private function comparable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) (float) $value;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
