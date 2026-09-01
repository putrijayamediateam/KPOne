<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\PatientProblemService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class ClinicalSafetyAuthorizationTest extends ClinicalTestCase
{
    #[DataProvider('roleMatrix')]
    public function test_permissions_are_granted_only_to_resident_doctor(string $role, bool $allowed): void
    {
        $actor = $this->actor($role);
        foreach ([
            'allergies.view.own', 'allergies.update.own', 'allergies.review.own',
            'problems.view.own', 'problems.update.own',
            'treatment_plans.view.own', 'treatment_plans.create.own', 'treatment_plans.update.own',
        ] as $permission) {
            $this->assertSame($allowed, $actor->can($permission), "{$role} {$permission}");
        }
    }

    public static function roleMatrix(): array
    {
        return [
            'director' => ['director', false],
            'doctor' => ['resident_doctor', true],
            'ca' => ['ca', false],
            'supervisor' => ['ca_supervisor', false],
            'panel' => ['panel_officer', false],
            'finance' => ['finance_officer', false],
            'business development' => ['business_development', false],
            'marketing' => ['marketing', false],
            'hr' => ['hr_manager', false],
            'technical' => ['technical_admin', false],
        ];
    }

    public function test_guest_non_clinical_roles_and_wrong_doctor_cannot_mutate_or_probe(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $payload = ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => null];
        auth()->logout();

        $this->post(route('encounters.allergies.no-known', $visit), $payload)
            ->assertRedirect(route('login'));

        foreach (['director', 'ca', 'ca_supervisor', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->post(route('encounters.allergies.no-known', $visit), $payload)->assertForbidden();
            $this->post(route('encounters.problems.store', $visit), [
                'expected_branch_id' => $visit->branch_id,
                'condition_text' => 'Synthetic forbidden condition',
            ])->assertForbidden();
        }

        $otherDoctor = $this->doctor();
        $this->selectBranch($otherDoctor);
        $this->post(route('encounters.allergies.no-known', $visit), $payload)->assertNotFound();
        $this->post(route('encounters.problems.store', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'condition_text' => 'Synthetic unrelated condition',
        ])->assertNotFound();
    }

    public function test_role_assignment_and_account_loss_fail_closed(): void
    {
        foreach (['inactive', 'role', 'assignment'] as $mode) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $this->startEncounter($doctor, $visit, $queue);
            if ($mode === 'inactive') {
                $doctor->forceFill(['is_active' => false])->save();
            } elseif ($mode === 'role') {
                $doctor->removeRole('resident_doctor');
            } else {
                StaffBranchAssignment::query()
                    ->where('staff_profile_id', $doctor->staffProfile->id)
                    ->update(['valid_until' => now()->subDay()->toDateString()]);
            }
            $this->selectBranch($doctor);

            $response = $this->post(route('encounters.allergies.no-known', $visit), [
                'expected_branch_id' => $visit->branch_id,
                'profile_lock_version' => null,
            ]);
            if ($mode === 'inactive') {
                $response->assertRedirect(route('login'));
            } elseif ($mode === 'assignment') {
                $response->assertNotFound();
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_legitimate_current_care_can_read_longitudinal_allergies_across_branches(): void
    {
        [$doctor, , $firstVisit, $firstQueue] = $this->servingFixture();
        $this->startEncounter($doctor, $firstVisit, $firstQueue);
        app(PatientAllergyService::class)->add($doctor, $firstVisit, [
            'expected_branch_id' => $firstVisit->branch_id,
            'profile_lock_version' => null,
            'allergen_text' => 'Synthetic cross-branch allergen',
            'category' => 'medication',
            'reaction_text' => null,
            'severity' => null,
        ]);

        $otherBranch = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)
            ->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $otherBranch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);
        $this->app->forgetScopedInstances();
        $ca = $this->actor('ca', $otherBranch);
        $originalBranch = $this->branch;
        $this->branch = $otherBranch;
        $secondVisit = $this->register($ca, $firstVisit->patient, [
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => 'Synthetic cross-branch reason',
        ]);
        $this->branch = $originalBranch;
        $secondQueue = $this->send($ca, $secondVisit);
        $this->selectBranch($doctor, $otherBranch);
        $secondQueue = app(QueueEntryService::class)->call($doctor, $secondVisit, [
            'expected_branch_id' => $otherBranch->id,
            'visit_lock_version' => $secondVisit->lock_version,
            'queue_lock_version' => $secondQueue->lock_version,
        ]);
        $this->startEncounter($doctor, $secondVisit->refresh(), $secondQueue);

        $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $secondVisit);
        $this->assertSame('Synthetic cross-branch allergen', $detail['allergies']['records'][0]['allergen']);
    }

    public function test_historical_authorship_does_not_grant_current_longitudinal_access(): void
    {
        [$historicalDoctor, $ca, $historicalVisit, $historicalQueue] = $this->servingFixture();
        $this->startEncounter($historicalDoctor, $historicalVisit, $historicalQueue);
        $currentDoctor = $this->doctor();
        $currentVisit = $this->consultationVisit($ca, $currentDoctor);
        $currentVisit->forceFill(['patient_id' => $historicalVisit->patient_id])->save();
        $currentQueue = $this->send($ca, $currentVisit);
        $this->selectBranch($currentDoctor);
        $currentQueue = app(QueueEntryService::class)->call($currentDoctor, $currentVisit, [
            'expected_branch_id' => $currentVisit->branch_id,
            'visit_lock_version' => $currentVisit->lock_version,
            'queue_lock_version' => $currentQueue->lock_version,
        ]);
        $this->startEncounter($currentDoctor, $currentVisit->refresh(), $currentQueue);

        $this->selectBranch($historicalDoctor);
        $this->post(route('encounters.allergies.no-known', $currentVisit), [
            'expected_branch_id' => $currentVisit->branch_id,
            'profile_lock_version' => null,
        ])->assertNotFound();
    }

    public function test_cross_organisation_current_safety_route_probing_is_not_found(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $otherOrganisation = Organisation::query()->create([
            'code' => 'SYNTH_SAFETY_OTHER',
            'name' => 'Synthetic Clinical Safety Other',
            'is_active' => true,
        ]);
        $otherBranch = new Branch;
        $otherBranch->forceFill([
            'organisation_id' => $otherOrganisation->id,
            'code' => 'OTHER',
            'name' => 'Synthetic Other Branch',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();
        $outsider = $this->actor('resident_doctor', $otherBranch);
        $this->selectBranch($outsider, $otherBranch);

        $this->post(route('encounters.allergies.no-known', $visit), [
            'expected_branch_id' => $otherBranch->id,
            'profile_lock_version' => null,
        ])->assertNotFound();
        $this->post(route('encounters.allergies.store', $visit), [
            'expected_branch_id' => 'malformed',
            'profile_lock_version' => null,
            'allergen_text' => '',
        ])->assertNotFound();
        $this->post(route('encounters.problems.store', $visit), [
            'expected_branch_id' => $otherBranch->id,
            'condition_text' => 'Synthetic cross-tenant probe',
        ])->assertNotFound();
    }

    public function test_sensitive_input_is_not_flashed_and_no_delete_routes_exist(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($doctor);

        $this->post(route('encounters.allergies.store', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => null,
            'allergen_text' => 'Synthetic private allergen',
            'reaction_text' => 'Synthetic private reaction',
            'category' => 'invalid',
            'severity' => 'mild',
            'allergies' => ['nested' => 'Synthetic nested Allergy content'],
            'allergy_profile' => ['nested' => 'Synthetic nested Profile content'],
        ])->assertSessionHasErrors('category')
            ->assertSessionMissing('_old_input.allergen_text')
            ->assertSessionMissing('_old_input.reaction_text')
            ->assertSessionMissing('_old_input.category')
            ->assertSessionMissing('_old_input.severity')
            ->assertSessionMissing('_old_input.allergies')
            ->assertSessionMissing('_old_input.allergy_profile');

        $this->post(route('encounters.problems.store', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'condition_text' => 'Synthetic private condition',
            'condition_code' => 'SYN-PRIVATE',
            'code_system' => 'Synthetic private code system',
            'onset_date' => 'not-a-date',
            'problems' => ['nested' => 'Synthetic nested Problem content'],
        ])->assertSessionHasErrors('onset_date')
            ->assertSessionMissing('_old_input.condition_text')
            ->assertSessionMissing('_old_input.condition_code')
            ->assertSessionMissing('_old_input.code_system')
            ->assertSessionMissing('_old_input.onset_date')
            ->assertSessionMissing('_old_input.problems');

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getName(), 'encounters.allergies')
                || str_contains((string) $route->getName(), 'encounters.problems'));
        $this->assertFalse($routes->contains(fn ($route) => in_array('DELETE', $route->methods(), true)));
    }

    public function test_operational_patient_visit_and_queue_projections_do_not_contain_clinical_safety_values(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        app(PatientAllergyService::class)->add($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => null,
            'allergen_text' => 'Synthetic projection-private allergen',
            'reaction_text' => 'Synthetic projection-private reaction',
            'category' => 'medication',
            'severity' => 'mild',
        ]);
        app(PatientProblemService::class)->add($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'condition_text' => 'Synthetic projection-private condition',
        ]);
        $this->selectBranch($ca);

        $operational = json_encode([
            app(PatientDirectoryService::class)->detail($ca, $visit->patient),
            app(VisitDirectoryService::class)->detail($ca, $visit),
            app(QueueDirectoryService::class)->snapshot($ca),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            'Synthetic projection-private allergen',
            'Synthetic projection-private reaction',
            'Synthetic projection-private condition',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $operational);
        }
    }
}
