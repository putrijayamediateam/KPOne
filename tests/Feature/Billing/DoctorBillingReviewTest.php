<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SyntheticVisitCompletion;

/**
 * BIL-37 (owner decision 24 Nov 2026 and the owner's answers): the Resident Doctor sees the bill of their own patient's
 * visit, view only. Every open is recorded (ids only) before anything is built; the projection is fresh and closed.
 */
class DoctorBillingReviewTest extends BillingTestCase
{
    public function test_only_the_resident_doctor_holds_the_view_permission(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p): bool => in_array('billing.view_readonly.branch', $p, true))->keys()->values()->all();
        $this->assertSame(['resident_doctor'], $holders);
        $this->assertNotContains('billing.view_readonly.branch', PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
    }

    public function test_the_attending_doctor_sees_exactly_the_read_only_bill_and_each_open_is_recorded(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1500, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($doctor, $visit->branch);

        $response = $this->get(route('billing.review', $visit))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $response->assertInertia(fn (Assert $page) => $page->component('Billing/Review')
            ->where('review.invoice.number', $invoice->invoice_number)->where('review.invoice.totals.totalSen', 4000)
            ->where('review.invoice.totals.paidSen', 1500)->where('review.invoice.totals.dueNowSen', 2500)
            ->where('review.invoice.payments.0.recordedBy', $ca->name)->where('review.invoice.payments.0.amountSen', 1500));
        $review = $response->viewData('page')['props']['review'];
        $this->assertSame(['visit', 'patient', 'invoice'], array_keys($review));
        $this->assertSame(['number', 'status', 'finalizedAt', 'lines', 'totals', 'payments'], array_keys($review['invoice']));
        $this->assertSame(['name', 'quantity', 'unitPriceSen', 'totalSen'], array_keys($review['invoice']['lines'][0]));
        $this->assertSame(['subtotalSen', 'adjustmentSen', 'totalSen', 'paidSen', 'panelSen', 'payLaterSen', 'dueNowSen'], array_keys($review['invoice']['totals']));
        $this->assertSame(['receivedAt', 'method', 'amountSen', 'status', 'recordedBy'], array_keys($review['invoice']['payments'][0]));
        $this->assertSame(['name', 'patientNumber'], array_keys($review['patient']));

        $log = AuditLog::query()->where('event', 'billing.reviewed')->sole();
        $this->assertSame($doctor->id, $log->actor_user_id);
        $this->assertSame(['ids' => ['visit' => $visit->id, 'patient' => $visit->patient_id, 'invoice' => $invoice->id]], $log->metadata);
        $this->get(route('billing.review', $visit))->assertOk();
        $this->assertSame(2, AuditLog::query()->where('event', 'billing.reviewed')->count());

        // Links: the consultation page and the doctor's Invoices page offer "View bill"; the Invoices page shows no money.
        $this->get(route('encounters.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billViewUrl', route('billing.review', $visit)));
    }

    public function test_the_invoices_page_links_the_own_doctor_to_the_bill_and_still_shows_no_money(): void
    {
        [$doctor, , $visit, $invoice] = $this->finalizedFixture();
        SyntheticVisitCompletion::complete($visit);
        $this->selectBranch($doctor, $visit->branch);
        $this->get(route('visits.invoice', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invoice.financial', null)->where('invoice.canSeeFinancial', false)->where('invoice.links.billing', null)
            ->where('invoice.links.billView', route('billing.review', [$visit, 'from' => 'invoices'])));
        $this->get(route('billing.review', [$visit, 'from' => 'invoices']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('backUrl', route('visits.invoice', $visit))->where('review.invoice.number', $invoice->invoice_number));
        $this->selectBranch($this->doctor(), $visit->branch);
        $this->get(route('visits.invoice', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('invoice.links.billView', null));
        $this->assertSame(1, AuditLog::query()->where('event', 'billing.reviewed')->count());
    }

    public function test_another_doctor_at_the_branch_gets_404_and_nothing_is_recorded(): void
    {
        [, , $visit] = $this->finalizedFixture();
        $other = $this->doctor();
        $this->selectBranch($other, $visit->branch);
        $this->get(route('billing.review', $visit))->assertNotFound();
        $this->get(route('encounters.show', $visit))->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', 'billing.reviewed')->count());
    }

    public function test_before_the_consultation_starts_the_assigned_doctor_sees_not_finalised_yet(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $doctor);
        $this->selectBranch($doctor, $visit->branch);
        $this->get(route('billing.review', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('review.invoice', null));
        $this->assertSame(['ids' => ['visit' => $visit->id, 'patient' => $visit->patient_id]], AuditLog::query()->where('event', 'billing.reviewed')->sole()->metadata);
        $this->selectBranch($other = $this->doctor(), $visit->branch);
        $this->get(route('billing.review', $visit))->assertNotFound();
    }

    public function test_once_the_consultation_started_the_attending_doctor_decides_not_the_assigned_one(): void
    {
        [$doctor, , $visit] = $this->finalizedFixture();
        $reassigned = $this->doctor();
        DB::table('visits')->where('id', $visit->id)->update(['assigned_doctor_user_id' => $reassigned->id]);
        $this->selectBranch($reassigned, $visit->branch);
        $this->get(route('billing.review', $visit))->assertNotFound();
        $this->selectBranch($doctor, $visit->branch);
        $this->get(route('billing.review', $visit))->assertOk();
        $this->assertSame(1, AuditLog::query()->where('event', 'billing.reviewed')->count());
    }

    public function test_a_visit_at_another_branch_is_404_even_for_its_doctor(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $otherBranch = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '<>', $visit->branch_id)->firstOrFail();
        $elsewhere = $this->actor('resident_doctor', $otherBranch);
        DB::table('visits')->where('id', $visit->id)->update(['assigned_doctor_user_id' => $elsewhere->id]);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->get(route('billing.review', $visit))->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', 'billing.reviewed')->count());
    }

    public function test_a_recorder_the_doctor_may_not_see_reads_staff_member_and_head_records_nothing(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        Role::findByName('resident_doctor', 'web')->revokePermissionTo('staff.view.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($doctor->fresh(), $visit->branch);
        $this->call('HEAD', route('billing.review', $visit))->assertNoContent();
        $this->assertSame(0, AuditLog::query()->where('event', 'billing.reviewed')->count());
        $this->get(route('billing.review', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('review.invoice.payments.0.recordedBy', 'Staff member'));
    }

    public function test_a_draft_is_never_shown(): void
    {
        [$doctor, $ca, $visit] = $this->billingFixture();
        app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $this->selectBranch($doctor, $visit->branch);
        $this->get(route('billing.review', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('review.invoice', null));
        $this->get(route('encounters.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billViewUrl', null));
    }

    public function test_a_void_invoice_shows_only_void(): void
    {
        [$doctor, , $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $visit->branch);
        $this->post(route('billing.void', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'reason' => 'Synthetic void for review'])->assertSessionHasNoErrors();
        $this->selectBranch($doctor, $visit->branch);
        $this->get(route('billing.review', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('review.invoice.status', 'voided')->where('review.invoice.totals', null)->where('review.invoice.lines', [])->where('review.invoice.payments', []));
    }

    public function test_the_doctor_still_cannot_reach_any_billing_write_or_print_and_others_cannot_open_the_review(): void
    {
        [$doctor, , $visit, $invoice] = $this->finalizedFixture();
        $this->selectBranch($doctor, $visit->branch);
        $this->get(route('billing.show', $visit))->assertForbidden();
        $this->get(route('billing.payments', $visit))->assertForbidden();
        $this->get(route('billing.print', [$visit, $invoice]))->assertForbidden();
        $this->post(route('billing.issue', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('billing.review', $visit))->assertStatus(405);
        foreach (['ca', 'finance_officer', 'director', 'panel_officer'] as $role) {
            $this->selectBranch($this->actor($role), $visit->branch);
            $this->get(route('billing.review', $visit))->assertForbidden();
        }
        $this->assertSame(0, AuditLog::query()->where('event', 'billing.reviewed')->count());
    }

    public function test_the_review_route_is_get_only(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'billing/review'));
        $this->assertSame([['GET', 'HEAD']], $routes->map(fn ($route): array => $route->methods())->values()->all());
        $this->assertStringNotContainsString('DB::transaction', File::get(app_path('Domain/Visit/Billing/Review/BillingReviewService.php')));
        $this->assertStringNotContainsString('lockForUpdate', File::get(app_path('Domain/Visit/Billing/Review/BillingReviewService.php')));
    }

    public function test_the_migration_grants_additively_and_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_25_200100_grant_doctor_billing_view_permission.php');
        Role::findByName('resident_doctor', 'web')->revokePermissionTo('billing.view_readonly.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $kept = Role::findByName('resident_doctor', 'web')->permissions()->count();
        $migration->up();
        $migration->up();
        $migration->down();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(Role::findByName('resident_doctor', 'web')->hasPermissionTo('billing.view_readonly.branch'));
        $this->assertSame($kept + 1, Role::findByName('resident_doctor', 'web')->permissions()->count());
        $this->assertSame(1, DB::table('permissions')->where('name', 'billing.view_readonly.branch')->count());
    }
}
