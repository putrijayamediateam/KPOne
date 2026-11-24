<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Assignment\DoctorAssignmentService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Register a Visit and put it on the waiting list in ONE transaction (owner decision, 2026-11-18: "always send, later
 * can edit or cancel visit"). A consultation joins the A list (Consultation Queue), an OTC visit the B list.
 *
 * Nothing new is decided here: registration is VisitRegistrationService::register and waiting is
 * QueueEntryService::enter or OtcQueueService::enter, each with its own permission, branch, row locks, idempotency and
 * audit (visit.created, then queue.entered or otc_queue.entered). They run inside one outer transaction, so if
 * queuing is refused (for example the doctor is not eligible today) the registration is rolled back too and the person
 * sees the reason; there is never a silent registered-only visit created by this path. The registration's own
 * idempotency key covers the whole step: a retry returns the same Visit and the entry it already has.
 *
 * An account that may register but not queue (no `queue.enter.branch`) registers only, as before; nothing is widened.
 * QR acceptance does the same pair of steps in its own transaction and is unchanged.
 */
class RegisterAndQueueService
{
    public function __construct(
        private VisitRegistrationService $registration,
        private QueueEntryService $queue,
        private OtcQueueService $otcQueue,
        private BranchAccessService $branches,
        private DoctorAssignmentService $assignment,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated registration attributes
     * @return array{visit: Visit, queueLabel: string|null}
     */
    public function register(User $actor, array $attributes): array
    {
        // An unauthorised request is refused before any row is locked (the registration step checks again under lock).
        Gate::forUser($actor)->authorize('create', Visit::class);

        // Laravel retries a deadlock only for the OUTERMOST transaction, so the attempts (3, as in the registration and
        // queue steps) are declared here, not on the nested ones. Lock order is the same as Call In, Call next and a
        // Visit edit (ConsultationLocks): the actor and the chosen doctor first, in ascending id, then the patient,
        // the Visit and the queue entry inside the steps below.
        return DB::transaction(function () use ($actor, $attributes): array {
            $doctorId = isset($attributes['assigned_doctor_user_id']) ? (int) $attributes['assigned_doctor_user_id'] : null;
            $attributes = $this->assignDoctor($actor, $attributes, $doctorId);
            $doctorId = isset($attributes['assigned_doctor_user_id']) ? (int) $attributes['assigned_doctor_user_id'] : null;
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $doctorId]);
            $visit = $this->registration->register($actor, $attributes);

            return ['visit' => $visit, 'queueLabel' => $this->send($actor, $visit)];
        }, 3);
    }

    /**
     * AUTO-1: with automatic assignment available at the branch (switch on and today's roster published), a blank doctor
     * is filled by the recommendation, and a doctor chosen by hand who is not the recommended one needs a fixed reason.
     * Runs before the registration step and takes the candidate doctors' locks first (ascending id), so the picture it
     * reads cannot change under it. A replay of an earlier registration is left alone. Nothing about sales leaves here.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function assignDoctor(User $actor, array $attributes, ?int $chosen): array
    {
        if (($attributes['visit_type'] ?? null) !== 'consultation') {
            return $attributes;
        }
        $branch = $this->branches->activeBranch($actor);
        $now = CarbonImmutable::now();
        if (! $branch || (int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id || ! $this->assignment->isAvailable($branch, $now)) {
            return $attributes;
        }
        // A replay keeps the doctor the first attempt chose: nothing is picked again and nothing is audited again.
        $earlier = isset($attributes['idempotency_key']) ? Visit::query()->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $attributes['idempotency_key'])->first() : null;
        if ($earlier !== null) {
            $attributes['assigned_doctor_user_id'] ??= $earlier->assigned_doctor_user_id;

            return $attributes;
        }
        $patientId = filled($attributes['patient_number'] ?? null)
            ? Patient::query()->where('organisation_id', $actor->organisation_id)->where('patient_number', $attributes['patient_number'])->value('id') : null;
        $pick = $this->assignment->pick($actor, $branch, $now, $patientId === null ? null : (int) $patientId);

        if ($chosen === null) {
            if ($pick['doctorId'] !== null) {
                $attributes['assigned_doctor_user_id'] = $pick['doctorId'];
                $attributes['doctor_source'] = 'auto';
                $attributes['doctor_reason_code'] = $pick['reason'];
            }

            return $attributes;
        }
        if ($pick['doctorId'] === null || $chosen === $pick['doctorId']) {
            $attributes['doctor_source'] = 'manual';
            $attributes['doctor_reason_code'] = $chosen === $pick['doctorId'] ? $pick['reason'] : 'no_recommendation';

            return $attributes;
        }
        $reason = $attributes['doctor_override_reason'] ?? null;
        if (! in_array($reason, DoctorAssignmentService::OVERRIDE_REASONS, true)) {
            throw ValidationException::withMessages(['doctor_override_reason' => 'Choose why you are not using the recommended doctor.']);
        }
        $attributes['doctor_source'] = 'override';
        $attributes['doctor_reason_code'] = 'ca_override_'.$reason;

        return $attributes;
    }

    private function send(User $actor, Visit $visit): ?string
    {
        $branch = $this->branches->activeBranch($actor);

        // A replay of an earlier registration, or a visit that already has its entry (in any state), is left as it is.
        $consultation = QueueEntry::query()->where('visit_id', $visit->id)->first();
        $otc = OtcQueueEntry::query()->where('visit_id', $visit->id)->first();
        if ($consultation) {
            return $consultation->status === QueueEntry::STATUS_WAITING
                ? QueueNumberFormat::format($consultation->queue_number) : null;
        }
        if ($otc) {
            return $otc->status === OtcQueueEntry::STATUS_WAITING
                ? QueueNumberFormat::format($otc->queue_number, QueueNumberFormat::OTC) : null;
        }

        // "Registers only" must be true at THIS branch: queueing needs its permission and a selectable active branch.
        if (! $branch || $visit->status !== Visit::STATUS_REGISTERED
            || ! $actor->can('queue.enter.branch') || ! $this->branches->canSelect($actor, $branch)) {
            return null;
        }

        if ($visit->visit_type === 'otc') {
            $entry = $this->otcQueue->enter($actor, $visit, ['expected_branch_id' => $branch->id]);

            return QueueNumberFormat::format($entry->queue_number, QueueNumberFormat::OTC);
        }

        $entry = $this->queue->enter($actor, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
        ]);

        return QueueNumberFormat::format($entry->queue_number);
    }
}
