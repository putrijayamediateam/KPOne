<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

class OtcBillingTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @param array<string, mixed> $f */
    private function price(array $f, int $sen = 250): void
    {
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic OTC prices', 'currency' => 'MYR'])->save();
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => 'medicine', 'code' => 'OTC-'.Str::upper(Str::random(5)), 'display_name' => 'Synthetic OTC charge', 'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => $f['medicine']->order_unit, 'is_active' => true])->save();
        (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $sen, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
    }

    /** @param array<string, mixed> $f */
    private function dispensed(array $f, string $quantity = '2.000'): DispensaryCase
    {
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, $quantity));
        $this->confirmAllergy($f, $case);

        return $this->complete($f, $case);
    }

    /** @param array<string, mixed> $f */
    private function build(array $f): Invoice
    {
        return app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
    }

    /** @param array<string, mixed> $f */
    private function finalized(array $f): Invoice
    {
        $invoice = $this->build($f);

        return app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);
    }

    public function test_an_otc_invoice_has_the_dispensed_medicine_only_and_no_consultation_line(): void
    {
        $f = $this->fixture();
        $this->price($f, 250);
        $case = $this->dispensed($f, '2.000');

        $invoice = $this->build($f);

        $this->assertNull($invoice->consultation_checkout_id);
        $this->assertSame($case->id, $invoice->dispensary_case_id);
        $this->assertSame(500, $invoice->total_sen);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame('medicine', $lines->sole()->line_type);
        $this->assertSame('2.000', $lines->sole()->quantity);
        $this->assertSame(0, InvoiceLine::query()->where('invoice_id', $invoice->id)->where('line_type', 'consultation')->count());
    }

    public function test_building_needs_a_completed_otc_case_and_a_governed_price(): void
    {
        $f = $this->fixture();
        $this->price($f);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        try {
            $this->build($f);
            $this->fail('An OTC case still being dispensed must not be billed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice', $e->errors());
        }

        $g = $this->fixture();
        $this->dispensed($g);
        $this->expectException(ValidationException::class);
        $this->build($g);
    }

    public function test_an_otc_visit_is_paid_and_completed_without_a_doctor_or_queue(): void
    {
        $f = $this->fixture();
        $this->price($f, 300);
        $this->dispensed($f, '2.000');
        $invoice = $this->finalized($f);
        (new PaymentMethod)->forceFill(['organisation_id' => $f['visit']->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $visit = $f['visit']->refresh();

        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Billing/Show')->where('billing.can.complete', false)->has('billing.invoice.lines', 1));
        app(PaymentService::class)->add($f['ca'], $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '600', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $invoice->refresh();
        app(CompleteVisitationService::class)->complete($f['ca'], $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->lock_version]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Fire the deferred reconciliation and completed-visit triggers now: the test transaction never commits.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->refresh()->status);
        $this->assertSame($invoice->public_id, $visit->completion_evidence['invoice_public_id']);
        $this->assertSame(0, DB::table('queue_entries')->where('visit_id', $visit->id)->count());
        $this->assertSame(0, DB::table('consultation_checkouts')->where('visit_id', $visit->id)->count());
    }

    public function test_an_unpaid_otc_visit_cannot_complete(): void
    {
        $f = $this->fixture();
        $this->price($f);
        $this->dispensed($f);
        $invoice = $this->finalized($f);
        $visit = $f['visit']->refresh();

        $this->expectException(ValidationException::class);
        app(CompleteVisitationService::class)->complete($f['ca'], $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->lock_version]);
    }

    public function test_a_consultation_invoice_still_needs_its_checkout(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint.');
        }
        $f = $this->fixture();
        $this->price($f);
        $this->dispensed($f);
        $invoice = $this->build($f);

        $this->expectException(QueryException::class);
        DB::table('invoices')->where('id', $invoice->id)->update(['dispensary_case_id' => null]);
    }

    public function test_the_database_proves_every_otc_line_against_a_dispensed_item(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only reconciliation.');
        }
        $f = $this->fixture();
        $this->price($f, 250);
        $this->dispensed($f, '2.000');
        $invoice = $this->build($f);
        $line = InvoiceLine::query()->where('invoice_id', $invoice->id)->sole();

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($line, $invoice): void {
            DB::table('invoice_lines')->where('id', $line->id)->update(['quantity' => '3.000', 'line_total_sen' => 750]);
            DB::table('invoices')->where('id', $invoice->id)->update(['subtotal_sen' => 750, 'total_sen' => 750]);
            // The proof is a deferred constraint trigger; the test transaction never commits, so fire it now.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_the_database_refuses_an_otc_visit_completion_without_a_finalized_invoice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only trigger.');
        }
        $f = $this->fixture();
        $this->price($f);
        $this->dispensed($f);
        $this->build($f);

        $this->expectException(QueryException::class);
        DB::table('visits')->where('id', $f['visit']->id)->update(['status' => 'completed', 'completed_at' => now(), 'completion_evidence' => json_encode([])]);
    }

    public function test_the_invoices_page_shows_an_otc_visit_with_only_what_was_dispensed(): void
    {
        $f = $this->fixture();
        $this->price($f, 250);
        $this->dispensed($f, '2.000');
        $this->finalized($f);
        $visit = $f['visit']->refresh();
        $visit->forceFill(['status' => Visit::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->get(route('visits.history', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Visits/History')
            ->where('history.visit.type', 'otc')
            ->where('history.consultation', null)
            ->has('history.medicines', 1)
            ->where('history.medicines.0.source', 'ca')
            ->where('history.medicines.0.changeState', 'added')
            ->where('history.medicines.0.quantityOrdered', null)
            ->where('history.medicines.0.dispensedQuantity', '2.000')
            ->where('history.medicines.0.dispensed.dosage', 'One tablet')
            ->where('history.services', [])
            ->where('history.financial.state.total', 500));
    }
}
