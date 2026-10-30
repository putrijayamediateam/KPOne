<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Services\PatientGuardianService;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\GuardianFixture;

class GuardianValidationLabelsTest extends PatientTestCase
{
    private function validationErrors(array $guardian): array
    {
        $actor = User::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic label actor', 'email' => 'actor@labels.test']);
        $actor->assignRole('ca');
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        try {
            app(PatientGuardianService::class)->validate($actor, ['date_of_birth' => '2015-03-03', 'guardian' => $guardian]);
            $this->fail('The unchanged guardian rules must refuse invalid fields.');
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    public function test_required_guardian_messages_use_staff_friendly_names_with_the_same_nested_keys(): void
    {
        $errors = $this->validationErrors(['relationship' => '', 'full_name' => '', 'phone' => '', 'phone_country' => '', 'email' => '', 'lock_version' => '', 'retry_key' => '']);
        foreach (['relationship' => 'Guardian relationship', 'full_name' => 'Guardian name', 'phone' => 'Guardian phone', 'phone_country' => 'Guardian phone country', 'lock_version' => 'Guardian contact version', 'retry_key' => 'Guardian request reference'] as $key => $name) {
            $this->assertArrayHasKey('guardian.'.$key, $errors);
            $this->assertStringContainsString($name, $errors['guardian.'.$key][0]);
            $this->assertStringNotContainsString('guardian.', $errors['guardian.'.$key][0]);
        }
        $this->assertDatabaseCount('patient_guardian_contacts', 0);
    }

    public function test_invalid_relationship_limits_email_and_retry_fields_keep_their_rules_with_friendly_names(): void
    {
        $errors = $this->validationErrors([...GuardianFixture::contact(), 'relationship' => 'unsupported', 'full_name' => str_repeat('x', 256), 'phone' => str_repeat('1', 65), 'phone_country' => 'USA', 'email' => 'invalid', 'lock_version' => -1, 'retry_key' => 'invalid']);
        foreach (['relationship', 'full_name', 'phone', 'phone_country', 'email', 'lock_version', 'retry_key'] as $key) {
            $this->assertArrayHasKey('guardian.'.$key, $errors);
            $this->assertStringContainsString('Guardian ', $errors['guardian.'.$key][0]);
            $this->assertStringNotContainsString('guardian.', $errors['guardian.'.$key][0]);
        }
        $this->assertDatabaseCount('patient_guardian_contacts', 0);
    }
}
