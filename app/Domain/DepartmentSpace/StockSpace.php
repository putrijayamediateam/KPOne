<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Organisation\Inventory\Services\ReorderSuggestionService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DS-8: the Stock space home. Every widget calls the Inventory page's own service (Stock review, Reorder
 * suggestions, Pilot readiness), which keeps its own permission and branch checks; a widget the person cannot open
 * on its page is left out. Stock data only: no patient data, and no cost or supplier terms are read or shown.
 */
final class StockSpace
{
    public const LIST_LIMIT = 5;

    /** Pages of 25 rows read from a paged Inventory list for one widget or answer. */
    private const PAGES = 4;

    public function __construct(
        private BranchAccessService $branches,
        private InventoryStockReviewService $review,
        private ReorderSuggestionService $reorder,
        private InventoryReadinessService $readiness,
        private InventoryBatchHoldService $holds,
        private GovernedStockService $governed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $watch = $this->watch($actor);
        $readiness = $this->openingsWaiting($actor);
        $links = [];
        if ($branch !== null && $actor->can('inventory.view.branch')) {
            $links[] = ['title' => 'Inventory', 'href' => route('inventory.index', absolute: false)];
            $links[] = ['title' => 'Batch holds', 'href' => route('inventory.batch-holds', absolute: false)];
            $links[] = ['title' => 'Stock count', 'href' => route('inventory.governed-counts', absolute: false)];
            $links[] = ['title' => 'Write-off', 'href' => route('inventory.governed-writeoffs', absolute: false)];
            $links[] = ['title' => 'Supplier returns', 'href' => route('inventory.supplier-returns', absolute: false)];
        }
        $primary = null;
        if ($branch !== null && $actor->can('inventory.view.branch')) {
            $primary = $actor->can(InventoryStockReviewService::MANAGE)
                ? ['title' => 'Open Stock review', 'href' => route('inventory.stock-review', absolute: false)]
                : ['title' => 'Open Inventory', 'href' => route('inventory.index', absolute: false)];
        }

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'watch' => $watch === null ? null : [
                'showLocation' => $watch['showLocation'],
                'lists' => array_map(fn (array $l): array => [
                    'key' => $l['key'], 'title' => $l['title'], 'total' => $l['total'], 'limit' => self::LIST_LIMIT,
                    'seeAllHref' => route('inventory.stock-review', absolute: false), 'rows' => array_slice($l['rows'], 0, self::LIST_LIMIT),
                ], $watch['lists']),
            ],
            'overdueReviews' => $watch === null || ! $watch['canManage'] ? null : [
                'total' => count($watch['overdue']), 'limit' => self::LIST_LIMIT, 'seeAllHref' => route('inventory.stock-review', absolute: false),
                'rows' => array_slice($watch['overdue'], 0, self::LIST_LIMIT),
            ],
            'readiness' => $readiness === null ? null : ['openings' => $readiness['openings'], 'limited' => $readiness['limited'], 'href' => route('inventory.readiness', absolute: false)],
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /** "250.000" as "250" and "0.5" as "0.5": a quantity without its trailing zeros. */
    private static function qty(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    /**
     * The three Stock review worklists (read up to four pages of 25 rows each), rows ordered for display, and the
     * overdue review-by dates carried on those rows.
     *
     * @return array{canManage: bool, showLocation: bool, lists: list<array{key: string, title: string, total: int, rows: list<array<string, mixed>>}>, overdue: list<array<string, mixed>>}|null
     */
    public function watch(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('inventory.view.branch')) {
            return null;
        }
        $suggested = [];
        foreach ((array) ($this->reorder->page($actor, ['need' => 'order'])['rows'] ?? []) as $q) {
            $suggested[(string) $q['key']] = (string) $q['suggestedQuantity'];
        }
        $titles = ['low_stock' => 'Low stock', 'expiring' => 'Expiring within 90 days', 'expired' => 'Expired on hand'];
        $first = $this->review->page($actor, []);
        $today = (string) $first['today'];
        $lists = [];
        $overdue = [];
        foreach ($titles as $key => $title) {
            $rows = array_values((array) $first['lists'][$key]['rows']);
            $last = (int) $first['lists'][$key]['lastPage'];
            for ($page = 2; $page <= min($last, self::PAGES); $page++) {
                $more = $this->review->page($actor, [$key.'_page' => $page]);
                $rows = array_merge($rows, array_values((array) $more['lists'][$key]['rows']));
            }
            foreach ($rows as $r) {
                if (($r['latest']['overdue'] ?? false) === true) {
                    $overdue[] = ['title' => $key, 'sku' => (string) $r['sku_code'], 'item' => (string) $r['item'], 'reviewBy' => (string) $r['latest']['reviewBy']];
                }
            }
            if ($key !== 'low_stock') {
                usort($rows, fn (array $a, array $b): int => strcmp((string) $a['expiry'], (string) $b['expiry']));
            }
            $lists[] = ['key' => $key, 'title' => $title, 'total' => (int) $first['lists'][$key]['total'], 'rows' => array_map(fn (array $r): array => $this->watchRow($key, $r, $today, $suggested), $rows)];
        }
        usort($overdue, fn (array $a, array $b): int => strcmp($a['reviewBy'], $b['reviewBy']));
        $todayDate = CarbonImmutable::parse($today)->startOfDay();
        $overdue = array_map(function (array $o) use ($todayDate): array {
            $due = CarbonImmutable::parse($o['reviewBy'])->startOfDay();
            $days = max(1, (int) $due->diffInDays($todayDate));

            return ['sku' => $o['sku'], 'item' => $o['item'], 'text' => SpaceDisplay::dueText($due->format('j M Y'), $days, 'Review due'), 'tone' => $days >= 30 ? 'red' : 'amber'];
        }, $overdue);

        return ['canManage' => (bool) $first['canManage'], 'showLocation' => $this->branchHasSeveralLocations($actor), 'lists' => $lists, 'overdue' => $overdue];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, string>  $suggested
     * @return array<string, string>
     */
    private function watchRow(string $key, array $r, string $today, array $suggested): array
    {
        $unit = (string) ($r['stock_unit'] ?? '');
        $quantity = (float) $r['quantity'];
        if ($key === 'low_stock') {
            $level = self::qty((string) $r['reorder_level']);
            $onHand = $quantity <= 0 ? 'Out of stock' : SpaceDisplay::quantityText((string) $r['quantity'], $unit).' on hand';
            $order = $suggested[$r['location_id'].':'.$r['sku_id']] ?? null;
            $detail = ($order !== null && (float) $order > 0 ? 'Order '.self::qty($order).' · ' : '').$onHand.', reorder level '.$level;
            $tone = $quantity <= 0 ? 'red' : 'amber';
        } else {
            $detail = SpaceDisplay::expiryText((string) $r['expiry'], $today).' · '.SpaceDisplay::quantityText((string) $r['quantity'], $unit);
            $tone = $key === 'expired' ? 'red' : 'amber';
        }

        return ['sku' => (string) $r['sku_code'], 'item' => (string) $r['item'], 'location' => (string) $r['location'], 'detail' => $detail, 'tone' => $tone];
    }

    /** The location line only matters once the branch keeps stock in more than one location. */
    private function branchHasSeveralLocations(User $actor): bool
    {
        $branch = $this->branches->activeBranch($actor);

        return $branch !== null && DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->where('is_active', true)->count() > 1;
    }

    /**
     * SKU and location pairs that still need an order, from the Reorder suggestions page's own rows. No cost and no
     * supplier terms are read.
     *
     * @return array{total: int, rows: list<array<string, string>>, all: list<array<string, mixed>>}|null
     */
    public function reorderNeeded(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('inventory.view.branch')) {
            return null;
        }
        $page = $this->reorder->page($actor, ['need' => 'order']);
        $rows = array_map(fn (array $r): array => [
            'sku' => (string) $r['skuCode'], 'item' => (string) $r['itemName'], 'location' => (string) $r['location'],
            'detail' => SpaceDisplay::quantityText((string) $r['onHand'], (string) $r['stockUnit']).' on hand, reorder level '.self::qty((string) $r['reorderLevel']).'. Suggested order: '.self::qty((string) $r['suggestedQuantity']),
        ], array_values((array) $page['rows']));

        return ['total' => (int) $page['total'], 'rows' => $rows, 'all' => array_values((array) $page['rows'])];
    }

    /**
     * Opening balances waiting for an independent check, from Pilot readiness' own pending list (first 25).
     *
     * @return array{openings: int, limited: bool}|null
     */
    public function openingsWaiting(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('inventory.references.manage.organisation')) {
            return null;
        }
        $page = $this->readiness->page($actor, []);

        return ['openings' => count((array) $page['pending']), 'limited' => (bool) $page['pendingLimited']];
    }

