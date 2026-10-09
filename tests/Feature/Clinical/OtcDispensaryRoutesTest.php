<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

class OtcDispensaryRoutesTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_the_ca_opens_an_otc_case_from_the_registration_board_and_lands_on_it(): void
    {
        $f = $this->fixture();
        $row = fn () => app(VisitDirectoryService::class)->search($f['ca'], [])['data']->firstWhere('visitNumber', $f['visit']->visit_number);
        $this->assertTrue($row()['can']['dispense']);
        $this->assertNull($row()['dispensaryUrl']);

        $response = $this->post(route('visits.dispense', $f['visit']), ['expected_branch_id' => $f['visit']->branch_id]);

        $case = DispensaryCase::query()->where('visit_id', $f['visit']->id)->sole();
        $response->assertRedirect(route('dispensary.show', $case));
        $this->assertFalse($row()['can']['dispense']);
        $this->assertTrue($row()['can']['openDispensary']);
        $this->assertSame(route('dispensary.show', $case), $row()['dispensaryUrl']);
        $this->assertSame('dispensing', $row()['dispensaryStatus']);
    }

    public function test_pressing_dispense_twice_lands_on_the_same_case(): void
    {
        $f = $this->fixture();
        $this->post(route('visits.dispense', $f['visit']), ['expected_branch_id' => $f['visit']->branch_id]);
        $this->post(route('visits.dispense', $f['visit']), ['expected_branch_id' => $f['visit']->branch_id]);

        $this->assertSame(1, DispensaryCase::query()->where('visit_id', $f['visit']->id)->count());
    }

    public function test_only_roles_with_the_otc_permission_can_dispense_and_a_refusal_stays_on_registration(): void
    {
        $f = $this->fixture();
        foreach (['resident_doctor', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $f['visit']->branch);
            $this->post(route('visits.dispense', $f['visit']), ['expected_branch_id' => $f['visit']->branch_id])->assertForbidden();
        }
        $this->assertSame(0, DispensaryCase::query()->count());

        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->from(route('billing.work'))->post(route('visits.dispense', $f['visit']), [])->assertRedirect(route('registration.index'));
    }

    public function test_the_otc_dispensary_page_renders_without_a_doctor_and_exposes_the_allergy_step(): void
    {
        $f = $this->fixture();
        $this->post(route('visits.dispense', $f['visit']), ['expected_branch_id' => $f['visit']->branch_id]);
        $case = DispensaryCase::query()->where('visit_id', $f['visit']->id)->sole();

        $this->get(route('dispensary.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Show')
            ->where('dispensary.caseType', 'otc')->where('dispensary.doctor', null)->where('dispensary.otc.allergyConfirmed', false)
            ->where('dispensary.can.return', false)->missing('dispensary.tvCall'));
    }

    public function test_the_allergy_statement_route_records_it_and_validates_the_choice(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $payload = ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->lock_version];

        $this->post(route('dispensary.otc-allergy', $case), [...$payload, 'allergy_statement' => 'maybe'])->assertSessionHasErrors('allergy_statement');
        $this->post(route('dispensary.otc-allergy', $case), [...$payload, 'allergy_statement' => 'has_allergy'])->assertRedirect(route('dispensary.show', $case));

        $this->assertSame('has_allergy', $case->handoffs()->sole()->otc_allergy_statement);
        $this->get(route('dispensary.show', $case))->assertInertia(fn (Assert $page) => $page->where('dispensary.otc.allergyConfirmed', true)->where('dispensary.otc.allergyStatement', 'has_allergy'));
    }

    public function test_after_dispensing_the_board_offers_billing_for_the_otc_visit(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);

        $row = app(VisitDirectoryService::class)->search($f['ca'], [])['data']->firstWhere('visitNumber', $f['visit']->visit_number);

        $this->assertNull($row['dispensaryUrl']);
        $this->assertFalse($row['can']['dispense']);
        $this->assertTrue($row['can']['openBilling']);
        $this->assertSame(route('billing.show', $f['visit']), $row['billingUrl']);
        $this->assertTrue($row['awaitingBilling']);
        $this->assertSame(1, DB::table('dispensary_cases')->where('case_type', 'otc')->count());
    }
}
