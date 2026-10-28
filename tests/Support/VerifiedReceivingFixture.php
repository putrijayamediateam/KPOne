<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use Illuminate\Support\Str;

/** Task/test bootstrap only, always fictional identities and a separate synthetic SKU. */
final class VerifiedReceivingFixture
{
    public function build(string $ordered = '10.000'): array
    {
        $helper = new BatchHoldFixture;
        $f = $helper->build(false);
        $finance = $helper->actor('finance_officer');
        $director = $helper->actor('director');
        $receiver = $helper->actor('ca_supervisor');
        session([BranchAccessService::SESSION_KEY => $f['visit']->branch_id]);
        $supplier = app(SupplierAdministrationService::class)->create($finance, ['code' => 'SYN-'.Str::upper(Str::random(8)), 'name' => 'Synthetic receiving supplier', 'business_email' => 'supplier@receiving.test', 'contact_name' => null, 'business_phone' => null]);
        $service = app(ProcurementService::class);
        $order = $service->create($finance, ['expected_branch_id' => $f['visit']->branch_id, 'supplier_public_id' => $supplier->public_id, 'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => $ordered, 'unit_cost_sen' => 125]]]);
        $order = $service->submit($finance, $order, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $order->lock_version]);
        $order = $service->approve($director, $order, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $order->lock_version]);
        $key = (string) Str::uuid();
        $payload = ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $order->lock_version, 'idempotency_key' => $key, 'retry_key' => (string) Str::uuid(), 'delivery_reference' => 'SYN-DELIVERY-'.$key, 'lines' => [['line_public_id' => $order->lines()->sole()->public_id, 'quantity' => '2.000', 'batch_number' => 'SYN-RECEIVING-'.Str::random(8), 'expiry_date' => '2028-12-31']]];

        return [...$f, 'receiver' => $receiver, 'finance' => $finance, 'director' => $director, 'supplier' => $supplier, 'order' => $order, 'payload' => $payload];
    }
}
