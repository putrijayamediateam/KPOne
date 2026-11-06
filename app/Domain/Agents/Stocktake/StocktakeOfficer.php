<?php

namespace App\Domain\Agents\Stocktake;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The stocktake officer, Rizal (AG-9, docs/agents/BRIEFS.md). Reads `inventory.stock_counts` through the
 * gateway inside the caller's run and reports, for one branch: SKU and location pairs holding stock with no
 * completed governed count (STK-5: posted after approval, or closed with no variance) in the last 30 days,
 * open count cases and cases with an unresolved variance (awaiting recount or approval, so not yet approved).
 * Records one counts-only decision step. Rizal creates no proposal, sends no message and changes no clinic
 * data. AG-8's morning run calls this.
 *
 * Notes: a completed count that a later reversal case undoes still counts as completed (the count did
 * happen). Stock under an inactive SKU or item is still counted as due while it is on hand.
 *
 * The requester is the person whose authority the read uses. The gateway needs one today; the 07:00 run
 * acts as "system" (contract section 5), so AG-8 adds the gateway's system path and a matching entry point
 * here. Do not invent a requester for scheduled runs.
 */
final class StocktakeOfficer
{
    public const AGENT = 'stocktake_officer';

    public const TASK = 'stock_count_check';

    /** A count dated exactly this many days ago (branch-local) still counts; one day older is due. */
    public const DUE_AFTER_DAYS = 30;

    public const LIST_LIMIT = 25;

    public function __construct(private AgentToolGateway $gateway, private AgentRunRecorder $recorder) {}

    public function check(AiAgentRun $run, User $requester, Branch $branch): StocktakeCheck
    {
        $agent = AiAgent::query()->whereKey($run->ai_agent_id)->where('organisation_id', $run->organisation_id)->first();
        if (! $agent || $agent->code !== self::AGENT) {
            throw new AuthorizationException('Only the stocktake officer runs this check.');
        }
        $read = $this->gateway->call($run, 'inventory.stock_counts', $requester, $branch);
        $result = self::evaluate($read);
        $this->recorder->step($run, 'decision', $result->summary());

        return $result;
    }

    /** @param array<string, mixed> $read the `inventory.stock_counts` result */
    public static function evaluate(array $read): StocktakeCheck
    {
        $date = (string) $read['date'];
        $dueBefore = CarbonImmutable::parse($date)->subDays(self::DUE_AFTER_DAYS)->toDateString();
        $rows = array_values(array_filter((array) $read['rows'], 'is_array'));

        $due = array_values(array_filter($rows, fn (array $row): bool => ($row['stocked'] ?? false) === true
            && ($row['lastCompletedCountDate'] === null || $row['lastCompletedCountDate'] < $dueBefore)));
        // Never counted first, then the oldest count; ties keep the data source's SKU and location order.
        usort($due, fn (array $a, array $b): int => [$a['lastCompletedCountDate'] !== null, $a['lastCompletedCountDate']] <=> [$b['lastCompletedCountDate'] !== null, $b['lastCompletedCountDate']]);
        $open = array_values(array_filter($rows, fn (array $row): bool => (int) $row['openCases'] > 0));

        $pick = fn (array $row): array => [
            'sku' => $row['sku'],
            'skuCode' => $row['skuCode'],
            'item' => $row['item'],
            'location' => $row['location'],
            'locationLabel' => $row['locationLabel'],
            'lastCompletedCountDate' => $row['lastCompletedCountDate'],
            'openCases' => (int) $row['openCases'],
            'unresolvedVariances' => (int) $row['unresolvedVariances'],
        ];

        return new StocktakeCheck(
            branch: (string) $read['branch'],
            date: $date,
            dueBefore: $dueBefore,
            countsDue: count($due),
            openCases: array_sum(array_map(fn (array $row): int => (int) $row['openCases'], $rows)),
            unresolvedVariances: array_sum(array_map(fn (array $row): int => (int) $row['unresolvedVariances'], $rows)),
            due: array_map($pick, array_slice($due, 0, self::LIST_LIMIT)),
            open: array_map($pick, array_slice($open, 0, self::LIST_LIMIT)),
        );
    }
}
