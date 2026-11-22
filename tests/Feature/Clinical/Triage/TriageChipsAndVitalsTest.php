<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Triage\TriageZoneProjection;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** TRI-1c: zone chips, "No triage yet", and the vitals copied from triage at consultation start. Synthetic data only. */
class TriageChipsAndVitalsTest extends TriageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mid-morning branch-local, so no step here can cross Kuala Lumpur midnight.
        $this->travelTo(CarbonImmutable::now($this->branch->timezone)->setTime(10, 0));
    }

    /** @return array{zone:string|null, triaged:bool}|null */
    private function zoneFor(User $actor, Visit $visit): ?array
    {
        $visit->refresh()->load('queueEntry');

        return app(TriageZoneProjection::class)->forVisits($actor, [TriageZoneProjection::subject($visit, $visit->queueEntry)])[$visit->id] ?? null;
    }

    public function test_the_zone_is_for_ca_supervisor_and_own_doctor_and_the_warning_for_anyone_who_may_call(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $ownDoctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $otherDoctor = $this->doctor();
        $supervisor = $this->actor('ca_supervisor');
        $director = $this->actor('director');

        $untriaged = ['zone' => null, 'triaged' => false];
        $this->assertSame($untriaged, $this->zoneFor($ca, $visit));
        $this->assertSame($untriaged, $this->zoneFor($supervisor, $visit));
        $this->assertSame($untriaged, $this->zoneFor($ownDoctor, $visit));
        $this->assertSame($untriaged, $this->zoneFor($director, $visit), 'the Director may call from the branch board, so is warned');
        $this->assertNull($this->zoneFor($otherDoctor, $visit), 'another doctor may not call this patient');
        foreach (['finance_officer', 'administrator', 'hr_manager', 'panel_officer'] as $role) {
            $this->assertNull($this->zoneFor($this->actor($role), $visit), $role);
        }

        $this->triage($ca, $visit, ['pulse_bpm' => 130, 'chosen_zone' => 'yellow']);
        $yellow = ['zone' => 'yellow', 'triaged' => true];
        $this->assertSame($yellow, $this->zoneFor($ca, $visit));
        $this->assertSame($yellow, $this->zoneFor($supervisor, $visit));
        $this->assertSame($yellow, $this->zoneFor($ownDoctor, $visit));
        $this->assertNull($this->zoneFor($director, $visit), 'the zone itself stays with CA, CA Supervisor and the own doctor');
        $this->assertNull($this->zoneFor($otherDoctor, $visit));
    }

    public function test_an_otc_visit_and_a_triaged_row_that_left_waiting_carry_no_warning(): void
    {
        [$ca] = $this->waitingFixture();
        $otc = $this->register($ca, $this->patient($ca), ['visit_type' => 'otc', 'assigned_doctor_user_id' => null]);
        $this->assertNull($this->zoneFor($ca, $otc));

        [$doctor, , $serving] = $this->servingFixture(null, $ca);
        $this->assertNull($this->zoneFor($ca, $serving), 'no "No triage yet" once the patient is called in');
    }

    public function test_board_rows_and_the_call_next_card_carry_only_the_zone(): void
    {
        $this->approvedGuideline();
        [$ca, $first] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($first->assigned_doctor_user_id);
        $this->triage($ca, $first, ['pulse_bpm' => 130, 'chosen_zone' => 'yellow']);

        $this->selectBranch($ca);
        $row = collect(app(VisitDirectoryService::class)->search($ca, [])['data'])->firstWhere('visitNumber', $first->visit_number);
        $this->assertSame(['zone' => 'yellow', 'triaged' => true], $row['triage']);
        $this->assertArrayNotHasKey('vitals', $row);

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director)->postJson(route('registration.search'))->assertOk()
            ->assertJsonPath('data.0.triage', null);

        $this->selectBranch($ca);
        $queueRow = collect(app(QueueDirectoryService::class)->snapshot($ca)['waiting']['data'])->firstWhere('visitNumber', $first->visit_number);
        $this->assertSame(['zone' => 'yellow', 'triaged' => true], $queueRow['triage']);

        $this->selectBranch($doctor);
        $this->actingAs($doctor)->getJson(route('queue.call-next.block', ['expected_branch_id' => $this->branch->id]))->assertOk()
            ->assertJsonPath('callNext.candidate.visitNumber', $first->visit_number)
            ->assertJsonPath('callNext.candidate.triage', ['zone' => 'yellow', 'triaged' => true]);

        $feed = json_encode(app(QueueDisplayFeedService::class)->feed($this->branch), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('triage', $feed);
        $this->assertStringNotContainsString('yellow', $feed);
    }

    public function test_consultation_start_copies_the_latest_of_each_reading_and_labels_it(): void
    {
        $this->approvedGuideline();
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $first = $this->triage($ca, $visit, ['pulse_bpm' => 82]);
        $secondCa = $this->actor('ca');
        $second = $this->triage($secondCa, $visit, [
            'systolic_bp' => null, 'diastolic_bp' => null, 'pulse_bpm' => null, 'spo2_percent' => null,
            'weight_kg' => null, 'height_cm' => null, 'temperature_celsius' => '37.20',
        ]);

        $encounter = $this->startEncounter($doctor, $visit->refresh(), $queue->refresh());

        $vitals = EncounterVitalObservation::query()->where('clinical_encounter_id', $encounter->id)->sole();
        $this->assertSame([120, 80, 82, '37.20', '98.00', '60.00', '160.00'], [
            $vitals->systolic_bp, $vitals->diastolic_bp, $vitals->pulse_bpm, $vitals->temperature_celsius,
            $vitals->spo2_percent, $vitals->weight_kg, $vitals->height_cm,
        ], 'temperature from the retake, everything else from the first save');
        $this->assertSame($second->id, $vitals->getAttribute('source_triage_assessment_id'));
        $this->assertSame($secondCa->id, $vitals->getAttribute('recorded_by_user_id'), 'the CA of the latest save that had a reading');
        $this->assertSame($doctor->id, $vitals->getAttribute('updated_by_user_id'));
        $this->assertTrue($vitals->observed_at->equalTo($second->recorded_at));
        $this->assertNotSame($first->id, $second->id);

        $audit = AuditLog::query()->where('event', 'encounter.vitals_from_triage')->sole();
        $this->assertSame(['triage_assessment_id' => $second->id, 'readings_copied' => 7], array_intersect_key($audit->metadata, ['triage_assessment_id' => 0, 'readings_copied' => 0]));
        $this->assertStringNotContainsString('37.2', json_encode($audit->metadata, JSON_THROW_ON_ERROR));

        $this->selectBranch($doctor);
        $page = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit->refresh());
        $this->assertSame($secondCa->name, $page['vitals']['fromTriage']['recordedBy']);
        $this->assertSame($vitals->observed_at->setTimezone($this->branch->timezone)->format('j M Y, g:i A'), $page['vitals']['fromTriage']['recordedAt']);
        $this->assertSame(['zone' => 'green', 'triaged' => true], $page['visit']['triage']);
    }

    public function test_no_triage_or_a_triage_without_readings_copies_nothing(): void
    {
        [$doctor, , $plain, $plainQueue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $plain, $plainQueue->refresh());
        $this->assertFalse(EncounterVitalObservation::query()->where('clinical_encounter_id', $encounter->id)->exists());

        [$doctor2, $ca, $signsOnly, $signsQueue] = $this->servingFixture();
        $this->triage($ca, $signsOnly, ['systolic_bp' => null, 'diastolic_bp' => null, 'pulse_bpm' => null, 'temperature_celsius' => null,
            'spo2_percent' => null, 'weight_kg' => null, 'height_cm' => null]);
        $second = $this->startEncounter($doctor2, $signsOnly->refresh(), $signsQueue->refresh());
        $this->assertFalse(EncounterVitalObservation::query()->where('clinical_encounter_id', $second->id)->exists());
        $this->assertSame(0, AuditLog::query()->where('event', 'encounter.vitals_from_triage')->count());
    }

    public function test_the_doctor_changing_the_copied_vitals_makes_them_the_doctors_own(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->triage($ca, $visit);
        $encounter = $this->startEncounter($doctor, $visit->refresh(), $queue->refresh());
        $vitals = EncounterVitalObservation::query()->where('clinical_encounter_id', $encounter->id)->sole();
        $copied = [
            'systolic_bp' => 120, 'diastolic_bp' => 80, 'pulse_bpm' => 80, 'temperature_celsius' => '36.80',
            'spo2_percent' => '98', 'weight_kg' => '60', 'height_cm' => '160',
        ];

        $this->selectBranch($doctor);
        app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter->refresh(), ['vitals' => $copied]));
        $this->assertSame($vitals->getAttribute('source_triage_assessment_id'), $vitals->refresh()->getAttribute('source_triage_assessment_id'), 'a save that leaves the vitals as copied keeps the label');

        app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter->refresh(), ['vitals' => [...$copied, 'temperature_celsius' => '37.50']]));
        $vitals->refresh();
        $this->assertNull($vitals->getAttribute('source_triage_assessment_id'));
        $this->assertSame($doctor->id, $vitals->getAttribute('recorded_by_user_id'));
        $this->assertSame('37.50', $vitals->temperature_celsius);
        $this->assertNull(app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit->refresh())['vitals']['fromTriage']);

        app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter->refresh(), ['vitals' => array_map(fn () => null, $copied)]));
        $this->assertFalse(EncounterVitalObservation::query()->where('clinical_encounter_id', $encounter->id)->exists(), 'clearing every reading still deletes the row');
    }

    public function test_postgresql_keeps_the_triage_label_true(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rule.');
        }
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->triage($ca, $visit);
        $encounter = $this->startEncounter($doctor, $visit->refresh(), $queue->refresh());
        $vitals = EncounterVitalObservation::query()->where('clinical_encounter_id', $encounter->id)->sole();

        [, $otherCa, $other] = $this->servingFixture();
        $otherTriage = $this->triage($otherCa, $other);

        $this->assertDatabaseRefuses("UPDATE encounter_vital_observations SET temperature_celsius = 39.00 WHERE id = {$vitals->id}", 'must match the triage readings');
        $this->assertDatabaseRefuses("UPDATE encounter_vital_observations SET source_triage_assessment_id = {$otherTriage->id} WHERE id = {$vitals->id}", 'kept or cleared');
        DB::unprepared("UPDATE encounter_vital_observations SET source_triage_assessment_id = NULL, temperature_celsius = 39.00 WHERE id = {$vitals->id}");
        $this->assertDatabaseRefuses("UPDATE encounter_vital_observations SET source_triage_assessment_id = {$otherTriage->id} WHERE id = {$vitals->id}", 'kept or cleared');

        [$doctor3, $ca3, $third, $thirdQueue] = $this->servingFixture();
        $encounter3 = $this->startEncounter($doctor3, $third, $thirdQueue->refresh());
        $this->assertDatabaseRefuses("INSERT INTO encounter_vital_observations (organisation_id, branch_id, clinical_encounter_id, pulse_bpm, recorded_by_user_id, updated_by_user_id, source_triage_assessment_id, created_at, updated_at)
            VALUES ({$encounter3->organisation_id}, {$encounter3->branch_id}, {$encounter3->id}, 80, {$ca3->id}, {$doctor3->id}, {$otherTriage->id}, now(), now())", 'belong to the encounter');
    }

    private function assertDatabaseRefuses(string $sql, string $message): void
    {
        DB::beginTransaction();
        try {
            DB::unprepared($sql);
            $this->fail("must be refused: $sql");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? (string) $e->getCode(), $sql);
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
