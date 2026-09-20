<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PublicIntakePayloadValidator;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VisitRegistrationService
{
    public function __construct(
        private BranchAccessService $branches,
        private PatientAdministrationService $patients,
        private VisitDoctorEligibilityService $doctors,
        private VisitNumberGenerator $numbers,
        private VisitReasonService $reasons,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function register(User $actor, array $attributes): Visit
    {
        Gate::forUser($actor)->authorize('create', Visit::class);
        $branch = $this->activeBranch($actor, $attributes);
        $validated = $this->validate($attributes);

        try {
            return DB::transaction(function () use ($actor, $branch, $validated): Visit {
                $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $lockedActor->load('roles.permissions');
                $this->lockActorBranchState($lockedActor);
                if (! $lockedActor->is_active
                    || ! $lockedActor->can('visits.create.branch')
                    || ! $this->branches->canSelect($lockedActor, $branch)) {
                    throw new AuthorizationException('You may not register a Visit.');
                }

                $existing = $this->idempotentVisit($lockedActor, $branch, $validated['idempotency_key']);
                if ($existing) {
                    return $existing;
                }

                $quickPatientCreated = ! filled($validated['patient_number'] ?? null);
                $patient = $this->resolvePatient($lockedActor, $validated);
                $lockedPatient = Patient::query()
                    ->whereKey($patient->id)
                    ->where('organisation_id', $lockedActor->organisation_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = $this->idempotentVisit($lockedActor, $branch, $validated['idempotency_key']);
                if ($existing) {
                    if ($quickPatientCreated) {
                        throw new IdempotentVisitResolved;
                    }

                    return $existing;
                }

                $now = now()->utc();
                [$dayStart, $dayEnd, $effectiveDate] = $this->branchDay($branch, $now);
                $repeatExists = Visit::query()
                    ->where('patient_id', $lockedPatient->id)
                    ->where('branch_id', $branch->id)
                    ->where('status', Visit::STATUS_REGISTERED)
                    ->whereBetween('registered_at', [$dayStart, $dayEnd])
                    ->lockForUpdate()
                    ->exists();
                if ($repeatExists && ! $validated['confirm_repeat']) {
                    throw ValidationException::withMessages([
                        'confirm_repeat' => 'This patient already has a registered Visit at this branch today. Review and confirm a legitimate repeat attendance.',
                    ]);
                }

                $doctor = null;
                if ($validated['assigned_doctor_user_id'] !== null) {
                    $doctor = $this->doctors->lockAndValidate($validated['assigned_doctor_user_id'], $branch, $effectiveDate);
                }

                $panel = $this->lockPanel($lockedActor, $validated);
                $reasons = $this->reasons->resolve(
                    $lockedActor,
                    $validated['visit_reason_public_ids'],
                    $validated['visit_type'] === 'consultation',
                );
                $visit = new Visit;
                $visit->forceFill([
                    'organisation_id' => $lockedActor->organisation_id,
                    'branch_id' => $branch->id,
                    'patient_id' => $lockedPatient->id,
                    'visit_number' => $this->numbers->next($lockedActor->organisation),
                    'idempotency_key' => $validated['idempotency_key'],
                    'visit_type' => $validated['visit_type'],
                    'status' => Visit::STATUS_REGISTERED,
                    'priority' => $validated['priority'],
                    'visit_reason' => $reasons->first()?->name,
                    'intake_purpose' => $validated['intake_purpose'],
                    'encrypted_presenting_information' => $validated['chief_complaint'] === null
                        ? null : [
                            'chief_complaint' => $validated['chief_complaint'],
                            'duration' => $validated['complaint_duration'],
                        ],
                    'assigned_doctor_user_id' => $doctor?->id,
                    'coverage_type' => $validated['coverage_type'],
                    'panel_id' => $panel?->id,
                    'coverage_panel_name_snapshot' => $panel?->name,
                    'coverage_member_reference' => $validated['coverage_type'] === 'panel'
                        ? $validated['coverage_member_reference'] : null,
                    'registered_at' => $now,
                    'registered_by_user_id' => $lockedActor->id,
                    'updated_by_user_id' => $lockedActor->id,
                    'lock_version' => 1,
                ])->save();
                $this->reasons->assign($visit, $reasons);

                $this->audit->record('visit.created', $visit, [
                    'visit_type' => $visit->visit_type,
                    'coverage_type' => $visit->coverage_type,
                    'repeat_attendance_confirmed' => $repeatExists,
                    'record_version' => 1,
                ], $lockedActor, $branch, $lockedActor->organisation_id);
                if ($visit->priority === 'urgent') {
                    $this->audit->record('visit.priority.marked_urgent', $visit, ['record_version' => 1], $lockedActor, $branch);
                }

                return $visit->refresh();
            }, 3);
        } catch (QueryException|ValidationException|IdempotentVisitResolved $exception) {
            $existing = Visit::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if ($existing) {
                return $this->assertIdempotentBranch($existing, $branch);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function activeBranch(User $actor, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $this->branches->canSelect($actor, $branch)) {
            throw ValidationException::withMessages(['branch' => 'Select an active authorised branch before registering.']);
        }
        if ((int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed after this form was opened. Review the branch and retry.',
            ]);
        }

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validate(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'idempotency_key' => ['required', 'uuid'],
            'expected_branch_id' => ['required', 'integer'],
            'patient_number' => [
                'nullable', 'string', 'max:20', 'required_without:quick_patient',
                Rule::prohibitedIf(fn (): bool => filled($attributes['quick_patient'] ?? null)),
            ],
            'quick_patient' => [
                'nullable', 'array', 'required_without:patient_number',
                Rule::prohibitedIf(fn (): bool => filled($attributes['patient_number'] ?? null)),
            ],
            'visit_type' => ['required', Rule::in(['consultation', 'otc'])],
            'assigned_doctor_user_id' => ['nullable', 'integer', 'required_if:visit_type,consultation'],
            'visit_reason' => ['prohibited'],
            'visit_reason_public_ids' => ['nullable', 'array', 'max:5', 'required_if:visit_type,consultation'],
            'visit_reason_public_ids.*' => ['required', 'uuid', 'distinct'],
            'priority' => ['required', Rule::in(['normal', 'urgent'])],
            'coverage_type' => ['required', Rule::in(['self_pay', 'panel'])],
            'panel_id' => ['nullable', 'integer', 'required_if:coverage_type,panel'],
            'coverage_member_reference' => ['nullable', 'string', 'max:100'],
            'confirm_repeat' => ['nullable', 'boolean'],
            'intake_purpose' => ['nullable', Rule::in(PublicIntakePayloadValidator::VISIT_PURPOSES)],
            'chief_complaint' => ['nullable', 'string', 'max:500'],
            'complaint_duration' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $validated['visit_reason_public_ids'] = array_values($validated['visit_reason_public_ids'] ?? []);
        $validated['coverage_member_reference'] = $this->nullableTrim($validated['coverage_member_reference'] ?? null);
        $validated['assigned_doctor_user_id'] = isset($validated['assigned_doctor_user_id'])
            ? (int) $validated['assigned_doctor_user_id'] : null;
        $validated['panel_id'] = isset($validated['panel_id']) ? (int) $validated['panel_id'] : null;
        $validated['confirm_repeat'] = (bool) ($validated['confirm_repeat'] ?? false);
        $validated['intake_purpose'] = $validated['intake_purpose'] ?? null;
        $validated['chief_complaint'] = $this->nullableTrim($validated['chief_complaint'] ?? null);
        $validated['complaint_duration'] = $this->nullableTrim($validated['complaint_duration'] ?? null);

        if ($validated['coverage_type'] === 'self_pay') {
            $validated['panel_id'] = null;
            $validated['coverage_member_reference'] = null;
        }

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function resolvePatient(User $actor, array $validated): Patient
    {
        if (filled($validated['patient_number'] ?? null)) {
            return Patient::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('patient_number', $validated['patient_number'])
                ->firstOrFail();
        }

        try {
            return $this->patients->create($actor, Arr::wrap($validated['quick_patient'] ?? []));
        } catch (ValidationException $exception) {
            $prefixed = [];
            foreach ($exception->errors() as $field => $messages) {
                $prefixed['quick_patient.'.$field] = $messages;
            }

            throw ValidationException::withMessages($prefixed);
        }
    }

    private function idempotentVisit(User $actor, Branch $branch, string $key): ?Visit
    {
        $visit = Visit::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        return $visit ? $this->assertIdempotentBranch($visit, $branch) : null;
    }

    private function assertIdempotentBranch(Visit $visit, Branch $branch): Visit
    {
        if ($visit->branch_id !== $branch->id) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This registration form is no longer valid. Start a new registration.',
            ]);
        }

        return $visit;
    }

    /** @param array<string, mixed> $validated */
    private function lockPanel(User $actor, array $validated): ?Panel
    {
        if ($validated['coverage_type'] !== 'panel') {
            return null;
        }

        $panel = Panel::query()
            ->whereKey($validated['panel_id'])
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if (! $panel) {
            throw ValidationException::withMessages(['panel_id' => 'Select an active Panel for this organisation.']);
        }

        return $panel;
    }

    /** @return array{0: mixed, 1: mixed, 2: string} */
    private function branchDay(Branch $branch, mixed $instant): array
    {
        $local = $instant->setTimezone($branch->timezone);

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc(), $local->toDateString()];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function lockActorBranchState(User $actor): void
    {
        $profile = StaffProfile::query()->where('user_id', $actor->id)->lockForUpdate()->first();
        if ($profile) {
            StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->lockForUpdate()
                ->get();
            $actor->unsetRelation('staffProfile');
        }
    }
}

final class IdempotentVisitResolved extends RuntimeException {}
