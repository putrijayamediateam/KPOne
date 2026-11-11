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
        $reorder = $this->reorderNeeded($actor);
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
                'lists' => array_map(fn (array $l): array => [
                    'key' => $l['key'], 'title' => $l['title'], 'total' => $l['total'], 'limit' => self::LIST_LIMIT,
                    'seeAllHref' => route('inventory.stock-review', absolute: false), 'rows' => array_slice($l['rows'], 0, self::LIST_LIMIT),
                ], $watch['lists']),
            ],
            'overdueReviews' => $watch === null || ! $watch['canManage'] ? null : [
                'total' => count($watch['overdue']), 'limit' => 3, 'seeAllHref' => route('inventory.stock-review', absolute: false),
                'rows' => array_slice($watch['overdue'], 0, 3),
            ],
            'reorder' => $reorder === null ? null : [
                'total' => $reorder['total'], 'limit' => self::LIST_LIMIT, 'seeAllHref' => route('inventory.reorder-suggestions', absolute: false),
                'rows' => array_slice($reorder['rows'], 0, self::LIST_LIMIT),
            ],
            'readiness' => $readiness === null ? null : ['openings' => $readiness['openings'], 'limited' => $readiness['limited'], 'href' => route('inventory.readiness', absolute: false)],
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * The three Stock review worklists (read up to four pages of 25 rows each), rows ordered for display, and the
     * overdue review-by dates carried on those rows.
     *
     * @return array{canManage: bool, lists: list<array{key: string, title: string, total: int, rows: list<array<string, mixed>>}>, overdue: list<array<string, mixed>>}|null
     */
    public function watch(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('inventory.view.branch')) {
            return null;
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
            $lists[] = ['key' => $key, 'title' => $title, 'total' => (int) $first['lists'][$key]['total'], 'rows' => array_map(fn (array $r): array => $this->watchRow($key, $r, $today), $rows)];
        }
        usort($overdue, fn (array $a, array $b): int => strcmp($a['reviewBy'], $b['reviewBy']));
        $overdue = array_map(fn (array $o): array => ['sku' => $o['sku'], 'item' => $o['item'], 'text' => 'Review was due '.CarbonImmutable::parse($o['reviewBy'])->format('j M Y')], $overdue);

        return ['canManage' => (bool) $first['canManage'], 'lists' => $lists, 'overdue' => $overdue];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, string>
     */
    private function watchRow(string $key, array $r, string $today): array
    {
        $unit = (string) ($r['stock_unit'] ?? '');
        $detail = $key === 'low_stock'
            ? rtrim(rtrim((string) $r['quantity'], '0'), '.').' '.$unit.' on hand, reorder level '.rtrim(rtrim((string) $r['reorder_level'], '0'), '.')
            : SpaceDisplay::expiryText((string) $r['expiry'], $today).' · '.rtrim(rtrim((string) $r['quantity'], '0'), '.').' '.$unit;

        return ['sku' => (string) $r['sku_code'], 'item' => (string) $r['item'], 'location' => (string) $r['location'], 'detail' => $detail, 'tone' => $key === 'low_stock' ? 'amber' : ($key === 'expired' || str_contains($detail, 'Expired') ? 'red' : 'amber')];
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
            'detail' => $r['onHand'].' '.$r['stockUnit'].' on hand, reorder level '.$r['reorderLevel'].', suggest '.$r['suggestedQuantity'],
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
                    $held[] = ['sku' => (string) $r['sku_code'], 'batch' => (string) $r['batch_number'], 'reason' => ucfirst(str_replace('_', ' ', (string) $r['reason']))];
                }
            }
            if ($page >= (int) $result['lastPage']) {
                break;
            }
            if ($page === self::PAGES) {
                $complete = false;
            }
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
