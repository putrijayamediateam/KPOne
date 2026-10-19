<?php

namespace Tests\Feature\Visit;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\SyntheticVisitCompletion;

class CompletedInvoiceTest extends BillingTestCase
{
    public function test_invoice_is_an_explicit_non_clinical_projection_and_preserves_the_ledger(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        ClinicalEncounter::query()->where('visit_id', $visit->id)->update(['clinical_note' => 'Synthetic confidential consultation text']);
        SyntheticVisitCompletion::complete($visit);
        $before = app(FinancialLedger::class)->state($invoice);

        $this->actingAs($ca)->get(route('visits.invoice', $visit))
            ->assertOk()->assertHeaderContains('Cache-Control', 'no-store')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Billing/Invoice')
                ->where('invoice.patient.patientNumber', $visit->patient->patient_number)
                ->where('invoice.links.consultation', route('visits.history', $visit))
                ->where('invoice.financial.state', $before)
                ->has('invoice.financial.lines', 1)
                ->missing('invoice.consultation')->missing('invoice.medicines')->missing('invoice.services')
                ->missing('invoice.visit.reason')->missing('invoice.visit.panelNote'));
        $response = $this->getJson(route('visits.invoice', $visit), ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '']);
        $response->assertOk()->assertDontSee('Synthetic confidential consultation text');
        $this->assertSame(
            ['patient', 'visit', 'notes', 'financial', 'outstanding', 'canSeeFinancial', 'canSeeLines', 'links'],
            array_keys($response->json('props.invoice')),
        );
        $this->assertSame(['url', 'branchId', 'invoiceNumber', 'canAdd'], array_keys($response->json('props.invoice.notes')));
        $this->assertSame($before, app(FinancialLedger::class)->state($invoice->refresh()));
        $this->assertSame('finalized', $invoice->status);

        // The former clinical view and DS-01c records remain a separate, authorised destination.
        $this->get(route('visits.history', $visit))->assertInertia(fn (Assert $page) => $page
            ->component('Visits/History')
            ->where('history.consultation.note', 'Synthetic confidential consultation text')
            ->has('history.medicines')->has('history.services'));
    }

    public function test_invoice_keeps_financial_line_print_and_identity_permissions_independent(): void
    {
        [, $ca, $visit] = $this->finalizedFixture();
        SyntheticVisitCompletion::complete($visit);
        $ca->syncRoles([]);
        $ca->givePermissionTo(['visits.history.view.branch', 'billing.summary.branch']);
        $this->actingAs($ca)->get(route('visits.invoice', $visit))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invoice.canSeeFinancial', true)
            ->where('invoice.canSeeLines', false)
            ->has('invoice.financial.lines', 0)
            ->where('invoice.financial.printUrl', null)
            ->where('invoice.patient.profileUrl', null)
            ->where('invoice.links.billing', null));
    }

    public function test_doctor_keeps_clinical_history_without_receiving_financial_data(): void
    {
        [$doctor, , $visit] = $this->finalizedFixture();
        SyntheticVisitCompletion::complete($visit);
        $this->selectBranch($doctor, $visit->branch);
        $this->actingAs($doctor)->get(route('visits.invoice', $visit))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invoice.financial', null)
            ->where('invoice.canSeeFinancial', false)
            ->where('invoice.links.billing', null));
        $this->get(route('visits.history', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Visits/History')->has('history.consultation'));
    }

    public function test_invoice_denies_unapproved_roles_non_completed_visits_and_other_branches(): void
    {
        [, $ca, $visit] = $this->finalizedFixture();
        $this->actingAs($ca)->get(route('visits.invoice', $visit))->assertNotFound();
        SyntheticVisitCompletion::complete($visit);
        foreach (['administrator', 'technical_admin', 'finance_officer', 'panel_officer', 'queue_display'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $visit->branch);
            $this->actingAs($actor)->get(route('visits.invoice', $visit))->assertForbidden();
        }
        $otherBranch = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '<>', $visit->branch_id)->firstOrFail();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->actingAs($other)->get(route('visits.invoice', $visit))->assertNotFound();
    }
}
