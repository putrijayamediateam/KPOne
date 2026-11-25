<?php

namespace App\Domain\HR\Availability\Services;

use App\Domain\HR\Availability\AvailabilityPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-22f page props for the "Free times" tab on /hr/open-shifts. A linked locum sees their own proposals and the branches
 * they are assigned to; a roster planner sees the waiting proposals (with the note, decrypted for HR only) and the latest
 * answered ones. A proposal whose time has ended shows as expired. A locum never sees anyone else's proposals.
 */
final class LocumAvailabilityPageService
{
    public static function canPropose(User $actor): bool
    {
        return $actor->can(AvailabilityPermissions::PROPOSE_OWN);
    }

    public static function canDecide(User $actor): bool
    {
        return $actor->can(RosterPermissions::MANAGE);
    }

    /** @return array<string, mixed> */
    public function page(User $actor): array
    {
        $now = CarbonImmutable::now('UTC');
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get()->keyBy('id');
        $props = ['canPropose' => self::canPropose($actor), 'canDecide' => self::canDecide($actor), 'linked' => false, 'mine' => [], 'waiting' => [],
            'answered' => [], 'branchOptions' => [], 'waitingCount' => 0, 'maxWaiting' => LocumAvailabilityService::MAX_WAITING,
            'maxDaysAhead' => LocumAvailabilityService::MAX_DAYS_AHEAD,
            'declineReasons' => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys(LocumAvailabilityService::DECLINE_REASONS), LocumAvailabilityService::DECLINE_REASONS),
            'stations' => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys(RosterRules::STATIONS), RosterRules::STATIONS),
            'rooms' => []];

        if ($props['canPropose']) {
            $props['linked'] = LocumSignIns::workerFor($actor) !== null;
            $profile = StaffProfile::query()->where('user_id', $actor->id)->first();
            $assigned = $profile === null ? collect() : $profile->branchAssignments()
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now->subDay()->toDateString()))->pluck('branch_id')->unique();
            $props['branchOptions'] = array_values($branches->only($assigned->all())->map(fn (Branch $b): array => ['value' => (string) $b->id, 'label' => (string) $b->name])->all());
            $rows = DB::table('hr_locum_availability')->where('organisation_id', $actor->organisation_id)->where('locum_user_id', $actor->id)
                ->orderByDesc('starts_at')->limit(100)->get();
            $props['mine'] = array_values($rows->map(fn (\stdClass $r): array => $this->row($r, $branches, $now, own: true))->all());
            $props['waitingCount'] = $rows->where('status', 'waiting')->filter(fn (\stdClass $r): bool => CarbonImmutable::parse((string) $r->ends_at, 'UTC')->greaterThan($now))->count();
        }
        if ($props['canDecide']) {
            $waiting = DB::table('hr_locum_availability as a')->join('users as u', 'u.id', '=', 'a.locum_user_id')
                ->where('a.organisation_id', $actor->organisation_id)->where('a.status', 'waiting')->where('a.ends_at', '>', $now)
                ->orderBy('a.starts_at')->orderBy('a.id')->limit(200)->get(['a.*', 'u.name as locum_name']);
            $takesStation = User::query()->with('roles')->whereIn('id', $waiting->pluck('locum_user_id')->unique())->get()
                ->mapWithKeys(fn (User $u): array => [$u->id => RosterRules::takesStation($u)]);
            $props['waiting'] = array_values($waiting->map(fn (\stdClass $r): array => $this->row($r, $branches, $now, own: false)
                + ['takesStation' => (bool) ($takesStation[$r->locum_user_id] ?? false)])->all());
            $props['answered'] = array_values(DB::table('hr_locum_availability as a')->join('users as u', 'u.id', '=', 'a.locum_user_id')
                ->where('a.organisation_id', $actor->organisation_id)->where('a.status', '<>', 'waiting')
                ->orderByDesc('a.updated_at')->orderByDesc('a.id')->limit(50)->get(['a.*', 'u.name as locum_name'])
                ->map(fn (\stdClass $r): array => $this->row($r, $branches, $now, own: false))->all());
            $props['rooms'] = BranchRoom::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'branch_id', 'name'])->groupBy('branch_id')
                ->map(fn ($rooms): array => array_values($rooms->map(fn (BranchRoom $room): array => ['value' => (string) $room->id, 'label' => (string) $room->name])->all()))->all();
        }

        return $props;
    }

    /**
     * @param  Collection<int|string, Branch>  $branches
     * @return array<string, mixed>
     */
    private function row(\stdClass $r, Collection $branches, CarbonImmutable $now, bool $own): array
    {
        $starts = CarbonImmutable::parse((string) $r->starts_at, 'UTC')->setTimezone((string) $r->timezone);
        $ends = CarbonImmutable::parse((string) $r->ends_at, 'UTC')->setTimezone((string) $r->timezone);
        $expired = $r->status === 'waiting' && $ends->lessThanOrEqualTo($now);
        $status = $expired ? 'expired' : (string) $r->status;

        return ['id' => (string) $r->public_id, 'branchId' => (int) $r->branch_id, 'branch' => (string) ($branches->get($r->branch_id)?->name ?? 'Branch'),
            'locum' => $own ? null : (string) ($r->locum_name ?? 'Locum'),
            'when' => $starts->format('D j M Y, g:i A').' to '.($ends->isSameDay($starts) ? $ends->format('g:i A') : $ends->format('D j M, g:i A')),
            'startTime' => $starts->format('H:i'), 'endTime' => $ends->format('H:i'),
            // The note is for the locum and HR; it never reaches anyone else.
            'note' => $r->note_encrypted === null ? null : Crypt::decryptString((string) $r->note_encrypted),
            'status' => $status, 'statusLabel' => $this->label($status, $r->decline_reason, $own), 'lockVersion' => (int) $r->lock_version];
    }

    private function label(string $status, ?string $reason, bool $own): string
    {
        return match ($status) {
            'waiting' => $own ? 'Waiting for HR' : 'Waiting for a decision',
            'expired' => 'Expired',
            'used' => $own ? 'Used. It shows on My roster once the week is approved.' : 'Used, added to the draft roster',
            'withdrawn' => $own ? 'Withdrawn' : 'Withdrawn by the locum',
            default => 'Declined: '.(LocumAvailabilityService::DECLINE_REASONS[$reason ?? ''] ?? 'other reason'),
        };
    }
}
