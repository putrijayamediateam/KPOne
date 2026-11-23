<?php

namespace App\Domain\Agents\Connector\Sources;

use App\Domain\Agents\Tools\AgentTool;
use App\Domain\Agents\Tools\ReadsBranches;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `connector.visits_by_state` (CN-3): visits registered at a branch on a branch-local date, by state. "Unfinished" is registered
 * and not yet completed. `totalNotCancelled` is the cohort of Insights In-clinic and `clinic.day_summary` "registered".
 */
final class VisitsByStateTool implements AgentTool
{
    use ReadsBranches;

    public function code(): string
    {
        return 'connector.visits_by_state';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        assert($branch !== null);
        $date = $this->localDate($branch, $input['date'] ?? null);
        $start = CarbonImmutable::parse($date, $branch->timezone)->startOfDay()->utc();
        $counts = DB::table('visits')->where('organisation_id', $requester->organisation_id)->where('branch_id', $branch->id)
            ->where('registered_at', '>=', $start)->where('registered_at', '<', $start->addDay())
            ->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
        $unfinished = (int) ($counts['registered'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);
        $cancelled = (int) ($counts['cancelled'] ?? 0);

        return [
            'branch' => $branch->code,
            'date' => $date,
            'unfinished' => $unfinished,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'totalNotCancelled' => $unfinished + $completed,
            'totalIncludingCancelled' => $unfinished + $completed + $cancelled,
        ];
    }
}
