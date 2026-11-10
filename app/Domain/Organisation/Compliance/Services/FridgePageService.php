<?php

namespace App\Domain\Organisation\Compliance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-11b page props for the Fridge log tab and the fridge part of Settings. Read only. In range, Out of range, Done and
 * Missed are worked out here when the page opens, in the branch-local day, and never stored. A person sees the fridges
 * of the branches they are assigned to (Director and Administrator, who manage fridges, see every branch).
 */
final class FridgePageService
{
    public function __construct(private BranchAccessService $branches, private StaffAccessService $staff) {}

    public function degrees(?int $tenths): ?string
    {
        return $tenths === null ? null : number_format($tenths / 10, 1, '.', '');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function fridgeTab(User $actor, array $query): array
    {
        $manage = $actor->can(CompliancePermissions::FRIDGE_MANAGE);
        $record = $actor->can(CompliancePermissions::FRIDGE_RECORD);
        $review = $actor->can(CompliancePermissions::FRIDGE_REVIEW);
        $fridges = DB::table('compliance_fridges as f')->join('branches as b', 'b.id', '=', 'f.branch_id')->where('f.organisation_id', $actor->organisation_id)->where('f.is_active', true)
            ->orderBy('b.name')->orderBy('f.name')->get(['f.*', 'b.name as branch_name', 'b.timezone as branch_timezone']);
        $assigned = [];
        $visible = $fridges->filter(function ($f) use ($actor, $manage, &$assigned): bool {
            $assigned[$f->branch_id] ??= $this->assigned($actor, (int) $f->branch_id);

            return $manage || $assigned[$f->branch_id];
        })->values();
        $zone = (string) ($visible->first()->branch_timezone ?? 'UTC');
        $month = $this->month($query['month'] ?? null, $zone);
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, $zone);
        $names = $this->staff->visibleUsers($actor);

        $cards = $visible->map(fn ($f): array => $this->card($f, $month, $names, (bool) ($record && ($assigned[$f->branch_id] ?? false)), (bool) ($review && ($assigned[$f->branch_id] ?? false)), $manage))->all();
        $nowLocal = CarbonImmutable::now($zone);

        return [
            'month' => $month,
            'monthLabel' => $first->format('F Y'),
            'previousMonth' => $first->subMonth()->format('Y-m'),
            'nextMonth' => $first->addMonth()->format('Y-m') > $nowLocal->format('Y-m') ? null : $first->addMonth()->format('Y-m'),
            'canRecord' => $record,
            'canReview' => $review,
            'canManage' => $manage,
            'setUp' => $cards !== [],
            'alertCount' => collect($cards)->sum(fn (array $c): int => count($c['alerts'])),
            'fridges' => $cards,
            'reasons' => $this->options(FridgeService::REASONS),
            'actions' => $this->options(FridgeService::ACTIONS),
            'correctionReasons' => $this->options(FridgeService::CORRECTION_REASONS),
        ];
    }

    /** @return array<int, array<string, mixed>> every fridge of the organisation for the Settings tab (archived ones last) */
    public function settingsFridges(User $actor): array
    {
        return DB::table('compliance_fridges as f')->join('branches as b', 'b.id', '=', 'f.branch_id')->where('f.organisation_id', $actor->organisation_id)
            ->orderByDesc('f.is_active')->orderBy('b.name')->orderBy('f.name')->get(['f.*', 'b.name as branch_name'])
            ->map(fn ($f): array => ['id' => (string) $f->public_id, 'lockVersion' => (int) $f->lock_version, 'name' => (string) $f->name, 'branchId' => (int) $f->branch_id,
                'branchName' => (string) $f->branch_name, 'min' => $this->degrees((int) $f->min_tenths), 'max' => $this->degrees((int) $f->max_tenths),
                'readingsPerDay' => (int) $f->readings_per_day, 'expectBy' => $f->expect_by === null ? null : substr((string) $f->expect_by, 0, 5), 'isActive' => (bool) $f->is_active])->values()->all();
    }

    private function assigned(User $actor, int $branchId): bool
    {
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKey($branchId)->first();

        return $branch !== null && $this->branches->hasEffectiveAssignment($actor, $branch);
    }

