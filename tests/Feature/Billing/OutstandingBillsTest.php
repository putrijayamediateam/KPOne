<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\OutstandingBillService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class OutstandingBillsTest extends BillingTestCase
{
    protected function anotherInvoice($doctor, $ca, $patient): array
    {
        $this->selectBranch($ca);
        $visit = $this->register($ca, $patient, ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason' => 'Synthetic repeat', 'confirm_repeat' => true]);
        $queue = $this->send($ca, $visit);
        $this->selectBranch($doctor);
        $queue = app(QueueEntryService::class)->call($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $encounter = $this->startEncounter($doctor, $visit->refresh(), $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $this->branch->id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version]);

        return [$visit, $invoice];
    }

    protected function approve($actor, $visit, $invoice, string $kind, int $amount, ?Panel $panel = null): mixed
    {
        $this->selectBranch($actor);
        DB::table('billing_approval_limits')->updateOrInsert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $actor->id, 'capability' => $kind], ['limit_sen' => 100000]);
        $service = app(ResponsibilityService::class);
        $record = $service->propose($actor, $visit, $invoice->refresh(), $kind, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'reason' => 'Synthetic private reason must not leak', 'due_date' => now()->addDays(5)->toDateString(), 'panel_id' => $panel?->id]);
        $service->approve($actor, $visit, $invoice->refresh(), $kind, $record->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $record->lock_version]);

        return $record->refresh();
    }

    protected function manyFixture(): array
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $ca->assignRole('ca_supervisor');
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id, 'name' => 'Synthetic Example Panel']);
        $others = [];
        for ($i = 0; $i < 12; $i++) {
            [$other, $bill] = $this->anotherInvoice($doctor, $ca, $visit->patient);
            $allocation = $this->approve($ca, $other, $bill, 'panel', 1000, $panel);
            if ($i === 0) {
                $this->approve($ca, $other, $bill, 'deferment', 2000);
            }
            $others[] = [$other, $bill, $allocation];
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);

        return [$doctor, $ca, $visit, $invoice, $finance, $others];
    }

    public function test_whole_set_totals_pagination_split_invoice_and_read_only_navigation(): void
    {
        [, , $visit, , $finance, $others] = $this->manyFixture();
        $summary = app(OutstandingBillService::class)->summary($finance, $visit);
        $this->assertSame(12, $summary['patient']['count']);
        $this->assertSame(36000, $summary['patient']['totalSen']);
        $this->assertSame(12, $summary['panel']['count']);
        $this->assertSame(12000, $summary['panel']['totalSen']);
        $before = $this->financialSnapshot();
        $url = route('billing.outstanding', [$visit, 'patient']);
        $this->getJson($url)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('count', 12)->assertJsonPath('totalSen', 36000)->assertJsonPath('lastPage', 2);
        $second = $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(2, 'data');
        $split = collect($second->json('data'))->firstWhere('invoiceNumber', $others[0][1]->invoice_number);
        $this->assertSame(3000, $split['amountSen']); // 1000 due now + 2000 approved pay later.
        $this->get($split['url'])->assertOk(); // Confirm's only operation.
        $this->assertSame($before, $this->financialSnapshot());
        $this->getJson($url.'?page=0')->assertUnprocessable();
        $this->getJson($url.'?page=999')->assertOk()->assertJsonPath('currentPage', 2);
        $this->getJson($url)->assertDontSee('Synthetic private reason')->assertJsonStructure(['data' => [['invoiceNumber', 'visitDate', 'amountSen', 'reviewRequired', 'url']]]);
        $this->assertSame(['invoiceNumber', 'visitDate', 'amountSen', 'reviewRequired', 'url'], array_keys($second->json('data.0')));
    }

    public function test_permission_matrix_in_summary_and_direct_group_endpoints(): void
    {
        [, , $visit] = $this->finalizedFixture();
        foreach (['ca' => [true, false], 'ca_supervisor' => [true, false], 'finance_officer' => [true, true], 'panel_officer' => [false, true], 'director' => [true, true], 'resident_doctor' => [false, false], 'administrator' => [false, false], 'technical_admin' => [false, false]] as $role => [$patient, $panel]) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            foreach (['patient' => $patient, 'panel' => $panel] as $group => $allowed) {
                $response = $this->getJson(route('billing.outstanding', [$visit, $group]));
                $allowed ? $response->assertOk() : $response->assertForbidden();
            }
            if ($patient || $panel) {
                $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $p) => $p
                    ->where('billing.outstanding.patient', $patient ? ['count' => 0, 'totalSen' => 0, 'url' => route('billing.outstanding', [$visit, 'patient'])] : null)
                    ->where('billing.outstanding.panel', $panel ? ['count' => 0, 'totalSen' => 0, 'url' => route('billing.outstanding', [$visit, 'panel'])] : null));
            }
        }
    }

    public function test_claimed_and_held_panel_match_claims_eligibility_but_patient_debt_remains(): void
    {
        [$doctor, $ca, $visit, , $finance, $others] = $this->manyFixture();
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        app(PanelClaimService::class)->prepare($officer, $others[0][2], ['expected_branch_id' => $visit->branch_id]);
        $others[1][1]->forceFill(['correction_hold' => true])->save();
        $this->selectBranch($finance);
        $this->getJson(route('billing.outstanding', [$visit, 'panel']))->assertOk()->assertJsonPath('count', 10)->assertJsonPath('totalSen', 10000);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 12)->assertJsonPath('totalSen', 36000);
        $this->selectBranch($officer);
        $claims = $this->get(route('panel-claims.index'))->assertOk();
        $claims->assertInertia(fn (Assert $p) => $p->where('work.eligible.total', 10));
        [$proposedVisit, $proposedInvoice] = $this->anotherInvoice($doctor, $ca, $visit->patient);
        $panel = Panel::query()->where('organisation_id', $visit->organisation_id)->firstOrFail();
        app(ResponsibilityService::class)->propose($ca, $proposedVisit, $proposedInvoice, 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $proposedInvoice->lock_version, 'amount_sen' => 1000, 'panel_id' => $panel->id, 'reason' => 'Synthetic proposed only']);
        $this->selectBranch($finance);
        $this->getJson(route('billing.outstanding', [$visit, 'panel']))->assertOk()->assertJsonPath('count', 10);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 13)->assertJsonPath('totalSen', 40000);

    }

    public function test_cross_branch_organisation_and_other_patient_are_excluded(): void
    {
        [$doctor, $ca, $visit] = $this->finalizedFixture();
        $this->anotherInvoice($doctor, $ca, $this->patient($ca));
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 0);
        $branch = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '<>', $visit->branch_id)->firstOrFail();
        $originalBranch = $this->branch;
        $this->branch = $branch;
        $branchCa = $this->actor('ca', $branch);
        $branchDoctor = $this->actor('resident_doctor', $branch);
        $this->anotherInvoice($branchDoctor, $branchCa, $visit->patient);
        $this->branch = $originalBranch;
        $this->selectBranch($ca);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 0);
        $other = $this->actor('finance_officer', $branch);
        $this->selectBranch($other, $branch);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertNotFound();
        $this->getJson(route('billing.outstanding', [$visit, 'panel']))->assertNotFound();
        $org = (new Organisation)->forceFill(['code' => 'SYN_FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true]);
        $org->save();
        $foreignBranch = (new Branch)->forceFill(['organisation_id' => $org->id, 'code' => 'SYN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignBranch->save();
        $foreign = $this->actor('finance_officer', $foreignBranch);
        $this->selectBranch($foreign, $foreignBranch);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertNotFound();
    }

    public function test_completed_invoice_projection_and_debt_collection_keep_existing_history(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $ca->assignRole('ca_supervisor');
        $debt = $this->approve($ca, $visit, $invoice, 'deferment', 4000);
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $history = $visit->fresh()->attributesToArray();
        [$next] = $this->anotherInvoice($doctor, $ca, $visit->patient);
        $this->get(route('visits.invoice', $visit))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('invoice.outstanding.patient.count', 1)->where('invoice.outstanding.patient.totalSen', 4000)
            ->missing('invoice.clinicalNote')->missing('invoice.vitals')->missing('invoice.diagnoses'));
        $rows = $this->getJson(route('billing.outstanding', [$next, 'patient']))->assertOk()->json('data');
        $this->get($rows[0]['url'])->assertOk()->assertInertia(fn (Assert $p) => $p->where('billing.can.pay', true));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($history, $visit->fresh()->attributesToArray());
        $this->assertSame(0, $debt->refresh()->remaining_sen);
        $this->getJson(route('billing.outstanding', [$next, 'patient']))->assertOk()->assertJsonPath('count', 0);
    }

    public function test_receipts_and_reversals_reconcile_patient_totals_without_counting_panel_as_cash(): void
    {
        [$doctor, $ca, $visit] = $this->finalizedFixture();
        [$other, $invoice] = $this->anotherInvoice($doctor, $ca, $visit->patient);
        $finance = $this->actor('finance_officer');
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $payment = app(PaymentService::class)->add($ca, $other, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1500, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($finance);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 1)->assertJsonPath('totalSen', 2500);
        app(InvoiceCorrectionService::class)->reverse($finance, $other, $invoice->refresh(), $payment, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $payment->lock_version, 'reason' => 'Synthetic recording error', 'recording_error_only' => true]);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('totalSen', 4000);
        app(InvoiceCorrectionService::class)->void($finance, $other, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'reason' => 'Synthetic reissue']);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 0);
        $this->selectBranch($ca);
        app(BillingBuilderService::class)->build($ca, $other, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $this->getJson(route('billing.outstanding', [$visit, 'patient']))->assertOk()->assertJsonPath('count', 0); // replacement is still draft.
    }

    private function financialSnapshot(): array
    {
        return collect(['invoices', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'panel_claims', 'audit_logs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
