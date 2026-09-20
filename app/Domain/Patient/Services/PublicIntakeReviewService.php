<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicIntakeReviewService
{
    public function __construct(
        private BranchAccessService $branches,
        private PublicIntakePayloadValidator $payloads,
        private PublicPatientIntakeService $publicIntakes,
        private PatientIdentityService $identity,
        private PatientAdministrationService $patients,
        private VisitRegistrationService $visits,
        private QueueEntryService $queue,
        private AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function listing(User $actor): array
    {
        $branch = $this->authorizedBranch($actor);
        $items = PublicPatientIntake::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereIn('status', [
                PublicPatientIntake::STATUS_PENDING,
                PublicPatientIntake::STATUS_UNDER_REVIEW,
                PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
            ])
            ->latest('submitted_at')
            ->limit(100)
            ->get();

        return [
            'branch' => $branch->only(['id', 'code', 'name']),
            'items' => $items->map(fn (PublicPatientIntake $intake): array => [
                'publicId' => $intake->public_id,
                'status' => $intake->status,
                'submissionType' => $intake->submission_type,
                'summary' => $this->summary($actor, $intake),
                'submittedAt' => $intake->submitted_at->toIso8601String(),
                'expiresAt' => $intake->expires_at->toIso8601String(),
                'lockVersion' => $intake->lock_version,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, string $publicId): array
    {
        $intake = $this->scoped($actor, $publicId);
        abort_if($intake->encrypted_payload === null, 410);
        $payload = Arr::wrap($intake->encrypted_payload);

        return [
            'publicId' => $intake->public_id,
            'status' => $intake->status,
            'submissionType' => $intake->submission_type,
            'privacyNoticeVersion' => $intake->privacy_notice_version,
            'consentedAt' => $intake->consented_at->toIso8601String(),
            'submittedAt' => $intake->submitted_at->toIso8601String(),
            'lockVersion' => $intake->lock_version,
            'fields' => $this->payloads->editableFields($payload),
            'duplicateCandidates' => $this->duplicateCandidates($actor, $payload),
        ];
    }

    public function startReview(User $actor, string $publicId, int $expectedVersion): PublicPatientIntake
    {
        return $this->transition($actor, $publicId, $expectedVersion, function (PublicPatientIntake $intake) use ($actor): void {
            if (! in_array($intake->status, [PublicPatientIntake::STATUS_PENDING, PublicPatientIntake::STATUS_CORRECTION_REQUIRED], true)) {
                $this->invalidState();
            }
            $from = $intake->status;
            $intake->forceFill([
                'status' => PublicPatientIntake::STATUS_UNDER_REVIEW,
                'review_started_at' => now()->utc(),
                'reviewing_user_id' => $actor->id,
                'lock_version' => $intake->lock_version + 1,
            ])->save();
            $this->record('public_intake.review_started', $intake, $actor, $from);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function correct(User $actor, string $publicId, array $attributes): PublicPatientIntake
    {
        $expectedVersion = (int) ($attributes['lock_version'] ?? 0);
        $current = $this->scoped($actor, $publicId);
        abort_if($current->encrypted_payload === null, 410);
        $currentPayload = $current->encrypted_payload;
        $attributes['submission_type'] = $currentPayload['submission_type'];
        $attributes['guardian_attestation'] = $currentPayload['guardian']['attested'] ?? false;
        $attributes['consent_confirmed'] = true;
        $attributes['privacy_notice_version'] = $currentPayload['consent']['privacy_notice_version'];
        $payload = $this->payloads->validate($attributes);

        return $this->transition($actor, $publicId, $expectedVersion, function (PublicPatientIntake $intake) use ($actor, $payload): void {
            if (! in_array($intake->status, [
                PublicPatientIntake::STATUS_PENDING,
                PublicPatientIntake::STATUS_UNDER_REVIEW,
                PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
            ], true)) {
                $this->invalidState();
            }
            $from = $intake->status;
            $intake->forceFill([
                'status' => PublicPatientIntake::STATUS_UNDER_REVIEW,
                'encrypted_payload' => $payload,
                'payload_fingerprint' => $this->publicIntakes->fingerprint($payload),
                'review_started_at' => $intake->review_started_at ?? now()->utc(),
                'reviewing_user_id' => $actor->id,
                'lock_version' => $intake->lock_version + 1,
            ])->save();
            $this->record('public_intake.corrected', $intake, $actor, $from, ['changed_groups' => ['patient', 'guardian', 'visit']]);
        });
    }

    public function requireCorrection(User $actor, string $publicId, int $expectedVersion): PublicPatientIntake
    {
        return $this->transition($actor, $publicId, $expectedVersion, function (PublicPatientIntake $intake) use ($actor): void {
            if (! in_array($intake->status, [PublicPatientIntake::STATUS_PENDING, PublicPatientIntake::STATUS_UNDER_REVIEW], true)) {
                $this->invalidState();
            }
            $from = $intake->status;
            $intake->forceFill([
                'status' => PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
                'correction_required_at' => now()->utc(),
                'reviewing_user_id' => $actor->id,
                'lock_version' => $intake->lock_version + 1,
            ])->save();
            $this->record('public_intake.correction_required', $intake, $actor, $from);
        });
    }

    public function reject(User $actor, string $publicId, int $expectedVersion, string $category): PublicPatientIntake
    {
        Validator::make(['category' => $category], [
            'category' => ['required', Rule::in(PublicPatientIntake::REJECTION_CATEGORIES)],
        ])->validate();

        return $this->transition($actor, $publicId, $expectedVersion, function (PublicPatientIntake $intake) use ($actor, $category): void {
            if (! in_array($intake->status, [
                PublicPatientIntake::STATUS_PENDING,
                PublicPatientIntake::STATUS_UNDER_REVIEW,
                PublicPatientIntake::STATUS_CORRECTION_REQUIRED,
            ], true)) {
                $this->invalidState();
            }
            $from = $intake->status;
            $intake->forceFill([
                'status' => PublicPatientIntake::STATUS_REJECTED,
                'rejected_at' => now()->utc(),
                'rejected_by_user_id' => $actor->id,
                'rejection_category' => $category,
                'payload_purge_at' => $intake->submitted_at->addDays((int) config('public-intake.retention_days', 30)),
                'lock_version' => $intake->lock_version + 1,
            ])->save();
            $this->record('public_intake.rejected', $intake, $actor, $from, ['reason_category' => $category]);
        });
    }

    /** @param array<string, mixed> $attributes
     * @return array{intake: PublicPatientIntake, patient: Patient, visit: Visit, queue: QueueEntry}
     */
    public function accept(User $actor, string $publicId, array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'resolution' => ['required', Rule::in(['match', 'create'])],
            'patient_number' => ['nullable', 'required_if:resolution,match', 'string', 'max:20'],
            'duplicate_override' => ['nullable', 'boolean'],
            'assigned_doctor_user_id' => ['required', 'integer'],
            'visit_reason_public_ids' => ['required', 'array', 'min:1', 'max:5'],
            'visit_reason_public_ids.*' => ['required', 'uuid', 'distinct'],
            'priority' => ['required', Rule::in(['normal', 'urgent'])],
            'coverage_type' => ['required', Rule::in(['self_pay', 'panel'])],
            'panel_id' => ['nullable', 'required_if:coverage_type,panel', 'integer'],
            'coverage_member_reference' => ['nullable', 'string', 'max:100'],
            'confirm_repeat' => ['nullable', 'boolean'],
        ])->validate();
        $branch = $this->authorizedBranch($actor);
        $fingerprint = $this->publicIntakes->fingerprint(Arr::except($validated, ['lock_version']));

        return DB::transaction(function () use ($actor, $publicId, $validated, $branch, $fingerprint): array {
            $intake = PublicPatientIntake::query()
                ->where('public_id', $publicId)
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($intake->status === PublicPatientIntake::STATUS_ACCEPTED) {
                if (! hash_equals((string) $intake->acceptance_idempotency_key, $validated['idempotency_key'])
                    || ! hash_equals((string) $intake->acceptance_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Penerimaan ini telah selesai dengan maklumat lain. Muat semula rekod.',
                    ]);
                }

                return $this->acceptedResult($intake);
            }
            if (! in_array($intake->status, [PublicPatientIntake::STATUS_PENDING, PublicPatientIntake::STATUS_UNDER_REVIEW], true)) {
                $this->invalidState();
            }
            if ($intake->lock_version !== (int) $validated['lock_version']) {
                $this->stale();
            }
            if ($intake->encrypted_payload === null || ! $intake->expires_at->isFuture()) {
                throw ValidationException::withMessages(['intake' => 'Maklumat ini telah tamat dan tidak boleh diterima.']);
            }

            if ($intake->status === PublicPatientIntake::STATUS_PENDING) {
                $from = $intake->status;
                $intake->forceFill([
                    'status' => PublicPatientIntake::STATUS_UNDER_REVIEW,
                    'review_started_at' => now()->utc(),
                    'reviewing_user_id' => $actor->id,
                    'lock_version' => $intake->lock_version + 1,
                ])->save();
                $this->record('public_intake.review_started', $intake, $actor, $from);
            }

            $collidingVisit = Visit::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($collidingVisit !== null) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Kunci penerimaan ini telah digunakan untuk pendaftaran lain. Muat semula dan cuba semula.',
                ]);
            }

            $payload = Arr::wrap($intake->encrypted_payload);
            if ($validated['resolution'] === 'match') {
                $patient = Patient::query()
                    ->where('organisation_id', $actor->organisation_id)
                    ->where('patient_number', $validated['patient_number'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->record('public_intake.matched', $intake, $actor, $intake->status, ['patient_id' => $patient->id]);
            } else {
                $patientAttributes = Arr::wrap($payload['patient'] ?? []);
                $patientAttributes['duplicate_override'] = (bool) ($validated['duplicate_override'] ?? false);
                $patient = $this->patients->create($actor, $patientAttributes);
                $this->record('public_intake.patient_created', $intake, $actor, $intake->status, ['patient_id' => $patient->id]);
            }

            $visit = $this->visits->register($actor, [
                'idempotency_key' => $validated['idempotency_key'],
                'expected_branch_id' => $branch->id,
                'patient_number' => $patient->patient_number,
                'visit_type' => 'consultation',
                'assigned_doctor_user_id' => $validated['assigned_doctor_user_id'],
                'visit_reason_public_ids' => $validated['visit_reason_public_ids'],
                'priority' => $validated['priority'],
                'coverage_type' => $validated['coverage_type'],
                'panel_id' => $validated['panel_id'] ?? null,
                'coverage_member_reference' => $validated['coverage_member_reference'] ?? null,
                'confirm_repeat' => (bool) ($validated['confirm_repeat'] ?? false),
                'intake_purpose' => data_get($payload, 'visit.purpose'),
                'chief_complaint' => data_get($payload, 'visit.chief_complaint'),
                'complaint_duration' => data_get($payload, 'visit.duration'),
            ]);
            $this->record('public_intake.visit_created', $intake, $actor, $intake->status, ['visit_id' => $visit->id]);

            $queue = $this->queue->enter($actor, $visit, [
                'expected_branch_id' => $branch->id,
                'visit_lock_version' => $visit->lock_version,
            ]);
            $this->record('public_intake.queue_created', $intake, $actor, $intake->status, ['queue_entry_id' => $queue->id]);

            $from = $intake->status;
            $now = now()->utc();
            $intake->forceFill([
                'status' => PublicPatientIntake::STATUS_ACCEPTED,
                'accepted_at' => $now,
                'accepted_by_user_id' => $actor->id,
                'acceptance_idempotency_key' => $validated['idempotency_key'],
                'acceptance_fingerprint' => $fingerprint,
                'patient_id' => $patient->id,
                'visit_id' => $visit->id,
                'queue_entry_id' => $queue->id,
                'payload_purge_at' => $now->copy()->addDays((int) config('public-intake.retention_days', 30)),
                'lock_version' => $intake->lock_version + 1,
            ])->save();
            $this->record('public_intake.accepted', $intake, $actor, $from, [
                'patient_id' => $patient->id,
                'visit_id' => $visit->id,
                'queue_entry_id' => $queue->id,
            ]);

            return compact('intake', 'patient', 'visit', 'queue');
        }, 3);
    }

    private function authorizedBranch(User $actor): Branch
    {
        if (! $actor->is_active || ! $actor->can('visits.create.branch')
            || ! $actor->can('queue.enter.branch') || ! $actor->can('patients.search.organisation')
            || ! $actor->can('public_intakes.review.branch')) {
            throw new AuthorizationException;
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $this->branches->canSelect($actor, $branch)) {
            throw new AuthorizationException;
        }

        return $branch;
    }

    private function scoped(User $actor, string $publicId): PublicPatientIntake
    {
        $branch = $this->authorizedBranch($actor);

        return PublicPatientIntake::query()
            ->where('public_id', $publicId)
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();
    }

    /** @param callable(PublicPatientIntake): void $callback */
    private function transition(User $actor, string $publicId, int $expectedVersion, callable $callback): PublicPatientIntake
    {
        $branch = $this->authorizedBranch($actor);

        return DB::transaction(function () use ($actor, $publicId, $expectedVersion, $branch, $callback): PublicPatientIntake {
            $intake = PublicPatientIntake::query()
                ->where('public_id', $publicId)
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($intake->lock_version !== $expectedVersion) {
                $this->stale();
            }
            $callback($intake);

            return $intake->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function duplicateCandidates(User $actor, array $payload): array
    {
        Gate::forUser($actor)->authorize('viewAny', Patient::class);
        $patientData = Arr::wrap($payload['patient'] ?? []);
        $candidates = $this->identity->possibleDuplicates($actor->organisation_id, $patientData);
        $identifier = Arr::wrap($patientData['identifiers'][0] ?? []);
        if ($identifier !== []) {
            $exactId = PatientIdentifier::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('identifier_type', $identifier['identifier_type'] ?? null)
                ->where('issuing_country_code', $identifier['issuing_country_code'] ?? null)
                ->where('normalized_value', $identifier['value'] ?? null)
                ->whereNull('retired_at')
                ->value('patient_id');
            if ($exactId) {
                $exact = Patient::query()->whereKey($exactId)->where('organisation_id', $actor->organisation_id)->first();
                if ($exact) {
                    $candidates->prepend($exact);
                }
            }
        }

        $results = $candidates->unique('id')->take(5)->map(function (Patient $patient): array {
            $patient->loadMissing('identifiers');
            $identifier = $patient->identifiers->firstWhere('retired_at', null);

            return [
                'patientNumber' => $patient->patient_number,
                'fullName' => $patient->full_name,
                'dateOfBirth' => $patient->date_of_birth?->format('Y-m-d'),
                'maskedPhone' => $this->identity->maskPhone($patient->mobile_phone),
                'maskedIdentifier' => $identifier
                    ? $this->identity->maskIdentifier($identifier->identifier_type, $identifier->normalized_value) : null,
            ];
        })->all();

        return array_values($results);
    }

    /** @return array{intake: PublicPatientIntake, patient: Patient, visit: Visit, queue: QueueEntry} */
    private function acceptedResult(PublicPatientIntake $intake): array
    {
        $intake->loadMissing(['patient', 'visit', 'queueEntry']);

        return [
            'intake' => $intake,
            'patient' => $intake->patient,
            'visit' => $intake->visit,
            'queue' => $intake->queueEntry,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function record(string $event, PublicPatientIntake $intake, User $actor, string $from, array $extra = []): void
    {
        $this->audit->record($event, $intake, [
            'public_intake_public_id' => $intake->public_id,
            'from_state' => $from,
            'to_state' => $intake->status,
            ...$extra,
        ], $actor, $intake->branch, $intake->organisation_id);
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['lock_version' => 'Rekod ini telah berubah. Muat semula dan semak semula.']);
    }

    private function invalidState(): never
    {
        throw ValidationException::withMessages(['status' => 'Tindakan ini tidak dibenarkan untuk status semasa.']);
    }

    /** @return array{name: string, age: int|null, purpose: string|null, complaint: string|null, duplicateStatus: string} */
    private function summary(User $actor, PublicPatientIntake $intake): array
    {
        $payload = Arr::wrap($intake->encrypted_payload);
        $dateOfBirth = data_get($payload, 'patient.date_of_birth');

        return [
            'name' => (string) data_get($payload, 'patient.full_name', ''),
            'age' => is_string($dateOfBirth) ? (int) now()->diffInYears($dateOfBirth) : null,
            'purpose' => data_get($payload, 'visit.purpose'),
            'complaint' => data_get($payload, 'visit.chief_complaint'),
            'duplicateStatus' => $this->duplicateCandidates($actor, $payload) === []
                ? 'none' : 'possible',
        ];
    }
}
