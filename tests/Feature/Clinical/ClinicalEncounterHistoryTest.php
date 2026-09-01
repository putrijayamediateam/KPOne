<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class ClinicalEncounterHistoryTest extends ClinicalTestCase
{
    public function test_current_care_doctor_can_open_full_read_only_historical_detail(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $patient = $this->patient($ca, ['full_name' => 'Synthetic History Patient']);
        [$historicalVisit, , $historical] = $this->servingEncounterForPatient($ca, $doctor, $patient);
        $historical = app(ClinicalEncounterService::class)->update($doctor, $historicalVisit, $this->aggregate($historical, [
            'clinical_note' => 'Synthetic historical clinical note',
            'diagnoses' => [[
                'diagnosis_text' => 'Synthetic historical diagnosis',
                'diagnosis_code' => 'UAT-A',
                'code_system' => 'UAT',
                'is_primary' => true,
            ]],
        ]));
        $historical->forceFill(['started_at' => now()->subDay()])->save();
        [$currentVisit] = $this->servingEncounterForPatient($ca, $doctor, $patient, confirmRepeat: true);

        $this->selectBranch($doctor);
        $response = $this->get(route('encounters.history.show', $historicalVisit));

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/HistoryShow')
            ->where('historical.patient.patientNumber', $patient->patient_number)
            ->where('historical.visit.visitNumber', $historicalVisit->visit_number)
            ->where('historical.encounter.clinicalNote', 'Synthetic historical clinical note')
            ->where('historical.vitals.systolicBp', 120)
            ->where('historical.vitals.bmi', 23.4)
            ->where('historical.diagnoses.0.diagnosisText', 'Synthetic historical diagnosis')
            ->where('historical.diagnoses.0.diagnosisCode', 'UAT-A')
            ->where('historical.diagnoses.0.codeSystem', 'UAT')
            ->where('historical.diagnoses.0.isPrimary', true)
            ->where('historical.navigation.backUrl', route('encounters.show', $currentVisit))
            ->missing('historical.encounter.lockVersion')
            ->missing('historical.patient.id')
            ->missing('historical.visit.id')
            ->missing('historical.visit.registrationReason')
            ->missing('historical.actions'));
    }

    public function test_authorized_history_can_be_loaded_on_demand_as_an_explicit_json_projection(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $patient = $this->patient($ca);
        [$historicalVisit, , $historical] = $this->servingEncounterForPatient($ca, $doctor, $patient);
        app(ClinicalEncounterService::class)->update($doctor, $historicalVisit, $this->aggregate($historical, [
            'clinical_note' => 'Synthetic on-demand note',
        ]));
        $historical->forceFill(['started_at' => now()->subDay()])->save();
        $this->servingEncounterForPatient($ca, $doctor, $patient, confirmRepeat: true);
        $this->selectBranch($doctor);

        $this->getJson(route('encounters.history.show', $historicalVisit))
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeaderContains('Cache-Control', 'private')
            ->assertJsonPath('encounter.clinicalNote', 'Synthetic on-demand note')
            ->assertJsonPath('patient.patientNumber', $patient->patient_number)
            ->assertJsonMissingPath('encounter.lockVersion')
            ->assertJsonMissingPath('patient.id')
            ->assertJsonMissingPath('visit.id')
            ->assertJsonMissingPath('treatmentPlan')
            ->assertJsonMissingPath('allergies')
            ->assertJsonMissingPath('problems')
            ->assertJsonMissingPath('actions');
    }

    public function test_attending_clinician_can_view_authored_history_without_current_care_path(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Synthetic authored history note',
        ]));
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

        $this->get(route('encounters.history.show', $visit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clinical/HistoryShow')
                ->where('historical.encounter.clinicalNote', 'Synthetic authored history note')
                ->where('historical.navigation.backUrl', route('queue.index')));
    }

    public function test_unrelated_doctor_cannot_distinguish_existing_history_from_missing_history(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Synthetic existence-sensitive note',
        ]));
        $withoutEncounter = $this->consultationVisit($ca, $doctor);
        $otherDoctor = $this->doctor();
        $this->selectBranch($otherDoctor);

        $existing = $this->get(route('encounters.history.show', $visit));
        $missing = $this->get(route('encounters.history.show', $withoutEncounter));

        $existing->assertNotFound()->assertDontSee('existence-sensitive');
        $missing->assertNotFound();
        $this->assertSame($existing->getStatusCode(), $missing->getStatusCode());

        $existingJson = $this->getJson(route('encounters.history.show', $visit));
        $missingJson = $this->getJson(route('encounters.history.show', $withoutEncounter));
        $existingJson->assertNotFound()->assertJsonMissingPath('encounter.clinicalNote');
        $missingJson->assertNotFound();
        $this->assertSame($existingJson->getStatusCode(), $missingJson->getStatusCode());
    }

    public function test_non_clinical_roles_and_guest_cannot_open_historical_detail(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        auth()->logout();

        $this->get(route('encounters.history.show', $visit))->assertRedirect(route('login'));
        foreach (['director', 'ca', 'ca_supervisor', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('encounters.history.show', $visit))->assertForbidden();
            $this->getJson(route('encounters.history.show', $visit))->assertForbidden();
        }
    }

    public function test_current_care_allows_same_organisation_cross_branch_history(): void
    {
        $historicalBranch = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)
            ->firstOrFail();
        $historicalDoctor = $this->actor('resident_doctor', $historicalBranch);
        $historicalCa = $this->actor('ca', $historicalBranch);
        $currentDoctor = $this->doctor();
        $currentCa = $this->actor('ca');
        $patient = $this->patient($currentCa, ['full_name' => 'Synthetic Cross Branch History']);
        [$historicalVisit, , $historical] = $this->servingEncounterForPatient(
            $historicalCa,
            $historicalDoctor,
            $patient,
            $historicalBranch,
        );
        app(ClinicalEncounterService::class)->update(
            $historicalDoctor,
            $historicalVisit,
            $this->aggregate($historical, ['clinical_note' => 'Synthetic cross branch note']),
        );
        $historical->forceFill(['started_at' => now()->subDay()])->save();
        $this->servingEncounterForPatient($currentCa, $currentDoctor, $patient);
        $this->selectBranch($currentDoctor, $this->branch);

        $this->get(route('encounters.history.show', $historicalVisit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('historical.branch.name', $historicalBranch->name)
                ->where('historical.encounter.clinicalNote', 'Synthetic cross branch note'));
        $this->assertFalse(
            StaffBranchAssignment::query()
                ->where('staff_profile_id', $currentDoctor->staffProfile->id)
                ->where('branch_id', $historicalBranch->id)
                ->exists(),
        );
    }

    public function test_cross_organisation_and_lost_current_care_eligibility_are_denied(): void
    {
        $historicalDoctor = $this->doctor();
        $currentDoctor = $this->doctor();
        $ca = $this->actor('ca');
        $patient = $this->patient($ca);
        [$historicalVisit] = $this->servingEncounterForPatient($ca, $historicalDoctor, $patient);
        $this->servingEncounterForPatient($ca, $currentDoctor, $patient, confirmRepeat: true);
        $this->selectBranch($currentDoctor);
        $this->get(route('encounters.history.show', $historicalVisit))->assertOk();

        StaffBranchAssignment::query()
            ->where('staff_profile_id', $currentDoctor->staffProfile->id)
            ->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->get(route('encounters.history.show', $historicalVisit))->assertNotFound();

        $otherOrganisation = Organisation::query()->create([
            'code' => 'SYNTH_HISTORY_OTHER',
            'name' => 'Synthetic History Other',
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
        $this->get(route('encounters.history.show', $historicalVisit))->assertNotFound();
    }

    public function test_historical_route_is_read_only(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($doctor);
        $uri = '/visits/'.$visit->visit_number.'/encounter/history';

        $route = Route::getRoutes()->getByName('encounters.history.show');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post($uri)->assertMethodNotAllowed();
        $this->patch($uri)->assertMethodNotAllowed();
        $this->delete($uri)->assertMethodNotAllowed();
        foreach (['finalize', 'complete', 'sign', 'handover', 'treatment'] as $forbidden) {
            $this->assertNull(Route::getRoutes()->getByName('encounters.history.'.$forbidden));
        }
    }

    /** @return array{Visit, QueueEntry, ClinicalEncounter} */
    private function servingEncounterForPatient(
        User $ca,
        User $doctor,
        Patient $patient,
        ?Branch $branch = null,
        bool $confirmRepeat = false,
    ): array {
        $branch ??= $this->branch;
        $this->selectBranch($ca, $branch);
        $visit = app(VisitRegistrationService::class)->register($ca, $this->visitAttributes($patient, [
            'expected_branch_id' => $branch->id,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => 'Synthetic history context',
            'confirm_repeat' => $confirmRepeat,
        ]));
        $queue = app(QueueEntryService::class)->enter($ca, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
        ]);
        $this->selectBranch($doctor, $branch);
        $queue = app(QueueEntryService::class)->call($doctor, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
        ]);
        $encounter = app(ClinicalEncounterService::class)->start($doctor, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
        ]);

        return [$visit->refresh(), $queue->refresh(), $encounter->refresh()];
    }
}
