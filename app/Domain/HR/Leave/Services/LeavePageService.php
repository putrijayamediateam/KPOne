<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveAdjustment;
use App\Domain\HR\Leave\Models\LeaveBalanceEntry;
use App\Domain\HR\Leave\Models\LeaveEvidenceFile;
use App\Domain\HR\Leave\Models\LeaveOpening;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for /hr/leave. My leave shows the person's own balances and requests (their own reason text only);
 * Approvals never lists the approver's own requests; the team calendar and the roster show "On leave" only, never the
 * type or reason. Approvers see the type (they decide it) but never the reason text outside the request they decide.
 */
final class LeavePageService
{
    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public function __construct(private LeaveBalanceService $balances, private LeaveSettingsService $settings, private LeaveCalendar $calendar) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canOwn = $actor->can(LeavePermissions::REQUEST_OWN);
        $canApprove = $actor->can(LeavePermissions::APPROVE);
        $canManage = $actor->can(LeavePermissions::MANAGE);
        abort_unless($canOwn || $canApprove || $canManage, 403);
        $tabs = array_values(array_filter([$canOwn ? 'my' : null, $canApprove ? 'approvals' : null, $canManage ? 'calendar' : null, $canManage ? 'settings' : null]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? $query['tab'] : ($tabs[0] ?? 'my');
        $today = $this->settings->today($actor);
        $types = $this->settings->types($actor->organisation_id);
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->get();
        $branch = $branches->firstWhere('id', filter_var($query['branch'] ?? null, FILTER_VALIDATE_INT)) ?? $branches->first();
        $year = filter_var($query['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: $today->year;
        $pending = $canApprove ? $this->pendingForApprover($actor) : collect();

        return [
            'tab' => $tab, 'tabs' => $tabs, 'today' => $today->toDateString(), 'year' => $year,
            'setUp' => $types->contains(fn (LeaveType $t): bool => $t->is_active),
            'approvalCount' => $pending->count(),
            'branches' => $branches->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'branchId' => $branch?->id,
            'my' => $canOwn ? $this->my($actor, $types, $today) : null,
            'approvals' => $canApprove && $tab === 'approvals' ? $this->approvals($actor, $pending, $today) : null,
            'calendar' => $canManage && $tab === 'calendar' && $branch !== null ? $this->teamCalendar($branch, $query['month'] ?? null, $today) : null,
            'settings' => $canManage && $tab === 'settings' ? $this->settingsPage($actor, $types, $branches, $branch, $year) : null,
            'rejectReasons' => $this->options(LeaveRequestService::REJECT_REASONS),
            'cancelReasons' => $this->options(LeaveRequestService::CANCEL_REASONS),
        ];
    }

    /**
     * @param  Collection<int, LeaveType>  $types
     * @return array<string, mixed>
     */
    private function my(User $actor, Collection $types, CarbonImmutable $today): array
    {
        $active = $types->filter(fn (LeaveType $t): bool => $t->is_active)->values();
        $requests = LeaveRequest::query()->where('user_id', $actor->id)->orderByDesc('start_date')->orderByDesc('id')->limit(50)->get();
        $branchZones = Branch::query()->whereIn('id', $requests->pluck('branch_id')->unique())->pluck('timezone', 'id');
        $typeNames = $types->pluck('name', 'id');

        return [
            'balances' => $this->balances->summary($actor, $active->sortBy('name'), $today->year, $today),
            'types' => $active->map(fn (LeaveType $t): array => ['id' => $t->id, 'name' => $t->name, 'allowHalfDays' => $t->allow_half_days,
                'evidenceNeeded' => $t->evidence_needed, 'usesBalance' => $t->uses_balance, 'acceptsFiles' => $t->accepts_evidence_files,
                'retentionDays' => $t->accepts_evidence_files ? $t->evidence_retention_days : null])->values()->all(),
            'requests' => (function () use ($requests, $typeNames, $branchZones, $types, $actor): array {
                $files = $this->filesFor($requests);

                return $requests->map(function (LeaveRequest $r) use ($typeNames, $branchZones, $types, $actor, $files): array {
                    $zone = (string) ($branchZones[$r->branch_id] ?? 'UTC');
                    $today = CarbonImmutable::now($zone)->toDateString();
                    $canCancel = $r->status === 'pending' || ($r->status === 'approved' && $r->start_date->toDateString() > $today);

                    return [...$this->requestRow($r, (string) ($typeNames[$r->leave_type_id] ?? 'Leave')), 'reason' => $r->reason_encrypted, 'canCancel' => $canCancel,
                        'evidence' => $this->evidence($r, $types->firstWhere('id', $r->leave_type_id), $actor, true, $zone, $files)];
                })->values()->all();
            })(),
        ];
    }

    /** @return Collection<int, LeaveRequest> */
    private function pendingForApprover(User $actor): Collection
    {
        return LeaveRequest::query()->with(['person.roles', 'type'])->where('organisation_id', $actor->organisation_id)->where('status', 'pending')
            ->where('user_id', '<>', $actor->id)->orderBy('start_date')->orderBy('id')->limit(200)->get();
    }

    /**
     * @param  Collection<int, LeaveRequest>  $pending
     * @return array<string, mixed>
     */
    private function approvals(User $actor, Collection $pending, CarbonImmutable $today): array
    {
        $zones = Branch::query()->where('organisation_id', $actor->organisation_id)->pluck('timezone', 'id');
        $approvedRequests = LeaveRequest::query()->with(['person.roles', 'type'])->where('organisation_id', $actor->organisation_id)->where('status', 'approved')
            ->where('user_id', '<>', $actor->id)->whereDate('end_date', '>=', $today->subDays(30)->toDateString())->orderBy('start_date')->limit(100)->get();
        // HR-2b: other people's files only for holders of the evidence view permission.
        $canSee = $actor->can(LeavePermissions::EVIDENCE_VIEW);
        $files = $canSee ? $this->filesFor($pending->concat($approvedRequests)) : collect();
        // The warning only counts files, so it is the same for approvers who cannot open them.
        $attached = $pending->isEmpty() ? collect() : LeaveEvidenceFile::query()->whereIn('leave_request_id', $pending->pluck('id'))
            ->where('status', 'active')->selectRaw('leave_request_id, count(*) as n')->groupBy('leave_request_id')->pluck('n', 'leave_request_id');
        $rows = $pending->map(function (LeaveRequest $r) use ($zones, $actor, $files, $canSee, $attached): array {
            $zone = (string) ($zones[$r->branch_id] ?? 'UTC');
            $warnings = [];
            $shifts = $this->approvedShifts($r, $zone);
            if ($shifts > 0) {
                $warnings[] = 'Has '.($shifts === 1 ? '1 approved shift' : "{$shifts} approved shifts").' in this period. Change the roster after approving.';
            }
            if ((int) ($attached[$r->id] ?? 0) > 0) {
                $warnings[] = 'Evidence attached: check it';
            } elseif ($r->type->evidence_needed) {
                // Daniel, HR-2b: a reference alone still needs checking; nothing at all is the real gap.
                $warnings[] = $r->evidence_reference_encrypted !== null && $r->evidence_reference_encrypted !== '' ? 'Evidence reference given: check it' : 'Evidence needed';
            }
            $sentOn = CarbonImmutable::parse($r->created_at)->setTimezone($zone)->startOfDay();
            $notice = (int) $sentOn->diffInDays(CarbonImmutable::parse($r->start_date->toDateString(), $zone), false);
            if ($notice <= 0) {
                $warnings[] = 'Sent on or after the start date';
            } elseif ($notice === 1) {
                $warnings[] = 'Sent 1 day before the start';
            }
            $left = $r->type->uses_balance ? $this->balances->left($r->user_id, $r->leave_type_id, $r->year, $r->id) - $r->halves : null;

            return [...$this->requestRow($r, $r->type->name), 'person' => $r->person->name ?? 'Staff member', 'role' => $this->roleLabel($r->person),
                'evidenceNeeded' => $r->type->evidence_needed, 'evidenceReference' => $r->evidence_reference_encrypted, 'reason' => $r->reason_encrypted,
                'leftAfter' => $left === null ? null : LeaveSupport::days($left), 'warnings' => $warnings,
                // When the request was sent (ISO, UTC), so the HR space can show how long the oldest has waited.
                'sentAt' => CarbonImmutable::parse($r->created_at)->utc()->toIso8601String(),
                'evidence' => $canSee ? $this->evidence($r, $r->type, $actor, false, $zone, $files) : null];
        })->values()->all();

        $approved = $approvedRequests->map(fn (LeaveRequest $r): array => [...$this->requestRow($r, $r->type->name), 'person' => $r->person->name ?? 'Staff member',
            'role' => $this->roleLabel($r->person), 'evidence' => $canSee ? $this->evidence($r, $r->type, $actor, false, (string) ($zones[$r->branch_id] ?? 'UTC'), $files) : null])
            ->values()->all();

        return ['pending' => $rows, 'approved' => $approved];
    }

    /** @return array<string, mixed> */
    private function teamCalendar(Branch $branch, mixed $month, CarbonImmutable $today): array
    {
        $start = is_string($month) && preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01') : false;
        $start = $start instanceof CarbonImmutable ? $start : $today->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $working = $this->calendar->workingDays($branch) ?? [];
        $holidays = $this->calendar->holidays($branch, $start, $end);
        $days = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $days[] = ['date' => $date, 'day' => $d->day, 'weekday' => $d->format('D'), 'label' => $d->format('D j M'),
                'holiday' => $holidays[$date] ?? null, 'working' => in_array($d->dayOfWeekIso, $working, true)];
        }
        $requests = LeaveRequest::query()->with('person')->where('branch_id', $branch->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->orderBy('start_date')->get();
        $rows = [];
        foreach ($requests as $r) {
            $row = $rows[$r->user_id] ?? ['name' => $r->person->name ?? 'Staff member', 'cells' => []];
            for ($d = CarbonImmutable::parse($r->start_date->toDateString()); $d->lessThanOrEqualTo(CarbonImmutable::parse($r->end_date->toDateString())); $d = $d->addDay()) {
                $date = $d->toDateString();
                if ($date < $start->toDateString() || $date > $end->toDateString() || isset($holidays[$date]) || ! in_array($d->dayOfWeekIso, $working, true)) {
                    continue;
                }
                $half = ($r->start_date->toDateString() === $date && $r->start_part !== 'full') || ($r->end_date->toDateString() === $date && $r->end_part === 'am');
                $row['cells'][$date] = isset($row['cells'][$date]) || ! $half ? 'full' : 'half';
            }
            $rows[$r->user_id] = $row;
        }
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['cells'] !== []));
        usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['month' => $start->format('Y-m'), 'monthLabel' => $start->format('F Y'), 'previous' => $start->subMonth()->format('Y-m'), 'next' => $start->addMonth()->format('Y-m'),
            'workingDaysSet' => $working !== [], 'days' => $days, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, LeaveType>  $types
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    private function settingsPage(User $actor, Collection $types, Collection $branches, ?Branch $branch, int $year): array
    {
        $people = User::query()->with('roles')->whereIn('id', $this->settings->humanIds($actor->organisation_id))->orderBy('name')->get();
        $names = User::query()->where('organisation_id', $actor->organisation_id)->pluck('name', 'id');
        $typeNames = $types->pluck('name', 'id');
        $rows = $this->settings->entitlementRows($actor->organisation_id, $year);
        $entry = fn (LeaveBalanceEntry $e, string $kind): array => ['publicId' => $e->public_id, 'kind' => $kind, 'person' => (string) ($names[$e->user_id] ?? 'Staff member'),
            'type' => (string) ($typeNames[$e->leave_type_id] ?? 'Leave'), 'year' => $e->year, 'days' => LeaveSupport::days($e->halves),
            'reason' => $kind === 'adjustment' ? (LeaveEntryService::ADJUSTMENT_REASONS[$e->reason_code ?? ''] ?? null) : null,
            'note' => $kind === 'adjustment' ? $e->note_encrypted : null, 'status' => $e->status,
            'enteredBy' => (string) ($names[$e->entered_by_user_id] ?? 'Staff member'), 'canDecide' => $e->status === 'pending' && $e->entered_by_user_id !== $actor->id,
            'lockVersion' => $e->lock_version];
        $entries = collect([
            ...LeaveOpening::query()->where('organisation_id', $actor->organisation_id)->where(fn ($q) => $q->where('status', 'pending')->orWhere('year', $year))->orderByDesc('id')->limit(100)->get()->map(fn ($e) => $entry($e, 'opening')),
            ...LeaveAdjustment::query()->where('organisation_id', $actor->organisation_id)->where(fn ($q) => $q->where('status', 'pending')->orWhere('year', $year))->orderByDesc('id')->limit(100)->get()->map(fn ($e) => $entry($e, 'adjustment')),
        ]);
        $working = DB::table('hr_branch_working_days')->where('organisation_id', $actor->organisation_id)->get()->keyBy('branch_id');
        $holidayRows = $branch === null ? collect() : DB::table('hr_public_holidays')->where('branch_id', $branch->id)
            ->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])->orderBy('holiday_date')->get();

        return [
            'types' => $types->map(fn (LeaveType $t): array => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'isPaid' => $t->is_paid, 'usesBalance' => $t->uses_balance,
                'allowHalfDays' => $t->allow_half_days, 'evidenceNeeded' => $t->evidence_needed, 'allowNegative' => $t->allow_negative,
                'carryMaxDays' => $t->carry_max_halves === null ? '' : LeaveSupport::days($t->carry_max_halves), 'carryExpiresOn' => $t->carry_expires_on ?? '',
                'isActive' => $t->is_active, 'acceptsEvidenceFiles' => $t->accepts_evidence_files, 'evidenceRetentionDays' => $t->evidence_retention_days === null ? '' : (string) $t->evidence_retention_days,
                'lockVersion' => $t->lock_version])->values()->all(),
            'roles' => collect(RosterPermissions::HUMAN_ROLES)->map(fn (string $r): array => ['value' => $r, 'label' => RosterRules::ROLE_LABELS[$r] ?? $r])->values()->all(),
            'entitlements' => collect($rows)->map(function (int $halves, string $key) use ($names): array {
                [$typeId, $who] = explode(':', $key, 2);
                $isPerson = str_starts_with($who, 'u');

                return ['leaveTypeId' => (int) $typeId, 'role' => $isPerson ? null : $who, 'userId' => $isPerson ? (int) substr($who, 1) : null,
                    'person' => $isPerson ? (string) ($names[(int) substr($who, 1)] ?? 'Staff member') : null, 'days' => LeaveSupport::days($halves)];
            })->values()->all(),
            'grantPreview' => $this->settings->grantPreview($actor, $year),
            'people' => $people->map(fn (User $u): array => ['id' => $u->id, 'label' => trim($u->name.' · '.$this->roleLabel($u), ' ·')])->values()->all(),
            'workingDays' => $branches->map(fn (Branch $b): array => ['branchId' => $b->id, 'branch' => $b->name,
                'weekdays' => $working->has($b->id) ? array_map('intval', json_decode((string) $working[$b->id]->weekdays, true) ?: []) : [],
                'lockVersion' => $working->has($b->id) ? (int) $working[$b->id]->lock_version : null])->values()->all(),
            'holidays' => $holidayRows->map(fn ($h): array => ['id' => $h->id, 'date' => substr((string) $h->holiday_date, 0, 10),
                'label' => CarbonImmutable::parse(substr((string) $h->holiday_date, 0, 10))->format('D j M Y'), 'name' => $h->name])->values()->all(),
            'entries' => $entries->sortBy(fn (array $e): string => ($e['status'] === 'pending' ? '0' : '1').$e['person'])->values()->all(),
            'adjustmentReasons' => $this->options(LeaveEntryService::ADJUSTMENT_REASONS),
        ];
    }

