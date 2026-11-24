<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Visit\Billing\Models\IssuedBillingDocument;
use App\Domain\Visit\Billing\Models\IssuedBillingDocumentPrint;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-36 (owner decision 24 Nov 2026; answers 1C, 2A and 3A): the Finance Officer and the Director print invoices and
 * receipts. The Finance Officer sees the patient's IC/passport and phone on the paper through the narrow
 * billing.print.patient_identity.branch, never through the patient pages, and each time they open an issued copy an
 * audit row is written (ids only).
 */
class FinanceDirectorPrintTest extends BillingTestCase
{
    public function test_exactly_these_roles_hold_the_print_permissions(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p): bool => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['ca', 'ca_supervisor', 'director', 'finance_officer', 'medical_assistant'], $holders('billing.print.branch'));
        $this->assertSame(['finance_officer'], $holders('billing.print.patient_identity.branch'));
        foreach (['billing.print.branch', 'billing.print.patient_identity.branch'] as $permission) {
            $this->assertNotContains($permission, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
        }
    }

    public function test_the_finance_officer_prints_an_invoice_and_a_receipt_without_patient_pages_and_each_open_is_audited(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $this->assertFalse($finance->can('patients.view.organisation'));
        $this->selectBranch($finance, $visit->branch);

        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.can.printInvoice', true)->where('billing.patient.profileUrl', null));
        $this->issuedInvoicePage($visit, $invoice->refresh())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Print')->where('issued.label', 'original')->where('billing.invoice.number', $invoice->invoice_number));
        $this->issuedReceiptPage($visit, $invoice, $payment)->assertOk();
        $this->assertSame(['invoice', 'receipt'], IssuedBillingDocument::query()->orderBy('id')->pluck('document_type')->all());
        $this->assertSame(2, AuditLog::query()->where('event', 'billing.document_viewed')->count());

        // A reprint and a reopen of the invoice copy: one more print, one more audit row on the open.
        $document = IssuedBillingDocument::query()->where('document_type', 'invoice')->sole();
        $reprint = $this->post(route('billing.issued.reprint', [$visit, $document->public_id]), ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'billing']);
        $reprint->assertSessionHasNoErrors()->assertRedirect();
        $this->get((string) $reprint->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('issued.label', 'copy'));
        $this->assertSame(3, AuditLog::query()->where('event', 'billing.document_viewed')->count());
        $log = AuditLog::query()->where('event', 'billing.document_viewed')->latest('id')->firstOrFail();
        $this->assertSame($finance->id, $log->actor_user_id);
        $this->assertSame(['document_type' => 'invoice', 'version' => 1], $log->metadata);

        // The narrow grant opens no patient page.
        $this->get('/patients')->assertForbidden();
        $this->get('/patients/'.rawurlencode($visit->patient->patient_number))->assertForbidden();
    }

    public function test_the_director_prints_with_patient_view_and_leaves_no_view_audit(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $director = $this->actor('director');
        $this->selectBranch($director, $visit->branch);
        $this->issuedInvoicePage($visit, $invoice)->assertOk()->assertInertia(fn (Assert $page) => $page->where('issued.label', 'original'));
        $this->assertSame(1, IssuedBillingDocumentPrint::query()->where('printed_by_user_id', $director->id)->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'billing.document_viewed')->count());
    }

    public function test_without_the_identity_grant_the_finance_officer_cannot_issue_or_open_a_copy(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->issuedInvoicePage($visit, $invoice)->assertOk(); // Issued by the CA.
        $print = IssuedBillingDocumentPrint::query()->sole();
        Role::findByName('finance_officer', 'web')->revokePermissionTo('billing.print.patient_identity.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $visit->branch);
        $this->post(route('billing.issue', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'billing'])->assertSessionHasErrors('document');
        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertRedirect();
        $this->assertSame(1, IssuedBillingDocumentPrint::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'billing.document_viewed')->count());
    }

    public function test_the_migration_grants_additively_and_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_25_200000_grant_billing_print_to_finance_and_director.php');
        foreach (['finance_officer' => ['billing.print.branch', 'billing.print.patient_identity.branch'], 'director' => ['billing.print.branch']] as $role => $permissions) {
            Role::findByName($role, 'web')->revokePermissionTo($permissions);
        }
        Permission::query()->where('name', 'billing.print.patient_identity.branch')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $kept = Role::findByName('finance_officer', 'web')->permissions()->count();

        $migration->up();
        $migration->up();
        $migration->down();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Role::findByName('finance_officer', 'web')->hasPermissionTo('billing.print.branch'));
        $this->assertTrue(Role::findByName('finance_officer', 'web')->hasPermissionTo('billing.print.patient_identity.branch'));
        $this->assertTrue(Role::findByName('director', 'web')->hasPermissionTo('billing.print.branch'));
        $this->assertFalse(Role::findByName('director', 'web')->hasPermissionTo('billing.print.patient_identity.branch'));
        $this->assertSame($kept + 2, Role::findByName('finance_officer', 'web')->permissions()->count());
        $this->assertSame(1, DB::table('permissions')->where('name', 'billing.print.patient_identity.branch')->count());
    }
}
