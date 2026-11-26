<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\ReceivingReviewService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic domain fixtures shared by STK-46 service and real-process correction race tests. */
final class HandCostFixture
{
    public function __construct(private Branch $branch) {}

    /** @return array<string, mixed> */
    public function receipt(array $cost = []): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $payload = $f['payload'];
        $payload['lines'][0] = [...$payload['lines'][0], ...$cost];
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $movement = StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->where('reference_public_id', $receipt->public_id)->sole();

        return [...$f, 'receipt' => $receipt, 'movement' => $movement];
    }

    /** @param array<string, mixed> $f
     * @return array{review: string, input: array<string, mixed>}
     */
    public function correction(array $f): array
    {
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $id = app(ReceivingReviewService::class)->request($f['receiver'], $f['order']->refresh(), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $f['order']->lock_version,
            'retry_key' => (string) Str::uuid(), 'kind' => 'correction', 'receipt_public_id' => $f['receipt']->public_id,
            'reason' => 'incorrect_quantity',
        ]);
        $review = DB::table('inventory_receiving_reviews')->find($id);
        app(BranchAccessService::class)->select($f['director'], $this->branch);
        app(ReceivingReviewService::class)->approve($f['director'], $review->public_id, [
            'expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(),
        ]);

        return ['review' => $review->public_id, 'input' => ['expected_branch_id' => $this->branch->id, 'review_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()]];
    }
}
