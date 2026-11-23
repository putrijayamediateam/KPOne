<?php

namespace App\Domain\Agents\Connector\Sources;

use App\Domain\Agents\Tools\AgentTool;
use App\Domain\Agents\Tools\ReadsBranches;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** `connector.queue_now` (CN-3): the consultation queue right now at a branch, as counts (today's queue only). */
final class QueueNowTool implements AgentTool
{
    use ReadsBranches;

    public function code(): string
    {
        return 'connector.queue_now';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        assert($branch !== null);
        $date = $this->localDate($branch, null);
        $entries = fn () => DB::table('queue_entries as q')->where('q.organisation_id', $requester->organisation_id)->where('q.branch_id', $branch->id)->where('q.operational_date', '<=', $date);
        $waiting = (int) $entries()->where('q.status', 'waiting')->count();
        $carried = (int) $entries()->where('q.status', 'waiting')->where('q.operational_date', '<', $date)->count();
        $notReady = (int) $entries()->where('q.status', 'waiting')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('queue_call_holds as h')->whereColumn('h.queue_entry_id', 'q.id')->where('h.state', 'active'))->count();
        $serving = $entries()->where('q.status', 'serving');
        $onHold = (int) (clone $serving)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('consultation_holds as c')->whereColumn('c.queue_entry_id', 'q.id')->whereNull('c.resumed_at'))->count();
        $servingTotal = (int) (clone $serving)->count();

        return [
            'branch' => $branch->code,
            'date' => $date,
            'waiting' => $waiting,
            'carriedOver' => $carried,
            'notReadyToCall' => $notReady,
            'serving' => $servingTotal - $onHold,
            'onHold' => $onHold,
        ];
    }
}
