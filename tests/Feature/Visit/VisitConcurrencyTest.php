<?php

namespace Tests\Feature\Visit;

use App\Domain\Visit\Services\VisitAdministrationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitConcurrencyTest extends VisitTestCase
{
    public function test_same_idempotency_key_has_one_logical_result(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $key = (string) Str::uuid();

        $first = $this->register($ca, $patient, ['idempotency_key' => $key]);
        $second = $this->register($ca, $patient, ['idempotency_key' => $key]);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('visits', 1);
    }

    public function test_different_keys_cannot_silently_bypass_repeat_decision(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $this->register($ca, $patient);

        $this->expectException(ValidationException::class);
        $this->register($ca, $patient, ['idempotency_key' => (string) Str::uuid()]);
    }

    public function test_update_then_cancel_with_stale_version_is_rejected(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        $service = app(VisitAdministrationService::class);
        $service->update($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => 1,
            'visit_type' => 'otc',
            'assigned_doctor_user_id' => null,
            'visit_reason' => null,
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
            'panel_id' => null,
            'coverage_member_reference' => null,
        ], $ca);

        $this->expectException(ValidationException::class);
        $service->cancel($visit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => 1,
            'cancellation_reason' => 'Synthetic stale cancellation',
        ], $ca);
    }
}
