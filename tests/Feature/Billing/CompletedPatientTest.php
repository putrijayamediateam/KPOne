<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

class CompletedPatientTest extends BillingTestCase
{
    public function test_zero_price_visit_completes_once_and_completed_board_is_structural(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(0);
        $this->get(route('billing.show', $visit))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')->assertInertia(fn (Assert $page) => $page->component('Billing/Show')->where('billing.can.complete', true)->missing('billing.clinicalNote'));
        $attrs = ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->lock_version];
        app(CompleteVisitationService::class)->complete($ca, $visit, $attrs);
        $this->assertSame('completed', $visit->refresh()->status);
        $this->assertNotNull($visit->completed_at);
        $this->assertSame($invoice->public_id, $visit->completion_evidence['invoice_public_id']);
        $board = app(VisitDirectoryService::class)->search($ca, ['board_status' => 'completed']);
        $this->assertSame(1, $board['total']);
        $row = $board['data']->first();
        $this->assertFalse($row['can']['update']);
        $this->assertFalse($row['can']['cancel']);
        $this->assertArrayNotHasKey('medicines', $row);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->expectException(ValidationException::class);
        app(CompleteVisitationService::class)->complete($ca, $visit, $attrs);
    }

    public function test_unpaid_invoice_cannot_complete(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->expectException(ValidationException::class);
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->lock_version]);
    }

    public function test_print_is_read_only_and_doctor_technical_admin_cannot_enter_billing(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $before = [$invoice->attributesToArray(), $visit->attributesToArray(), DB::table('audit_logs')->count()];
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Billing/Print')->missing('billing.clinicalNote'));
        $this->assertSame($before, [$invoice->fresh()->attributesToArray(), $visit->fresh()->attributesToArray(), DB::table('audit_logs')->count()]);
        foreach ([$doctor, $this->actor('technical_admin')] as $actor) {
            $this->selectBranch($actor, $visit->branch);
            $this->get(route('billing.show', $visit))->assertForbidden();
            $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id])->assertForbidden();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $visit->branch);
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.invoice.lines', [])->where('billing.can.complete', false));
    }
}
