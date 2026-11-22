<?php

namespace Tests\Feature\Approvals;

use App\Domain\Access\BranchAccessService;
use App\Domain\Approvals\Sources\CashCloseSource;
use App\Domain\Approvals\Sources\PurchaseRequestSource;
use App\Domain\Approvals\Sources\SupplierInvoiceSource;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\PurchaseRequestService;
use App\Domain\Organisation\Inventory\Services\SupplierInvoiceService;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/**
 * APPR-1a contract: each source counts what its owning page lists as waiting, minus what the person made themselves (the
 * maker never decides their own). If an owning page changes its rule, the matching test here fails.
 */
final class ApprovalSourceContractTest extends VisitTestCase
{
    private function staff(string $role): User
    {
        $actor = (new VerifiedReceivingFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    public function test_purchase_requests_count_the_pages_waiting_list_without_the_viewers_own(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $input = fn () => ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'destination_location_public_id' => $f['location']->public_id,
            'preferred_supplier_public_id' => $f['supplier']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '10', 'note' => 'Running low']]];
        $ca = $this->staff('ca');
        $supervisor = $this->staff('ca_supervisor');
        $this->actingAs($ca)->post(route('inventory.purchase-requests.store'), $input())->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('inventory.purchase-requests.store'), $input())->assertSessionHasNoErrors();

        $page = app(PurchaseRequestService::class)->page($supervisor, $this->branch, []);
        $this->assertSame(2, $page['counts']['waiting']);
        $this->assertSame(1, app(PurchaseRequestSource::class)->count($supervisor, $this->branch), 'the supervisor does not decide their own request');
        $this->assertCount(1, app(PurchaseRequestSource::class)->items($supervisor, $this->branch, 5));
    }

    public function test_supplier_invoices_count_the_pages_waiting_list_without_the_viewers_own(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('2.000');
        app(ProcurementService::class)->receive($this->staffAs($f['receiver']), $f['order'], $f['payload']);
        $service = app(SupplierInvoiceService::class);
        $line = $f['order']->lines()->orderBy('id')->firstOrFail();
        $record = fn () => ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'purchase_order_public_id' => $f['order']->public_id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(6)), 'invoice_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
            'lines' => [['line_public_id' => $line->public_id, 'quantity' => '3', 'total' => '2.50']]];
        $finance = $this->staff('finance_officer');
        $service->record($finance, $record());

        $this->assertSame(1, $service->page($finance, $this->branch, [])['counts']['waiting']);
        $this->assertSame(0, app(SupplierInvoiceSource::class)->count($finance, $this->branch), 'the recorder does not decide their own invoice');
        $this->assertSame(1, app(SupplierInvoiceSource::class)->count($f['director'], $this->branch));
    }

    public function test_a_waiting_cash_count_is_counted_for_another_approver_and_not_the_counter(): void
    {
        $counter = $this->staff('ca');
        $day = CarbonImmutable::now($this->branch->timezone)->subDay()->startOfDay();
        $this->actingAs($counter)->post(route('cash-closings.store'), ['expected_branch_id' => $this->branch->id, 'business_date' => $day->toDateString(),
            'counted_cash' => '0', 'variance_reason' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(1, CashClosing::query()->count());
        Cache::flush();
        $supervisor = $this->staff('ca_supervisor');

        $this->assertSame(1, app(CashCloseSource::class)->count($supervisor, $this->branch));
        $this->assertSame(0, app(CashCloseSource::class)->count($counter, $this->branch), 'the counter never approves their own count');
        $this->actingAs($supervisor)->get(route('cash-closings.index', ['date' => $day->toDateString()]))->assertInertia(fn ($p) => $p->where('cashClosing.status', 'waiting'));
    }

    private function staffAs(User $user): User
    {
        app(BranchAccessService::class)->select($user, $this->branch);

        return $user;
    }
}
