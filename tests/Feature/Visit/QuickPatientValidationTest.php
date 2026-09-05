<?php

namespace Tests\Feature\Visit;

use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuickPatientValidationTest extends VisitTestCase
{
    public function test_quick_create_http_and_domain_require_phone_and_identification(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $base = ['idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id, 'visit_type' => 'otc', 'priority' => 'normal', 'coverage_type' => 'self_pay'];
        foreach ([['full_name' => 'Synthetic Quick', 'sex' => 'unknown'], ['full_name' => 'Synthetic Quick', 'sex' => 'unknown', 'mobile_phone' => '+60123456789']] as $quick) {
            $key = isset($quick['mobile_phone']) ? 'quick_patient.identifiers' : 'quick_patient.mobile_phone';
            $this->post(route('registration.store'), [...$base, 'quick_patient' => $quick])->assertSessionHasErrors($key);
            try {
                app(VisitRegistrationService::class)->register($ca, [...$base, 'quick_patient' => $quick]);
                $this->fail('Quick domain creation bypassed required registration data.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
        $visit = app(VisitRegistrationService::class)->register($ca, [...$base, 'quick_patient' => ['full_name' => 'Synthetic Quick', 'sex' => 'unknown', 'mobile_phone' => '020 7946 0018', 'phone_country' => 'GB', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'GB', 'value' => 'syn-quick']]]]);
        $this->assertSame('+442079460018', $visit->patient->mobile_phone);
        $this->assertSame('SYN-QUICK', $visit->patient->identifiers()->sole()->normalized_value);
    }
}
