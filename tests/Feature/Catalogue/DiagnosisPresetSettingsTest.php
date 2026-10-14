<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Clinical\Models\DiagnosisPreset;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\DiagnosisPresetSettingsService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Clinical\ClinicalTestCase;

class DiagnosisPresetSettingsTest extends ClinicalTestCase
{
    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    public function test_only_settings_roles_can_reach_and_mutate_the_reference_page(): void
    {
        foreach (['director', 'administrator', 'finance_officer', 'panel_officer'] as $index => $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('diagnosis-presets.index'))->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
                ->assertInertia(fn (Assert $p) => $p->component('ConsultationSettings/Index')->where('workspace.navigation.consultationSettings', true)->where('status', 'all')->has('presets', $index)->missing('patients')->missing('encounters'));
            $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic '.$role]]))->assertRedirect();
        }
        $preset = DiagnosisPreset::query()->firstOrFail();
        foreach (['technical_admin', 'ca', 'ca_supervisor', 'resident_doctor', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('diagnosis-presets.index'))->assertForbidden();
            $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Denied']]))->assertForbidden();
            $this->patch(route('diagnosis-presets.rename', $preset->id), $this->payload(['name' => 'Denied', 'lock_version' => 1]))->assertForbidden();
            $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => false, 'lock_version' => 1]))->assertForbidden();
        }
        $this->assertDatabaseCount('diagnosis_presets', 4);
    }

    public function test_batch_creation_is_atomic_trimmed_case_insensitive_and_retry_safe(): void
    {
        $this->selectBranch($this->actor('panel_officer'));
        $data = $this->payload(['names' => [' Synthetic first ', 'Synthetic second']]);
        $this->post(route('diagnosis-presets.store'), $data)->assertRedirect(route('diagnosis-presets.index', ['status' => 'all', 'q' => '']));
        $this->post(route('diagnosis-presets.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('diagnosis_presets', 2);
        $this->assertDatabaseCount('diagnosis_preset_commands', 1);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'diagnosis_preset.created')->count());
        $this->post(route('diagnosis-presets.store'), array_replace($data, ['names' => ['Changed']]))->assertSessionHasErrors('preset');
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic third', ' SYNTHETIC FIRST ']]))->assertSessionHasErrors('names.1');
        $this->assertDatabaseCount('diagnosis_presets', 2);
        $this->assertDatabaseHas('diagnosis_presets', ['name' => 'Synthetic first', 'normalized_name' => 'synthetic first']);
        foreach (DB::table('audit_logs')->where('event', 'diagnosis_preset.created')->get() as $audit) {
            $this->assertSame(['lock_version' => 1], json_decode($audit->metadata, true));
        }
    }

    public function test_invalid_batches_leave_no_presets_commands_or_audits(): void
    {
        $this->selectBranch($this->actor('administrator'));
        foreach ([['names' => ['One', ' one ']], ['names' => ['']], ['names' => ['   ']], ['names' => []], ['names' => ['Valid', str_repeat('x', 501)]], ['names' => array_fill(0, 21, 'Synthetic')], ['names' => ['Valid'], 'idempotency_key' => 'bad'], ['names' => ['Valid'], 'expected_branch_id' => 999]] as $change) {
            $this->post(route('diagnosis-presets.store'), $this->payload($change))->assertRedirect(route('diagnosis-presets.index', ['status' => 'all', 'q' => '']))->assertSessionHasErrors();
            $this->assertDatabaseCount('diagnosis_presets', 0);
            $this->assertDatabaseCount('diagnosis_preset_commands', 0);
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'diagnosis_preset.created')->count());
        }
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => [str_repeat('x', 500)]]))->assertRedirect();
        $this->assertSame(500, mb_strlen(DiagnosisPreset::query()->sole()->name));
    }

    public function test_rename_archive_restore_versions_and_retry_audits_are_structural(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic original']]))->assertRedirect();
        $preset = DiagnosisPreset::query()->sole();
        $rename = $this->payload(['name' => ' Synthetic renamed ', 'lock_version' => 1]);
        $this->patch(route('diagnosis-presets.rename', $preset->id), $rename)->assertRedirect();
        $this->patch(route('diagnosis-presets.rename', $preset->id), $rename)->assertRedirect();
        $this->assertSame('Synthetic renamed', $preset->refresh()->name);
        $this->assertSame('synthetic renamed', $preset->normalized_name);
        $this->assertSame(2, $preset->lock_version);
        $this->patch(route('diagnosis-presets.rename', $preset->id), array_replace($rename, ['name' => 'Changed']))->assertSessionHasErrors('preset');
        $this->patch(route('diagnosis-presets.rename', $preset->id), $this->payload(['name' => 'Stale', 'lock_version' => 1]))->assertSessionHasErrors('preset');
        $archive = $this->payload(['active' => false, 'lock_version' => 2]);
        $this->patch(route('diagnosis-presets.active', $preset->id), $archive)->assertRedirect();
        $this->patch(route('diagnosis-presets.active', $preset->id), $archive)->assertRedirect();
        $this->assertFalse($preset->refresh()->is_active);
        $this->assertSame(3, $preset->lock_version);
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => [' SYNTHETIC RENAMED ']]))->assertSessionHasErrors('names.0');
        $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => true, 'lock_version' => 2]))->assertSessionHasErrors('preset');
        $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => true, 'lock_version' => 3]))->assertRedirect();
        $this->assertTrue($preset->refresh()->is_active);
        $this->assertSame(4, $preset->lock_version);
        foreach (['renamed' => 2, 'archived' => 3, 'restored' => 4] as $event => $version) {
            $audit = DB::table('audit_logs')->where('event', 'diagnosis_preset.'.$event)->sole();
            $this->assertSame(['lock_version' => $version], json_decode($audit->metadata, true));
        }
        $this->delete(route('diagnosis-presets.active', $preset->id))->assertStatus(405);
        try {
            $preset->delete();
            $this->fail('A preset was deleted.');
        } catch (LogicException $e) {
            $this->assertSame('Diagnosis presets are retained; archive them instead.', $e->getMessage());
        }
        $this->assertDatabaseCount('diagnosis_presets', 1);
    }

    public function test_renaming_to_duplicate_is_refused_and_noop_status_is_retry_recorded_without_audit(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic one', 'Synthetic two']]))->assertRedirect();
        $one = DiagnosisPreset::query()->where('name', 'Synthetic one')->sole();
        $this->patch(route('diagnosis-presets.rename', $one->id), $this->payload(['name' => ' SYNTHETIC TWO ', 'lock_version' => 1]))->assertSessionHasErrors('name');
        $data = $this->payload(['active' => true, 'lock_version' => 1]);
        $this->patch(route('diagnosis-presets.active', $one->id), $data)->assertRedirect();
        $this->patch(route('diagnosis-presets.active', $one->id), $data)->assertRedirect();
        $this->assertSame(1, $one->refresh()->lock_version);
        $this->assertDatabaseCount('diagnosis_preset_commands', 2);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'diagnosis_preset.restored')->count());
    }

    public function test_existing_patient_diagnoses_are_never_imported_exposed_or_rewritten(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter));
        $before = DB::table('encounter_diagnoses')->get()->toJson();
        $this->selectBranch($this->actor('panel_officer'));
        $this->get(route('diagnosis-presets.index'))->assertInertia(fn (Assert $p) => $p->has('presets', 0)->missing('patients')->missing('diagnoses'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic reference diagnosis']]))->assertRedirect();
        $preset = DiagnosisPreset::query()->sole();
        $this->patch(route('diagnosis-presets.rename', $preset->id), $this->payload(['name' => 'Synthetic renamed reference', 'lock_version' => 1]))->assertRedirect();
        $this->patch(route('diagnosis-presets.active', $preset->id), $this->payload(['active' => false, 'lock_version' => 2]))->assertRedirect();
        $this->assertSame($before, DB::table('encounter_diagnoses')->get()->toJson());
        $this->get(route('diagnosis-presets.index'))->assertInertia(fn (Assert $p) => $p->has('presets', 1)->where('presets.0.name', 'Synthetic renamed reference'));
        $this->get(route('encounters.show', $visit))->assertForbidden();
    }

    public function test_search_status_and_pagination_preserve_filters_after_actions(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $service = app(DiagnosisPresetSettingsService::class);
        foreach (range(1, 26) as $n) {
            $service->create($actor, $this->payload(['names' => ['Synthetic '.$n]]));
        }
        $service->create($actor, $this->payload(['names' => ['Synthetic 10%']]));
        $special = DiagnosisPreset::query()->where('name', 'Synthetic 10%')->sole();
        $this->get(route('diagnosis-presets.index'))->assertInertia(fn (Assert $p) => $p->has('presets', 25)->where('pagination.total', 27)->where('pagination.lastPage', 2));
        $filters = ['status' => 'active', 'q' => '10%'];
        $this->patch(route('diagnosis-presets.active', ['preset' => $special->id, ...$filters]), $this->payload(['active' => false, 'lock_version' => 1]))->assertRedirect(route('diagnosis-presets.index', $filters));
        $this->get(route('diagnosis-presets.index', $filters))->assertInertia(fn (Assert $p) => $p->has('presets', 0));
        $this->get(route('diagnosis-presets.index', ['status' => 'archived', 'q' => '10%']))->assertInertia(fn (Assert $p) => $p->has('presets', 1)->where('presets.0.active', false));
        $this->get(route('diagnosis-presets.index', ['q' => '10%']))->assertInertia(fn (Assert $p) => $p->has('presets', 1));
    }

    public function test_other_organisations_and_expired_assignments_cannot_mutate_presets(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic reference']]))->assertRedirect();
        $preset = DiagnosisPreset::query()->sole();
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYNTH-OTHER', 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH-DEPT', 'name' => 'Synthetic Department', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH-OTHER', 'name' => 'Synthetic Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = $this->actor('administrator', $branch);
        $this->selectBranch($outsider, $branch);
        $this->get(route('diagnosis-presets.index'))->assertInertia(fn (Assert $p) => $p->has('presets', 0));
        $this->post(route('diagnosis-presets.store'), ['expected_branch_id' => $branch->id, 'idempotency_key' => DB::table('diagnosis_preset_commands')->where('organisation_id', $director->organisation_id)->value('idempotency_key'), 'names' => ['Synthetic reference']])->assertRedirect();
        $this->assertDatabaseCount('diagnosis_presets', 2);
        $this->assertDatabaseCount('diagnosis_preset_commands', 2);
        $this->patch(route('diagnosis-presets.active', $preset->id), ['expected_branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'active' => false])->assertNotFound();
        $this->selectBranch($director);
        StaffBranchAssignment::query()->where('staff_profile_id', $director->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->expectException(AuthorizationException::class);
        app(DiagnosisPresetSettingsService::class)->create($director, $this->payload(['names' => ['Synthetic denied']]));
    }

    public function test_presets_are_shared_across_assigned_branches_and_bad_filters_do_not_mutate(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic shared reference']]))->assertRedirect();
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->selectBranch($this->actor('panel_officer', $other), $other);
        $this->get(route('diagnosis-presets.index'))->assertInertia(fn (Assert $p) => $p->has('presets', 1)->where('presets.0.name', 'Synthetic shared reference'));
        $this->post(route('diagnosis-presets.store', ['status' => 'invalid']), ['expected_branch_id' => $other->id, 'idempotency_key' => (string) Str::uuid(), 'names' => ['Synthetic refused']])
            ->assertRedirect(route('diagnosis-presets.index'))->assertSessionHasErrors('status');
        $this->assertDatabaseCount('diagnosis_presets', 1);
    }

    public function test_retained_presets_or_commands_prevent_destructive_rollback(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic reference']]))->assertRedirect();
        $migration = require database_path('migrations/2026_10_14_000600_create_diagnosis_presets.php');
        try {
            $migration->down();
            $this->fail('Preset evidence was removed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Diagnosis presets and retry evidence must be retained.', $e->getMessage());
        }
        $this->assertDatabaseCount('diagnosis_presets', 1);
        $this->assertDatabaseCount('diagnosis_preset_commands', 1);
    }
}
