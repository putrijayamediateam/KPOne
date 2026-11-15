<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class ClinicalEncounterAuthorizationTest extends ClinicalTestCase
{
    public function test_guest_inactive_and_non_clinical_roles_are_denied(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        auth()->logout();

        $this->get(route('encounters.show', $visit))->assertRedirect(route('login'));
        foreach (['director', 'ca', 'ca_supervisor', 'technical_admin', 'panel_officer', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('encounters.show', $visit))->assertForbidden();
        }

        $doctor->forceFill(['is_active' => false])->save();
        $this->selectBranch($doctor);
        $this->get(route('encounters.show', $visit))->assertRedirect(route('login'));
        $this->assertSame($doctor->id, $encounter->attending_clinician_user_id);
    }

    #[DataProvider('roleMatrix')]
    public function test_locked_clinical_permission_matrix(string $role, bool $allowed): void
    {
        $actor = $this->actor($role);
        foreach ([
            'encounters.view.own', 'encounters.start.own', 'encounters.update.own',
            'encounters.history.view.organisation',
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
            'technical' => ['technical_admin', false],
        ];
    }

    public function test_patient_visit_and_queue_permissions_do_not_imply_encounter_access(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $actor = $this->actor('technical_admin');
        foreach (['patients.view.organisation', 'visits.view.branch', 'queue.view.branch'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->selectBranch($actor);

        $this->get(route('encounters.show', $visit))->assertForbidden();
        $this->post(route('encounters.store', $visit), [])->assertForbidden();
    }

    public function test_other_doctor_cannot_probe_encounter_existence_or_update(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $visitWithoutEncounter = $this->consultationVisit($this->actor('ca'), $doctor);
        $other = $this->doctor();
        $this->selectBranch($other);

        $this->get(route('encounters.show', $visit))->assertNotFound();
        $this->get(route('encounters.show', $visitWithoutEncounter))->assertNotFound();
        $this->patch(route('encounters.update', $visit), $this->aggregate($encounter))->assertNotFound();
        $this->patch(route('encounters.update', $visitWithoutEncounter), $this->aggregate($encounter))->assertNotFound();
    }

    public function test_director_with_explicit_resident_doctor_role_uses_clinical_role(): void
    {
        $doctor = $this->actor('director');
        $doctor->assignRole('resident_doctor');
        [, , $visit, $queue] = $this->servingFixture($doctor);

        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->assertSame($doctor->id, $encounter->attending_clinician_user_id);
    }

    public function test_cross_branch_and_cross_organisation_probing_does_not_resolve(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
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
        $this->selectBranch($doctor, $otherBranch);
        $this->get(route('encounters.show', $visit))->assertNotFound();

        $otherOrganisation = Organisation::query()->create([
            'code' => 'SYNTH_CLINICAL_OTHER', 'name' => 'Synthetic Clinical Other', 'is_active' => true,
        ]);
        $outsiderBranch = new Branch;
        $outsiderBranch->forceFill([
            'organisation_id' => $otherOrganisation->id, 'code' => 'OTHER',
            'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true,
        ])->save();
        $outsider = $this->actor('resident_doctor', $outsiderBranch);
        $this->selectBranch($outsider, $outsiderBranch);
        $this->get(route('encounters.show', $visit))->assertNotFound();
    }

    public function test_role_or_branch_eligibility_loss_fails_closed_on_update(): void
    {
        foreach (['role', 'assignment'] as $mode) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            if ($mode === 'role') {
                $doctor->removeRole('resident_doctor');
            } else {
                StaffBranchAssignment::query()
                    ->where('staff_profile_id', $doctor->staffProfile->id)
                    ->update(['valid_until' => now()->subDay()->toDateString()]);
            }
            $this->selectBranch($doctor);

            try {
                app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
                $this->fail("Clinical update remained available after {$mode} loss.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }
}
