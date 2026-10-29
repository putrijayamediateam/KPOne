<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic bootstrap, explicit branch, never an application mutation API. */
final class GovernedStockFixture
{
    public function __construct(private Branch $branch) {}

    public function actor(string $role): User
    {
        $actor = User::factory()->create(['organisation_id' => $this->branch->organisation_id, 'name' => 'Synthetic stock '.$role, 'email' => 'stock-'.Str::uuid().'@kpone.test']);
        $actor->assignRole($role);
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $actor->id])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $this->branch, ['is_primary' => true]);

        return $actor;
    }

    public function build(string $quantity = '10.000'): array
    {
        $actor = $this->actor('ca_supervisor');
        app(BranchAccessService::class)->select($actor, $this->branch);
        $code = 'SYN-'.Str::upper(Str::random(10));
        $item = new InventoryItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'code' => $code, 'generic_name' => 'Synthetic governed item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'inventory_item_id' => $item->id, 'sku_code' => $code, 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true])->save();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'code' => $code, 'name' => 'Synthetic governed dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => $code, 'expiry_date' => now()->setTimezone($this->branch->timezone)->addDays(90)->toDateString(), 'received_at' => now()->subDay(), 'status' => 'available'])->save();
        app(InventoryMovementService::class)->openingBalance($actor, ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $sku->public_id, 'location_public_id' => $location->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $quantity]);
        $balance = DB::table('inventory_stock_balances')->where('inventory_location_id', $location->id)->where('inventory_batch_id', $batch->id)->sole();

        return ['branch' => $this->branch, 'proposer' => $actor, 'item' => $item, 'sku' => $sku, 'location' => $location, 'batch' => $batch, 'balance' => $balance];
    }
}
