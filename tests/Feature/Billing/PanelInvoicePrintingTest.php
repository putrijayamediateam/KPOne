<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\IssuedBillingDocument;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\PanelInvoicePrinting;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/**
 * PCA-1 (owner decision 2026-11-13): a Panel patient never gets a printed invoice; a receipt still prints for the cash the
 * patient paid. Invoice copies issued before the rule stay viewable but are not reprinted. Finance Officers and Directors
 * handle Panel claims as Panel Officers do.
 */
class PanelInvoicePrintingTest extends BillingTestCase
{
    public function test_a_self_pay_invoice_still_prints(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.can.print', true)->where('billing.can.printInvoice', true));
        $this->issuedInvoicePage($visit, $invoice)->assertOk();
        $this->assertFalse(PanelInvoicePrinting::blocked($visit, $invoice));
    }

    public function test_a_proposed_panel_share_stops_invoice_printing_and_an_earlier_copy_is_view_only(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->selectBranch($ca);
        $this->issuedInvoicePage($visit, $invoice)->assertOk(); // Printed while still self-pay.
        $this->proposePanel($ca, $visit, $invoice);

        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page
            ->where('billing.can.print', true)->where('billing.can.printInvoice', false)
            ->where('billing.issuedCopies.0.reprintable', false)
            ->where('billing.issuedCopies.0.viewUrl', fn (?string $url) => is_string($url) && str_contains($url, '/issued/prints/')));
        $document = IssuedBillingDocument::query()->sole();
        $copies = $this->get(route('billing.show', $visit))->viewData('page')['props']['billing']['issuedCopies'];
        $this->get($copies[0]['viewUrl'])->assertOk();

        $before = DB::table('issued_billing_document_prints')->count();
        $this->post(route('billing.issued.reprint', [$visit, $document->public_id]), $this->input($visit))->assertSessionHasErrors(['document' => PanelInvoicePrinting::MESSAGE]);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertSessionHasErrors(['document' => PanelInvoicePrinting::MESSAGE]);
        $this->assertSame($before, DB::table('issued_billing_document_prints')->count());
        $this->assertSame(1, IssuedBillingDocument::query()->count());
        $this->assertTrue(PanelInvoicePrinting::blocked($visit, $invoice->refresh()));
    }

    public function test_the_view_page_of_an_earlier_panel_invoice_copy_is_view_only_and_a_receipt_copy_is_not(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->selectBranch($ca);
        $this->issuedInvoicePage($visit, $invoice)->assertInertia(fn (Assert $page) => $page->where('issued.viewOnly', false));
        $this->proposePanel($ca, $visit, $invoice);
        $copies = $this->get(route('billing.show', $visit))->viewData('page')['props']['billing']['issuedCopies'];
        $before = DB::table('issued_billing_document_prints')->count();
        $this->get($copies[0]['viewUrl'].'?from=billing')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Billing/Print')->where('issued.viewOnly', true));
        $this->assertSame($before, DB::table('issued_billing_document_prints')->count());
    }

    public function test_a_stray_panel_proposal_blocks_printing_until_the_invoice_is_voided_and_reissued(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->proposePanel($ca, $visit, $invoice);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.can.printInvoice', false));

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.void', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'reason' => 'Synthetic Panel proposal made in error'])->assertSessionHasNoErrors();
        $builder = app(BillingBuilderService::class);
        $reissued = $builder->build($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $reissued = $builder->finalize($ca, $visit, $reissued, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $reissued->lock_version]);
        $this->assertNotSame($invoice->id, $reissued->id);

        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.can.printInvoice', true));
        $this->issuedInvoicePage($visit, $reissued)->assertOk();
    }

    public function test_the_receipt_for_cash_the_panel_patient_paid_still_prints(): void
    {
        [$ca, , $visit, $invoice] = $this->completedPanelVisit();
        $payment = Payment::query()->sole();
        $this->selectBranch($ca);
        $this->issuedReceiptPage($visit, $invoice, $payment)->assertOk();
        $this->assertSame(['receipt'], IssuedBillingDocument::query()->pluck('document_type')->all());
        $this->post(route('billing.issued.reprint', [$visit, IssuedBillingDocument::query()->sole()->public_id]), $this->input($visit))->assertSessionHasNoErrors();
    }

    public function test_the_completed_invoice_page_offers_no_print_for_a_panel_patient(): void
    {
        [$ca, , $visit] = $this->completedPanelVisit();
        $this->selectBranch($ca);
        $this->get(route('visits.invoice', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invoice.financial.printUrl', null)->where('invoice.financial.panelNoInvoicePrint', true));
    }

    public function test_a_visit_registered_with_panel_coverage_counts_as_panel_even_before_a_share_is_proposed(): void
    {
        $visit = (new Visit)->forceFill(['coverage_type' => 'panel']);
        $invoice = (new Invoice)->forceFill(['id' => 0]);
        $this->assertTrue(PanelInvoicePrinting::blocked($visit, $invoice));
        $visit->coverage_type = 'self_pay';
        $this->assertFalse(PanelInvoicePrinting::blocked($visit, $invoice));
    }

    public function test_finance_officer_and_director_hold_the_panel_claim_permissions_and_the_grant_is_additive(): void
    {
        $permissions = ['panel.claims.manage.branch', 'panel.claims.patient_identity.view.branch', 'panel.claims.clinical_filters.branch'];
        foreach (['panel_officer', 'finance_officer', 'director'] as $role) {
            $actor = $this->actor($role);
            foreach ($permissions as $permission) {
                $this->assertTrue($actor->can($permission), "$role $permission");
            }
        }
        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'administrator', 'technical_admin'] as $role) {
            $this->assertFalse($this->actor($role)->can('panel.claims.manage.branch'), $role);
        }

        $snapshot = fn (): string => DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $before = $snapshot();
        $migration = require base_path('database/migrations/2026_11_15_000100_grant_panel_claims_access_to_finance_and_director.php');
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertSame($before, $snapshot());

        // A database that never received the grant gets it, and the Panel Officer's own grant is untouched.
        $roles = DB::table('roles')->whereIn('name', ['finance_officer', 'director'])->pluck('id');
        $ids = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        DB::table('role_has_permissions')->whereIn('role_id', $roles)->whereIn('permission_id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $migration->up();
        $this->assertSame($before, $snapshot());
    }

    /** @return array{expected_branch_id: int, idempotency_key: string, return_to: string} */
    private function input(Visit $visit): array
    {
        return ['expected_branch_id' => (int) $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'billing'];
    }

    private function proposePanel(User $ca, Visit $visit, Invoice $invoice): CoverageAllocation
    {
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '3000',
            'panel_id' => $panel->id, 'reason' => 'Synthetic Panel responsibility'])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();

        return CoverageAllocation::query()->where('invoice_id', $invoice->id)->sole();
    }

    /** @return array{User, User, Visit, Invoice} RM 40 invoice: Panel RM 30 approved, patient paid RM 10 cash, completed. */
    private function completedPanelVisit(): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $panelOfficer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $panelOfficer->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $allocation = $this->proposePanel($ca, $visit, $invoice);
        $this->selectBranch($panelOfficer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', $allocation->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1000',
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();

        return [$ca, $panelOfficer, $visit->refresh(), $invoice->refresh()];
    }
}
