<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimAmounts;
use App\Domain\Visit\Billing\Services\PanelClaimEligibility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PanelSummaryReportService extends BranchReportService
{
    protected function supportsFilters(): bool
    {
        return false;
    }

    public function key(): string
    {
        return 'panel_summary';
    }

    public function title(): string
    {
        return 'Panel summary';
    }

    public function basis(): string
    {
        return 'Invoice finalisation-date cohort: current approved Panel responsibility on finalised, non-void invoices, including correction holds. Claim figures are current recorded amounts, not invoice adjustments. Claimed means a first submitted event exists; never-submitted prepared packs remain unclaimed. Accepted is the recorded accepted amount; Rejected is current fully rejected claim amount (not a partial-decision remainder). Remitted is recorded claim payments, not patient receipts. Outstanding is accepted less remitted, floored at zero, matching claim detail; unaccepted claims are not accepted receivables. Age as of branch-local today, from first submitted date; unclaimed responsibility ages separately from invoice finalisation. Buckets: 0-30, 31-60, 61-90, over 90 days. MYR. Current Panel names; no patient content. Only Panels with qualifying invoices appear.';
    }

    protected function project(User $actor, Branch $branch, array $filters): array
    {
        $groups = [];
        $query = app(PanelClaimEligibility::class)->approvedAllocations($actor->organisation_id, $branch->id)
            ->where('i.finalized_at', '>=', CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc())
            ->where('i.finalized_at', '<', CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc())
            ->orderByRaw('pn.name COLLATE "C"')->orderBy('c.id')->select(['c.id', 'c.panel_id', 'c.amount_sen', 'pn.name', 'i.finalized_at']);
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $query->chunk(500, function ($allocations) use ($actor, $branch, $today, &$groups): void {
            $claims = PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('coverage_allocation_id', $allocations->pluck('id'))->get(['id', 'organisation_id', 'branch_id', 'coverage_allocation_id', 'claim_amount_sen', 'accepted_amount_sen', 'status'])->keyBy('coverage_allocation_id');
            $submittedDates = DB::table('panel_claim_events')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('panel_claim_id', $claims->pluck('id'))->where('event_type', 'submitted')->selectRaw('panel_claim_id, MIN(occurred_at) AS first_submitted')->groupBy('panel_claim_id')->pluck('first_submitted', 'panel_claim_id');
            $payments = DB::table('panel_claim_payments')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('panel_claim_id', $claims->pluck('id'))->selectRaw('panel_claim_id, SUM(amount_sen) AS paid')->groupBy('panel_claim_id')->pluck('paid', 'panel_claim_id');
            foreach ($allocations as $allocation) {
                $id = (int) $allocation->panel_id;
                $group = $groups[$id] ?? ['name' => (string) $allocation->name, 'values' => array_fill(0, 18, 0)];
                $group['values'][0]++;
                $group['values'][1] += (int) $allocation->amount_sen;
                $claim = $claims->get($allocation->id);
                $submitted = $claim ? $submittedDates->get($claim->id) : null;
                if ($claim && $submitted !== null) {
                    $paid = (int) $payments->get($claim->id, 0);
                    $money = PanelClaimAmounts::summary($claim, $paid);
                    $group['values'][2]++;
                    foreach (['claimed' => 3, 'accepted' => 6, 'rejected' => 7, 'remitted' => 8, 'outstanding' => 9] as $key => $index) {
                        $group['values'][$index] += $money[$key];
                    }
                    $index = $this->bucket(CarbonImmutable::parse((string) $submitted, 'UTC')->setTimezone($branch->timezone), $today);
                    $group['values'][10 + $index] = ($group['values'][10 + $index] ?? 0) + $money['outstanding'];
                } else {
                    $group['values'][4]++;
                    $group['values'][5] += (int) $allocation->amount_sen;
                    $index = $this->bucket(CarbonImmutable::parse((string) $allocation->finalized_at, 'UTC')->setTimezone($branch->timezone), $today);
                    $group['values'][14 + $index] = ($group['values'][14 + $index] ?? 0) + (int) $allocation->amount_sen;
                }
                $groups[$id] = $group;
            }
        });
        $columns = ['Panel', 'Approved invoices', 'Approved responsibility (MYR)', 'Claimed invoices', 'Claimed current (MYR)', 'Unclaimed invoices', 'Unclaimed responsibility (MYR)', 'Accepted (MYR)', 'Fully rejected current (MYR)', 'Remitted (MYR)', 'Accepted outstanding (MYR)', 'Claim outstanding 0-30 days (MYR)', 'Claim outstanding 31-60 days (MYR)', 'Claim outstanding 61-90 days (MYR)', 'Claim outstanding over 90 days (MYR)', 'Unclaimed 0-30 days (MYR)', 'Unclaimed 31-60 days (MYR)', 'Unclaimed 61-90 days (MYR)', 'Unclaimed over 90 days (MYR)'];
        $sums = array_fill(0, 18, 0);
        $rows = [];
        foreach ($groups as $group) {
            $values = [];
            for ($i = 0; $i < 18; $i++) {
                $number = $group['values'][$i] ?? 0;
                $sums[$i] += $number;
                $values[] = in_array($i, [0, 2, 4], true) ? (string) $number : ReportCsv::money($number);
            }
            $rows[] = [$group['name'], ...$values];
        }
        $totals = [];
        foreach ($sums as $i => $value) {
            $totals[$columns[$i + 1]] = in_array($i, [0, 2, 4], true) ? (string) $value : ReportCsv::money($value);
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    private function bucket(CarbonImmutable $date, CarbonImmutable $today): int
    {
        $age = max(0, (int) $date->startOfDay()->diffInDays($today, false));

        return $age <= 30 ? 0 : ($age <= 60 ? 1 : ($age <= 90 ? 2 : 3));
    }
}
