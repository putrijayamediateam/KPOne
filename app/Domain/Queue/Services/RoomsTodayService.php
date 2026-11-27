<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Q-16d: the "Rooms today" view of the queue board: each doctor who can take consultations at the branch today, their room and
 * where it came from, whether they are free, and how many patients wait for them. Read only, structural: doctor names, rooms,
 * queue numbers and counts. No patient name, reading or reason, and no zone.
 *
 * The board holders (queue.view.branch) see it; a doctor with only queue.view.own does not.
 */
class RoomsTodayService
{
    /** The held-consultations cap per doctor (HC-01). */
    private const HELD_CAP = 3;

    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private DoctorRoomService $rooms,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, hasRooms: bool, operationalDate: string} */
    public function forActiveBranch(User $actor, int $expectedBranchId): array
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $expectedBranchId || ! $actor->is_active || ! $actor->can('queue.view.branch')) {
            throw new AuthorizationException('You may not view the rooms for this branch.');
        }
        $today = now()->setTimezone($branch->timezone)->toDateString();
        $doctors = $this->doctors->eligibleDoctors($branch, $today);
        $ids = $doctors->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return ['rows' => [], 'hasRooms' => $this->rooms->branchHasConsultationRooms($branch), 'operationalDate' => $today];
        }

        $waiting = QueueEntry::query()
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id)
            ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
            ->whereDate('queue_entries.operational_date', '<=', $today)
            ->where('visits.status', Visit::STATUS_REGISTERED)
            ->whereIn('visits.assigned_doctor_user_id', $ids)
            ->groupBy('visits.assigned_doctor_user_id')
            ->selectRaw('visits.assigned_doctor_user_id as doctor_id, count(*) as total')
            ->selectRaw('sum(case when exists (select 1 from queue_call_holds h where h.queue_entry_id = queue_entries.id and h.closed_at is null) then 1 else 0 end) as not_ready')
            ->get()->keyBy('doctor_id');

        $serving = QueueEntry::query()
            ->with(['visit:id,assigned_doctor_user_id', 'visit.clinicalEncounter.activeHold'])
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('status', QueueEntry::STATUS_SERVING)
            ->whereHas('visit', fn ($q) => $q->whereIn('assigned_doctor_user_id', $ids))
            ->get();

        $rows = [];
        foreach ($doctors as $doctor) {
            $mine = $serving->filter(fn (QueueEntry $e): bool => (int) $e->visit->assigned_doctor_user_id === (int) $doctor->id);
            $held = $mine->filter(fn (QueueEntry $e): bool => $e->visit->clinicalEncounter?->activeHold !== null);
            $seeing = $mine->first(fn (QueueEntry $e): bool => $e->visit->clinicalEncounter?->activeHold === null);
            $resolved = $this->rooms->resolve((int) $doctor->id, $branch, $today);
            $row = $waiting->get($doctor->id);
            $rows[] = [
                'doctorId' => (int) $doctor->id,
                'doctorName' => $doctor->name,
                'roomName' => $resolved['room']?->name,
                // "chosen" (the doctor's own pick), "roster" (today's approved shift) or null.
                'roomSource' => $resolved['source'],
                // Serving here, busy at another branch (no patient is named), or free.
                'state' => $seeing !== null ? 'serving' : (ConsultationLocks::doctorBusy($doctor) ? 'busy_elsewhere' : 'free'),
                'servingNumber' => $seeing !== null ? QueueNumberFormat::format($seeing->queue_number) : null,
                'waiting' => (int) ($row->total ?? 0),
                'notReady' => (int) ($row->not_ready ?? 0),
                'held' => $held->count(),
                'heldCap' => self::HELD_CAP,
            ];
        }

        return ['rows' => $rows, 'hasRooms' => $this->rooms->branchHasConsultationRooms($branch), 'operationalDate' => $today];
    }
}
