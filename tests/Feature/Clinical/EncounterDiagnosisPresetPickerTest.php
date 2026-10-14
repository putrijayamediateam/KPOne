<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\DiagnosisPreset;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EncounterDiagnosisPresetPickerTest extends ClinicalTestCase
{
    private function preset(User $creator, string $name, bool $active = true): DiagnosisPreset
    {
        $preset = new DiagnosisPreset;
        $preset->forceFill(['organisation_id' => $creator->organisation_id, 'name' => $name, 'normalized_name' => mb_strtolower($name),
            'is_active' => $active, 'lock_version' => 1, 'created_by_user_id' => $creator->id])->save();

        return $preset;
    }

    public function test_lookup_is_active_scoped_bounded_literal_and_does_not_mutate_clinical_data(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->preset($doctor, 'Synthetic 10% reference');
        $this->preset($doctor, 'Synthetic archived reference', false);
        foreach (range(1, 52) as $n) {
            $this->preset($doctor, 'Synthetic reference '.$n);
        }
        $version = $encounter->lock_version;
        $auditCount = DB::table('audit_logs')->count();
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id]))
            ->assertOk()->assertJsonCount(50, 'data')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id, 'q' => '10%']))
            ->assertOk()->assertExactJson(['data' => [['id' => DiagnosisPreset::query()->where('name', 'Synthetic 10% reference')->sole()->id, 'name' => 'Synthetic 10% reference']]]);
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id, 'q' => 'archived']))->assertExactJson(['data' => []]);
        $this->assertSame($version, $encounter->refresh()->lock_version);
        $this->assertDatabaseCount('encounter_diagnoses', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertFalse($doctor->can('catalogue.settings.manage.organisation'));
        $this->get(route('diagnosis-presets.index'))->assertForbidden();
    }

    public function test_existing_encounter_permissions_ownership_and_branch_context_are_required(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $url = route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id]);
        foreach (['director', 'administrator', 'panel_officer', 'finance_officer', 'ca', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->getJson($url)->assertForbidden();
        }
        $this->selectBranch($this->doctor());
        $this->getJson($url)->assertNotFound();
        $this->selectBranch($doctor);
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id, 'q' => str_repeat('x', 201)]))->assertUnprocessable();
        $doctor->roles()->sole()->revokePermissionTo('encounters.update.own');
        $this->getJson($url)->assertForbidden();
    }

    public function test_held_cancelled_and_expired_assignment_contexts_cannot_offer_presets(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $url = route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id]);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->getJson($url)->assertForbidden();
        // Controlled synthetic state fixture; leave the production transition services unchanged.
        $encounter->holds()->update(['resumed_at' => now()->utc(), 'resumed_by_user_id' => $doctor->id]);
        $visit->forceFill(['status' => Visit::STATUS_CANCELLED, 'cancelled_at' => now()->utc(), 'cancelled_by_user_id' => $doctor->id, 'cancellation_reason' => 'Synthetic cancellation'])->save();
        $this->getJson($url)->assertForbidden();
        $visit->forceFill(['status' => Visit::STATUS_REGISTERED, 'cancelled_at' => null, 'cancelled_by_user_id' => null, 'cancellation_reason' => null])->save();
        StaffBranchAssignment::query()->where('staff_profile_id', $doctor->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_other_organisation_presets_are_not_returned_and_foreign_visits_are_refused(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYNTH-FOREIGN', 'name' => 'Synthetic Foreign Organisation', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH', 'name' => 'Synthetic Department', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH', 'name' => 'Synthetic Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreignDoctor = $this->actor('resident_doctor', $branch);
        $this->preset($foreignDoctor, 'Synthetic foreign reference');
        $this->selectBranch($doctor);
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id]))->assertExactJson(['data' => []]);
        $this->selectBranch($foreignDoctor, $branch);
        $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $branch->id]))->assertNotFound();
    }

    public function test_selected_text_is_saved_through_existing_aggregate_and_later_preset_changes_do_not_rewrite_it(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $preset = $this->preset($doctor, 'Synthetic chosen reference');
        $name = $this->getJson(route('encounters.diagnosis-presets', ['visit' => $visit, 'expected_branch_id' => $visit->branch_id]))->assertOk()->json('data.0.name');
        $data = $this->aggregate($encounter, ['diagnoses' => [['diagnosis_text' => $name, 'diagnosis_code' => null, 'code_system' => null, 'is_primary' => true]]]);
        $this->patch(route('encounters.update', $visit), $data)->assertRedirect(route('encounters.show', $visit));
        $row = DB::table('encounter_diagnoses')->sole();
        $this->assertSame($preset->name, $row->diagnosis_text);
        $this->assertNull($row->diagnosis_code);
        $this->assertNull($row->code_system);
        $before = DB::table('encounter_diagnoses')->get()->toJson();
        $preset->forceFill(['name' => 'Synthetic renamed reference', 'normalized_name' => 'synthetic renamed reference', 'is_active' => false])->save();
        $this->assertSame($before, DB::table('encounter_diagnoses')->get()->toJson());
        $this->assertSame(2, $encounter->refresh()->lock_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'encounter.updated']);
    }
}
