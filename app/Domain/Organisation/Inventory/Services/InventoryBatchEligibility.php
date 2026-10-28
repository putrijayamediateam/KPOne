<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One reason-producing predicate for every dispensing selection and final check. */
final class InventoryBatchEligibility
{
    public const FEFO_REASONS = ['earlier_batch_damaged', 'earlier_batch_insufficient', 'earlier_batch_held', 'other_approved'];

    public function localDate(Branch $branch): string
    {
        return now()->setTimezone($branch->timezone)->toDateString();
    }

    public function query(int $organisationId, Branch $branch): Builder
    {
        return DB::query()->fromSub(DB::table('inventory_batches as b')
            ->join('inventory_skus as s', fn ($q) => $q->on('s.id', '=', 'b.inventory_sku_id')->on('s.organisation_id', '=', 'b.organisation_id'))
            ->join('inventory_items as i', fn ($q) => $q->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
            ->leftJoin('inventory_batch_hold_states as h', fn ($q) => $q->on('h.inventory_batch_id', '=', 'b.id')->where('h.organisation_id', $organisationId)->where('h.branch_id', $branch->id))
            ->leftJoin('inventory_batch_hold_actions as a', 'a.id', '=', 'h.active_action_id')
            ->where('b.organisation_id', $organisationId)
            ->select(['b.*', 's.is_active as sku_active', 'i.is_active as item_active', 'a.reason as hold_reason'])
            ->selectRaw("CASE WHEN s.is_active IS NOT TRUE THEN 'inactive_sku' WHEN i.is_active IS NOT TRUE THEN 'inactive_item' WHEN b.status <> 'available' THEN 'unavailable_status' WHEN b.expiry_date IS NULL OR b.expiry_date <= ? THEN 'expiry' WHEN h.active_action_id IS NOT NULL THEN 'held' ELSE NULL END as eligibility_reason", [$this->localDate($branch)]), 'eligibility');
    }

    public function assertEligible(int $organisationId, Branch $branch, int $batchId, string $medicine): void
    {
        $row = $this->query($organisationId, $branch)->where('eligibility.id', $batchId)->first();
        $reason = $row->eligibility_reason ?? ($row ? null : 'missing_reference');
        if ($reason !== null) {
            $message = match ($reason) {
                'inactive_sku' => 'the stock SKU is inactive', 'inactive_item' => 'the inventory item is inactive',
                'unavailable_status' => 'the batch status is not available', 'expiry' => 'the batch expiry must be after the branch-local current date',
                'held' => 'the batch is held at this branch ('.$row->hold_reason.')', default => 'the inventory reference is unavailable',
            };
            throw ValidationException::withMessages(['allocations' => $medicine.': '.$message.'.']);
        }
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
