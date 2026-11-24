<?php

namespace App\Domain\Shared\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
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

    public function basisLines(): array
    {
        return [
            ['label' => 'Invoices', 'text' => 'The current approved Panel share on finalised invoices that are not void, including ones on a correction hold, counted on the day each invoice was finalised. Only Panels with invoices in the period appear.'],
            ['label' => 'Claims', 'text' => 'Claim figures are the amounts recorded on the claims now. Claimed means the claim was submitted at least once; a claim pack prepared but never submitted still counts as unclaimed.'],
            ['label' => 'Decisions and payments', 'text' => 'Accepted is what the Panel accepted; Rejected is the amount of claims the Panel fully rejected (not the unpaid part of a partial decision). Remitted is what the Panel has paid on claims, not patient receipts.'],
            ['label' => 'Outstanding', 'text' => 'Accepted less remitted, never below zero, as on the claim page; a claim not yet accepted is not counted as owed. Awaiting decision is the amount of submitted or queried claims with no Panel decision yet; it is not part of Outstanding.'],
            ['label' => 'Ages', 'text' => 'Counted up to today (branch time) from the first submitted date, for Outstanding and Awaiting decision; an unclaimed share ages from the day its invoice was finalised. Age groups: 0-30, 31-60, 61-90 and over 90 days.'],
            ['label' => 'Notes', 'text' => 'Amounts in MYR. Current Panel names; no patient details.'],
        ];
    }

    public function basisSummary(): string
    {
        return 'Invoices count on the day they were finalised; claim figures and ages are as they stand today.';
    }

    /**
     * BIL-31 (design note 2.2): Panel money a branch is still owed, over every finalised Panel invoice up to now, from
     * the same per-allocation adding-up as this report (groups()), so the overview and the report cannot disagree.
     * Over-90 parts use the report's own age groups. It checks no permission: callers must authorise (today only
     * DirectorOverviewService, after its own checks).
     *
     * @return array{awaitingDecisionSen: int, awaitingDecisionOver90Sen: int, acceptedOutstandingSen: int, acceptedOutstandingOver90Sen: int, unclaimedSen: int, unclaimedOver90Sen: int}
     */
    public function totals(int $organisationId, Branch $branch, ?CarbonImmutable $fromUtc = null, ?CarbonImmutable $toUtc = null): array
    {
        $sums = array_fill(0, 24, 0);
        foreach ($this->groups($organisationId, $branch, $fromUtc, $toUtc, true) as $group) {
            for ($i = 0; $i < 24; $i++) {
                $sums[$i] += (int) ($group['values'][$i] ?? 0);
            }
        }

        return [
            'awaitingDecisionSen' => $sums[19], 'awaitingDecisionOver90Sen' => $sums[23],
            'acceptedOutstandingSen' => $sums[9], 'acceptedOutstandingOver90Sen' => $sums[13],
            'unclaimedSen' => $sums[5], 'unclaimedOver90Sen' => $sums[17],
        ];
    }

    /**
     * Per-Panel values for approved Panel shares of invoices finalised in [from, to) at one branch (a null from means
     * since the first; a null to means up to now). Value indexes follow the report columns after "Panel".
     *
     * @return array<int, array{name: string, values: array<int, int>}>
     */
    private function groups(int $organisationId, Branch $branch, ?CarbonImmutable $fromUtc, ?CarbonImmutable $toUtc, bool $owingOnly = false): array
    {
        $groups = [];
        $query = app(PanelClaimEligibility::class)->approvedAllocations($organisationId, $branch->id);
        if ($fromUtc !== null) {
            $query->where('i.finalized_at', '>=', $fromUtc);
        }
        if ($owingOnly) {
            // BIL-31 (Kumar F2): for totals(), skip Panel shares that can add nothing to the six figures, in SQL: keep a
            // share that is not yet claimed (no original claim with a submitted event), or that has a claim still
            // awaiting a decision or accepted for more than has been paid. Anything in doubt is kept.
            $query->where(function ($owing): void {
                $owing->whereNotExists(fn ($q) => $q->selectRaw('1')->from('panel_claims as oc')->whereColumn('oc.coverage_allocation_id', 'c.id')
                    ->where('oc.claim_kind', 'original')
                    ->whereExists(fn ($e) => $e->selectRaw('1')->from('panel_claim_events as oe')->whereColumn('oe.panel_claim_id', 'oc.id')->where('oe.event_type', 'submitted')))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('panel_claims as cl')->whereColumn('cl.coverage_allocation_id', 'c.id')
                        ->where(fn ($open) => $open->where(fn ($awaiting) => $awaiting->whereIn('cl.status', ['submitted', 'queried'])->whereNull('cl.accepted_amount_sen'))
                            ->orWhereRaw('COALESCE(cl.accepted_amount_sen, 0) > (SELECT COALESCE(SUM(pay.amount_sen), 0) FROM panel_claim_payments pay WHERE pay.panel_claim_id = cl.id AND NOT EXISTS (SELECT 1 FROM panel_claim_payment_reversals rv WHERE rv.panel_claim_payment_id = pay.id))')));
            });
        }
        if ($toUtc !== null) {
            $query->where('i.finalized_at', '<', $toUtc); // Null (totals to date): every finalised invoice so far.
        }
        $query
            ->orderByRaw('pn.name COLLATE "C"')->orderBy('c.id')->select(['c.id', 'c.panel_id', DB::raw('kpone_panel_effective(i.id) as amount_sen'), 'pn.name', 'i.finalized_at']); // PCA-2: approved and unclaimed use the current Panel share.
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $query->chunk(500, function ($allocations) use ($organisationId, $branch, $today, &$groups): void {
            $claims = PanelClaim::query()->where('organisation_id', $organisationId)->where('branch_id', $branch->id)
                ->whereIn('coverage_allocation_id', $allocations->pluck('id'))->get(['id', 'organisation_id', 'branch_id', 'coverage_allocation_id', 'claim_amount_sen', 'accepted_amount_sen', 'status', 'claim_kind']);
            // PCA-2: one original claim per Panel share decides claimed or unclaimed; supplementary claims add their money.
            $supplementary = $claims->where('claim_kind', 'supplementary')->where('status', '!=', 'cancelled')->groupBy('coverage_allocation_id');
            $allClaimIds = $claims->pluck('id');
            $claims = $claims->where('claim_kind', 'original')->keyBy('coverage_allocation_id');
            $submittedDates = DB::table('panel_claim_events')->where('organisation_id', $organisationId)->where('branch_id', $branch->id)
                ->whereIn('panel_claim_id', $allClaimIds)->where('event_type', 'submitted')->selectRaw('panel_claim_id, MIN(occurred_at) AS first_submitted')->groupBy('panel_claim_id')->pluck('first_submitted', 'panel_claim_id');
            $payments = DB::table('panel_claim_payments')->where('organisation_id', $organisationId)->where('branch_id', $branch->id)
                ->whereIn('panel_claim_id', $allClaimIds)->whereRaw(PanelClaimPaymentReversal::NOT_REVERSED)->selectRaw('panel_claim_id, SUM(amount_sen) AS paid')->groupBy('panel_claim_id')->pluck('paid', 'panel_claim_id');
            foreach ($allocations as $allocation) {
                $id = (int) $allocation->panel_id;
                $group = $groups[$id] ?? ['name' => (string) $allocation->name, 'values' => array_fill(0, 24, 0)];
                $group['values'][0]++;
                $group['values'][1] += (int) $allocation->amount_sen;
                $claim = $claims->get($allocation->id);
                $submitted = $claim ? $submittedDates->get($claim->id) : null;
                if ($claim && $submitted !== null) {
                    $group['values'][2]++;
                    foreach (collect([$claim])->merge($supplementary->get($allocation->id, collect())) as $each) {
                        $eachSubmitted = $submittedDates->get($each->id);
                        if ($eachSubmitted === null) {
                            continue; // A supplementary claim counts once it is submitted, ageing from its own submission.
                        }
                        $money = PanelClaimAmounts::summary($each, (int) $payments->get($each->id, 0));
                        foreach (['claimed' => 3, 'accepted' => 6, 'rejected' => 7, 'remitted' => 8, 'outstanding' => 9] as $key => $index) {
                            $group['values'][$index] += $money[$key];
                        }
                        $index = $this->bucket(CarbonImmutable::parse((string) $eachSubmitted, 'UTC')->setTimezone($branch->timezone), $today);
                        $group['values'][10 + $index] = ($group['values'][10 + $index] ?? 0) + $money['outstanding'];
                        if (in_array($each->status, ['submitted', 'queried'], true) && $each->accepted_amount_sen === null) {
                            $group['values'][18]++;
                            $group['values'][19] += (int) $each->claim_amount_sen;
                            $group['values'][20 + $index] += (int) $each->claim_amount_sen;
                        }
                    }
                } else {
                    $group['values'][4]++;
                    $group['values'][5] += (int) $allocation->amount_sen;
                    $index = $this->bucket(CarbonImmutable::parse((string) $allocation->finalized_at, 'UTC')->setTimezone($branch->timezone), $today);
                    $group['values'][14 + $index] = ($group['values'][14 + $index] ?? 0) + (int) $allocation->amount_sen;
                }
                $groups[$id] = $group;
            }
        });

        return $groups;
    }

    protected function project(User $actor, Branch $branch, array $filters): array
    {
        $groups = $this->groups($actor->organisation_id, $branch,
            CarbonImmutable::parse($filters['from'], $branch->timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($filters['to'], $branch->timezone)->addDay()->startOfDay()->utc());
        $columns = ['Panel', 'Approved invoices', 'Approved responsibility (MYR)', 'Claimed invoices', 'Claimed current (MYR)', 'Unclaimed invoices', 'Unclaimed responsibility (MYR)', 'Accepted (MYR)', 'Fully rejected current (MYR)', 'Remitted (MYR)', 'Accepted outstanding (MYR)', 'Claim outstanding 0-30 days (MYR)', 'Claim outstanding 31-60 days (MYR)', 'Claim outstanding 61-90 days (MYR)', 'Claim outstanding over 90 days (MYR)', 'Unclaimed 0-30 days (MYR)', 'Unclaimed 31-60 days (MYR)', 'Unclaimed 61-90 days (MYR)', 'Unclaimed over 90 days (MYR)', 'Awaiting decision claims', 'Awaiting decision (MYR)', 'Awaiting decision 0-30 days (MYR)', 'Awaiting decision 31-60 days (MYR)', 'Awaiting decision 61-90 days (MYR)', 'Awaiting decision over 90 days (MYR)'];
        $sums = array_fill(0, 24, 0);
        $rows = [];
        foreach ($groups as $group) {
            $values = [];
            for ($i = 0; $i < 24; $i++) {
                $number = $group['values'][$i] ?? 0;
                $sums[$i] += $number;
                $values[] = in_array($i, [0, 2, 4, 18], true) ? (string) $number : ReportCsv::money($number);
            }
            $rows[] = [$group['name'], ...$values];
        }
        $totals = [];
        foreach ($sums as $i => $value) {
            $totals[$columns[$i + 1]] = in_array($i, [0, 2, 4, 18], true) ? (string) $value : ReportCsv::money($value);
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    private function bucket(CarbonImmutable $date, CarbonImmutable $today): int
    {
        $age = max(0, (int) $date->startOfDay()->diffInDays($today, false));

        return $age <= 30 ? 0 : ($age <= 60 ? 1 : ($age <= 90 ? 2 : 3));
    }
}
