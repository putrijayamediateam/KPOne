<?php

namespace App\Domain\Visit\Assignment;

use App\Domain\Access\StaffKind;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\HR\Roster\Services\RosterOnDuty;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Shared\Services\InvoiceSalesProjection;
use App\Domain\Visit\Assignment\Models\BranchAutoAssignSetting;
use App\Domain\Visit\Billing\Models\SalesTarget;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * AUTO-1 (owner decisions 24 Nov 2026, design note docs/teams/patients-registration/AUTO-1-DESIGN.md): which doctor a
 * registered consultation goes to. Pure ordering over data read now; the caller (RegisterAndQueueService) locks the
 * candidate doctors first, in ascending id, so two registrations cannot both read the same stale picture.
 *
 * Rules:
 * - Candidates: doctors on today's approved roster at this branch (RosterOnDuty::onDutyOn, never on call) who are also
 *   eligible today. Auto is available only when the Director switched it on for the branch AND a roster is published for
 *   the day; otherwise the CA picks by hand.
 * - Room: once a branch has consultation rooms, Call In needs one, so a doctor with no room today (the roster room for
 *   the shift they are on, or one they chose) is never picked and shows as "no room".
 * - Free: not Serving a patient who is not held (ConsultationLocks::doctorBusy) AND nobody Waiting for them.
 * - Order: follow-up doctor (seen in the last 30 days) if free; then free non-locums, then free locums; then, when every
 *   doctor is busy, the lowest net sales this month (non-locums first), skipping a doctor with 3 or more waiting.
 * - Among free doctors in one group: lowest target progress % first; two within 5 points of the lowest go to the lower
 *   net sales per patient this month; a doctor with no target comes after those with one. Ties: fewer waiting, then id.
 *
 * Sales and targets are read here and never leave the server: the screen list carries id, name, free and waiting only.
 */
final class DoctorAssignmentService
{
    public const FOLLOW_UP_DAYS = 30;

    public const NEAR_POINTS = 5;

    public const BUSY_CAP = 3;

    /** Fixed reasons a CA gives when choosing a doctor other than the recommended one (audited as ca_override_*). */
    public const OVERRIDE_REASONS = ['patient_requested', 'continuity', 'recommended_unavailable', 'other'];

    public function __construct(private readonly RosterOnDuty $roster, private readonly VisitDoctorEligibilityService $eligibility, private readonly DoctorRoomService $rooms) {}

    public function isEnabled(Branch $branch): bool
    {
        return BranchAutoAssignSetting::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->value('enabled') === true;
    }

    /** True when the Director switched auto on for the branch and the day's roster is published. */
    public function isAvailable(Branch $branch, CarbonImmutable $now): bool
    {
        return $this->isEnabled($branch) && $this->roster->isPublished($branch, $now);
    }

    /**
     * Rostered, eligible doctors at the branch for today, in ascending id (the lock order).
     *
     * @return list<int>
     */
    public function candidateIds(Branch $branch, CarbonImmutable $now): array
    {
        $rostered = collect($this->roster->onDutyOn($branch, $now))->filter(fn ($s): bool => $s->isDoctor)->pluck('userId')->unique();
        $eligible = $this->eligibility->eligibleDoctorIds($branch, $now->setTimezone($branch->timezone)->toDateString());

        return array_values($rostered->intersect($eligible)->map(fn ($id): int => (int) $id)->sort()->values()->all());
    }

    /**
     * Doctors a CA may choose by hand: when auto is available only those on today's roster, otherwise everyone eligible.
     *
     * @return list<int>
     */
    public function pickerIds(Branch $branch, CarbonImmutable $now): array
    {
        return $this->isAvailable($branch, $now)
            ? $this->candidateIds($branch, $now)
            : $this->eligibility->eligibleDoctorIds($branch, $now->setTimezone($branch->timezone)->toDateString());
    }

    /**
     * Lock the candidates (ascending id, with the actor), then order them. Call inside the caller's transaction.
     *
     * @return array{doctorId: int|null, reason: string|null, list: list<array{id: int, name: string, free: bool, waiting: int, room: bool}>}
     */
    public function pick(User $actor, Branch $branch, CarbonImmutable $now, ?int $patientId): array
    {
        $ids = $this->candidateIds($branch, $now);
        ConsultationLocks::users($actor->organisation_id, [$actor->id, ...$ids]);
        // Read again under the lock: the roster, eligibility and waiting counts as they are now.
        $ids = $this->candidateIds($branch, $now);

        return $this->order($actor->organisation_id, $branch, $now, $ids, $patientId);
    }

