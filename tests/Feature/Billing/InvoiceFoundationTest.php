<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use Illuminate\Validation\ValidationException;
use LogicException;

class InvoiceFoundationTest extends BillingTestCase
{
    public function test_consultation_only_invoice_is_exact_immutable_and_non_stock_mutating(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $this->assertSame('finalized', $invoice->status);
        $this->assertSame('KPI-00000001', $invoice->invoice_number);
        $this->assertSame(4000, $invoice->total_sen);
        $this->assertSame('consultation', InvoiceLine::query()->sole()->line_type);
        $this->assertSame('registered', $visit->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->expectException(LogicException::class);
        $invoice->forceFill(['total_sen' => 1])->save();
    }

    public function test_explicit_zero_price_is_valid(): void
    {
        [, , , $invoice] = $this->finalizedFixture(0);
        $this->assertSame(0, $invoice->total_sen);
        $this->assertSame('finalized', $invoice->status);
    }

    public function test_missing_price_blocks_and_repeated_draft_build_does_not_duplicate_sources(): void
    {
        [, $ca, $visit, , $charge] = $this->billingFixture();
        $builder = app(BillingBuilderService::class);
        $attrs = ['expected_branch_id' => $visit->branch_id, 'lock_version' => null];
        $draft = $builder->build($ca, $visit, $attrs);
        $again = $builder->build($ca, $visit, $attrs);
        $this->assertSame($draft->id, $again->id);
        $this->assertSame($draft->lock_version, $again->lock_version);
        $this->assertDatabaseCount('invoice_lines', 1);
        $charge->forceFill(['is_active' => false])->save();
        $this->expectException(ValidationException::class);
        $builder->finalize($ca, $visit, $draft, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $draft->lock_version]);
    }

    public function test_changed_price_cannot_silently_finalize_old_draft(): void
    {
        [, $ca, $visit, , $charge, $book] = $this->billingFixture();
        $builder = app(BillingBuilderService::class);
        $draft = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $entry = new PriceEntry;
        $entry->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => 4500, 'version' => 2, 'effective_at' => now()->subSecond(), 'published_by_user_id' => $ca->id])->save();
        $this->expectException(ValidationException::class);
        $builder->finalize($ca, $visit, $draft, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $draft->lock_version]);
    }
}
