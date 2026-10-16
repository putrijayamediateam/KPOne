<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceActivityTest extends BillingTestCase
{
    private function url($visit, $invoice = null, array $query = []): string
    {
        return ($invoice ? route('billing.activity', [$visit, $invoice]) : route('visits.invoice.activity', $visit)).'?'.http_build_query(['expected_branch_id' => $visit->branch_id, ...$query]);
    }

    private function pay($ca, $visit, $invoice, string $amount = '1000')
    {
        $method = PaymentMethod::query()->where('organisation_id', $visit->organisation_id)->where('code', 'cash')->first();
        if (! $method) {
            $method = new PaymentMethod;
            $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic Cash'])->save();
        }

        return app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'reference' => 'SYNTHETIC-REFERENCE']);
    }

    public function test_receipts_reversal_and_finalisation_are_projected_once_without_mutation_or_private_fields(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $payment = $this->pay($ca, $visit, $invoice);
        $finance = $this->actor('finance_officer');
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice->refresh(), $payment, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $payment->lock_version, 'recording_error_only' => true, 'reason' => 'Synthetic secret correction text']);
        app(AuditRecorder::class)->record('billing.payment_recorded', $invoice, ['clinical_note' => 'Synthetic clinical sentinel', 'secret' => 'Synthetic secret sentinel'], $ca, $visit->branch);
        $before = [$invoice->refresh()->getAttributes(), $visit->refresh()->getAttributes(), $payment->refresh()->getAttributes(), DB::table('audit_logs')->count(), app(FinancialLedger::class)->state($invoice)];
        $response = $this->actingAs($ca)->getJson($this->url($visit, $invoice))->assertOk()->assertHeaderContains('Cache-Control', 'no-store');
        $rows = collect($response->json('data'));
        $this->assertSame(1, $rows->where('message', 'Payment recorded')->count());
        $this->assertSame(1, $rows->where('message', 'Invoice finalised')->count());
        $this->assertSame(1000, $rows->firstWhere('message', 'Payment recorded')['amountSen']);
        $this->assertSame('Synthetic Cash', $rows->firstWhere('message', 'Payment recorded')['method']);
        $this->assertSame(1000, $rows->firstWhere('message', 'Payment recording reversed (not a cash refund)')['amountSen']);
        foreach (['Synthetic secret correction text', 'Synthetic clinical sentinel', 'Synthetic secret sentinel', 'SYNTHETIC-REFERENCE', 'metadata', 'request_id', 'ip_address', 'payload_hash', 'idempotency_key'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->assertSame($before, [$invoice->refresh()->getAttributes(), $visit->refresh()->getAttributes(), $payment->refresh()->getAttributes(), DB::table('audit_logs')->count(), app(FinancialLedger::class)->state($invoice)]);
        $this->assertSame(['data', 'nextCursor'], array_keys($response->json()));
        $this->assertSame(['id', 'message', 'scope', 'amountSen', 'method', 'actor', 'occurredAt'], array_keys($rows->first()));
    }

    public function test_all_standard_roles_retain_their_distinct_page_access_and_doctors_receive_no_financial_events(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->pay($ca, $visit, $invoice, '4000');
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $visit->branch);
            $completed = $this->actingAs($actor)->getJson($this->url($visit));
            $billing = $this->getJson($this->url($visit, $invoice));
            $actor->can('visits.history.view.branch') ? $completed->assertOk() : $completed->assertForbidden();
            $actor->hasAnyPermission(['billing.view.branch', 'billing.summary.branch']) ? $billing->assertOk() : $billing->assertForbidden();
            if ($role === 'resident_doctor') {
                $completed->assertJsonMissing(['message' => 'Invoice finalised'])->assertJsonMissing(['message' => 'Payment recorded']);
                $this->assertTrue(collect($completed->json('data'))->every(fn ($row) => $row['amountSen'] === null && $row['method'] === null && $row['scope'] === 'Visit'));
                $this->assertContains('Visit completed', array_column($completed->json('data'), 'message'));
            }
        }
    }

    public function test_panel_and_pay_later_proposals_approvals_and_superseded_proposals_keep_their_own_visibility(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = new Panel;
        $panel->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'SYN-PANEL', 'name' => 'Synthetic Panel', 'is_active' => true])->save();
        $finance = $this->actor('finance_officer');
        foreach (['panel', 'deferment'] as $kind) {
            DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $finance->id, 'capability' => $kind, 'limit_sen' => 4000]);
        }
        $service = app(ResponsibilityService::class);
        foreach (['panel', 'panel', 'deferment'] as $index => $kind) {
            $invoice->refresh();
            $proposal = $service->propose($ca, $visit, $invoice, $kind, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '1000', 'panel_id' => $panel->id, 'due_date' => now()->addDay()->toDateString(), 'reason' => 'Synthetic hidden proposal reason', 'member_reference' => 'HIDDEN-MEMBER']);
            if ($index > 0) {
                $invoice->refresh();
                $service->approve($finance, $visit, $invoice, $kind, $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
            }
        }
        $panelActor = $this->actor('panel_officer');
        $this->selectBranch($panelActor);
        $response = $this->actingAs($panelActor)->getJson($this->url($visit, $invoice))->assertOk();
        $this->assertSame(2, collect($response->json('data'))->where('message', 'Panel responsibility proposed')->count());
        $this->assertContains('Panel responsibility approved', array_column($response->json('data'), 'message'));
        $response->assertDontSee('Pay later')->assertDontSee('Synthetic hidden proposal reason')->assertDontSee('HIDDEN-MEMBER');
        $this->selectBranch($finance);
        $all = $this->actingAs($finance)->getJson($this->url($visit, $invoice))->assertOk();
        $this->assertContains('Pay later proposed', array_column($all->json('data'), 'message'));
        $this->assertContains('Pay later approved', array_column($all->json('data'), 'message'));
    }

    public function test_cursor_is_stable_for_ties_newer_activity_and_bound_to_actor_document_and_permissions(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        for ($n = 0; $n < 45; $n++) {
            app(AuditRecorder::class)->record('visit.details.updated', $visit, ['free_text' => 'Synthetic forbidden text'], $ca, $visit->branch);
        }
        $first = $this->actingAs($ca)->getJson($this->url($visit, $invoice))->assertOk()->assertJsonCount(20, 'data');
        $cursor = $first->json('nextCursor');
        $this->assertIsString($cursor);
        app(AuditRecorder::class)->record('visit.details.updated', $visit, [], $ca, $visit->branch);
        $second = $this->getJson($this->url($visit, $invoice, ['cursor' => $cursor]))->assertOk()->assertJsonCount(20, 'data');
        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $third = $this->getJson($this->url($visit, $invoice, ['cursor' => $second->json('nextCursor')]))->assertOk();
        $this->assertNull($third->json('nextCursor'));
        $this->getJson($this->url($visit, $invoice, ['cursor' => 'tampered']))->assertUnprocessable();
        $finance = $this->actor('finance_officer');
        $this->actingAs($finance)->getJson($this->url($visit, $invoice, ['cursor' => $cursor]))->assertUnprocessable();
        // A changed financial visibility profile cannot continue an old cursor.
        $ca->syncRoles([]);
        $ca->givePermissionTo('billing.summary.branch');
        $this->actingAs($ca)->getJson($this->url($visit, $invoice, ['cursor' => $cursor]))->assertUnprocessable();
    }

    public function test_selected_invoice_never_combines_voided_replacement_or_other_visit_finances(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        app(InvoiceCorrectionService::class)->void($finance, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'reason' => 'Synthetic hidden void reason']);
        $replacement = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id]);
        $this->actingAs($ca)->getJson($this->url($visit, $replacement))->assertOk()->assertJsonMissing(['message' => 'Invoice voided'])->assertJsonMissing(['message' => 'Invoice finalised']);
        $old = $this->getJson($this->url($visit, $invoice))->assertOk();
        $this->assertContains('Invoice voided', array_column($old->json('data'), 'message'));
        $old->assertDontSee('Synthetic hidden void reason');
        $other = $this->register($ca, $this->patient($ca));
        $this->getJson($this->url($other, $invoice))->assertNotFound();
    }

    public function test_fallback_actor_privacy_and_generic_events_do_not_guess_line_identity(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $plan = new TreatmentPlan;
        $plan->forceFill(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'clinical_encounter_id' => ClinicalEncounter::query()->where('visit_id', $visit->id)->value('id'), 'status' => 'in_progress', 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id, 'lock_version' => 1])->save();
        $case = new DispensaryCase;
        $case->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'visit_id' => $visit->id, 'clinical_encounter_id' => ClinicalEncounter::query()->where('visit_id', $visit->id)->value('id'), 'treatment_plan_id' => $plan->id, 'case_type' => 'consultation', 'status' => 'pending', 'received_at' => now(), 'lock_version' => 1])->save();
        app(AuditRecorder::class)->record('dispensary.item_removed', $case, ['medicine_name' => 'Synthetic forbidden item', 'price' => 999, 'clinical_note' => 'Synthetic clinical sentinel'], $doctor, $visit->branch);
        $ca->syncRoles([]);
        $ca->givePermissionTo(['billing.summary.branch', 'visits.history.view.branch']);
        $response = $this->actingAs($ca)->getJson($this->url($visit, $invoice))->assertOk();
        $generic = collect($response->json('data'))->firstWhere('message', 'Medicine line removed at Dispensary');
        $this->assertSame('Staff member', $generic['actor']);
        $this->assertNull($generic['amountSen']);
        $response->assertDontSee('Synthetic forbidden item')->assertDontSee('Synthetic clinical sentinel')->assertDontSee($doctor->email);

    }

    public function test_empty_log_does_not_fabricate_a_registration_or_invoice_event(): void
    {
        [, $ca, $visit] = $this->finalizedFixture();
        $empty = Visit::factory()->create(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'patient_id' => $visit->patient_id]);
        $url = route('billing.visit-activity', $empty).'?expected_branch_id='.$visit->branch_id;
        $this->actingAs($ca)->getJson($url)->assertOk()->assertExactJson(['data' => [], 'nextCursor' => null]);
    }

    public function test_completed_debt_collection_is_an_additional_receipt_without_changing_completion_history(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $finance->id, 'capability' => 'deferment', 'limit_sen' => 4000]);
        $proposal = app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '4000', 'due_date' => now()->addDay()->toDateString(), 'reason' => 'Synthetic hidden reason']);
        $invoice->refresh();
        app(ResponsibilityService::class)->approve($finance, $visit, $invoice, 'deferment', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $before = $visit->refresh()->getAttributes();
        $this->pay($finance, $visit, $invoice, '1000');
        $response = $this->actingAs($finance)->getJson($this->url($visit, $invoice))->assertOk();
        $messages = array_column($response->json('data'), 'message');
        $this->assertContains('Visit completed', $messages);
        $this->assertContains('Payment recorded', $messages);
        $this->assertContains('Pay later approved', $messages);
        $this->assertSame($before, $visit->refresh()->getAttributes());
        $this->assertSame(3000, app(FinancialLedger::class)->state($invoice)['deferred']);
    }

    public function test_foreign_organisation_and_general_audit_authority_never_grant_invoice_activity(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYN-FOREIGN', 'name' => 'Synthetic Foreign Group'])->save();
        $branch = $visit->branch->replicate();
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-FOREIGN', 'name' => 'Synthetic Foreign Branch'])->save();
        $foreign = $this->actor('ca', $branch);
        $this->selectBranch($foreign, $branch);
        $this->actingAs($foreign)->getJson($this->url($visit, $invoice, ['expected_branch_id' => $branch->id]))->assertNotFound();
        $ca->syncRoles([]);
        $ca->givePermissionTo('audit.view.organisation');
        $this->selectBranch($ca, $visit->branch);
        $this->actingAs($ca)->getJson($this->url($visit, $invoice))->assertForbidden();
    }

    public function test_wrong_branch_foreign_organisation_inactive_and_active_doctor_access_are_denied(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->actingAs($doctor)->getJson($this->url($visit))->assertNotFound();
        $this->actingAs($ca)->getJson($this->url($visit, $invoice, ['expected_branch_id' => $visit->branch_id + 10000]))->assertNotFound();
        $otherBranch = Branch::query()->where('organisation_id', $visit->organisation_id)->whereKeyNot($visit->branch_id)->firstOrFail();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->actingAs($other)->getJson($this->url($visit, $invoice))->assertNotFound();
        $ca->forceFill(['is_active' => false])->save();
        session()->forget('errors');
        $this->actingAs($ca)->getJson($this->url($visit, $invoice))->assertRedirect(route('login'));
    }
}
