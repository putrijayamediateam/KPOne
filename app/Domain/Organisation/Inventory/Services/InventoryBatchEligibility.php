<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One reason-producing predicate for every dispensing selection and final check. */
final class InventoryBatchEligibility
{
    public const FEFO_REASONS = ['earlier_batch_damaged', 'earlier_batch_insufficient', 'earlier_batch_held', 'other_approved'];

    public function localDate(Branch $branch): string
    {
        return now()->setTimezone($branch->timezone)->toDateString();
    }

    /** @var array<string, bool> whether the STK-25 recall table exists, per connection and database */
    private static array $recallTable = [];

    /**
     * A historical schema (some migration regression tests stop before STK-25) has no recall table, so no batch can
     * be recalled there. Remembered per connection and database, so normal use asks only once.
     */
    private function hasRecalls(): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().'|'.$connection->getDatabaseName();

        return self::$recallTable[$key] ??= Schema::connection($connection->getName())->hasTable('inventory_recall_batches');
    }

    public function query(int $organisationId, Branch $branch): Builder
    {
        if (! $this->hasRecalls()) {
            return DB::query()->fromSub(DB::table('inventory_batches as b')
                ->join('inventory_skus as s', fn ($q) => $q->on('s.id', '=', 'b.inventory_sku_id')->on('s.organisation_id', '=', 'b.organisation_id'))
                ->join('inventory_items as i', fn ($q) => $q->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
                ->leftJoin('inventory_batch_hold_states as h', fn ($q) => $q->on('h.inventory_batch_id', '=', 'b.id')->where('h.organisation_id', $organisationId)->where('h.branch_id', $branch->id))
                ->leftJoin('inventory_batch_hold_actions as a', 'a.id', '=', 'h.active_action_id')
                ->where('b.organisation_id', $organisationId)
                ->select(['b.*', 's.is_active as sku_active', 'i.is_active as item_active', DB::raw('NULL::bigint as recall_id'), 'a.reason as hold_reason'])
                ->selectRaw("CASE WHEN s.is_active IS NOT TRUE THEN 'inactive_sku' WHEN i.is_active IS NOT TRUE THEN 'inactive_item' WHEN b.status <> 'available' THEN 'unavailable_status' WHEN b.expiry_date IS NULL OR b.expiry_date <= ? THEN 'expiry' WHEN h.active_action_id IS NOT NULL THEN 'held' ELSE NULL END as eligibility_reason", [$this->localDate($branch)]), 'eligibility');
        }

        return DB::query()->fromSub(DB::table('inventory_batches as b')
            ->join('inventory_skus as s', fn ($q) => $q->on('s.id', '=', 'b.inventory_sku_id')->on('s.organisation_id', '=', 'b.organisation_id'))
            ->join('inventory_items as i', fn ($q) => $q->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
            ->leftJoin('inventory_batch_hold_states as h', fn ($q) => $q->on('h.inventory_batch_id', '=', 'b.id')->where('h.organisation_id', $organisationId)->where('h.branch_id', $branch->id))
            ->leftJoin('inventory_batch_hold_actions as a', 'a.id', '=', 'h.active_action_id')
            // STK-25: an active recall blocks every batch of the item with that batch number, at every branch and
            // independently of any branch hold. It shows through the same "held" signal with its own reason, which
            // wins over a branch hold's, so every picker and page that shows holds shows the recall.
            ->leftJoin('inventory_recall_batches as rb', fn ($q) => $q->on('rb.inventory_item_id', '=', 's.inventory_item_id')
                ->on('rb.batch_key', '=', DB::raw('upper(btrim(b.batch_number))'))->where('rb.active', true))
            ->where('b.organisation_id', $organisationId)
            ->select(['b.*', 's.is_active as sku_active', 'i.is_active as item_active', 'rb.recall_id'])
            ->selectRaw("CASE WHEN rb.id IS NOT NULL THEN 'all_branch_recall' ELSE a.reason END as hold_reason")
            ->selectRaw("CASE WHEN s.is_active IS NOT TRUE THEN 'inactive_sku' WHEN i.is_active IS NOT TRUE THEN 'inactive_item' WHEN b.status <> 'available' THEN 'unavailable_status' WHEN b.expiry_date IS NULL OR b.expiry_date <= ? THEN 'expiry' WHEN rb.id IS NOT NULL THEN 'recalled' WHEN h.active_action_id IS NOT NULL THEN 'held' ELSE NULL END as eligibility_reason", [$this->localDate($branch)]), 'eligibility');
    }

    public function assertEligible(int $organisationId, Branch $branch, int $batchId, string $medicine): void
    {
        $problem = $this->problem($organisationId, $branch, $batchId);
        if ($problem !== null) {
            throw ValidationException::withMessages(['allocations' => $medicine.': '.$problem.'.']);
        }
    }

    /** TIDY-4: a hold reason in words, as the batch hold screen shows it (never the stored code). */
    private const HOLD_REASON_WORDS = [
        'suspected_quality_issue' => 'suspected quality issue',
        'recall' => 'recall',
        'damaged' => 'damaged',
        'other_safety' => 'other safety concern',
    ];

    /**
     * Why a batch is not usable at a branch, in the same words dispensing uses, or null when it is usable (STK-37:
     * stock moves share this rule). Without branch holds, a branch hold is ignored: stock in an organisation store
     * belongs to no branch, so no branch hold can apply to it.
     */
    public function problem(int $organisationId, Branch $branch, int $batchId, bool $branchHolds = true): ?string
    {
        $row = $this->query($organisationId, $branch)->where('eligibility.id', $batchId)->first();
        $reason = $row->eligibility_reason ?? ($row ? null : 'missing_reference');
        if ($reason === null || ($reason === 'held' && ! $branchHolds)) {
            return null;
        }

        return match ($reason) {
            'inactive_sku' => 'the stock SKU is inactive', 'inactive_item' => 'the inventory item is inactive',
            'unavailable_status' => 'the batch status is not available', 'expiry' => 'the batch expiry must be after the branch-local current date',
            'recalled' => 'the batch is under a recall at every branch',
            'held' => 'the batch is held at this branch ('.(self::HOLD_REASON_WORDS[$row->hold_reason] ?? 'held').')', default => 'the inventory reference is unavailable',
        };
    }

    public function earlierEligible(int $organisationId, Branch $branch, int $skuId, int $batchId): bool
    {
        $selected = $this->query($organisationId, $branch)->where('eligibility.id', $batchId)->first();
        if (! $selected) {
            return false;
        }

        return $this->query($organisationId, $branch)->where('eligibility.inventory_sku_id', $skuId)->whereNull('eligibility.eligibility_reason')->whereDate('eligibility.expiry_date', '<', $selected->expiry_date)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('inventory_stock_balances as balance')->join('inventory_locations as location', 'location.id', '=', 'balance.inventory_location_id')->whereColumn('balance.inventory_batch_id', 'eligibility.id')->where('balance.organisation_id', $organisationId)->where('balance.quantity', '>', 0)->where('location.branch_id', $branch->id)->where('location.is_active', true)->where('location.type', 'dispensary'))->exists();
    }
}