    /**
     * Read-only recommendation for the CA's screen (no lock). The same ordering as `pick`.
     *
     * @return array{doctorId: int|null, reason: string|null, list: list<array{id: int, name: string, free: bool, waiting: int, room: bool}>}
     */
    public function recommend(User $actor, Branch $branch, CarbonImmutable $now, ?int $patientId, bool $withSales = false): array
    {
        return $this->order($actor->organisation_id, $branch, $now, $this->candidateIds($branch, $now), $patientId, withSales: $withSales);
    }

    /**
     * @param  list<int>  $ids
     * @return array{doctorId: int|null, reason: string|null, list: list<array{id: int, name: string, free: bool, waiting: int, room: bool}>}
     */
    private function order(int $organisationId, Branch $branch, CarbonImmutable $now, array $ids, ?int $patientId, bool $withSales = true): array
    {
        if ($ids === []) {
            return ['doctorId' => null, 'reason' => null, 'list' => []];
        }
        $local = $now->setTimezone($branch->timezone);
        $first = $local->startOfMonth();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $first->toDateString(), $branch->timezone)->utc();
        $period = [$branch->id => ['start' => $from, 'end' => $from->setTimezone($branch->timezone)->addMonthNoOverflow()->utc()]];
        $users = User::query()->where('organisation_id', $organisationId)->whereIn('id', $ids)->get()->keyBy('id');
        $waiting = QueueEntry::query()->where('queue_entries.branch_id', $branch->id)->where('queue_entries.status', QueueEntry::STATUS_WAITING)
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')->whereIn('visits.assigned_doctor_user_id', $ids)
            ->groupBy('visits.assigned_doctor_user_id')->selectRaw('visits.assigned_doctor_user_id as doctor_id, count(*) as n')->pluck('n', 'doctor_id');
        $patients = Visit::query()->where('branch_id', $branch->id)->whereIn('assigned_doctor_user_id', $ids)->where('status', '!=', Visit::STATUS_CANCELLED)
            ->where('registered_at', '>=', $period[$branch->id]['start'])->where('registered_at', '<', $period[$branch->id]['end'])
            ->groupBy('assigned_doctor_user_id')->selectRaw('assigned_doctor_user_id as doctor_id, count(*) as n')->pluck('n', 'doctor_id');
        $targets = SalesTarget::query()->where('organisation_id', $organisationId)->where('branch_id', $branch->id)->whereDate('month', $first->toDateString())
            ->whereIn('doctor_user_id', $ids)->whereNull('cleared_at')->pluck('amount_sen', 'doctor_user_id');