    /**
     * @param  Collection<int, LeaveRequest>  $requests
     * @return Collection<int, Collection<int, LeaveEvidenceFile>> active files by request id
     */
    private function filesFor(Collection $requests): Collection
    {
        $ids = $requests->pluck('id')->unique()->values();

        return $ids->isEmpty() ? collect() : LeaveEvidenceFile::query()->whereIn('leave_request_id', $ids)->where('status', 'active')->orderBy('id')->get()->groupBy('leave_request_id');
    }

    /**
     * HR-2b: the evidence line of one request. Labels never carry a file name or anything from the file.
     *
     * @param  Collection<int, Collection<int, LeaveEvidenceFile>>  $files
     * @return array<string, mixed>|null
     */
    private function evidence(LeaveRequest $r, ?LeaveType $type, User $actor, bool $own, string $zone, Collection $files): ?array
    {
        $list = $files->get($r->id, collect());
        if ($type === null || (! $type->accepts_evidence_files && $list->isEmpty())) {
            return null;
        }
        $open = in_array($r->status, ['pending', 'approved'], true);
        $today = CarbonImmutable::now($zone)->toDateString();
        $ownWindow = $r->status === 'pending' || ($r->status === 'approved'
            && $today <= CarbonImmutable::parse($r->end_date->toDateString())->addDays(LeaveEvidenceService::ADD_DAYS_AFTER_END)->toDateString());
        $manage = ! $own && $actor->can(LeavePermissions::MANAGE);
        $names = User::query()->whereIn('id', $list->pluck('uploaded_by_user_id')->unique())->pluck('name', 'id');
        $counter = ['photo' => 0, 'pdf' => 0];

        return [
            'acceptsFiles' => $type->accepts_evidence_files,
            'retentionDays' => $type->evidence_retention_days,
            'canAdd' => $type->accepts_evidence_files && $open && $list->count() < LeaveEvidenceService::MAX_FILES && ($own ? $ownWindow : $manage),
            'files' => $list->values()->map(function (LeaveEvidenceFile $f) use (&$counter, $names, $zone, $own, $manage, $r): array {
                $group = $f->kind === 'pdf' ? 'pdf' : 'photo';
                $counter[$group]++;

                return ['publicId' => $f->public_id, 'kind' => $f->kind, 'label' => ($group === 'pdf' ? 'PDF ' : 'Photo ').$counter[$group],
                    'size' => $f->bytes >= 1048576 ? round($f->bytes / 1048576, 1).' MB' : max(1, (int) round($f->bytes / 1024)).' KB',
                    'addedBy' => (string) ($names[$f->uploaded_by_user_id] ?? 'Staff member'), 'onBehalf' => $f->on_behalf,
                    'addedOn' => CarbonImmutable::parse($f->created_at)->setTimezone($zone)->format('j M Y'),
                    'url' => route('hr.leave.evidence.show', $f->public_id), 'lockVersion' => $f->lock_version,
                    'canRemove' => $own ? $r->status === 'pending' : $manage];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function requestRow(LeaveRequest $r, string $typeName): array
    {
        $start = $r->start_date;
        $end = $r->end_date;
        $parts = ['am' => ' (morning)', 'pm' => ' (afternoon)', 'full' => ''];
        $label = $start->equalTo($end)
            ? $start->format('D j M Y').$parts[$r->start_part]
            : $start->format('j M').$parts[$r->start_part].' – '.$end->format('j M Y').($r->end_part === 'am' ? ' (morning)' : '');

        return ['publicId' => $r->public_id, 'dates' => $label, 'startDate' => $start->toDateString(), 'endDate' => $end->toDateString(), 'type' => $typeName,
            'days' => LeaveSupport::days($r->halves), 'status' => $r->status, 'statusLabel' => self::STATUS_LABELS[$r->status] ?? $r->status, 'lockVersion' => $r->lock_version];
    }

    private function approvedShifts(LeaveRequest $r, string $zone): int
    {
        [$from, $to] = $this->calendar->utcSlots($r, $zone);

        return DB::table('hr_roster_shifts as s')->join('hr_roster_versions as v', 'v.id', '=', 's.roster_version_id')
            ->where('s.staff_user_id', $r->user_id)->where('v.status', 'approved')
            ->where('s.starts_at', '<', $to->toIso8601String())->where('s.ends_at', '>', $from->toIso8601String())->count();
    }

    private function roleLabel(?User $user): string
    {
        $roles = $user?->roles->pluck('name')->all() ?? [];
        foreach (RosterRules::ROLE_LABELS as $role => $label) {
            if (in_array($role, $roles, true)) {
                return $label;
            }
        }

        return '';
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value:string, label:string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($labels), array_values($labels));
    }
}
