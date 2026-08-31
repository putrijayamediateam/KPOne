<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Queue\Services\QueueEntryService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

class ClinicalEncounterDirectoryTest extends ClinicalTestCase
{
    public function test_detail_projection_is_minimized_private_and_derives_bmi(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));

        $this->selectBranch($doctor);
        $response = $this->get(route('encounters.show', $visit));

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $response
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clinical/Show')
                ->where('clinical.patient.patientNumber', $visit->patient->patient_number)
                ->where('clinical.vitals.bmi', 23.4)
                ->where('clinical.queue.status', 'serving')
                ->where('clinical.limitations.structuredHistory', 'Structured allergy and medical-condition history is not yet available in KPOne.')
                ->missing('clinical.patient.mobilePhone')
                ->missing('clinical.patient.identifiers')
                ->missing('clinical.visit.coverageMemberReference')
                ->missing('clinical.queue.id')
                ->missing('clinical.encounter.id'));
    }

    public function test_history_is_bounded_same_patient_same_organisation_and_contains_no_notes(): void
    {
        [$doctor, $ca, $currentVisit, $currentQueue] = $this->servingFixture();
        $current = $this->startEncounter($doctor, $currentVisit, $currentQueue);
        for ($index = 0; $index < 18; $index++) {
            $visit = $this->consultationVisit($ca, $doctor);
            $visit->forceFill(['patient_id' => $currentVisit->patient_id])->save();
            $queue = $this->send($ca, $visit);
            $this->selectBranch($doctor);
            $queue = app(QueueEntryService::class)->call($doctor, $visit, [
                'expected_branch_id' => $visit->branch_id,
                'visit_lock_version' => $visit->lock_version,
                'queue_lock_version' => $queue->lock_version,
            ]);
            $history = $this->startEncounter($doctor, $visit->refresh(), $queue);
            app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($history, [
                'clinical_note' => 'Synthetic historical private note '.$index,
            ]));
        }

        $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $currentVisit);

        $this->assertCount(15, $detail['history']);
        $encoded = json_encode($detail['history'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('historical private note', $encoded);
    }

    public function test_patient_master_permission_alone_cannot_obtain_clinical_history(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $actor = $this->actor('technical_admin');
        $actor->givePermissionTo(Permission::findOrCreate('patients.view.organisation', 'web'));
        $this->selectBranch($actor);

        $this->get(route('encounters.show', $visit))->assertForbidden();
        $this->actingAs($actor)->get(route('patients.show', $visit->patient))->assertOk()
            ->assertDontSee('Structured allergy')
            ->assertDontSee('Synthetic clinical note');
    }

    public function test_global_audit_projection_neutralizes_clinical_subject_and_values(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);

        $this->get(route('audit-logs.index'))->assertOk()
            ->assertSee('Clinical record')
            ->assertDontSee($visit->patient->full_name)
            ->assertDontSee($visit->visit_number)
            ->assertDontSee('Synthetic clinical note')
            ->assertDontSee('Synthetic diagnosis alpha');
    }
}
