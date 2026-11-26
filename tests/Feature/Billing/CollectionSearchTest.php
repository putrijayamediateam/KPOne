<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\PayLaterCollectionService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MergeFixture;

class CollectionSearchTest extends BillingTestCase
{
    private User $doctor;

    private User $ca;

    private User $supervisor;

    public function test_search_reads_beyond_first_page_and_clamps_the_filtered_page_without_financial_writes(): void
    {
        $this->collection(1);
        for ($i = 0; $i < PayLaterCollectionService::PER_PAGE; $i++) {
            [$last, $invoice] = $this->anotherCollection(2);
        }
        $this->selectBranch($this->ca);
        $service = app(PayLaterCollectionService::class);
        $unfiltered = $service->page($this->ca, 1, false);
        $this->assertCount(20, $unfiltered['data']);
        $this->assertNotContains($last->visit_number, array_column($unfiltered['data'], 'visitNumber'));
        $before = $this->financialRows();
        $filtered = $service->page($this->ca, 99, false, ['query' => strtolower($invoice->invoice_number)]);
        $this->assertSame(21, $filtered['all']['count']);
        $this->assertSame(21000, $filtered['all']['totalSen']);
        $this->assertSame(1, $filtered['shown']['count']);
        $this->assertSame(1000, $filtered['shown']['totalSen']);
        $this->assertSame(1, $filtered['currentPage']);
        $this->assertSame($last->visit_number, $filtered['data'][0]['visitNumber']);
        $this->assertSame($before, $this->financialRows());
    }

    public function test_due_age_boundaries_partition_the_existing_effective_due_dates(): void
    {
        $this->collection(0);
        foreach ([1, 30, 31, 60, 61, 62] as $days) {
            $this->anotherCollection($days);
        }
        $this->travel(61)->days();
        $this->selectBranch($this->ca);
        $before = $this->financialRows();
        foreach (['over60' => 1, '31to60' => 2, '1to30' => 2, 'not_overdue' => 2] as $age => $count) {
            $result = app(PayLaterCollectionService::class)->page($this->ca, 1, false, ['age' => $age]);
            $this->assertSame(7, $result['all']['count']);
            $this->assertSame($count, $result['shown']['count'], $age);
            $this->assertSame($count * 1000, $result['shown']['totalSen']);
            $this->assertSame($age, $result['filters']['age']);
        }
        $this->assertSame($before, $this->financialRows());
    }

    public function test_age_changes_at_branch_midnight_not_utc_midnight(): void
    {
        $this->travelTo(now('Asia/Kuala_Lumpur')->startOfDay()->addHours(23)->addMinutes(59));
        $this->collection(0);
        $this->selectBranch($this->ca);
        $service = app(PayLaterCollectionService::class);
        $this->assertSame(1, $service->page($this->ca, 1, false, ['age' => 'not_overdue'])['shown']['count']);
        $this->travel(2)->minutes();
        $this->assertSame(1, $service->page($this->ca, 1, false, ['age' => '1to30'])['shown']['count']);
        $this->assertSame(0, $service->page($this->ca, 1, false, ['age' => 'not_overdue'])['shown']['count']);
    }

    public function test_literal_search_and_merged_identity_match_only_the_displayed_name_and_visible_numbers(): void
    {
        [$visit] = $this->collection(2);
        $visit->patient->forceFill(['full_name' => 'Synthetic 50%_! Collection'])->save();
        $this->anotherCollection(2);
        $this->selectBranch($this->ca);
        $service = app(PayLaterCollectionService::class);
        foreach (['%', '_', '!', 'SYNTHETIC 50%_!'] as $query) {
            $this->assertSame(1, $service->page($this->ca, 1, false, ['query' => $query])['shown']['count']);
        }
        $this->assertSame(0, $service->page($this->ca, 1, false, ['query' => "' OR 1=1 --"])['shown']['count']);
        $kept = $this->patient(null, ['full_name' => 'Synthetic Kept Collection']);
        MergeFixture::merge($kept, $visit->patient->refresh(), $this->ca, $this->supervisor);
        $this->assertSame(0, $service->page($this->ca, 1, false, ['query' => '50%_!'])['shown']['count']);
        foreach (['kept collection', $kept->patient_number, $visit->patient->patient_number] as $query) {
            $result = $service->page($this->ca, 1, false, ['query' => $query]);
            $this->assertSame(1, $result['shown']['count']);
            $this->assertSame($kept->patient_number, $result['data'][0]['patientNumber']);
            $this->assertSame($visit->patient->patient_number, $result['data'][0]['mergedFrom']);
        }
    }