    private function month(mixed $value, string $zone): string
    {
        $now = CarbonImmutable::now($zone);
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) && $value <= $now->format('Y-m') && $value >= $now->subYears(3)->format('Y-m')) {
            return $value;
        }

        return $now->format('Y-m');
    }

    /** @return array<string, mixed> */
    private function card(\stdClass $f, string $month, mixed $names, bool $canRecord, bool $canReview, bool $canManage): array
    {
        $zone = (string) $f->branch_timezone;
        $min = (int) $f->min_tenths;
        $max = (int) $f->max_tenths;
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, $zone);
        $now = CarbonImmutable::now($zone);
        $monthEnd = $first->endOfMonth();
        $from = $first->startOfMonth()->utc();
        $to = $monthEnd->utc();
        $readings = DB::table('compliance_fridge_readings as r')->leftJoin('compliance_fridge_corrections as c', 'c.reading_id', '=', 'r.id')->leftJoin('compliance_fridge_reviews as v', 'v.reading_id', '=', 'r.id')
            ->where('r.fridge_id', $f->id)->whereBetween('r.taken_at', [$from, $to])->orderByDesc('r.taken_at')
            ->get(['r.*', 'c.reason_code as error_reason', 'v.public_id as review_id', 'v.action_code', 'v.note_encrypted', 'v.created_at as reviewed_at', 'v.reviewed_by_user_id']);
        // Every unreviewed out-of-range reading stays an alert however many in-range readings follow it (judged in SQL against the
        // range kept on the reading, so no row limit can hide one).
        $open = DB::table('compliance_fridge_readings as r')->leftJoin('compliance_fridge_corrections as c', 'c.reading_id', '=', 'r.id')->leftJoin('compliance_fridge_reviews as v', 'v.reading_id', '=', 'r.id')
            ->where('r.fridge_id', $f->id)->whereNull('c.id')->whereNull('v.id')
            ->whereRaw('(r.temp_tenths < r.range_min_tenths OR r.temp_tenths > r.range_max_tenths OR r.low_tenths < r.range_min_tenths OR r.low_tenths > r.range_max_tenths OR r.high_tenths < r.range_min_tenths OR r.high_tenths > r.range_max_tenths)')
            ->orderByDesc('r.taken_at')->limit(200)->get(['r.*']);
        $userIds = $readings->pluck('entered_by_user_id')->merge($readings->pluck('reviewed_by_user_id'))->merge($open->pluck('entered_by_user_id'))->filter()->unique();
        $people = $names->whereIn('id', $userIds)->pluck('name', 'id');
        $view = fn ($r): array => [
            'id' => (string) $r->public_id, 'takenAt' => CarbonImmutable::parse((string) $r->taken_at)->utc()->toIso8601String(), 'temp' => $this->degrees((int) $r->temp_tenths),
            'low' => $this->degrees($r->low_tenths === null ? null : (int) $r->low_tenths), 'high' => $this->degrees($r->high_tenths === null ? null : (int) $r->high_tenths),
            'rangeMin' => $this->degrees((int) $r->range_min_tenths), 'rangeMax' => $this->degrees((int) $r->range_max_tenths), 'outOfRange' => FridgeService::outOfRange($r),
            'reason' => $r->reason_code === null ? null : (FridgeService::REASONS[$r->reason_code] ?? 'Other'), 'enteredBy' => (string) ($people[$r->entered_by_user_id] ?? 'Staff member'),
        ];
        $recent = $readings->take(40)->map(function ($r) use ($view, $people): array {
            return $view($r) + [
                'errorReason' => $r->error_reason === null ? null : (FridgeService::CORRECTION_REASONS[$r->error_reason] ?? 'Other'),
                'review' => $r->review_id === null ? null : ['action' => FridgeService::ACTIONS[$r->action_code] ?? 'Other', 'note' => $this->decrypt($r->note_encrypted), 'by' => (string) ($people[$r->reviewed_by_user_id] ?? 'Staff member'),
                    'at' => CarbonImmutable::parse((string) $r->reviewed_at)->utc()->toIso8601String()],
            ];
        })->values()->all();

        // Day by day for the month, in the branch-local day. A fridge added mid-month shows days from its first day.
        $created = CarbonImmutable::parse((string) $f->created_at)->setTimezone($zone)->startOfDay();
        $days = [];
        $good = $readings->whereNull('error_reason')->groupBy(fn ($r): string => CarbonImmutable::parse((string) $r->taken_at)->setTimezone($zone)->toDateString());
        for ($d = $first->startOfMonth(); $d <= $monthEnd && $d <= $now; $d = $d->addDay()) {
            if ($d < $created) {
                continue;
            }
            $date = $d->toDateString();
            $list = $good->get($date, collect());
            $count = $list->count();
            $isToday = $date === $now->toDateString();
            $cutoff = $f->expect_by === null ? null : CarbonImmutable::parse($date.' '.substr((string) $f->expect_by, 0, 5), $zone);
            $missed = $count < (int) $f->readings_per_day && (! $isToday || ($cutoff !== null && $now > $cutoff));
            $values = $list->flatMap(fn ($r): array => array_filter([(int) $r->temp_tenths, $r->low_tenths === null ? null : (int) $r->low_tenths, $r->high_tenths === null ? null : (int) $r->high_tenths], fn ($v): bool => $v !== null))->all();
            $days[] = ['date' => $date, 'count' => $count, 'lowest' => $values === [] ? null : $this->degrees(min($values)), 'highest' => $values === [] ? null : $this->degrees(max($values)),
                'outOfRange' => $list->contains(fn ($r): bool => FridgeService::outOfRange($r)), 'missed' => $missed, 'isToday' => $isToday];
        }
        $todayCount = ($good->get($now->toDateString()) ?? collect())->count();

        return [
            'id' => (string) $f->public_id, 'lockVersion' => (int) $f->lock_version, 'name' => (string) $f->name, 'branchId' => (int) $f->branch_id, 'branchName' => (string) $f->branch_name, 'timezone' => $zone,
            'min' => $this->degrees($min), 'max' => $this->degrees($max), 'readingsPerDay' => (int) $f->readings_per_day, 'expectBy' => $f->expect_by === null ? null : substr((string) $f->expect_by, 0, 5),
            'canRecord' => $canRecord, 'canReview' => $canReview, 'canManage' => $canManage,
            'todayCount' => $todayCount, 'alerts' => $open->map(fn ($r): array => $view($r))->values()->all(), 'recent' => $recent, 'days' => array_reverse($days),
        ];
    }

    /**
     * @param  array<string, string>  $map
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $map): array
    {
        return collect($map)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
