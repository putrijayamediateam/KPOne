<?php

namespace App\Domain\Organisation\Inventory\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only notices for the hand costs that contributed to a report's scoped figures. */
final class HandCostRestatement
{
    /**
     * A credit recorded this period may use a supplier-return movement from an earlier period.
     *
     * @param  list<int>  $keys
     * @return list<int>
     */
    public static function creditMovementIds(int $organisationId, array $keys, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return array_values(DB::table('inventory_supplier_return_actions as c')
            ->join('inventory_supplier_returns as r', 'r.id', '=', 'c.return_id')
            ->join('inventory_supplier_return_actions as d', 'd.return_id', '=', 'r.id')
            ->where('c.organisation_id', $organisationId)->whereIn('r.branch_id', $keys)
            ->where('c.action', 'credit_recorded')->whereNotNull('c.credit_amount_sen')
            ->where('d.action', 'dispatched')->whereNotNull('d.movement_id')
            ->where('c.created_at', '>=', $start->toDateTimeString())->where('c.created_at', '<=', $end->toDateTimeString())
            ->pluck('d.movement_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * @param  list<array<string, mixed>>  $replays  opening and closing, or closing alone
     * @param  list<int>  $keys  selected branch keys
     * @param  list<int>  $movementIds  movements used by the period's figures
     * @return list<array{id:int, skuCode:string, changedOn:string, message:string}>
     */
    public static function notices(int $organisationId, array $replays, array $keys, bool $all, array $movementIds, string $zone): array
    {
        $costs = [];
        foreach ($replays as $replay) {
            foreach ($replay['positions'] as $p) {
                if (in_array($p['branch_key'], $keys, true)) {
                    $costs += $p['hand_costs'] ?? [];
                }
            }
            if ($all) {
                foreach ($replay['inTransit'] as $p) {
                    $costs += $p['hand_costs'] ?? [];
                }
            }
            foreach ($movementIds as $id) {
                $m = $replay['movements'][$id] ?? null;
                if ($m !== null && (in_array($m['in_branch'], $keys, true) || in_array($m['out_branch'], $keys, true))) {
                    $costs += $m['hand_costs'] ?? [];
                }
            }
        }
        if ($costs === []) {
            return [];
        }
        $codes = DB::table('inventory_skus')->where('organisation_id', $organisationId)
            ->whereIn('id', array_column($costs, 'sku_id'))->pluck('sku_code', 'id');
        ksort($costs);

        return array_values(array_map(function (array $cost) use ($codes, $zone): array {
            $code = (string) ($codes[$cost['sku_id']] ?? 'Stock item');
            $date = CarbonImmutable::parse($cost['accepted_at'], 'UTC')->setTimezone($zone)->format('j M Y, g:i A');

            return ['id' => (int) $cost['id'], 'skuCode' => $code, 'changedOn' => $date,
                'message' => 'Changed on '.$date.': a hand cost was accepted for '.$code.'. Historical figures have been recalculated; an earlier download may differ.'];
        }, $costs));
    }
}
