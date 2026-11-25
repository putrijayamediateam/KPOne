<?php

namespace App\Domain\Visit\Assignment;

use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\HR\Roster\OnDutyShift;
use App\Domain\HR\Roster\Services\RosterOnDuty;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * AUTO-1c (owner answers Q1 to Q6, 24 Nov 2026, "Setuju" 25 Nov): a read-only warning for a WAITING consultation row whose
 * assigned doctor is no longer a good place to wait. The assignment is never changed by the system; the CA reassigns through
 * Edit visit (which keeps the patient's place and is audited).
 *
 * A row warns, in this order, only when today's approved roster is published at the branch:
 * - off_roster: the doctor is not on today's approved roster at all;
 * - on_leave: the doctor's shift covers this moment but approved leave takes them off it;
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
        $today = $this->roster->onDutyOn($branch, $now);
        $rostered = array_flip(array_map(fn (OnDutyShift $s): int => $s->userId, $today));
        $onDutyNow = array_flip(array_map(fn (OnDutyShift $s): int => $s->userId, $this->roster->onDutyNow($branch, $now)));
        $coveringNow = [];
        foreach ($today as $shift) {
            if ($shift->startsAt->lessThanOrEqualTo($now) && $shift->endsAt->greaterThan($now)) {
                $coveringNow[$shift->userId] = true;
            }
        }
        $needsRoom = $this->rooms->branchHasConsultationRooms($branch);
        $operationalDate = $this->rooms->operationalDate($branch);
        $users = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $ids)->get()->keyBy('id');
        $others = null;

        $out = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            $code = match (true) {
                $user === null => null,
                ! isset($rostered[$id]) => 'off_roster',
                isset($coveringNow[$id]) && ! isset($onDutyNow[$id]) => 'on_leave',
                isset($onDutyNow[$id]) && $needsRoom && $this->rooms->resolve($id, $branch, $operationalDate)['room'] === null => 'no_room',
                default => null,
            };
            if ($code === null && $user !== null && isset($onDutyNow[$id]) && ConsultationLocks::doctorBusy($user)) {
                // One read of who is free, shared by every row: doctors on duty now with a room and nobody waiting.
                $others ??= array_values(array_map(fn (array $d): int => $d['id'], array_filter(
                    $this->assignment->recommend($actor, $branch, $now, null)['list'],
                    fn (array $d): bool => $d['free'] && isset($onDutyNow[$d['id']]),
                )));
                $code = array_diff($others, [$id]) !== [] ? 'another_free' : null;
            }
            $out[$id] = $code === null ? null : ['code' => $code, 'label' => self::LABELS[$code]];
        }

        return $out;
    }
}
