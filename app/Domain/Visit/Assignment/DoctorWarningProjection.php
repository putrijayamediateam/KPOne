<?php

namespace App\Domain\Visit\Assignment;

use App\Domain\HR\Roster\OnDutyShift;
use App\Domain\HR\Roster\Services\RosterOnDuty;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\QueueEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * AUTO-1c (owner answers Q1 to Q6, 24 Nov 2026, "Setuju" 25 Nov): a read-only warning for a WAITING consultation row whose
 * assigned doctor is no longer a good place to wait. The assignment is never changed by the system; the CA reassigns through
 * Edit visit (which keeps the patient's place and is audited).
 *
 * A row warns, in this order, only when today's approved roster is published at the branch:
 * - on_leave: approved leave covers now, including full-day leave;
 * - off_roster: no approved shift covers now (distinguishes an ended/not-started shift in its label);
 * - no_room: on duty now at a branch with consultation rooms, but no room today;
 * - another_free: busy (Serving a patient who is not held) while another doctor who is on duty now, has a room and has
 *   nobody waiting is free right now. Without that last condition every patient behind a busy doctor would warn all day.
 *
 * Batched: today's roster, the roster now, the doctors, their rooms and the free doctors are each read once per board
 * load, never per row. No lock, write or audit. It carries a code and a fixed phrase only: never another doctor's name,
 * sales, targets or any figure. The caller shows it only to a viewer who may open Edit visit on that row.
 */
final class DoctorWarningProjection
{
    public const LABELS = [
        'off_roster' => "Not on today's roster",
        'on_leave' => 'On leave now',
        'no_room' => 'No room today',
        'another_free' => 'Another doctor free',
    ];

    /** @var array<string, bool> whether today's roster is published, read once per branch and minute (a board polls and calls this per tab) */
    private array $published = [];

    public function __construct(private readonly RosterOnDuty $roster, private readonly DoctorRoomService $rooms, private readonly DoctorAssignmentService $assignment) {}

    /**
     * @param  array<int, int|null>  $doctorIds  the assigned doctor of each WAITING consultation row on the page
     * @return array<int, array{code: string, label: string}|null> by doctor id (only doctors that were asked about)
     */
    public function forDoctors(User $actor, Branch $branch, array $doctorIds): array
    {
        $ids = array_values(array_unique(array_filter($doctorIds)));
        if ($ids === []) {
            return [];
        }
        $now = CarbonImmutable::now();
        $key = $branch->id.'|'.$now->format('Y-m-d H:i');
        $this->published[$key] ??= $this->roster->isPublished($branch, $now);
        if (! $this->published[$key]) {
            return array_fill_keys($ids, null);
        }
        $availability = $this->roster->availabilityForWarnings($branch, $now, $ids);
        $onDutyNow = array_fill_keys(array_keys(array_filter($availability,
            fn (array $state): bool => $state['scheduledNow'] && ! $state['onLeave'],
        )), true);
        $needsRoom = $this->rooms->branchHasConsultationRooms($branch);
        $operationalDate = $this->rooms->operationalDate($branch);
        $users = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $ids)->get()->keyBy('id');
        $rooms = $needsRoom ? $this->rooms->resolveMany($ids, $branch, $operationalDate) : [];
        $busy = $this->busyIds($actor->organisation_id, $ids);
        $others = null;

        $out = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            $code = match (true) {
                $user === null => null,
                $availability[$id]['onLeave'] => 'on_leave',
                ! $availability[$id]['scheduledNow'] => 'off_roster',
                isset($onDutyNow[$id]) && $needsRoom && $rooms[$id]['room'] === null => 'no_room',
                default => null,
            };
            if ($code === null && $user !== null && isset($onDutyNow[$id]) && isset($busy[$id])) {
                // One read of who is free, shared by every row: doctors on duty now with a room and nobody waiting.
                if ($others === null) {
                    $currentIds = array_fill_keys(array_map(fn (OnDutyShift $shift): int => $shift->userId,
                        $this->roster->onDutyNow($branch, $now),
                    ), true);
                    // Same eligible/day-rostered candidate set as recommendation, without sales or per-doctor queries.
                    $candidateIds = array_values(array_intersect($this->assignment->candidateIds($branch, $now), array_keys($currentIds)));
                    $candidateRooms = $needsRoom ? $this->rooms->resolveMany($candidateIds, $branch, $operationalDate) : [];
                    $candidateBusy = $this->busyIds($actor->organisation_id, $candidateIds);
                    $waiting = QueueEntry::query()->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
                        ->where('queue_entries.branch_id', $branch->id)->where('queue_entries.status', QueueEntry::STATUS_WAITING)
                        ->whereIn('visits.assigned_doctor_user_id', $candidateIds)->distinct()->pluck('visits.assigned_doctor_user_id')->flip();
                    $others = array_values(array_filter($candidateIds, fn (int $candidate): bool => ! isset($candidateBusy[$candidate])
                        && ! $waiting->has($candidate) && (! $needsRoom || $candidateRooms[$candidate]['room'] !== null)));

                }
                $code = array_diff($others, [$id]) !== [] ? 'another_free' : null;
            }
            $label = $code === 'off_roster' && $availability[$id]['scheduledToday']
                ? 'Off duty now'
                : ($code === null ? null : self::LABELS[$code]);
            $out[$id] = $code === null ? null : ['code' => $code, 'label' => $label];
        }

        return $out;
    }

    /**
     * Boolean membership only, equivalent to ConsultationLocks::doctorBusy for this organisation.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function busyIds(int $organisationId, array $ids): array
    {
        return array_fill_keys(QueueEntry::query()->where('queue_entries.organisation_id', $organisationId)
            ->where('queue_entries.status', QueueEntry::STATUS_SERVING)
            ->whereHas('visit', fn ($query) => $query->whereIn('assigned_doctor_user_id', $ids))
            ->whereDoesntHave('visit.clinicalEncounter.activeHold')
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->distinct()->pluck('visits.assigned_doctor_user_id')->map(fn ($id): int => (int) $id)->all(), true);
    }
}
