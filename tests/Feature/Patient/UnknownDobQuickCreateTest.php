<?php

namespace Tests\Feature\Patient;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

class UnknownDobQuickCreateTest extends VisitTestCase
{
    public function test_staff_http_and_quick_create_require_tick_and_quick_retry_preserves_evidence(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $quick = ['full_name' => 'Synthetic unknown DOB adult', 'sex' => 'unknown', 'mobile_phone' => '+12025550120', 'phone_country' => 'US', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'US', 'value' => 'SYN-UNKNOWN-DOB-QUICK']]];
        $this->post('/patients', [...$quick, 'expected_branch_id' => $this->branch->id])->assertSessionHasErrors('adult_confirmed');
        $input = ['idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id, 'visit_type' => 'otc', 'coverage_type' => 'self_pay', 'priority' => 'normal', 'quick_patient' => $quick];
        $this->post('/registration', $input)->assertSessionHasErrors('quick_patient.adult_confirmed');
        $this->assertDatabaseCount('patients', 0);
        $input['quick_patient'] += ['adult_confirmed' => true, 'adult_confirmation_key' => (string) Str::uuid()];
        $this->post('/registration', $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/registration', $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('patient_adult_confirmations', 1);
        $this->assertSame('staff_create', DB::table('patient_adult_confirmations')->sole()->source);
    }

    public function test_ended_assignment_and_foreign_organisation_cannot_confirm(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $input = ['full_name' => 'Synthetic denied adult', 'sex' => 'unknown', 'mobile_phone' => '+12025550120', 'phone_country' => 'US', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'US', 'value' => 'SYN-DENIED-DOB']], 'adult_confirmed' => true, 'adult_confirmation_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id];
        StaffBranchAssignment::query()->where('staff_profile_id', $ca->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString(), 'valid_from' => now()->subDays(5)->toDateString()]);
        $this->post('/patients', $input)->assertNotFound();
        $this->assertDatabaseCount('patient_adult_confirmations', 0);
        $other = Organisation::query()->create(['code' => 'SYN_DOB_OTHER', 'name' => 'Synthetic other organisation']);
        $foreign = (new Branch)->forceFill(['organisation_id' => $other->id, 'code' => 'SYN_DOB_BRANCH', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreign->save();
        session([BranchAccessService::SESSION_KEY => $foreign->id]);
        $this->post('/patients', [...$input, 'expected_branch_id' => $foreign->id])->assertStatus(404);
        $this->assertDatabaseCount('patients', 0);
    }
}
