<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * STK-23 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): cycle counts. Each SKU at a branch gets an ABC class
 * from how often it was dispensed there in the last 90 days (no cost is used, so no value is shown to anyone);
 * controlled medicines are always A. A SKU at a location is due for a governed STK-5 count when its last completed
 * count is older than its class interval. "Start count" opens STK-5 count cases for every batch of it there.
 */
final class CycleCountService
{
    /** Days between counts per class (fixed in v1; a branch setting is a possible follow-up). */
    public const INTERVALS = ['A' => 30, 'B' => 60, 'C' => 90];

    public const LOOK_BACK_DAYS = 90;

    private const PAGE_SIZE = 25;

    /** STK-5 states in which a count case is still open. */
    private const OPEN_STATES = ['awaiting_count', 'awaiting_recount', 'awaiting_approval'];

    /** STK-5 states in which a count is complete. */
    private const DONE_STATES = ['posted', 'closed_zero'];

    public function __construct(private GovernedStockService $governed) {}

    /**
     * Counts due at the branch: one row per SKU and location that holds stock, overdue first.
     *
     * @return array<string, mixed>
     */
    public function due(User $actor, Branch $branch, int $page): array
    {
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $pairs = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')
            ->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('b.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('s.is_active', true)->where('i.is_active', true)
            ->where('b.quantity', '>', 0)
            ->groupBy('s.id', 's.public_id', 's.sku_code', 'i.generic_name', 'l.id', 'l.public_id', 'l.name')
            ->get(['s.id as sku_id', 's.public_id as sku_public_id', 's.sku_code', 'i.generic_name as item', 'l.id as location_id', 'l.public_id as location_public_id', 'l.name as location']);
        $classes = $this->classes($actor, $branch);
        $latest = DB::table('inventory_governed_actions')->select('case_id')->selectRaw('MAX(lock_version) as version')->groupBy('case_id');
        $cases = DB::table('inventory_governed_cases as c')->joinSub($latest, 'latest', 'latest.case_id', '=', 'c.id')
            ->join('inventory_governed_actions as a', fn ($j) => $j->on('a.case_id', '=', 'c.id')->on('a.lock_version', '=', 'latest.version'))
            ->where('c.organisation_id', $actor->organisation_id)->where('c.branch_id', $branch->id)->where('c.kind', 'count')
            ->get(['c.inventory_sku_id', 'c.inventory_location_id', 'a.state', 'a.created_at']);
        $lastCounted = [];
        $open = [];
        foreach ($cases as $case) {
            $key = $case->inventory_sku_id.':'.$case->inventory_location_id;
            if (in_array($case->state, self::DONE_STATES, true)) {
                $at = CarbonImmutable::parse((string) $case->created_at, 'UTC')->setTimezone($branch->timezone);
                $lastCounted[$key] = isset($lastCounted[$key]) && $lastCounted[$key]->greaterThan($at) ? $lastCounted[$key] : $at;
            } elseif (in_array($case->state, self::OPEN_STATES, true)) {
                $open[$key] = true;
            }
        }
        $rows = [];
        foreach ($pairs as $pair) {
            $key = $pair->sku_id.':'.$pair->location_id;
            $class = $classes[(int) $pair->sku_id] ?? 'C';
            $last = $lastCounted[$key] ?? null;
            $dueOn = $last?->startOfDay()->addDays(self::INTERVALS[$class]);
            $overdue = $dueOn === null ? null : (int) $dueOn->diffInDays($today, false);
            if ($dueOn !== null && $overdue < 0) {
                continue;
            }
            $rows[] = ['skuPublicId' => $pair->sku_public_id, 'skuCode' => $pair->sku_code, 'item' => $pair->item, 'locationPublicId' => $pair->location_public_id, 'location' => $pair->location,
                'class' => $class, 'lastCounted' => $last?->format('j M Y'), 'dueOn' => $dueOn?->format('j M Y'), 'overdueDays' => $overdue, 'open' => isset($open[$key])];
        }
        // Never counted first, then the most overdue, then A before B before C, then by SKU.
        usort($rows, fn (array $a, array $b): int => [$a['overdueDays'] !== null, -($a['overdueDays'] ?? 0), $a['class'], $a['skuCode'], $a['location']]
            <=> [$b['overdueDays'] !== null, -($b['overdueDays'] ?? 0), $b['class'], $b['skuCode'], $b['location']]);
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $lastPage);

        return ['rows' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), 'total' => $total, 'page' => $page, 'lastPage' => $lastPage,
            'intervals' => self::INTERVALS, 'lookBackDays' => self::LOOK_BACK_DAYS];
    }

    /**
     * ABC class per SKU at the branch: A for the SKUs making up the first 80% of dispensing movements in the look-back,
     * B for the next 15%, C for the rest and anything not dispensed. Controlled medicines are always A.
     *
     * @return array<int, 'A'|'B'|'C'>
     */
    public function classes(User $actor, Branch $branch): array
    {
        $since = CarbonImmutable::now('UTC')->subDays(self::LOOK_BACK_DAYS);
        $usage = DB::table('stock_movements as m')->join('inventory_locations as l', 'l.id', '=', 'm.source_location_id')
            ->where('m.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->where('m.movement_type', StockMovement::TYPE_DISPENSE)->where('m.occurred_at', '>=', $since)
            ->groupBy('m.inventory_sku_id')->selectRaw('m.inventory_sku_id, count(*) as uses')->orderByDesc('uses')->orderBy('m.inventory_sku_id')
            ->pluck('uses', 'inventory_sku_id');
        $classes = self::rank($usage->map(fn (mixed $uses): int => (int) $uses)->all());
        $controlled = DB::table('medicine_catalogue_inventory_skus as map')->join('medicine_catalogue_items as m', 'm.id', '=', 'map.medicine_catalogue_item_id')
            ->where('map.organisation_id', $actor->organisation_id)->where('map.is_active', true)->where('m.is_controlled', true)->pluck('map.inventory_sku_id');
        foreach ($controlled as $skuId) {
            $classes[(int) $skuId] = 'A';
        }

        return $classes;
    }

    /**
     * Ranks SKUs by dispensing movements, most first: A until 80% of all movements are covered, B until 95%, C after.
     * The SKU that crosses a line keeps the higher class. Ties are broken by SKU id, so the result is stable.
     *
     * @param  array<int, int>  $usageBySku
     * @return array<int, 'A'|'B'|'C'>
     */
    public static function rank(array $usageBySku): array
    {
        uksort($usageBySku, fn (int $a, int $b): int => [$usageBySku[$b], $a] <=> [$usageBySku[$a], $b]);
        $total = array_sum($usageBySku);
        $classes = [];
        $before = 0;
        foreach ($usageBySku as $skuId => $uses) {
            $classes[$skuId] = $before * 100 < 80 * $total ? 'A' : ($before * 100 < 95 * $total ? 'B' : 'C');
            $before += $uses;
        }

        return $classes;
    }

    /**
     * "Start count": STK-5 count cases for every batch of a SKU at a location, all or nothing. A batch with a count
     * already open is skipped. Each case uses STK-5's own locks, snapshot rule, retry key and audit.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(User $actor, array $input): int
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'retry_key' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid'], 'location_public_id' => ['required', 'uuid']])->validate();
        $branch = app(InventoryAuthorityService::class)->activeBranch($actor, $data);

        return DB::transaction(function () use ($actor, $branch, $data): int {
            $balances = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')
                ->where('b.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->where('l.is_active', true)
                ->where('s.public_id', strtolower((string) $data['sku_public_id']))->where('l.public_id', strtolower((string) $data['location_public_id']))->where('b.quantity', '>', 0)
                ->orderBy('b.id')->get(['b.id', 'b.lock_version', 's.stock_unit']);
            abort_if($balances->isEmpty(), 404);
            $latest = DB::table('inventory_governed_actions')->select('case_id')->selectRaw('MAX(lock_version) as version')->groupBy('case_id');
            $busy = DB::table('inventory_governed_cases as c')->joinSub($latest, 'latest', 'latest.case_id', '=', 'c.id')
                ->join('inventory_governed_actions as a', fn ($j) => $j->on('a.case_id', '=', 'c.id')->on('a.lock_version', '=', 'latest.version'))
                ->whereIn('c.balance_id', $balances->pluck('id'))->where('c.kind', 'count')->whereIn('a.state', self::OPEN_STATES)->pluck('c.balance_id')->map(fn (mixed $id): int => (int) $id)->all();
            $opened = 0;
            foreach ($balances as $balance) {
                if (in_array((int) $balance->id, $busy, true)) {
                    continue;
                }
                // One retry key per batch, derived from the page's key, so a retried press opens nothing twice.
                $this->governed->propose($actor, 'count', ['expected_branch_id' => $branch->id, 'lock_version' => 0, 'retry_key' => Uuid::uuid5((string) $data['retry_key'], 'balance:'.$balance->id)->toString(),
                    'balance_id' => (int) $balance->id, 'balance_version' => (int) $balance->lock_version, 'unit' => (string) $balance->stock_unit]);
                $opened++;
            }
            if ($opened === 0 && $busy === []) {
                throw ValidationException::withMessages(['cycle' => 'Nothing to count here.']);
            }

            return $opened;
        }, 3);
    }
}
