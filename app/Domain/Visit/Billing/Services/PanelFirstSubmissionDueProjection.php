<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Internal page-only projection; the directory has already authorized the active branch and claim list. */
final class PanelFirstSubmissionDueProjection
{
    /**
     * @param  list<int>  $claimIds
     * @return array<int, array{dueLabel: string, ruleLabel: string, overdue: bool}>
     */
    public function forClaims(Branch $branch, array $claimIds, CarbonImmutable $now): array
    {
        if ($claimIds === []) {
            return [];
        }
        if (count($claimIds) > 20) {
            throw new \InvalidArgumentException('Submission due context is limited to one claim page.');
        }
        $rows = DB::table('panel_claims as c')
            ->join('invoices as i', function ($join): void {
                $join->on('i.id', '=', 'c.invoice_id')->on('i.organisation_id', '=', 'c.organisation_id')->on('i.branch_id', '=', 'c.branch_id');
            })
            ->join('visits as v', function ($join): void {
                $join->on('v.id', '=', 'i.visit_id')->on('v.organisation_id', '=', 'c.organisation_id')->on('v.branch_id', '=', 'c.branch_id');
            })
            ->join('panels as pn', function ($join): void {
                $join->on('pn.id', '=', 'c.panel_id')->on('pn.organisation_id', '=', 'c.organisation_id');
            })
            ->where('c.organisation_id', $branch->organisation_id)->where('c.branch_id', $branch->id)->whereIn('c.id', $claimIds)
            ->get(['c.id', 'v.registered_at', 'pn.claim_due_days', 'pn.claim_due_rule', 'pn.claim_due_time', 'pn.claim_due_offset']);
        $out = [];
        foreach ($rows as $row) {
            if ($row->registered_at === null) {
                continue;
            }
            $due = PanelClaimDueRule::dueAt($row, CarbonImmutable::parse((string) $row->registered_at, 'UTC')->setTimezone($branch->timezone));
            $timed = ($row->claim_due_rule ?? 'days_after_visit') !== 'days_after_visit' && isset($row->claim_due_time, $row->claim_due_offset);
            $out[(int) $row->id] = [
                'dueLabel' => $due->format($timed ? 'j M Y, g:i A' : 'j M Y'),
                'ruleLabel' => PanelClaimDueRule::describe($row),
                'overdue' => PanelClaimDueRule::isOverdue($row, $due, $now->setTimezone($branch->timezone)),
            ];
        }

        return $out;
    }
}
