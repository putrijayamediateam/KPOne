<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PrintLayoutReleaseTest extends BillingTestCase
{
    public function test_split_and_reversed_receipts_keep_original_amount_method_dates_minimal_patient_routes_and_all_rows(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic saved method', 'requires_reference' => false, 'is_active' => true]);
        $method->save();
        $payments = [];
        foreach ([1000, 1500] as $amount) {
            $payments[] = app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        }
        $method->forceFill(['name' => 'Synthetic renamed method'])->save();
        $routes = $this->routeContract();
        foreach ([false, true] as $reversed) {
            if ($reversed) {
                $finance = $this->actor('finance_officer');
                $this->selectBranch($finance, $visit->branch);
                app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $payments[0], ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $payments[0]->lock_version, 'recording_error_only' => true, 'reason' => 'Synthetic recording error']);
            }
            $this->selectBranch($ca, $visit->branch);
            $before = $this->allRows();
            $this->assertNotEmpty($before['invoices']);
            $this->assertNotEmpty($before['payments']);
            $invoiceResponse = $this->get(route('billing.print', [$visit, $invoice]))->assertOk();
            $invoiceProps = $invoiceResponse->viewData('page')['props'];
            $this->assertSame(4000, $invoiceProps['billing']['invoice']['state']['total']);
            $this->assertSame($reversed ? 1500 : 2500, $invoiceProps['billing']['invoice']['state']['self_pay']);
            $this->assertSame($reversed ? 2500 : 1500, $invoiceProps['billing']['invoice']['state']['due_now']);
            $this->assertSame($invoice->finalized_at->setTimezone($visit->branch->timezone)->format('j M Y, g:i A'), $invoiceProps['billing']['invoice']['finalizedAt']);
            foreach ($payments as $index => $payment) {
                $response = $this->get(route('billing.receipt', [$visit, $invoice, $payment]))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
                $page = $response->viewData('page');
                $this->assertSame('Billing/Print', $page['component']);
                $props = $page['props'];
                $this->assertSame(['name', 'patientNumber', 'profileUrl'], array_keys($props['billing']['patient']));
                $this->assertSame($visit->patient->full_name, $props['billing']['patient']['name']);
                $this->assertSame($visit->patient->patient_number, $props['billing']['patient']['patientNumber']);
                $this->assertArrayNotHasKey('billTo', $props);
                $this->assertSame($index === 0 ? 1000 : 1500, $props['receipt']['amountSen']);
                $this->assertSame('Synthetic saved method', $props['receipt']['method']);
                $this->assertSame($reversed && $index === 0 ? 'reversed' : 'posted', $props['receipt']['status']);
                $this->assertSame($payment->receipt_number, $props['receipt']['number']);
                $this->assertSame($payment->received_at->setTimezone($visit->branch->timezone)->format('j M Y, g:i A'), $props['receipt']['receivedAt']);
            }
            $this->assertSame($before, $this->allRows(), 'All public rows must remain identical after invoice and receipt reads.');
            $this->assertSame($routes, $this->routeContract());
        }
    }

    private function routeContract(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): array => [$route->methods(), $route->uri(), $route->getName(), $route->getActionName(), $route->gatherMiddleware()])->all();
    }

    private function allRows(): array
    {
        $snapshot = [];
        foreach (Schema::getTables() as $table) {
            if ($table['schema'] !== 'public') {
                continue;
            }
            $query = DB::table($table['name']);
            $hasId = Schema::hasColumn($table['name'], 'id');
            if ($hasId) {
                $query->orderBy('id');
            }
            $rows = $query->get()->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            if (! $hasId) {
                sort($rows);
            }
            $snapshot[$table['name']] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }
}
