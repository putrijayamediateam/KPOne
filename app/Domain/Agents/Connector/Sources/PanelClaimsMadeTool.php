<?php

namespace App\Domain\Agents\Connector\Sources;

use App\Domain\Agents\Tools\AgentTool;
use App\Domain\Agents\Tools\ReadsBranches;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `connector.panel_claims_made` (CN-3): Panel claims created in KPOne at a branch, by branch-local creation date (default today):
 * how many, the amount claimed, and per status and Panel. Original claims only (a supplementary claim is a later adjustment).
 */
final class PanelClaimsMadeTool implements AgentTool
{
    use ReadsBranches;

    public const MAX_DAYS = 31;

    public function code(): string
    {
        return 'connector.panel_claims_made';
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
        assert($branch !== null);
        $today = $this->localDate($branch, null);
        $from = $input['from'] ?? ($input['to'] ?? $today);
        $to = $input['to'] ?? $from;
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose an end date not before the start date and at most '.self::MAX_DAYS.' days.']);
        }
        $rows = DB::table('panel_claims')->where('organisation_id', $requester->organisation_id)->where('branch_id', $branch->id)->where('claim_kind', 'original')
            ->where('created_at', '>=', CarbonImmutable::parse($from, $branch->timezone)->startOfDay()->utc())
            ->where('created_at', '<', CarbonImmutable::parse($to, $branch->timezone)->addDay()->startOfDay()->utc())
            ->groupBy('panel_id', 'status')->selectRaw('panel_id, MAX(panel_name_snapshot) as panel_label, status, COUNT(*) as total, SUM(claim_amount_sen) as amount')
            ->get();

        $byStatus = [];
        $byPanel = [];
        foreach ($rows as $row) {
            $byStatus[$row->status] = ($byStatus[$row->status] ?? 0) + (int) $row->total;
            $panel = $byPanel[$row->panel_id] ?? ['panel' => (string) $row->panel_label, 'claims' => 0, 'claimedSen' => 0];
            $panel['claims'] += (int) $row->total;
            $panel['claimedSen'] += (int) $row->amount;
            $byPanel[$row->panel_id] = $panel;
        }
        ksort($byStatus);
        $panels = array_values($byPanel);
        usort($panels, fn (array $a, array $b): int => strcmp($a['panel'], $b['panel']));

        return [
            'branch' => $branch->code,
            'from' => $from,
            'to' => $to,
            'claims' => array_sum($byStatus),
            'claimedSen' => array_sum(array_column($panels, 'claimedSen')),
            'byStatus' => $byStatus,
            'panels' => $panels,
        ];
    }
}