    public function test_filtered_corrected_bill_uses_existing_due_now_date_and_preserves_pay_later_breakdown(): void
    {
        [$visit, $invoice] = $this->collection(10);
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        $this->selectBranch($this->ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '3000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $payment = Payment::query()->where('patient_id', $visit->patient_id)->where('status', 'posted')->sole();
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $payment]), ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version, 'payment_lock_version' => $payment->lock_version,
            'reason' => 'Synthetic collection filter correction', 'recording_error_only' => '1', 'received_sen' => '2500',
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->travel(2)->days();
        $this->selectBranch($this->ca);
        $before = $this->financialRows();
        $result = app(PayLaterCollectionService::class)->page($this->ca, 1, false, ['query' => $invoice->invoice_number, 'age' => '1to30']);
        $this->assertSame(1, $result['shown']['count']);
        $this->assertSame(1500, $result['shown']['totalSen']);
        $this->assertSame(500, $result['dueNow'][$visit->visit_number]);
        $this->assertSame(1000, $result['data'][0]['remainingSen']);
        $this->assertSame(2, $result['data'][0]['daysOverdue']);
        $this->assertArrayHasKey($visit->visit_number, $result['payLaterDue']);
        $this->assertSame($before, $this->financialRows());
    }

    public function test_http_filters_validate_and_preserve_existing_role_and_branch_scope(): void
    {
        [$visit, $invoice] = $this->collection(2);
        $this->selectBranch($this->ca);
        $this->getJson(route('billing.pay-later-collections', ['query' => str_repeat('x', 101)]))->assertUnprocessable();
        $this->getJson(route('billing.pay-later-collections', ['age' => 'invented']))->assertUnprocessable();
        $this->get(route('billing.pay-later-collections', ['query' => ' '.$invoice->invoice_number.' ', 'age' => 'not_overdue']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.filters.query', $invoice->invoice_number)
            ->where('collections.filters.age', 'not_overdue')
            ->where('collections.shown.count', 1));
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->get(route('billing.pay-later-collections', ['query' => $visit->patient->patient_number]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('collections.shown.count', 0)->where('collections.all.count', 0));
        $this->selectBranch($this->actor('resident_doctor'));
        $this->getJson(route('billing.pay-later-collections', ['query' => $invoice->invoice_number]))->assertForbidden();
    }

    /** @return array{Visit, Invoice} */
    private function collection(int $dueInDays): array
    {
        [$this->doctor, $this->ca, $visit, $invoice] = $this->finalizedFixture();
        $this->supervisor = $this->actor('ca_supervisor');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id,
            'user_id' => $this->supervisor->id, 'capability' => 'deferment', 'limit_sen' => 10000]);
        $this->defer($visit, $invoice, $dueInDays);

        return [$visit, $invoice];
    }

    /** @return array{Visit, Invoice} */
    private function anotherCollection(int $dueInDays): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture($this->doctor, $this->ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version,
            'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
        $this->defer($visit, $invoice, $dueInDays);

        return [$visit, $invoice];
    }

    private function defer(Visit $visit, Invoice $invoice, int $dueInDays): void
    {
        $this->selectBranch($this->ca);
        $service = app(ResponsibilityService::class);
        $proposal = $service->propose($this->ca, $visit, $invoice->refresh(), 'deferment', ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'due_date' => now($this->branch->timezone)->addDays($dueInDays)->toDateString(), 'reason' => 'Synthetic collection filter fixture']);
        $this->selectBranch($this->supervisor);
        $service->approve($this->supervisor, $visit, $invoice->refresh(), 'deferment', $proposal->public_id, ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
    }

    private function financialRows(): array
    {
        return collect(['invoices', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'audit_logs'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
