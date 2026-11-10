<?php

namespace App\Domain\HR\OpenShifts\Services;

use App\Domain\HR\OpenShifts\OpenShiftPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR-15b page props for /hr/open-shifts. A linked locum sees the open shifts at the branches they are assigned to, and
 * their own asks; the Director and HR Manager see open shifts with every ask, and the filled and withdrawn ones. A
 * locum never sees who else asked.
 */
final class OpenShiftsPageService
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canManage = $actor->can(OpenShiftPermissions::MANAGE);
        $canAsk = $actor->can(OpenShiftPermissions::REQUEST_OWN);
        $tabs = array_values(array_filter([$canAsk ? 'available' : null, $canAsk ? 'mine' : null, $canManage ? 'open' : null, $canManage ? 'closed' : null]));
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], $tabs, true) ? $query['tab'] : ($tabs[0] ?? 'available');
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get();
        $names = $branches->pluck('name', 'id');
        $now = CarbonImmutable::now('UTC');

        $row = fn (\stdClass $s): array => ['id' => (string) $s->public_id, 'branch' => (string) ($names[$s->branch_id] ?? 'Branch'),
            'when' => $this->when($s), 'sessionLabel' => RosterRules::SESSIONS[$s->session_type] ?? (string) $s->session_type,
            'roleLabel' => OpenShiftService::ROLES[$s->role_needed] ?? (string) $s->role_needed, 'breakMinutes' => (int) $s->break_minutes,
            'status' => (string) $s->status, 'lockVersion' => (int) $s->lock_version];

        $props = ['tab' => $tab, 'tabs' => $tabs, 'canManage' => $canManage, 'canAsk' => $canAsk, 'linked' => $canAsk && LocumSignIns::workerFor($actor) !== null,
            'available' => [], 'mine' => [], 'open' => [], 'closed' => [],
            'branches' => array_values($branches->map(fn (Branch $b): array => ['value' => (string) $b->id, 'label' => (string) $b->name])->all()),
            'sessions' => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys(RosterRules::SESSIONS), RosterRules::SESSIONS),
            'roles' => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys(OpenShiftService::ROLES), OpenShiftService::ROLES),
            'declineReasons' => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys(OpenShiftService::DECLINE_REASONS), OpenShiftService::DECLINE_REASONS),
            'maxDaysAhead' => OpenShiftService::MAX_DAYS_AHEAD,
            'today' => CarbonImmutable::now((string) ($branches->first()->timezone ?? 'UTC'))->toDateString()];

        if ($canAsk && $props['linked']) {
            $assigned = $this->assignedBranches($actor);
            $asked = DB::table('hr_open_shift_requests')->where('locum_user_id', $actor->id)->pluck('open_shift_id')->all();
            $props['available'] = array_values(DB::table('hr_open_shifts')->where('organisation_id', $actor->organisation_id)->where('status', 'open')
                ->where('starts_at', '>', $now)->whereIn('branch_id', $assigned)->whereNotIn('id', $asked)->orderBy('starts_at')->limit(100)->get()
                ->map($row)->all());
            $props['mine'] = array_values(DB::table('hr_open_shift_requests as r')->join('hr_open_shifts as s', 's.id', '=', 'r.open_shift_id')
                ->where('r.locum_user_id', $actor->id)->orderByDesc('s.starts_at')->limit(100)
                ->get(['s.*', 'r.public_id as request_id', 'r.status as request_status', 'r.decline_reason', 'r.lock_version as request_lock'])
                ->map(fn (\stdClass $s): array => [...$row($s), 'requestId' => (string) $s->request_id, 'requestStatus' => (string) $s->request_status,
                    'requestStatusLabel' => $this->askLabel((string) $s->request_status, $s->decline_reason), 'requestLockVersion' => (int) $s->request_lock])->all());
        }
        if ($canManage) {
            $shifts = DB::table('hr_open_shifts')->where('organisation_id', $actor->organisation_id)
                ->where(fn ($q) => $tab === 'closed' ? $q->where('status', '<>', 'open') : $q->where('status', 'open'))
                ->orderBy($tab === 'closed' ? 'closed_at' : 'starts_at', $tab === 'closed' ? 'desc' : 'asc')->limit(200)->get();
            $requests = DB::table('hr_open_shift_requests as r')->join('users as u', 'u.id', '=', 'r.locum_user_id')
                ->whereIn('r.open_shift_id', $shifts->pluck('id')->all())->orderBy('r.asked_at')
                ->get(['r.*', 'u.name as locum_name'])->groupBy('open_shift_id');
            $zone = (string) ($branches->first()->timezone ?? 'UTC');
            $props[$tab === 'closed' ? 'closed' : 'open'] = array_values($shifts->map(fn (\stdClass $s): array => [...$row($s),
                'started' => CarbonImmutable::parse((string) $s->starts_at, 'UTC')->lessThanOrEqualTo($now),
                'asks' => array_values(($requests->get($s->id) ?? collect())->map(fn (\stdClass $r): array => ['id' => (string) $r->public_id,
                    'locum' => (string) $r->locum_name, 'askedOn' => CarbonImmutable::parse((string) $r->asked_at, 'UTC')->setTimezone($zone)->format('j M Y, g:i A'),
                    'status' => (string) $r->status, 'statusLabel' => $this->askLabel((string) $r->status, $r->decline_reason),
                    'own' => (int) $r->locum_user_id === $actor->id, 'lockVersion' => (int) $r->lock_version])->all())])->all());
        }

        return $props;
    }

    /** @return list<int> branches with an assignment for the locum that has not ended */
    private function assignedBranches(User $actor): array
    {
        $profile = StaffProfile::query()->where('user_id', $actor->id)->first();
        if ($profile === null) {
            return [];
        }
        $today = CarbonImmutable::now('UTC')->toDateString();

        return array_values(array_map('intval', $profile->branchAssignments()->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->pluck('branch_id')->unique()->values()->all()));
    }

    private function when(\stdClass $s): string
    {
        $starts = CarbonImmutable::parse((string) $s->starts_at, 'UTC')->setTimezone((string) $s->timezone);
        $ends = CarbonImmutable::parse((string) $s->ends_at, 'UTC')->setTimezone((string) $s->timezone);

        return $starts->format('D j M Y, g:i A').' to '.($ends->isSameDay($starts) ? $ends->format('g:i A') : $ends->format('D j M, g:i A'));
    }

    private function askLabel(string $status, ?string $reason): string
    {
        return match ($status) {
            'asked' => 'Asked, waiting for HR',
            'confirmed' => 'Confirmed. It shows on My roster once the week is approved.',
            'withdrawn' => 'Withdrawn by the locum',
            default => match ($reason) {
                'filled' => 'Not chosen: another locum was confirmed',
                'shift_withdrawn' => 'Not needed: HR withdrew the shift',
                default => 'Not chosen: '.(OpenShiftService::DECLINE_REASONS[$reason ?? ''] ?? 'declined'),
            },
        };
    }
}
