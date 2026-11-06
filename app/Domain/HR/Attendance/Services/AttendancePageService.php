<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** HR-3a page props for /hr/attendance: My attendance, Review and Settings. Read only. */
final class AttendancePageService
{
    public function __construct(private BranchAccessService $branches, private AttendanceDays $days, private AttendanceSettingsService $settings) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canClock = $actor->can(ClockPermissions::CLOCK_OWN);
        $canReview = $actor->can(ClockPermissions::REVIEW);
        $tabs = array_keys(array_filter(['my' => $canClock, 'review' => $canReview, 'settings' => $canReview]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'kinds' => collect(CorrectionService::KINDS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'reasons' => collect(CorrectionService::REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'rejectReasons' => collect(CorrectionService::REJECT_REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'my' => $canClock ? $this->my($actor) : null,
            'review' => $canReview && $tab === 'review' ? $this->review($actor, $query) : null,
            'settings' => $canReview && $tab === 'settings' ? $this->settingsPage($actor) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function my(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $zone = $branch !== null ? $branch->timezone : $this->settings->today($actor->organisation_id)->getTimezone()->getName();
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($zone)->startOfDay();
        $assigned = $branch !== null && app(AttendanceSupport::class)->assigned($actor, $branch, $today);
        $open = ClockSession::query()->where('user_id', $actor->id)->where('status', 'open')->first();
        $stale = $open !== null && $open->clock_in_at->lessThan($now->subHours(AttendanceSupport::MAX_SESSION_HOURS));

        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $from = $today->subDays(31)->min($weekStart);
        $sessions = ClockSession::query()->where('user_id', $actor->id)->whereDate('local_date', '>=', $from->toDateString())
            ->whereDate('local_date', '<=', $weekStart->addDays(6)->toDateString())->orderBy('clock_in_at')->get();
        $shifts = $this->days->shifts($actor->organisation_id, [$actor->id], $from, $weekStart->addDays(6));
        $waiting = $this->waiting($sessions);
        $rows = collect($this->days->rows($actor->organisation_id, $sessions, $shifts, $waiting));
        $week = $rows->filter(fn (array $r): bool => $r['date'] >= $weekStart->toDateString())->values();
        $attention = $rows->filter(fn (array $r): bool => $r['date'] < $weekStart->toDateString() && $r['needsClockOut'] && ! $r['correctionWaiting'])->values();

        $todayShifts = $shifts->filter(fn (RosterShift $s): bool => $s->starts_at->setTimezone($s->timezone)->toDateString() === $today->toDateString())->values();
        $lastToday = $sessions->filter(fn (ClockSession $s): bool => $s->local_date->toDateString() === $today->toDateString() && $s->status === 'closed')->sortBy('clock_out_at')->last();
        $state = 'out';
        $done = null;
        if ($open !== null && ! $stale) {
            $state = 'in';
        } elseif ($lastToday !== null && $lastToday->clock_out_at !== null) {
            $later = $todayShifts->contains(fn (RosterShift $s): bool => $s->starts_at->greaterThan($lastToday->clock_out_at));
            if (! $later) {
                $state = 'done';
                $worked = AttendanceDays::worked($lastToday, $todayShifts->first());
                $done = 'Done for today: '.AttendanceSupport::time($lastToday->clock_in_at, $lastToday->timezone).' – '
                    .AttendanceSupport::time($lastToday->clock_out_at, $lastToday->timezone).', '.AttendanceSupport::duration($worked ?? 0);
            }
        }
        $settings = $this->settings->forDate($actor->organisation_id, $today);

        return [
            'branch' => $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name],
            'timezone' => $zone,
            'serverNow' => $now->toIso8601String(),
            'canPress' => $assigned,
            'refusal' => $assigned ? null : 'Choose a branch you work at in the top bar.',
            'state' => $state,
            'clockedInLabel' => $state === 'in' ? 'Clocked in at '.AttendanceSupport::time($open->clock_in_at, $open->timezone) : null,
            'doneLabel' => $done,
            'staleLabel' => $stale ? 'Your clock-in on '.AttendanceSupport::day($open->local_date).' has no clock-out. Add your clock-out time below.' : null,
            'todayShift' => $todayShifts->isEmpty() ? null : $todayShifts->map(fn (RosterShift $s): string => (RosterRules::SESSIONS[$s->session_type] ?? 'Other')
                .', '.AttendanceSupport::time($s->starts_at, $s->timezone).' to '.AttendanceSupport::time($s->ends_at, $s->timezone).', '.$s->version->week->branch->name)->implode('; '),
            'week' => $week->all(),
            'weekLabel' => AttendanceSupport::day($weekStart).' to '.AttendanceSupport::day($weekStart->addDays(6)),
            'attention' => $attention->all(),
            'settingsReady' => $settings !== null,
            'correctionDays' => $settings?->correctionDays,
            'corrections' => $this->corrections(AttendanceCorrection::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)
                ->orderByDesc('id')->limit(20)->get(), $actor),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function review(User $actor, array $query): array
    {
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get();
        $requested = filter_var($query['branch'] ?? null, FILTER_VALIDATE_INT);
        $branch = $branches->firstWhere('id', $requested) ?? $branches->firstWhere('id', $this->branches->activeBranch($actor)?->id) ?? $branches->first();
        if ($branch === null) {
            return ['branches' => [], 'branch' => null, 'rows' => [], 'pending' => [], 'staff' => [], 'summary' => '', 'date' => null];
        }
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $date = is_string($query['date'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $query['date']) === 1
            ? (CarbonImmutable::createFromFormat('!Y-m-d', $query['date'], $branch->timezone) ?: $today) : $today;
        $shifts = $this->days->shifts($actor->organisation_id, [], $date, $date, $branch->id);
        $sessions = ClockSession::query()->where('organisation_id', $actor->organisation_id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branch->id)->whereDate('local_date', $date->toDateString()))
                ->orWhere(fn ($q) => $q->whereIn('user_id', $shifts->pluck('staff_user_id')->unique()->values())->whereDate('local_date', $date->toDateString())))
            ->orderBy('clock_in_at')->get();
        $rows = $this->days->rows($actor->organisation_id, $sessions, $shifts, $this->waiting($sessions));
        $names = User::query()->whereIn('id', collect($rows)->pluck('userId')->unique())->pluck('name', 'id');
        $rows = array_map(fn (array $r): array => [...$r, 'person' => (string) ($names[$r['userId']] ?? 'Staff member')], $rows);
        $pending = AttendanceCorrection::query()->where('organisation_id', $actor->organisation_id)->where('status', 'pending')->orderBy('id')->limit(100)->get();
        $flagged = count(array_filter($rows, fn (array $r): bool => $r['flags'] !== []));
        $staffIds = StaffBranchAssignment::query()->where('branch_id', $branch->id)->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date->toDateString()))
            ->join('staff_profiles', 'staff_profiles.id', '=', 'staff_branch_assignments.staff_profile_id')->pluck('staff_profiles.user_id');

        return [
            'branches' => $branches->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'date' => $date->toDateString(),
            'dateLabel' => $date->format('l, j M Y'),
            'prevDate' => $date->subDay()->toDateString(),
            'nextDate' => $date->addDay()->toDateString(),
            'isToday' => $date->toDateString() === $today->toDateString(),
            'settingsReady' => $this->settings->forDate($actor->organisation_id, $date) !== null,
            'rows' => $rows,
            'summary' => ($flagged === 1 ? '1 flag' : "{$flagged} flags").' · '.($pending->count() === 1 ? '1 correction waiting' : "{$pending->count()} corrections waiting"),
            'pending' => $this->corrections($pending, $actor),
            'staff' => User::query()->whereIn('id', $staffIds)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsPage(User $actor): array
    {
        $versions = $this->settings->versions($actor->organisation_id);
        $today = $this->settings->today($actor->organisation_id);
        $current = $this->settings->forDate($actor->organisation_id, $today);
        $creators = User::query()->whereIn('id', $versions->map(fn (AttendanceSettingsVersion $v): int => $v->createdByUserId)->unique()->values())->pluck('name', 'id');

        return [
            'today' => $today->toDateString(),
            'current' => $current === null ? null : ['graceMinutes' => $current->graceMinutes, 'forgottenWindowMinutes' => $current->forgottenWindowMinutes,
                'correctionDays' => $current->correctionDays, 'effectiveFrom' => AttendanceSupport::day(CarbonImmutable::parse($current->effectiveFrom))],
            'suggested' => ['graceMinutes' => AttendanceSettingsService::SUGGESTED['grace_minutes'], 'forgottenWindowMinutes' => AttendanceSettingsService::SUGGESTED['forgotten_window_minutes'],
                'correctionDays' => AttendanceSettingsService::SUGGESTED['correction_days']],
            'history' => $versions->take(10)->map(fn (AttendanceSettingsVersion $v): array => ['id' => $v->id, 'effectiveFrom' => AttendanceSupport::day(CarbonImmutable::parse($v->effectiveFrom)),
                'summary' => "Grace {$v->graceMinutes} min · forgotten clock-out after {$v->forgottenWindowMinutes} min · corrections up to {$v->correctionDays} days back",
                'by' => (string) ($creators[$v->createdByUserId] ?? 'Staff member')])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, ClockSession>  $sessions
     * @return array<int, true>
     */
    private function waiting(Collection $sessions): array
    {
        $out = [];
        foreach (AttendanceCorrection::query()->whereIn('clock_session_id', $sessions->pluck('id'))->where('status', 'pending')->pluck('clock_session_id') as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * @param  Collection<int, AttendanceCorrection>  $corrections
     * @return array<int, array<string, mixed>>
     */
    private function corrections(Collection $corrections, User $actor): array
    {
        $sessions = ClockSession::query()->whereIn('id', $corrections->pluck('clock_session_id')->filter())->get()->keyBy('id');
        $branches = Branch::query()->whereIn('id', $corrections->pluck('branch_id')->unique())->get()->keyBy('id');
        $names = User::query()->whereIn('id', $corrections->pluck('user_id')->merge($corrections->pluck('proposed_by_user_id'))->unique())->pluck('name', 'id');
        $statuses = ['pending' => 'Correction waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Withdrawn'];

        return $corrections->map(function (AttendanceCorrection $c) use ($sessions, $branches, $names, $statuses, $actor): array {
            $zone = $branches[$c->branch_id]->timezone ?? 'UTC';
            $session = $c->clock_session_id === null ? null : $sessions[$c->clock_session_id] ?? null;
            $day = $session !== null ? $session->local_date : $c->proposed_in_at?->setTimezone($zone)->startOfDay();
            $new = match ($c->kind) {
                'change_in' => AttendanceSupport::time($c->proposed_in_at, $zone),
                'set_out' => AttendanceSupport::time($c->proposed_out_at, $zone),
                default => AttendanceSupport::time($c->proposed_in_at, $zone).' – '.AttendanceSupport::time($c->proposed_out_at, $zone),
            };
            $recorded = match ($c->kind) {
                'change_in' => AttendanceSupport::time($session?->clock_in_at, $zone),
                'set_out' => AttendanceSupport::time($session?->clock_out_at, $zone),
                default => null,
            };

            return [
                'publicId' => $c->public_id,
                'person' => (string) ($names[$c->user_id] ?? 'Staff member'),
                'dayLabel' => $day === null ? '' : AttendanceSupport::day($day),
                'branch' => $branches[$c->branch_id]->name ?? '',
                'kind' => $c->kind,
                'kindLabel' => CorrectionService::KINDS[$c->kind] ?? $c->kind,
                'compare' => $c->status === 'pending' ? 'Recorded: '.($recorded ?? 'none').' · New: '.$new : 'New: '.$new,
                'reason' => CorrectionService::REASONS[$c->reason_code] ?? $c->reason_code,
                'note' => $c->note_encrypted,
                'status' => $c->status,
                'statusLabel' => $statuses[$c->status] ?? $c->status,
                'onBehalf' => $c->proposed_by_user_id !== $c->user_id,
                'proposedBy' => (string) ($names[$c->proposed_by_user_id] ?? 'Staff member'),
                'canDecide' => $c->status === 'pending' && $actor->can(ClockPermissions::REVIEW) && $c->user_id !== $actor->id && $c->proposed_by_user_id !== $actor->id,
                'canCancel' => $c->status === 'pending' && $c->proposed_by_user_id === $actor->id,
                'lockVersion' => $c->lock_version,
            ];
        })->values()->all();
    }
}