        $needsRoom = $this->rooms->branchHasConsultationRooms($branch);
        $operationalDate = $this->rooms->operationalDate($branch);
        $rows = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            if ($user === null) {
                continue;
            }
            // The CA's screen only needs free, waiting and room; sales are read only when the registration picks.
            $sales = $withSales ? InvoiceSalesProjection::netSales($organisationId, $period, $id)['netSen'] : 0;
            $count = (int) ($patients[$id] ?? 0);
            $target = (int) ($targets[$id] ?? 0);
            $wait = (int) ($waiting[$id] ?? 0);
            $rows[$id] = [
                'id' => $id, 'name' => (string) $user->name, 'locum' => StaffKind::isLocum($user), 'waiting' => $wait,
                'room' => ! $needsRoom || $this->rooms->resolve($id, $branch, $operationalDate)['room'] !== null,
                'free' => $wait === 0 && ! ConsultationLocks::doctorBusy($user),
                'sales' => $sales, 'percent' => $target > 0 ? $sales * 100 / $target : null,
                'perPatient' => $count > 0 ? intdiv($sales, $count) : 0,
            ];
        }

        $previous = $this->previousDoctor($branch, $patientId, $now);
        $freeResidents = $this->freeOrder(array_filter($rows, fn (array $r): bool => $r['free'] && $r['room'] && ! $r['locum']));
        $freeLocums = $this->freeOrder(array_filter($rows, fn (array $r): bool => $r['free'] && $r['room'] && $r['locum']));
        $busy = array_values(array_filter($rows, fn (array $r): bool => ! $r['free'] && $r['room']));
        $noRoom = array_values(array_filter($rows, fn (array $r): bool => ! $r['room']));
        usort($noRoom, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        usort($busy, fn (array $a, array $b): int => [$a['locum'], $a['sales'], $a['id']] <=> [$b['locum'], $b['sales'], $b['id']]);

        $ordered = [...$freeResidents, ...$freeLocums, ...$busy, ...$noRoom];
        $doctorId = null;
        $reason = null;
        $isFree = fn (int $id): bool => isset($rows[$id]) && $rows[$id]['free'] && $rows[$id]['room'];
        if ($previous !== null && isset($rows[$previous]) && $isFree($previous)) {
            [$doctorId, $reason] = [$previous, 'follow_up_previous'];
        } elseif ($freeResidents !== [] || $freeLocums !== []) {
            [$doctorId, $reason] = [($freeResidents[0] ?? $freeLocums[0])['id'], 'next_in_turn'];
        } elseif ($busy !== []) {
            $open = array_values(array_filter($busy, fn (array $r): bool => $r['waiting'] < self::BUSY_CAP));
            if ($open !== []) {
                [$doctorId, $reason] = [$open[0]['id'], 'all_busy_lowest_sales'];
            } else {
                usort($busy, fn (array $a, array $b): int => [$a['waiting'], $a['id']] <=> [$b['waiting'], $b['id']]);
                [$doctorId, $reason] = [$busy[0]['id'], 'all_busy_capped'];
            }
        }
        if (! $withSales) {
            // The CA's list is neutral: by name, so its order never reveals who the rules rank first.
            usort($ordered, fn (array $a, array $b): int => ($b['room'] <=> $a['room']) ?: (strcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']));
        }

        return ['doctorId' => $doctorId, 'reason' => $reason, 'list' => array_map(
            fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'free' => $r['free'] && $r['room'], 'waiting' => $r['waiting'], 'room' => $r['room']], $ordered)];
    }

    /**
     * One group of free doctors, best first. Those with a target come first, ordered by progress %; every doctor within
     * 5 points of the lowest remaining one goes to the lower sales per patient. Doctors without a target follow.
     *
     * @internal public only so the ordering rules can be tested without invoices.
     *
     * @param  array<int, array<string, mixed>>  $group
     * @return list<array<string, mixed>>
     */
    public function freeOrder(array $group): array
    {
        $with = array_values(array_filter($group, fn (array $r): bool => $r['percent'] !== null));
        $without = array_values(array_filter($group, fn (array $r): bool => $r['percent'] === null));
        $out = [];
        while ($with !== []) {
            usort($with, fn (array $a, array $b): int => [$a['percent'], $a['id']] <=> [$b['percent'], $b['id']]);
            $lowest = $with[0]['percent'];
            $near = array_keys(array_filter($with, fn (array $r): bool => $r['percent'] - $lowest <= self::NEAR_POINTS));
            usort($near, fn (int $x, int $y): int => [$with[$x]['perPatient'], $with[$x]['waiting'], $with[$x]['id']] <=> [$with[$y]['perPatient'], $with[$y]['waiting'], $with[$y]['id']]);
            $out[] = $with[$near[0]];
            array_splice($with, $near[0], 1);
        }
        usort($without, fn (array $a, array $b): int => [$a['perPatient'], $a['waiting'], $a['id']] <=> [$b['perPatient'], $b['waiting'], $b['id']]);

        return [...$out, ...$without];
    }

    private function previousDoctor(Branch $branch, ?int $patientId, CarbonImmutable $now): ?int
    {
        if ($patientId === null) {
            return null;
        }
        $id = Visit::query()->where('organisation_id', $branch->organisation_id)->where('patient_id', $patientId)->whereNotNull('assigned_doctor_user_id')
            ->where('status', '!=', Visit::STATUS_CANCELLED)->where('registered_at', '>=', $now->subDays(self::FOLLOW_UP_DAYS))
            ->orderByDesc('registered_at')->orderByDesc('id')->value('assigned_doctor_user_id');

        return $id === null ? null : (int) $id;
    }
}
