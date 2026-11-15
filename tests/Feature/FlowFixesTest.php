<?php

namespace Tests\Feature;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Clinical\ClinicalTestCase;

class FlowFixesTest extends ClinicalTestCase
{
    public function test_registration_send_to_waiting_has_fixed_success_and_both_validation_destinations(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->selectBranch($ca);
        $url = route('queue.store', $visit);
        $payload = ['from' => 'registration', 'expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version];
        $this->withSession(['_previous' => ['url' => '/unrelated-page']]);
        $this->post($url, ['from' => 'registration'])->assertRedirect(route('registration.index'))->assertSessionHasErrors('expected_branch_id');
        $this->post($url, [...$payload, 'visit_lock_version' => 999])->assertRedirect(route('registration.index'))->assertSessionHasErrors('lock_version');
        $this->assertDatabaseCount('queue_entries', 0);
        $this->post($url, $payload)->assertRedirect(route('registration.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('queue_entries', 1);
    }

    public function test_absent_source_keeps_queue_fallback_and_external_source_is_refused(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $this->selectBranch($ca);
        $payload = ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version];
        $this->post(route('queue.store', $visit), [...$payload, 'from' => 'https://external.test/'])->assertRedirect(route('queue.index'))->assertSessionHasErrors('from');
        $this->assertDatabaseCount('queue_entries', 0);
        $this->post(route('queue.store', $visit), $payload)->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
    }

    public function test_otc_still_returns_to_registration_with_or_without_source(): void
    {
        $ca = $this->actor('ca');
        foreach ([[], ['from' => 'registration']] as $source) {
            $visit = $this->register($ca, $this->patient($ca));
            $this->selectBranch($ca);
            $this->post(route('queue.store', $visit), $source)->assertRedirect(route('registration.index'))->assertSessionHasErrors('expected_branch_id');
            $this->post(route('queue.store', $visit), [...$source, 'expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version])->assertRedirect(route('registration.index'))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('otc_queue_entries', 2);
        $this->assertDatabaseCount('queue_entries', 0);
    }

    public function test_all_tab_exposes_only_pending_or_dispensing_consultation_cases_without_source_writes(): void
    {
        foreach (['pending', 'dispensing', 'completed', 'returned_to_doctor'] as $status) {
            [$doctor, $ca, $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            $plan = TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
            $case = new DispensaryCase;
            $case->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'visit_id' => $visit->id, 'clinical_encounter_id' => $encounter->id, 'treatment_plan_id' => $plan->id, 'case_type' => 'consultation', 'status' => $status, 'lock_version' => 1, 'received_at' => now()->utc()])->save();
            $actors = [$ca, $this->actor('ca_supervisor')];
            $before = $this->snapshot();
            foreach ($actors as $actor) {
                $this->selectBranch($actor, $visit->branch);
                $rows = app(VisitDirectoryService::class)->search($actor, ['board_status' => 'all'])['data'];
                $row = $rows->firstWhere('visitNumber', $visit->visit_number);
                $eligible = in_array($status, ['pending', 'dispensing'], true);
                $this->assertSame($eligible, $row['can']['openDispensary']);
                $this->assertSame($eligible ? route('dispensary.show', $case) : null, $row['dispensaryUrl']);
                $this->assertSame($eligible ? $status : null, $row['dispensaryStatus']);
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_dispensary_hint_does_not_widen_roles_or_branch_scope_and_revocation_is_effective(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $plan = TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
        $case = new DispensaryCase;
        $case->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'visit_id' => $visit->id, 'clinical_encounter_id' => $encounter->id, 'treatment_plan_id' => $plan->id, 'case_type' => 'consultation', 'status' => 'pending', 'lock_version' => 1, 'received_at' => now()->utc()])->save();
        foreach ([$doctor, $this->actor('director')] as $actor) {
            $this->selectBranch($actor, $visit->branch);
            $row = app(VisitDirectoryService::class)->search($actor, [])['data']->firstWhere('visitNumber', $visit->visit_number);
            $this->assertFalse($row['can']['openDispensary']);
            $this->assertNull($row['dispensaryUrl']);
            $this->assertNull($row['dispensaryStatus']);
        }
        $otherBranch = Branch::query()->where('organisation_id', $visit->organisation_id)->whereKeyNot($visit->branch_id)->orderBy('id')->firstOrFail();
        $foreignCa = $this->actor('ca', $otherBranch);
        $this->selectBranch($foreignCa, $otherBranch);
        $this->assertCount(0, app(VisitDirectoryService::class)->search($foreignCa, [])['data']);
        $this->get(route('dispensary.show', $case))->assertNotFound();
        Role::findByName('ca')->revokePermissionTo('dispensary.view.branch');
        $ca->load('roles.permissions');
        $this->selectBranch($ca, $visit->branch);
        $row = app(VisitDirectoryService::class)->search($ca, [])['data']->firstWhere('visitNumber', $visit->visit_number);
        $this->assertFalse($row['can']['openDispensary']);
        $this->assertNull($row['dispensaryUrl']);
        $this->get(route('dispensary.show', $case))->assertForbidden();
    }

    public function test_handed_off_consultation_still_refuses_clinical_save_and_keeps_all_source_rows(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $before = $this->snapshot();
        $this->selectBranch($doctor, $visit->branch);
        $this->patchJson(route('encounters.update', $visit), $this->aggregate($encounter->refresh(), ['clinical_note' => 'Synthetic refused edit']))->assertStatus(422)->assertJsonPath('errors.encounter.0', 'Only a registered Consultation currently Serving can use a clinical Encounter.');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_registration_call_in_goes_to_queue_with_current_serving_actions(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($supervisor, $doctor);
        $queue = $this->send($supervisor, $visit);
        $this->selectBranch($supervisor);
        $this->patch(route('queue.call', $visit), ['from' => 'registration', 'expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version])
            ->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
        $row = app(VisitDirectoryService::class)->search($supervisor, [])['data']->firstWhere('visitNumber', $visit->visit_number);
        $this->assertSame('serving', $row['queueStatus']);
        $this->assertFalse($row['can']['call']);
        $this->assertDatabaseCount('queue_calls', 1);
    }

    public function test_reopening_clears_checkout_with_idle_and_busy_doctor_then_allows_save(): void
    {
        foreach ([false, true] as $busy) {
            [$doctor, $ca, $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
            $checkout = app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
            if ($busy) {
                [, , $otherVisit, $otherQueue] = $this->servingFixture($doctor, $ca);
                $otherEncounter = $this->startEncounter($doctor, $otherVisit, $otherQueue);
            }
            app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => $checkout->lock_version]);
            $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit->refresh());
            $this->assertNull($detail['treatmentPlan']['checkout']);
            $this->assertSame($busy, $detail['hold']['isHeld']);
            $this->assertNull($checkout->refresh()->current_visit_guard);
            $this->assertSame('superseded', $checkout->status);
            if ($busy) {
                $this->holdEncounter($doctor, $otherVisit, $otherQueue, $otherEncounter);
                app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
            }
            $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit->refresh());
            $this->assertNull($detail['treatmentPlan']['checkout']);
            $this->assertFalse($detail['hold']['isHeld']);
            $this->patch(route('encounters.update', $visit), $this->aggregate($encounter->refresh(), ['clinical_note' => 'Synthetic note after reopen and resume']))
                ->assertRedirect(route('encounters.show', $visit))->assertSessionHasNoErrors();
            $this->assertSame('Synthetic note after reopen and resume', $encounter->refresh()->clinical_note);
        }
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['visits', 'queue_entries', 'clinical_encounters', 'encounter_vital_observations', 'encounter_diagnoses', 'treatment_plans', 'dispensary_cases', 'stock_movements', 'inventory_stock_balances', 'invoices', 'payments', 'audit_logs'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
