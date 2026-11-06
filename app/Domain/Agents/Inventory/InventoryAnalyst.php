<?php

namespace App\Domain\Agents\Inventory;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Hana, the inventory analyst (AG-6, contract sections 6 and 14). Reads the three inventory data sources through the
 * gateway inside the caller's run and proposes restock, expiry, transfer and slow-moving suggestions by the fixed
 * rules in InventoryRules, through ProposalService (the approval inbox). A suggestion already made today for the
 * same branch, item and type is not proposed again (the 14:00 update). Hana changes no stock: an approval records
 * the decision only. A run with no person reads through the gateway's system path.
 */
final class InventoryAnalyst
{
    public const AGENT = 'inventory_analyst';

    public const TASK = 'inventory_review';

    public const RESTOCK = 'inventory.restock_suggestion';

    public const EXPIRY = 'inventory.expiry_warning';

    public const TRANSFER = 'inventory.transfer_suggestion';

    public const SLOW = 'inventory.slow_moving';

    /** The 07:00 run makes all four checks; the 14:00 update repeats restock and expiry (contract section 14). */
    public const ALL_CHECKS = ['restock', 'expiry', 'transfer', 'slow_moving'];

    private const KEY_PREFIX = ['restock' => 'rs', 'expiry' => 'ex', 'transfer' => 'tr', 'slow_moving' => 'sm'];

    public function __construct(private AgentToolGateway $gateway, private AgentRunRecorder $recorder, private ProposalService $proposals) {}

    /** @param list<string> $checks */
    public function review(AiAgentRun $run, Branch $branch, array $checks = self::ALL_CHECKS, ?User $requester = null): InventoryReview
    {
        if (array_diff($checks, self::ALL_CHECKS) !== [] || $checks === []) {
            throw new InvalidArgumentException('Unknown inventory check.');
        }
        $agent = AiAgent::query()->whereKey($run->ai_agent_id)->where('organisation_id', $run->organisation_id)->first();
        if (! $agent || $agent->code !== self::AGENT || $run->task_type !== self::TASK) {
            throw new AuthorizationException('Only the inventory analyst runs this review.');
        }
        $read = fn (string $tool): array => $requester === null
            ? $this->gateway->callAsSystem($run, $tool, $branch)
            : $this->gateway->call($run, $tool, $requester, $branch);

        $review = $read('inventory.stock_review');
        $date = (string) $review['date'];
        $planned = [];
        // Transfers already suggested today (pending or approved): this branch's own cover its restock, and every
        // other branch's take their share of a sender's surplus, so two branches are never sent the same surplus.
        $today = $this->todaysTransfers($run->organisation_id, $date);
        $incoming = [];
        $outgoing = [];
        foreach ($today as $transfer) {
            if ($transfer['to_branch_code'] === $branch->code) {
                $incoming[$transfer['sku_public_id']] = ($incoming[$transfer['sku_public_id']] ?? 0) + InventoryRules::milli($transfer['quantity']);
            } else {
                $key = $transfer['sku_public_id'].'|'.$transfer['from_branch_code'];
                $outgoing[$key] = ($outgoing[$key] ?? 0) + InventoryRules::milli($transfer['quantity']);
            }
        }
        if (in_array('transfer', $checks, true)) {
            foreach (InventoryRules::transfers($read('inventory.branch_balances'), $branch->code, $outgoing) as $payload) {
                $identity = [$payload['sku_public_id'], $payload['from_branch_code']];
                $planned[] = ['transfer', self::TRANSFER, $payload, $identity];
                if (! $this->exists($run->organisation_id, $this->key('transfer', $branch, $date, $identity))) {
                    $incoming[$payload['sku_public_id']] = ($incoming[$payload['sku_public_id']] ?? 0) + InventoryRules::milli($payload['quantity']);
                }
            }
        }
        if (in_array('restock', $checks, true)) {
            $covered = array_map(fn (int $milli): string => InventoryRules::fromMilli($milli), $incoming);
            foreach (InventoryRules::restocks(self::rows($review['rows']['lowStock']), $covered) as $payload) {
                $planned[] = ['restock', self::RESTOCK, $payload, [$payload['sku_public_id'], $payload['location_public_id']]];
            }
        }
        if (in_array('expiry', $checks, true)) {
            foreach (InventoryRules::expiryWarnings([...self::rows($review['rows']['expiring']), ...self::rows($review['rows']['expired'])], $date) as $payload) {
                $planned[] = ['expiry', self::EXPIRY, $payload, [$payload['sku_public_id'], $payload['batch_public_id'], $payload['location_public_id']]];
            }
        }
        if (in_array('slow_moving', $checks, true)) {
            foreach (InventoryRules::slowMoving(self::rows($read('inventory.dispensing_activity')['locations'])) as $payload) {
                $planned[] = ['slow_moving', self::SLOW, $payload, [$payload['sku_public_id'], $payload['location_public_id']]];
            }
        }

        $proposed = [];
        $already = [];
        foreach ($planned as [$check, $type, $payload, $identity]) {
            // One suggestion per branch, item, type and local date: the 14:00 update never repeats a 07:00 one.
            $key = $this->key($check, $branch, $date, $identity);
            if ($this->exists($run->organisation_id, $key)) {
                $already[$type] = ($already[$type] ?? 0) + 1;

                continue;
            }
            $this->proposals->propose($run, $type, $branch, $payload, $key);
            $proposed[$type] = ($proposed[$type] ?? 0) + 1;
        }

        $result = new InventoryReview($branch->code, $date, $proposed, $already);
        $this->recorder->step($run, 'decision', $result->summary());

        return $result;
    }

    /** @param list<string> $identity */
    private function key(string $check, Branch $branch, string $date, array $identity): string
    {
        return 'hana:'.self::KEY_PREFIX[$check].':'.$branch->id.':'.$date.':'.sha1(implode('|', $identity));
    }

    private function exists(int $organisationId, string $key): bool
    {
        return AiProposal::query()->where('organisation_id', $organisationId)->where('idempotency_key', $key)->exists();
    }

    /**
     * Transfer suggestions Hana made today at any branch that are still pending or were approved (a rejected one
     * frees its quantity again).
     *
     * @return list<array<string, string>>
     */
    private function todaysTransfers(int $organisationId, string $date): array
    {
        return array_values(AiProposal::query()->where('organisation_id', $organisationId)->where('type', self::TRANSFER)
            ->whereIn('status', ['pending', 'approved'])->where('idempotency_key', 'like', 'hana:tr:%:'.$date.':%')
            ->get()->map(fn (AiProposal $proposal): array => array_map('strval', $proposal->payload))->all());
    }

    /**
     * The row lists of a data source result, as written by the tools in this namespace.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter((array) $rows, 'is_array'));

        return $list;
    }
}
