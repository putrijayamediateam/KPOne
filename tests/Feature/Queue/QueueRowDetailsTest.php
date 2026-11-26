<?php

namespace Tests\Feature\Queue;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Tests\Feature\Clinical\Triage\TriageTestCase;
use Tests\Support\SyntheticVisitCompletion;

/** Q-16c: the board row details panel (owner decisions 25 Nov 2026). */
class QueueRowDetailsTest extends TriageTestCase
{
    /** @return array{User, User, Visit} */
    private function fixture(): array
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'yellow']);

        return [$ca, $doctor, $visit->refresh()];
    }

    private function open(User $actor, Visit $visit)
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->getJson(route('queue.details', $visit->visit_number));
    }

    public function test_the_recorders_and_the_own_doctor_get_the_panel_with_only_the_agreed_fields(): void
    {
        [$ca, $doctor, $visit] = $this->fixture();
        foreach ([$ca, $this->actor('ca_supervisor'), $doctor] as $viewer) {
            $json = $this->open($viewer, $visit)->assertOk()->json('details');
            $this->assertSame(
                ['ageYears', 'allergies', 'allergyStatus', 'coverageLabel', 'lastBp', 'lastVisit', 'memberReference', 'reason', 'sex', 'triage', 'visitNumber'],
                collect($json)->keys()->sort()->values()->all(),
            );
            $this->assertSame('yellow', $json['triage']['zone']);
            $this->assertSame('120', $json['triage']['readings']['systolic_bp']);
            $this->assertSame('unknown', $json['allergyStatus']);
        }
        $raw = json_encode($this->open($ca, $visit)->json());
        $this->assertStringNotContainsString($visit->patient->full_name, $raw);
        $this->assertStringNotContainsString('date_of_birth', $raw);
    }

    public function test_the_panel_carries_allergy_names_last_bp_and_the_previous_visit(): void
    {
        [$ca, , $visit] = $this->fixture();
        $profile = PatientAllergyProfile::factory()->create(['patient_id' => $visit->patient_id, 'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'reviewed_at' => now()->utc(), 'reviewed_by_user_id' => User::query()->value('id')]);
        PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $profile->id, 'allergen_text' => 'Penicillin']);
        PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $profile->id, 'allergen_text' => 'Wrongly entered', 'status' => PatientAllergyRecord::STATUS_ENTERED_IN_ERROR, 'entered_in_error_at' => now()->utc(), 'entered_in_error_by_user_id' => $profile->updated_by_user_id]);
        $earlier = SyntheticVisitCompletion::complete($this->register($ca, $visit->patient, ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $this->doctor()->id, 'visit_reason' => 'Synthetic earlier reason', 'confirm_repeat' => true]));
        $encounter = ClinicalEncounter::factory()->create(['visit_id' => $earlier->id, 'started_at' => now()->utc()->subDays(10)]);
        EncounterVitalObservation::factory()->create(['clinical_encounter_id' => $encounter->id, 'systolic_bp' => 142, 'diastolic_bp' => 90]);

        $json = $this->open($ca, $visit)->assertOk()->json('details');

        $this->assertSame(['Penicillin'], $json['allergies']);
        $this->assertSame('has_allergies', $json['allergyStatus']);
        $this->assertSame(142, $json['lastBp']['systolic']);
        $this->assertSame(90, $json['lastBp']['diastolic']);
        $this->assertSame($earlier->visit_number, $json['lastVisit']['visitNumber']);
        $this->assertSame('Self-pay', $json['coverageLabel']);
        $this->assertNull($json['memberReference']);
    }

    public function test_every_other_role_and_another_doctor_is_refused(): void
    {
        [, , $visit] = $this->fixture();
        foreach (['director', 'finance_officer', 'panel_officer', 'hr_manager'] as $role) {
            $this->open($this->actor($role), $visit)->assertForbidden();
        }
        $this->open($this->doctor(), $visit)->assertForbidden();
    }

    public function test_each_open_writes_one_values_free_audit_row_and_head_writes_none(): void
    {
        [$ca, , $visit] = $this->fixture();
        $before = AuditLog::query()->where('event', 'queue.details_viewed')->count();

        $this->open($ca, $visit)->assertOk();
        $this->assertSame($before + 1, AuditLog::query()->where('event', 'queue.details_viewed')->count());
        $log = AuditLog::query()->where('event', 'queue.details_viewed')->latest('id')->firstOrFail();
        $this->assertSame(['ids' => ['visit' => $visit->id, 'patient' => $visit->patient_id]], $log->metadata);

        $this->selectBranch($ca);
        $this->actingAs($ca)->call('HEAD', route('queue.details', $visit->visit_number))->assertStatus(405);
        $this->assertSame($before + 1, AuditLog::query()->where('event', 'queue.details_viewed')->count());
    }

    public function test_nothing_is_shown_if_the_audit_cannot_be_written(): void
    {
        [$ca, , $visit] = $this->fixture();
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                return null;
            }
        });
        config(['app.debug' => false]);
        $this->selectBranch($ca);

        $response = $this->actingAs($ca)->getJson(route('queue.details', $visit->visit_number));
        $response->assertStatus(500);
        $this->assertStringNotContainsString($visit->patient->full_name, (string) $response->getContent());
    }

    public function test_a_visit_at_another_branch_is_not_found(): void
    {
        [, , $visit] = $this->fixture();
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $ca = $this->actor('ca', $other);
        $this->selectBranch($ca, $other);

        $this->actingAs($ca)->getJson(route('queue.details', $visit->visit_number))->assertNotFound();
    }
}
