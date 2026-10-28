<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\GoodsReceipt;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\PurchaseOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReceiptCorrectionService
{
    /**
     * Whole-receipt reversal only, after independent approval.
     *
     * @param  array<string,mixed>  $input
     */
    public function reverse(User $actor, string $reviewKey, array $input): int
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'review_lock_version' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid']])->validate();
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $hash = hash('sha256', json_encode([$reviewKey, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $reviewKey, $data, $authority, $branch, $hash): int {
            $actor = $authority->lockForBranch($actor, $branch, ProcurementService::RECEIVE_PERMISSION);
            $candidate = DB::table('inventory_receiving_reviews')->where('public_id', $reviewKey)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('receiver_user_id', $actor->id)->where('kind', 'correction')->firstOrFail();
            $order = PurchaseOrder::query()->whereKey($candidate->purchase_order_id)->lockForUpdate()->firstOrFail();
            $location = InventoryLocation::query()->whereKey($order->destination_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $review = DB::table('inventory_receiving_reviews')->where('id', $candidate->id)->lockForUpdate()->sole();
            $retry = DB::table('inventory_receipt_corrections')->where('actor_user_id', $actor->id)->where('retry_key', $data['idempotency_key'])->first();
            if ($retry) {
                if (! hash_equals($retry->payload_hash, $hash)) {
                    $this->refuse('This retry key was used for another correction.');
                }

                return $retry->id;
            }
            if ((int) $data['review_lock_version'] !== 2 || $order->lock_version !== $review->order_lock_version || $order->status === PurchaseOrder::STATUS_CLOSED || ! DB::table('inventory_receiving_approvals')->where('review_id', $review->id)->exists() || DB::table('inventory_receiving_consumptions')->where('review_id', $review->id)->exists()) {
                $this->refuse('An unchanged, independently approved correction is required.');
            }
            $payload = json_decode($review->payload, true, 512, JSON_THROW_ON_ERROR);
            $receipt = GoodsReceipt::query()->where('public_id', $payload['receipt_public_id'])->where('organisation_id', $actor->organisation_id)->where('purchase_order_id', $order->id)->where('received_by_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            if (DB::table('inventory_receipt_corrections')->where('goods_receipt_id', $receipt->id)->exists()) {
                $this->refuse('This receipt already has a reversing correction.');
            }
            $lines = DB::table('inventory_receipt_line_evidence')->where('goods_receipt_id', $receipt->id)->orderBy('id')->get();
            abort_if($lines->isEmpty(), 404);
            $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lots = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')->join('inventory_batches as batch', 'batch.id', '=', 'lot.inventory_batch_id')->where('line.goods_receipt_id', $receipt->id)->whereIn('lot.outcome', ['accepted', 'quarantined'])->orderBy('batch.inventory_sku_id')->orderBy('batch.id')->get(['lot.*', 'batch.inventory_sku_id']);
            $skus = InventorySku::query()->whereIn('id', $lots->pluck('inventory_sku_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $batches = InventoryBatch::query()->whereIn('id', $lots->pluck('inventory_batch_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lots as $lot) {
                if (DB::table('stock_movements')->where('inventory_batch_id', $lot->inventory_batch_id)->where('source_location_id', $location->id)->where('occurred_at', '>=', $receipt->received_at)->exists()) {
                    $this->refuse('Stock has moved out since this receipt. Owner review is required; it cannot be reversed here.');
                }
            }
            $publicId = (string) Str::uuid();
            $id = DB::table('inventory_receipt_corrections')->insertGetId(['public_id' => $publicId, 'review_id' => $review->id, 'goods_receipt_id' => $receipt->id, 'actor_user_id' => $actor->id, 'retry_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now()->utc()]);
            foreach ($lots as $lot) {
                app(InventoryMovementService::class)->recordReceiptCorrection($actor, $location, $skus->get($lot->inventory_sku_id), $batches->get($lot->inventory_batch_id), $lot->quantity, $publicId);
            }
            foreach ($lines as $line) {
                $orderLine = $orderLines->get($line->purchase_order_line_id);
                $next = StockUnitQuantity::scaled($orderLine->received_quantity, true) - StockUnitQuantity::scaled($line->applied_order_quantity, true);
                if ($next < 0) {
                    $this->refuse('The order quantities no longer match this receipt.');
                }
                $orderLine->forceFill(['received_quantity' => StockUnitQuantity::format($next)])->save();
            }
            $hasReceived = $order->lines()->where('received_quantity', '>', 0)->exists();
            $order->forceFill(['status' => $hasReceived ? PurchaseOrder::STATUS_PARTIALLY_RECEIVED : PurchaseOrder::STATUS_APPROVED, 'lock_version' => $order->lock_version + 1])->save();
            DB::table('inventory_receiving_consumptions')->insert(['review_id' => $review->id, 'goods_receipt_id' => null, 'created_at' => now()->utc()]);
            abort_if(app(AuditRecorder::class)->record('inventory.receiving.receipt_reversed', $receipt, ['correction_id' => $id, 'review_id' => $review->id], $actor, $branch) === null, 503, 'The correction could not be audited. Nothing was changed.');

            return $id;
        }, 3);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['correction' => $message]);
    }
}
