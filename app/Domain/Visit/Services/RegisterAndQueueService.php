<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $doctorId]);
            $visit = $this->registration->register($actor, $attributes);

            return ['visit' => $visit, 'queueLabel' => $this->send($actor, $visit)];
        }, 3);
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
