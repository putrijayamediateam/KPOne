<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\BillingWorkDirectoryService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

class BillingWorkSearchTest extends BillingTestCase
{
    private string $fixtureName = 'Synthetic Search Match';

    protected function patient(?User $actor = null, array $overrides = []): Patient
    {
        return parent::patient($actor, ['full_name' => $this->fixtureName, ...$overrides]);
    }

    private function moreFinalized(User $doctor, User $ca): void
    {
        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
    }

    public function test_search_is_applied_before_count_and_twenty_five_row_page(): void
    {
        [$doctor, $ca] = $this->finalizedFixture();
        for ($i = 1; $i < 26; $i++) {
            $this->moreFinalized($doctor, $ca);
        }
        $this->fixtureName = 'Synthetic Other Invoice';
        $this->moreFinalized($doctor, $ca);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.work', ['q' => '  sEaRcH MaTcH  ', 'page' => 2]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('work.branchId', $this->branch->id)->where('work.filters.q', 'sEaRcH MaTcH')->where('work.total', 26)->where('work.currentPage', 2)->where('work.lastPage', 2)
            ->has('work.data', 1)->where('work.data.0.patientName', 'Synthetic Search Match')->where('work.data.0.state.total', 4000));
        $this->get(route('billing.work'))->assertInertia(fn (Assert $p) => $p->where('work.total', 27)->has('work.data', 25));
        $this->get(route('billing.work', ['q' => 'Search Match', 'page' => 1000]))->assertInertia(fn (Assert $p) => $p->where('work.total', 26)->where('work.currentPage', 1000)->has('work.data', 0));
    }

    public function test_literal_patterns_and_identifiers_preserve_financial_and_panel_rows(): void
    {
        $this->fixtureName = 'Synthetic Literal %_\\ Patient';
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(), 'method' => 'cash', 'amount_sen' => 1234]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $directory = app(BillingWorkDirectoryService::class);
        $original = $directory->search($finance, false, 1)['data'];
        foreach (['%', '_', '\\', $invoice->invoice_number, $visit->visit_number, 'literal'] as $text) {
            $result = $directory->search($finance, false, 1, $text);
            $this->assertSame(1, $result['total']);
            $this->assertSame($original, $result['data']);
        }
        $this->assertSame(1234, $original[0]['state']['self_pay']);
        $this->assertSame(2766, $original[0]['state']['due_now']);
        $this->assertSame(0, $directory->search($finance, false, 1, "' OR 1=1 --")['total']);
        $this->fixtureName = 'Synthetic Plain Other';
        $this->moreFinalized($this->doctor(), $ca);
        $this->selectBranch($finance);
        foreach (['%', '_', '\\'] as $text) {
            $this->assertSame(1, $directory->search($finance, false, 1, $text)['total'], 'Wildcards remain literal.');
        }
        $this->selectBranch($ca);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'reason' => 'Synthetic responsibility', 'panel_id' => $panel->id, 'member_reference' => 'SYN-PRIVATE']);
        $actor = $this->actor('panel_officer');
        $this->selectBranch($actor);
        $before = $directory->search($actor, true, 1);
        $filtered = $directory->search($actor, true, 1, $invoice->invoice_number);
        $this->assertSame($before['data'], $filtered['data']);
        $this->assertSame(1000, $filtered['data'][0]['amountSen']);
        $this->assertArrayNotHasKey('state', $filtered['data'][0]);
        $this->assertArrayNotHasKey('memberReference', $filtered['data'][0]);
    }

    public function test_invalid_search_is_rejected_and_grouping_does_not_escape_branch_or_role_scope(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach ([['value'], ['q' => 'value'], str_repeat('x', 121)] as $text) {
            $this->getJson(route('billing.work', ['q' => $text]))->assertUnprocessable()->assertJsonValidationErrors('q');
        }
        try {
            app(BillingWorkDirectoryService::class)->search($finance, false, 1, ['value']);
            $this->fail('Direct service rejects non-string input.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('q', $e->errors());
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->firstOrFail();
        $foreignBranch = $this->actor('finance_officer', $other);
        $this->selectBranch($foreignBranch, $other);
        foreach ([$invoice->invoice_number, $visit->visit_number, 'Synthetic'] as $text) {
            $this->get(route('billing.work', ['q' => $text]))->assertInertia(fn (Assert $p) => $p->where('work.total', 0)->has('work.data', 0));
        }
        $organisation = Organisation::query()->forceCreate(['code' => 'SYN-WORK-FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true]);
        $foreign = Branch::query()->forceCreate(['organisation_id' => $organisation->id, 'code' => 'SYN-WORK', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignActor = $this->actor('finance_officer', $foreign);
        $this->selectBranch($foreignActor, $foreign);
        $this->get(route('billing.work', ['q' => $invoice->invoice_number]))->assertInertia(fn (Assert $p) => $p->where('work.total', 0)->has('work.data', 0));
        $this->selectBranch($finance);
        $this->get(route('billing.work', ['q' => '1']))->assertOk();
        $this->get(route('billing.work', ['q' => str_repeat('x', 120)]))->assertOk();
        $this->selectBranch($this->actor('ca'));
        $this->get(route('billing.work', ['q' => $invoice->invoice_number]))->assertForbidden();
    }
}