    /**
     * Batches on hold at this branch, from the Batch holds page's own rows (read up to four pages of 25 batches).
     *
     * @return array{rows: list<array<string, string>>, complete: bool}|null
     */
    public function heldBatches(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('inventory.view.branch')) {
            return null;
        }
        $held = [];
        $complete = true;
        for ($page = 1; $page <= self::PAGES; $page++) {
            $result = $this->holds->page($actor, ['page' => $page]);
            foreach ((array) $result['rows'] as $r) {
                if (($r['reason'] ?? null) !== null) {
                    $held[] = ['key' => (string) $r['public_id'], 'sku' => (string) $r['sku_code'], 'batch' => (string) $r['batch_number'], 'reason' => ucfirst(str_replace('_', ' ', (string) $r['reason'])), 'since' => ''];
                }
            }
            if ($page >= (int) $result['lastPage']) {
                break;
            }
            if ($page === self::PAGES) {
                $complete = false;
            }
        }

        if ($held !== []) {
            $branch = $this->branches->activeBranch($actor);
            $since = DB::table('inventory_batch_hold_states as h')->join('inventory_batch_hold_actions as a', 'a.id', '=', 'h.active_action_id')
                ->join('inventory_batches as b', 'b.id', '=', 'h.inventory_batch_id')
                ->where('h.branch_id', $branch->id)->where('b.organisation_id', $actor->organisation_id)
                ->whereIn('b.public_id', array_column($held, 'key'))->pluck('a.created_at', 'b.public_id');
            foreach ($held as &$h) {
                $at = $since[$h['key']] ?? null;
                $h['since'] = $at === null ? '' : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($branch->timezone)->format('j M Y');
            }
            unset($h);
        }

