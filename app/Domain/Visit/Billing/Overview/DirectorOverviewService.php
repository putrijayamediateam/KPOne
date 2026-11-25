<?php

namespace App\Domain\Visit\Billing\Overview;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\InvoiceSalesProjection;
use App\Domain\Shared\Services\PanelSummaryReportService;
use App\Domain\Visit\Billing\Models\SalesTarget;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Domain\Visit\Billing\Services\SalesTargetService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * BIL-31: the Director and Finance overview (owner item 32 and P8; design note revision 2 and the owner's answers,
 * all A). Read only, counts and amounts only, for every active branch of the organisation.
 *
 * Every figure comes from the code behind the page it links to: net sales (InvoiceSalesProjection, as Insights and
 * Sales targets), target progress (SalesTargetService), Panel money owed (PanelSummaryReportService::totals), the
 * daily close status (CashClosingService::status) and the waiting counts (OverviewWaitingCounts).
 */
final class DirectorOverviewService
{
    public const PERMISSION = 'insights.overview.view.organisation';

    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private SalesTargetService $targets,
        private PanelSummaryReportService $panelSummary,
        private CashClosingService $closings,
        private OverviewWaitingCounts $waiting,
    ) {}

    /** @return array<string, mixed> */
    public function page(User $actor): array
    {
        // INS-1 (owner, 24 Nov 2026): the overview stands on its own permission, not on Insights page access.
        if (! $actor->is_active || ! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException('You may not perform this operation.');
        }
        $active = $this->branches->activeBranch($actor);
        abort_unless($active && $active->organisation_id === $actor->organisation_id, 404);
        // The usual actor rule: an assignment at the active branch that is effective today (Kumar, design review F10).
        if (! $this->authority->wouldAllow($actor, $active, self::PERMISSION)) {
            throw new AuthorizationException('You may not perform this operation.');
        }
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('code')->get();
        $waiting = $this->waiting->counts($actor->organisation_id, array_values($branches->pluck('id')->map(fn ($id): int => (int) $id)->all()));

        $rows = $branches->map(function (Branch $branch) use ($actor, $waiting): array {
            $now = CarbonImmutable::now($branch->timezone);
            $today = $now->startOfDay();
            $monthStart = $today->startOfMonth();
            $net = fn (CarbonImmutable $from, CarbonImmutable $to): int => InvoiceSalesProjection::netSales($actor->organisation_id, [$branch->id => ['start' => $from->utc(), 'end' => $to->utc()]])['netSen'];
            // Each part of net sales counts on its own day. Month to yesterday is read directly (Kumar F4), so a sale
            // committed between two reads cannot make it short.
            $monthSoFar = $net($monthStart, $now);
            $todaySoFar = $net($today, $now);
            $monthToYesterday = $net($monthStart, $today);
            $fullDays = $today->day - 1;
            $lastStart = $monthStart->subMonthNoOverflow();
            $lastDays = min($fullDays, $lastStart->daysInMonth);
            $target = SalesTarget::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereDate('month', $monthStart->toDateString())->whereNull('doctor_user_id')->first();

            return [
                'branchId' => $branch->id,
                'branch' => $branch->name,
                'code' => $branch->code,
                'sales' => [
                    'todaySoFarSen' => $todaySoFar,
                    'monthToYesterdaySen' => $fullDays === 0 ? null : $monthToYesterday,
                    'lastMonthSameDaysSen' => $lastDays === 0 ? null : $net($lastStart, $lastStart->addDays($lastDays)),
                    'lastMonthFrom' => $lastDays === 0 ? null : $lastStart->toDateString(),
                    'lastMonthTo' => $lastDays === 0 ? null : $lastStart->addDays($lastDays - 1)->toDateString(),
                ],
                'target' => $this->targets->progress($branch, $monthStart, $target?->amount_sen, $monthSoFar),
                'panel' => $this->panelSummary->totals($actor->organisation_id, $branch),
                'yesterdayClose' => $this->closings->status($branch, $today->subDay()),
                'waiting' => $waiting['branches'][$branch->id] ?? array_fill_keys(OverviewWaitingCounts::BRANCH_KINDS, 0),
            ];
        })->values()->all();

        return [
            'month' => CarbonImmutable::now($active->timezone)->format('Y-m'),
            'branches' => $rows,
            'organisationWaiting' => $waiting['organisation'],
            'canSetTargets' => $actor->can(SalesTargetService::MANAGE),
        ];
    }
}
