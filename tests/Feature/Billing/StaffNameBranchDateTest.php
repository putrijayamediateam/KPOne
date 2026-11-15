<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\StaffAccessService;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-21: a branch-level viewer sees a staff member's name while that person is assigned to the branch on the
 * branch's own calendar day, as branch access already decides it. Between 00:00 and 08:00 in Kuala Lumpur the UTC
 * date is still yesterday, which used to keep a leaver named and a new starter hidden.
 */
class StaffNameBranchDateTest extends BillingTestCase
{
    public function test_names_follow_the_branch_day_just_after_midnight_in_kuala_lumpur(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $leaver = $this->actor('ca');
        $leaver->forceFill(['name' => 'Synthetic Leaver Yesterday'])->save();
        $starter = $this->actor('ca');
        $starter->forceFill(['name' => 'Synthetic Starter Today'])->save();
        $this->pay($leaver, $visit, $invoice, '1500');
        $this->pay($starter, $visit, $invoice, '2500');

        // 01:00 tomorrow in Kuala Lumpur is 17:00 today in UTC: the UTC date is the branch's yesterday. Built from the
        // real clock, so the test never ages; every assignment below is set explicitly against that branch day.
        $branchNow = now('Asia/Kuala_Lumpur')->addDay()->setTime(1, 0)->toImmutable();
        $this->assertSame('Asia/Kuala_Lumpur', $this->branch->timezone);
        $this->travelTo($branchNow);
        $this->assertSame($branchNow->subDay()->toDateString(), now()->utc()->toDateString());
        $this->assign($ca, '2000-01-01', null);                                              // The viewer: long assigned.
        $this->assign($leaver, '2000-01-01', $branchNow->subDay()->toDateString());          // Last day: yesterday, branch time.
        $this->assign($starter, $branchNow->toDateString(), null);                          // First day: today, branch time.

        $this->selectBranch($ca); // A CA sees staff of their own branch only.
        $visible = app(StaffAccessService::class)->visibleUsers($ca)->whereIn('id', [$leaver->id, $starter->id])->pluck('name')->all();
        $this->assertSame(['Synthetic Starter Today'], $visible);
        $this->get(route('billing.show', $visit))->assertOk()->assertDontSee('Synthetic Leaver Yesterday')->assertInertia(fn (Assert $page) => $page
            ->where('billing.payments.0.recordedBy', 'Staff member')
            ->where('billing.payments.1.recordedBy', 'Synthetic Starter Today'));

        // Later the same branch day (UTC has caught up) gives the same answer.
        $this->travelTo($branchNow->setTime(20, 0));
        $this->assertSame(['Synthetic Starter Today'], app(StaffAccessService::class)->visibleUsers($ca)->whereIn('id', [$leaver->id, $starter->id])->pluck('name')->all());
    }

    private function assign($user, string $from, ?string $until): void
    {
        // Synthetic fixture write of one assignment period at the test branch.
        DB::table('staff_branch_assignments')->where('staff_profile_id', $user->staffProfile->id)->where('branch_id', $this->branch->id)
            ->update(['valid_from' => $from, 'valid_until' => $until]);
    }

    private function pay($actor, $visit, $invoice, string $sen): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => (string) ExactMoney::sen($sen), 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
    }
}
