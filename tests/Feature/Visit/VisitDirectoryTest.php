<?php

namespace Tests\Feature\Visit;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisitDirectoryTest extends VisitTestCase
{
    public function test_registration_create_shows_bounded_branch_relevant_recent_patients_with_minimized_projection(): void
    {
        $ca = $this->actor();
        $director = $this->actor('director');
        $branchRelevant = $this->patient($director, [
            'full_name' => 'Synthetic Branch Recent',
            'mobile_phone' => '0123456789',
            'identifiers' => [['identifier_type' => 'nric', 'value' => '900101011234']],
        ]);
        foreach (range(1, 21) as $index) {
            $this->patient($director, ['full_name' => 'Synthetic Recent '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
        }
        $this->register($ca, $branchRelevant);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($ca)->get(route('registration.create'));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $response->assertOk()
            ->assertHeaderContains('Cache-Control', 'private')
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertInertia(fn ($page) => $page
                ->component('Registration/Create')
                ->has('recentPatients', 20)
                ->where('recentPatients.0.patientNumber', $branchRelevant->patient_number)
                ->where('recentPatients.0.fullName', 'Synthetic Branch Recent')
                ->has('recentPatients.0.identifier.maskedValue')
                ->has('recentPatients.0.maskedPhone')
                ->missing('recentPatients.0.id')
                ->missing('recentPatients.0.organisationId')
                ->missing('recentPatients.0.mobilePhone')
                ->missing('recentPatients.0.email')
                ->missing('recentPatients.0.address')
                ->missing('recentPatients.0.normalizedValue'));
        $response->assertDontSee('900101011234')->assertDontSee('0123456789');
        $this->assertSame(1, $queries->filter(fn (string $query): bool => str_contains($query, 'from "branches"')
            && str_contains($query, 'order by "name" asc'))->count(), 'Branch list should be resolved once per request.');
        $assignmentQueries = $queries->filter(fn (string $query): bool => str_starts_with(
            $query,
            'select "id", "branch_id", "valid_from", "valid_until" from "staff_branch_assignments"',
        ));
        $this->assertSame(1, $assignmentQueries->count(), 'Branch assignments should be loaded once per request.');
        $this->assertSame(1, $queries->filter(fn (string $query): bool => preg_match('/^select .* from "patient_identifiers"/', $query) === 1)->count(), 'Recent Patient identifiers should be eager loaded once.');
    }

    public function test_recent_patients_are_not_available_to_roles_without_registration_authority(): void
    {
        foreach (['technical_admin', 'resident_doctor'] as $role) {
            $this->actingAs($this->actor($role))
                ->get(route('registration.create'))
                ->assertForbidden()
                ->assertDontSee('recentPatients');
        }
    }

    public function test_patient_search_remains_organisation_wide_from_registration(): void
    {
        $ca = $this->actor();
        $patient = $this->patient($this->actor('director'), ['full_name' => 'Synthetic Organisation Search']);

        $this->actingAs($ca)->postJson(route('patients.search'), [
            'query' => 'Organisation Search',
            'search_type' => 'name',
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.patientNumber', $patient->patient_number);
    }

    public function test_registration_filters_are_applied_server_side(): void
    {
        $ca = $this->actor();
        $normal = $this->register($ca, $this->patient(), ['priority' => 'normal']);
        $urgent = $this->register($ca, $this->patient(), ['priority' => 'urgent']);

        $this->actingAs($ca)->postJson(route('registration.search'), ['priority' => 'urgent'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.visitNumber', $urgent->visit_number)
            ->assertJsonMissing(['visitNumber' => $normal->visit_number]);
    }

    public function test_consultation_validation_uses_clinic_facing_language(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($ca);

        $this->post(route('registration.store'), [
            ...$this->visitAttributes($patient),
            'idempotency_key' => (string) Str::uuid(),
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => null,
            'visit_reason' => null,
        ])->assertSessionHasErrors([
            'assigned_doctor_user_id' => 'Please select a doctor for this consultation.',
            'visit_reason' => 'Please enter a reason for this consultation.',
        ]);
    }

    public function test_console_is_branch_day_scoped_paginated_and_minimized(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient(), ['visit_reason' => 'Synthetic bounded administrative reason']);

        $this->actingAs($ca)->get(route('registration.index'))
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'private')
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertInertia(fn ($page) => $page
                ->component('Registration/Index')
                ->where('options.branch.timezone', $this->branch->timezone)
                ->missing('options.idempotencyKey')
                ->missing('options.panels')
                ->where('visits.total', 1)
                ->where('visits.data.0.visitNumber', $visit->visit_number)
                ->missing('visits.data.0.id')
                ->missing('visits.data.0.memberReference')
                ->missing('visits.data.0.phone')
                ->missing('visits.data.0.email'));
    }

    public function test_server_filters_escape_like_metacharacters_and_accept_exact_patient_number(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $this->register($ca, $patient);

        foreach (['%%%', '___'] as $query) {
            $this->actingAs($ca)->postJson(route('registration.search'), ['patient_query' => $query])
                ->assertOk()->assertJsonPath('total', 0);
        }
        $this->actingAs($ca)->postJson(route('registration.search'), ['patient_query' => $patient->patient_number])
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_console_rejects_more_than_31_days(): void
    {
        $ca = $this->actor();
        $this->actingAs($ca)->postJson(route('registration.search'), [
            'date_from' => '2026-01-01',
            'date_to' => '2026-02-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_malaysia_midnight_defines_operational_day(): void
    {
        try {
            Date::setTestNow('2026-08-27 15:59:00 UTC');
            $ca = $this->actor();
            $patient = $this->patient();
            $beforeMidnight = $this->register($ca, $patient);

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $afterMidnight = $this->register($ca, $patient);

            $this->assertSame('2026-08-27', $beforeMidnight->registered_at->setTimezone('Asia/Kuala_Lumpur')->toDateString());
            $this->assertSame('2026-08-28', $afterMidnight->registered_at->setTimezone('Asia/Kuala_Lumpur')->toDateString());
            $this->assertDatabaseCount('visits', 2);
            $this->actingAs($ca)->postJson(route('registration.search'), [
                'date_from' => '2026-08-27',
                'date_to' => '2026-08-27',
            ])->assertOk()->assertJsonPath('total', 1);
            $this->actingAs($ca)->postJson(route('registration.search'), [
                'date_from' => '2026-08-28',
                'date_to' => '2026-08-28',
            ])->assertOk()->assertJsonPath('total', 1);
        } finally {
            Date::setTestNow();
        }
    }
}