        return ['rows' => $held, 'complete' => $complete];
    }

    /**
     * Posted write-offs this branch month, counted per reason from the governed write-off page's own cases (read up to
     * four pages of 25). No quantity value and no cost.
     *
     * @return array{month: string, byReason: array<string, int>, total: int, complete: bool}|null
     */
    public function writtenOffThisMonth(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || ! $actor->can('inventory.view.branch')) {
            return null;
        }
        $start = CarbonImmutable::now($branch->timezone)->startOfMonth()->utc();
        $by = [];
        $complete = true;
        for ($page = 1; $page <= self::PAGES; $page++) {
            $result = $this->governed->page($actor, 'writeoff', ['state' => 'posted', 'page' => $page]);
            foreach ((array) $result['cases']['rows'] as $c) {
                if (($c['kind'] ?? '') !== 'writeoff') {
                    continue;
                }
                $approved = collect((array) $c['history'])->firstWhere('action', 'approved');
                $at = is_object($approved) ? ($approved->created_at ?? null) : ($approved['created_at'] ?? null);
                if ($at !== null && CarbonImmutable::parse((string) $at)->greaterThanOrEqualTo($start)) {
                    $by[(string) $c['reason']] = ($by[(string) $c['reason']] ?? 0) + 1;
                }
            }
            if ($page >= (int) $result['cases']['lastPage']) {
                break;
            }
            if ($page === self::PAGES) {
                $complete = false;
            }
        }

        return ['month' => CarbonImmutable::now($branch->timezone)->format('F Y'), 'byReason' => $by, 'total' => array_sum($by), 'complete' => $complete];
    }
}
