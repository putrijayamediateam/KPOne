<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\ReceiptCorrectionService;
use App\Domain\Organisation\Inventory\Services\ReceivingReviewService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic STK-7 bootstrap: one verified delivery of an accepted lot at the branch's dispensary. */
final class SupplierReturnFixture
{
    public function __construct(private Branch $branch) {}

    public function actor(string $role, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $actor = (new VerifiedReceivingFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** @return array<string, mixed> */
    public function received(string $quantity = '10.000'): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build($quantity);
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $payload = $f['payload'];
        $payload['lines'][0]['quantity'] = $quantity;
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $lot = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
            ->where('line.goods_receipt_id', $receipt->id)->sole(['lot.*']);

        return [...$f, 'receipt' => $receipt, 'lot' => $lot, 'returnBatchId' => (int) $lot->inventory_batch_id];
    }

    /** @return array<string, mixed> A damaged delivery received as one quarantined lot (independently approved, batch held). */
    public function quarantined(string $quantity = '5.000'): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build($quantity);
        $payload = [...$f['payload'], 'lines' => [['line_public_id' => $f['order']->lines()->sole()->public_id, 'physical_quantity' => $quantity,
            'lots' => [['quantity' => $quantity, 'batch_number' => 'SYN-QUAR-'.Str::random(6), 'expiry_date' => '2028-12-31', 'outcome' => 'quarantined', 'reason' => 'damaged']]]]];
        $access = app(BranchAccessService::class);
        $reviews = app(ReceivingReviewService::class);
        $access->select($f['receiver'], $this->branch);
        $review = DB::table('inventory_receiving_reviews')->find($reviews->request($f['receiver'], $f['order'], $payload));
        $access->select($f['director'], $this->branch);
        $reviews->approve($f['director'], $review->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
        $access->select($f['receiver'], $this->branch);
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], [...$payload, 'review_public_id' => $review->public_id, 'review_lock_version' => 2]);
        $lot = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
            ->where('line.goods_receipt_id', $receipt->id)->sole(['lot.*']);

        return [...$f, 'receipt' => $receipt, 'lot' => $lot, 'returnBatchId' => (int) $lot->inventory_batch_id];
    }

    /** Approved whole-receipt reversing correction (STK-3) of the fixture's delivery, by its receiver. */
    public function reverse(array $f): void
    {
        $access = app(BranchAccessService::class);
        $reviews = app(ReceivingReviewService::class);
        $access->select($f['receiver'], $this->branch);
        $review = DB::table('inventory_receiving_reviews')->find($reviews->request($f['receiver'], $f['order'], ['expected_branch_id' => $this->branch->id,
            'lock_version' => $f['order']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(), 'kind' => 'correction', 'receipt_public_id' => $f['receipt']->public_id, 'reason' => 'incorrect_quantity']));
        $access->select($f['director'], $this->branch);
        $reviews->approve($f['director'], $review->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
        $access->select($f['receiver'], $this->branch);
        app(ReceiptCorrectionService::class)->reverse($f['receiver'], $review->public_id, ['expected_branch_id' => $this->branch->id, 'review_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    public function request(array $f, string $quantity, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'receipt_lot_id' => $f['lot']->id, 'unit' => $f['sku']->stock_unit, 'quantity' => $quantity, 'reason' => 'damaged', 'supplier_reference' => 'SYN-RMA-1', ...$overrides];
    }
}
