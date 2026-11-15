<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Domain\Visit\Billing\Services\PanelClaimAmounts;
use App\Domain\Visit\Billing\Services\PanelClaimDueRule;
use App\Domain\Visit\Billing\Services\PanelClaimEligibility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `panel.claims_summary`: per branch and Panel, with the Panel summary (RPT-01d) definitions: approved
 * Panel invoices, claimed (a claim with a first `submitted` event) and unclaimed, accepted, remitted and
 * accepted outstanding (accepted less remitted). The cohort is invoices finalised in the optional
 * branch-local date range (default: all time). `overdueUnclaimed*` counts unclaimed invoices past the
 * Panel's claim due rule (the Panel Claims Overdue-tab rule). Panel names and money only.
 */
final class PanelClaimsSummaryTool implements SystemReadableTool
{
    use ReadsBranches;

    public function __construct(private PanelClaimEligibility $eligibility) {}

    public function code(): string
    {
        return 'panel.claims_summary';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        return $this->runForOrganisation($requester->organisation_id, $branch, $input);
    }

    public function runForOrganisation(int $organisationId, ?Branch $branch, array $input): array
    {
        // AG-8: an organisation scope, never a person (see ReadsBranches::organisationScope).
        $requester = $this->organisationScope($organisationId);
        if (isset($input['from'], $input['to']) && $input['to'] < $input['from']) {
            throw ValidationException::withMessages(['to' => 'The end date must not be before the start date.']);
        }
        $result = [];
        foreach ($this->branchesFor($requester, $branch) as $each) {
            $now = CarbonImmutable::now($each->timezone);
            $query = $this->eligibility->approvedAllocations($requester->organisation_id, $each->id)
                ->orderByRaw('pn.name COLLATE "C"')->orderBy('c.id')
                ->select(['c.id', 'c.panel_id', 'c.amount_sen', 'pn.name as panel_label', 'i.finalized_at', 'v.registered_at', 'pn.claim_due_days', 'pn.claim_due_rule', 'pn.claim_due_time', 'pn.claim_due_offset']);
            if (isset($input['from'])) {
                $query->where('i.finalized_at', '>=', CarbonImmutable::parse($input['from'], $each->timezone)->startOfDay()->utc());
            }
            if (isset($input['to'])) {
                $query->where('i.finalized_at', '<', CarbonImmutable::parse($input['to'], $each->timezone)->addDay()->startOfDay()->utc());
            }
            $panels = [];
            $query->chunk(500, function ($allocations) use ($requester, $each, $now, &$panels): void {
                $claims = PanelClaim::query()->where('organisation_id', $requester->organisation_id)->where('branch_id', $each->id)
                    ->whereIn('coverage_allocation_id', $allocations->pluck('id'))->get(['id', 'organisation_id', 'branch_id', 'coverage_allocation_id', 'claim_amount_sen', 'accepted_amount_sen', 'status'])->keyBy('coverage_allocation_id');
                $submitted = DB::table('panel_claim_events')->where('organisation_id', $requester->organisation_id)->where('branch_id', $each->id)
                    ->whereIn('panel_claim_id', $claims->pluck('id'))->where('event_type', 'submitted')->distinct()->pluck('panel_claim_id')->flip();
                $paid = DB::table('panel_claim_payments')->where('organisation_id', $requester->organisation_id)->where('branch_id', $each->id)
                    ->whereIn('panel_claim_id', $claims->pluck('id'))->whereRaw(PanelClaimPaymentReversal::NOT_REVERSED)->groupBy('panel_claim_id')->selectRaw('panel_claim_id, SUM(amount_sen) as paid')->pluck('paid', 'panel_claim_id');
                foreach ($allocations as $allocation) {
                    $id = (int) $allocation->panel_id;
                    $panel = $panels[$id] ?? ['panel' => (string) $allocation->panel_label, 'approvedInvoices' => 0, 'approvedSen' => 0, 'claimedInvoices' => 0, 'claimedSen' => 0,
                        'unclaimedInvoices' => 0, 'unclaimedSen' => 0, 'overdueUnclaimedInvoices' => 0, 'overdueUnclaimedSen' => 0,
                        'acceptedSen' => 0, 'remittedSen' => 0, 'acceptedOutstandingSen' => 0, 'acceptedUnpaidClaims' => 0];
                    $panel['approvedInvoices']++;
                    $panel['approvedSen'] += (int) $allocation->amount_sen;
                    $claim = $claims->get($allocation->id);
                    if ($claim && $submitted->has($claim->id)) {
                        $money = PanelClaimAmounts::summary($claim, (int) $paid->get($claim->id, 0));
                        $panel['claimedInvoices']++;
                        $panel['claimedSen'] += $money['claimed'];
                        $panel['acceptedSen'] += $money['accepted'];
                        $panel['remittedSen'] += $money['remitted'];
                        $panel['acceptedOutstandingSen'] += $money['outstanding'];
                        $panel['acceptedUnpaidClaims'] += $money['outstanding'] > 0 ? 1 : 0;
                    } else {
                        $panel['unclaimedInvoices']++;
                        $panel['unclaimedSen'] += (int) $allocation->amount_sen;
                        $due = PanelClaimDueRule::dueAt($allocation, CarbonImmutable::parse((string) $allocation->registered_at, 'UTC')->setTimezone($each->timezone));
                        if (PanelClaimDueRule::isOverdue($allocation, $due, $now)) {
                            $panel['overdueUnclaimedInvoices']++;
                            $panel['overdueUnclaimedSen'] += (int) $allocation->amount_sen;
                        }
                    }
                    $panels[$id] = $panel;
                }
            });
            $rows = array_values($panels);
            $totals = [];
            foreach (['approvedInvoices', 'approvedSen', 'claimedInvoices', 'claimedSen', 'unclaimedInvoices', 'unclaimedSen', 'overdueUnclaimedInvoices', 'overdueUnclaimedSen', 'acceptedSen', 'remittedSen', 'acceptedOutstandingSen', 'acceptedUnpaidClaims'] as $key) {
                $totals[$key] = array_sum(array_column($rows, $key));
            }
            $result[] = ['branch' => $each->code, 'date' => $now->toDateString(), 'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null, 'totals' => $totals, 'panels' => $rows];
        }

        return ['scope' => $branch->code ?? 'all', 'branches' => $result];
    }
}
