<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Str;

/** Synthetic STK-6 bootstrap: one source with opening stock, one destination, a dispatched two-stage transfer. */
final class TransferShortageFixture
{
    public function __construct(private Branch $branch) {}

    public function actor(string $role, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $actor = (new GovernedStockFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** @return array<string, mixed> */
    public function dispatched(string $quantity = '10.000', string $opening = '20.000'): array
    {
        $stock = (new GovernedStockFixture($this->branch))->build($opening);
        $supervisor = $stock['proposer'];
        $destination = new InventoryLocation;
        $destination->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'code' => 'DEST-'.Str::upper(Str::random(8)), 'name' => 'Synthetic receiving dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        $requester = $this->actor('ca');
        $service = app(StockRequestService::class);
        $request = $service->create($requester, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $stock['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $stock['sku']->public_id, 'quantity' => $quantity]]]);
        app(BranchAccessService::class)->select($supervisor, $this->branch);
        $request = $service->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
        $line = $request->lines()->sole();
        $request = $service->dispatch($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version, 'dispatch_idempotency_key' => (string) Str::uuid(), 'lines' => [['line_public_id' => $line->public_id, 'batch_public_id' => $stock['batch']->public_id, 'quantity' => $quantity]]]);

        return [...$stock, 'request' => $request, 'line' => $line->refresh(), 'destination' => $destination, 'requester' => $requester, 'supervisor' => $supervisor];
    }

    /** @param array<string,string> $counts */
    public function receipt(StockRequest $request, string $linePublicId, array $counts, ?string $key = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version, 'receive_idempotency_key' => $key ?? (string) Str::uuid(), 'lines' => [[
            'line_public_id' => $linePublicId,
            'accepted_quantity' => $counts['accepted'] ?? '0',
            'short_quantity' => $counts['short'] ?? '0',
            'damaged_quantity' => $counts['damaged'] ?? '0',
            'extra_quantity' => $counts['extra'] ?? '0',
        ]]];
    }
}
