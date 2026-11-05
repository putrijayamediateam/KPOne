<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MergeFixture;

/** REG-5: a pay-later bill of a merged-away record lists under the kept record, noting the old KP number. */
class PayLaterMergedPatientTest extends BillingTestCase
{
    public function test_a_merged_away_patients_bill_shows_the_kept_record_and_the_old_number(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $supervisor = $this->actor('ca_supervisor');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 10000]);
        $service = app(ResponsibilityService::class);
        $this->selectBranch($ca);
        $proposal = $service->propose($ca, $visit, $invoice->refresh(), 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version,
            'amount_sen' => 3000, 'due_date' => now($this->branch->timezone)->addDays(2)->toDateString(), 'reason' => 'Synthetic private pay-later reason']);
        $this->selectBranch($supervisor);
        $service->approve($supervisor, $visit, $invoice->refresh(), 'deferment', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);

        $this->selectBranch($ca);
        $this->get(route('billing.pay-later-collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.data.0.patientNumber', $visit->patient->patient_number)
            ->where('collections.data.0.mergedFrom', null));

        $kept = $this->patient(null, ['full_name' => 'Synthetic Pay Later Kept']);
        $old = $visit->patient->patient_number;
        MergeFixture::merge($kept, $visit->patient, $ca, $supervisor);

        $this->get(route('billing.pay-later-collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.data.0.patientName', 'Synthetic Pay Later Kept')
            ->where('collections.data.0.patientNumber', $kept->patient_number)
            ->where('collections.data.0.mergedFrom', $old)
            ->where('collections.all.count', 1));
    }
}
