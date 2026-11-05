<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BIL-3 (owner decision 2026-11-05): the branch list of approved pay-later amounts still to collect, oldest due date
 * first, for holders of outstanding.view.branch at their branch. Read-only; patient name and KP number only.
 */
class PayLaterCollectionTest extends BillingTestCase
{
    private User $ca;

    private User $supervisor;

    private User $doctor;

    public function test_lists_only_unpaid_approved_pay_later_oldest_due_first_with_exact_amounts(): void
    {
        $f = $this->collectionFixture();
        $before = $this->financialRows();
        $this->travel(3)->days(); // The first bill (due in 2 days) is now 1 day past due, in the branch time zone.

        $this->selectBranch($this->ca);
        $this->get(route('billing.pay-later-collections'))->assertOk()
            ->assertDontSee('Synthetic private pay-later reason')
            ->assertInertia(fn (Assert $page) => $page->component('Billing/PayLaterCollections')
                ->where('collections.all', ['count' => 2, 'totalSen' => 4500, 'overdueCount' => 1, 'overdueSen' => 3000])
                ->where('collections.shown.count', 2)
                ->where('collections.data', fn ($rows) => collect($rows)->map(fn ($r) => [$r['visitNumber'], $r['approvedSen'], $r['remainingSen'], $r['daysOverdue'], $r['url']])->all() === [
                    [$f['overdue']->visit_number, 3000, 3000, 1, route('billing.show', $f['overdue'], false)],
                    [$f['partial']->visit_number, 2000, 1500, 0, route('billing.show', $f['partial'], false)],
                ])
                ->where('collections.data.0.patientNumber', $f['overdue']->patient->patient_number)
                ->where('collections.data.0.patientName', $f['overdue']->patient->full_name));

        $this->get(route('billing.pay-later-collections', ['overdue' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.overdueOnly', true)->where('collections.shown', ['count' => 1, 'totalSen' => 3000, 'overdueCount' => 1, 'overdueSen' => 3000])
            ->where('collections.all.count', 2)->where('collections.data.0.visitNumber', $f['overdue']->visit_number)->count('collections.data', 1));

        $this->assertSame($before, $this->financialRows());
    }

    public static function viewers(): array
    {
        return [['ca', 200], ['ca_supervisor', 200], ['finance_officer', 200], ['director', 200], ['panel_officer', 403], ['resident_doctor', 403], ['administrator', 403], ['technical_admin', 403]];
    }

    #[DataProvider('viewers')]
    public function test_only_outstanding_viewers_can_open_the_list(string $role, int $status): void
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->get(route('billing.pay-later-collections'))->assertStatus($status);
    }

    public function test_another_branch_sees_none_of_this_branchs_bills_and_the_billing_page_links_the_list(): void
    {
        $f = $this->collectionFixture();
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $other = $this->actor('ca', $puchong);
        $this->selectBranch($other, $puchong);
        $this->get(route('billing.pay-later-collections'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('collections.branch', $puchong->name)->where('collections.all.count', 0)->count('collections.data', 0));

        $this->selectBranch($this->ca);
        $this->get(route('billing.show', $f['partial']))->assertInertia(fn (Assert $page) => $page->where('billing.payLaterCollectionsUrl', '/pay-later-collections'));
        $panelOfficer = $this->actor('panel_officer');
        $this->selectBranch($panelOfficer);
        $this->get(route('billing.show', $f['partial']))->assertInertia(fn (Assert $page) => $page->where('billing.payLaterCollectionsUrl', null));
        $this->selectBranch($this->ca);
        $invoice = Invoice::query()->where('visit_id', $f['partial']->id)->sole();
        $this->get(route('billing.print', [$f['partial'], $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.payLaterCollectionsUrl', null));
    }

    public function test_a_completed_visit_is_listed_and_a_voided_invoice_is_replaced_by_its_reissue_exactly_once(): void
    {
        $f = $this->collectionFixture();

        // Pay later for the whole bill, then the visit is completed: still to collect.
        [$completed, $completedInvoice] = $this->anotherVisit();
        $this->defer($completed, $completedInvoice, 4000, 3);
        $this->selectBranch($this->ca);
        $this->post(route('billing.complete', $completed), ['expected_branch_id' => $completed->branch_id, 'lock_version' => $completedInvoice->refresh()->lock_version, 'visit_lock_version' => $completed->refresh()->lock_version])
            ->assertRedirect(route('billing.show', $completed))->assertSessionHasNoErrors();
        $this->assertSame(Visit::STATUS_COMPLETED, $completed->refresh()->status);

        // A wrong invoice is voided by Finance and reissued; pay later is then approved on the replacement.
        [$reissued, $original] = $this->anotherVisit();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.void', [$reissued, $original]), ['expected_branch_id' => $reissued->branch_id, 'lock_version' => $original->refresh()->lock_version, 'reason' => 'Synthetic wrong invoice'])
            ->assertRedirect(route('billing.show', $reissued))->assertSessionHasNoErrors();
        $this->selectBranch($this->ca);
        $builder = app(BillingBuilderService::class);
        $replacement = $builder->build($this->ca, $reissued->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => null]);
        $replacement = $builder->finalize($this->ca, $reissued, $replacement, ['expected_branch_id' => $this->branch->id, 'lock_version' => $replacement->lock_version]);
        $this->defer($reissued, $replacement, 1000, 4);

        $this->selectBranch($this->ca);
        $this->get(route('billing.pay-later-collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.all', ['count' => 4, 'totalSen' => 9500, 'overdueCount' => 0, 'overdueSen' => 0])
            ->where('collections.data', fn ($rows) => collect($rows)->map(fn ($r) => [$r['visitNumber'], $r['invoiceNumber'], $r['remainingSen']])->all() === [
                [$f['overdue']->visit_number, Invoice::query()->where('current_visit_guard', $f['overdue']->id)->value('invoice_number'), 3000],
                [$completed->visit_number, $completedInvoice->refresh()->invoice_number, 4000],
                [$reissued->visit_number, $replacement->refresh()->invoice_number, 1000],
                [$f['partial']->visit_number, Invoice::query()->where('current_visit_guard', $f['partial']->id)->value('invoice_number'), 1500],
            ]));
        $this->assertNotSame($original->refresh()->invoice_number, $replacement->invoice_number);
    }

    public function test_rows_carry_only_the_agreed_fields_pages_clamp_and_another_organisation_sees_nothing(): void
    {
        $this->collectionFixture();
        $this->selectBranch($this->ca);
        $this->get(route('billing.pay-later-collections', ['page' => 999]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.currentPage', 1)->where('collections.lastPage', 1)->count('collections.data', 2)
            ->where('collections.data.0', fn ($row) => array_keys(collect($row)->all()) === ['patientName', 'patientNumber', 'visitNumber', 'invoiceNumber', 'visitDate', 'dueDate', 'daysOverdue', 'approvedSen', 'remainingSen', 'url']));

        $org = new Organisation;
        $org->forceFill(['code' => 'UAT_FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true])->save();
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $org->id, 'code' => 'UAT_FOREIGN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = $this->actor('director', $foreignBranch);
        $this->selectBranch($foreign, $foreignBranch);
        $this->get(route('billing.pay-later-collections'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('collections.branch', 'Synthetic foreign branch')->where('collections.all.count', 0)->count('collections.data', 0));
    }

    /**
     * Five RM 40.00 visits: an approved RM 30.00 pay later due in 2 days; an approved RM 20.00 due in 10 days with
     * RM 5.00 of it already collected; one fully settled; one only proposed; one plain self-pay.
     *
     * @return array<string, Visit>
     */
    private function collectionFixture(): array
    {
        [$this->doctor, $this->ca, $first, $firstInvoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $first->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $this->supervisor = $this->actor('ca_supervisor');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $first->organisation_id, 'branch_id' => $first->branch_id, 'user_id' => $this->supervisor->id, 'capability' => 'deferment', 'limit_sen' => 10000]);

        $this->defer($first, $firstInvoice, 3000, 2);
        [$partial, $partialInvoice] = $this->anotherVisit();
        $this->defer($partial, $partialInvoice, 2000, 10);
        $this->pay($partial, $partialInvoice, 2500); // RM 20.00 due now first, then RM 5.00 off the pay later.
        [$settled, $settledInvoice] = $this->anotherVisit();
        $this->defer($settled, $settledInvoice, 4000, 5);
        $this->pay($settled, $settledInvoice, 4000);
        [$proposed, $proposedInvoice] = $this->anotherVisit();
        $this->selectBranch($this->ca);
        app(ResponsibilityService::class)->propose($this->ca, $proposed, $proposedInvoice->refresh(), 'deferment', ['expected_branch_id' => $proposed->branch_id, 'lock_version' => $proposedInvoice->lock_version,
            'amount_sen' => 1000, 'due_date' => now($this->branch->timezone)->addDays(1)->toDateString(), 'reason' => 'Synthetic private pay-later reason']);
        $this->anotherVisit();

        return ['overdue' => $first->refresh(), 'partial' => $partial->refresh()];
    }

    private function defer(Visit $visit, Invoice $invoice, int $sen, int $dueInDays): void
    {
        $service = app(ResponsibilityService::class);
        $this->selectBranch($this->ca);
        $proposal = $service->propose($this->ca, $visit, $invoice->refresh(), 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version,
            'amount_sen' => $sen, 'due_date' => now($this->branch->timezone)->addDays($dueInDays)->toDateString(), 'reason' => 'Synthetic private pay-later reason']);
        $this->selectBranch($this->supervisor);
        $service->approve($this->supervisor, $visit, $invoice->refresh(), 'deferment', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
    }

    private function pay(Visit $visit, Invoice $invoice, int $sen): void
    {
        $this->selectBranch($this->ca);
        app(PaymentService::class)->add($this->ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $sen, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array{Visit, Invoice} A finalised RM 40.00 invoice for a new patient. */
    private function anotherVisit(): array
    {
        $this->selectBranch($this->ca);
        $visit = $this->consultationVisit($this->ca, $this->doctor);
        $queue = $this->send($this->ca, $visit);
        $this->selectBranch($this->doctor);
        $queue = app(QueueEntryService::class)->call($this->doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $encounter = $this->startEncounter($this->doctor, $visit->refresh(), $queue);
        app(CompleteConsultationService::class)->complete($this->doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version,
            'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($this->ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($this->ca, $visit, ['expected_branch_id' => $this->branch->id, 'lock_version' => null]);

        return [$visit->refresh(), $builder->finalize($this->ca, $visit, $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version])];
    }

    /** @return array<string, string> */
    private function financialRows(): array
    {
        return collect(['invoices', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'audit_logs'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
