<?php

namespace Tests\Feature\Visit;

use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

class VisitPanelNoteTest extends ClinicalTestCase
{
    public function test_registration_stores_trimmed_note_and_retries_without_duplicate_visit_or_audit(): void
    {
        $ca = $this->actor('ca');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic note Panel']);
        $payload = $this->visitAttributes($this->patient($ca), [
            'coverage_type' => 'panel', 'panel_id' => $panel->id,
            'coverage_panel_note' => '  Synthetic employer approval note.  ', 'panel_note_panel_id' => $panel->id,
        ]);
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $payload)->assertRedirect();
        $visit = Visit::query()->sole();
        $this->assertSame('Synthetic employer approval note.', $visit->coverage_panel_note);
        $this->assertArrayNotHasKey('coverage_panel_note', $visit->toArray());
        $auditCount = DB::table('audit_logs')->count();
        $this->post(route('registration.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseCount('visit_panel_note_commands', 1);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'visit.panel_note.updated')->count());
        $this->post(route('registration.store'), [...$payload, 'coverage_panel_note' => 'Different synthetic note'])->assertSessionHasErrors('panel_note_idempotency_key');
        $this->assertSame('Synthetic employer approval note.', $visit->refresh()->coverage_panel_note);
    }

    public function test_note_is_available_in_registration_edit_and_read_only_own_encounter_projection(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $visit = $this->consultationVisit($ca, $doctor, [
            'coverage_type' => 'panel', 'panel_id' => $panel->id,
            'coverage_panel_note' => 'Synthetic visit-specific note', 'panel_note_panel_id' => $panel->id,
        ]);
        $this->get(route('visits.edit', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('visit.coverage.panelNote', 'Synthetic visit-specific note'));
        $queue = $this->send($ca, $visit);
        $this->selectBranch($doctor);
        $queue = app(QueueEntryService::class)->call($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $this->startEncounter($doctor, $visit, $queue);
        $this->assertSame('Synthetic visit-specific note', app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['visit']['panelNote']);
        $this->selectBranch($ca);
        $before = DB::table('visit_panel_note_commands')->count();
        $this->patch(route('visits.update', $visit), $this->editPayload($visit, ['coverage_panel_note' => 'Refused note']))->assertForbidden();
        $this->assertSame($before, DB::table('visit_panel_note_commands')->count());
        $this->assertSame('Synthetic visit-specific note', $visit->refresh()->coverage_panel_note);
        $this->selectBranch($this->doctor());
        $this->get(route('encounters.show', $visit))->assertNotFound();
    }

    public function test_note_edits_have_versions_retry_conflicts_and_content_free_audit(): void
    {
        $ca = $this->actor('ca');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $visit = $this->register($ca, $this->patient($ca), ['coverage_type' => 'panel', 'panel_id' => $panel->id]);
        $payload = $this->editPayload($visit, ['coverage_panel_note' => 'Synthetic updated note']);
        $this->patch(route('visits.update', $visit), $payload)->assertRedirect();
        $this->assertSame(2, $visit->refresh()->lock_version);
        $this->assertSame('Synthetic updated note', $visit->coverage_panel_note);
        $this->patch(route('visits.update', $visit), $payload)->assertRedirect();
        $this->assertSame(2, $visit->refresh()->lock_version);
        $this->assertDatabaseCount('visit_panel_note_commands', 1);
        $this->patch(route('visits.update', $visit), [...$payload, 'coverage_panel_note' => 'Different text'])->assertSessionHasErrors('panel_note_idempotency_key');
        $this->patch(route('visits.update', $visit), [...$payload, 'panel_note_idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('lock_version');
        $audit = DB::table('audit_logs')->where('event', 'visit.details.updated')->sole();
        $this->assertStringContainsString('coverage_panel_note', $audit->metadata);
        $this->assertStringNotContainsString('Synthetic updated note', $audit->metadata);
    }

    public function test_panel_change_clears_old_note_and_accepts_only_a_note_bound_to_the_new_panel(): void
    {
        $ca = $this->actor('ca');
        $first = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $second = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $visit = $this->register($ca, $this->patient($ca), ['coverage_type' => 'panel', 'panel_id' => $first->id, 'coverage_panel_note' => 'Old synthetic note', 'panel_note_panel_id' => $first->id]);
        $payload = $this->editPayload($visit, ['panel_id' => $second->id, 'panel_note_panel_id' => $first->id, 'coverage_panel_note' => 'Old synthetic note']);
        $this->patch(route('visits.update', $visit), $payload)->assertSessionHasErrors('coverage_panel_note');
        $this->assertSame($first->id, $visit->refresh()->panel_id);
        $this->patch(route('visits.update', $visit), [...$payload, 'coverage_panel_note' => null])->assertRedirect();
        $this->assertNull($visit->refresh()->coverage_panel_note);
        $this->assertSame($second->id, $visit->panel_id);
        $this->patch(route('visits.update', $visit), $this->editPayload($visit, ['coverage_panel_note' => 'New Panel note']))->assertRedirect();
        $this->assertSame('New Panel note', $visit->refresh()->coverage_panel_note);
        $this->patch(route('visits.update', $visit), $this->editPayload($visit, ['coverage_type' => 'self_pay']))->assertRedirect();
        $this->assertNull($visit->refresh()->coverage_panel_note);
        $this->assertNull($visit->panel_id);
    }

    public function test_limits_blank_notes_self_pay_and_required_edit_retry_key(): void
    {
        $ca = $this->actor('ca');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $payload = $this->visitAttributes($this->patient($ca), ['coverage_type' => 'panel', 'panel_id' => $panel->id, 'panel_note_panel_id' => $panel->id, 'coverage_panel_note' => str_repeat('x', 501)]);
        $this->selectBranch($ca);
        $this->post(route('registration.store'), $payload)->assertSessionHasErrors('coverage_panel_note');
        $this->assertDatabaseCount('visits', 0);
        $this->post(route('registration.store'), [...$payload, 'coverage_panel_note' => str_repeat('x', 500)])->assertRedirect();
        $visit = Visit::query()->sole();
        $this->assertSame(500, strlen($visit->coverage_panel_note));
        $this->patch(route('visits.update', $visit), [...$this->editPayload($visit), 'panel_note_idempotency_key' => null])->assertSessionHasErrors('panel_note_idempotency_key');
        $this->patch(route('visits.update', $visit), $this->editPayload($visit, ['coverage_panel_note' => '   ']))->assertRedirect();
        $this->assertNull($visit->refresh()->coverage_panel_note);
    }

    public function test_registration_permissions_and_branch_scope_cannot_be_bypassed_with_note_fields(): void
    {
        $ca = $this->actor('ca');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $payload = $this->visitAttributes($this->patient($ca), ['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_note' => 'Synthetic scoped note', 'panel_note_panel_id' => $panel->id]);
        foreach (['resident_doctor', 'technical_admin', 'administrator', 'finance_officer', 'panel_officer'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->post(route('registration.store'), $payload)->assertForbidden();
        }
        $this->selectBranch($ca);
        $otherBranch = $this->organisation->branches()->whereKeyNot($this->branch->id)->firstOrFail();
        $this->post(route('registration.store'), [...$payload, 'expected_branch_id' => $otherBranch->id])->assertSessionHasErrors('expected_branch_id');
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('visit_panel_note_commands', 0);
        foreach (['director', 'ca_supervisor'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->post(route('registration.store'), [...$payload, 'idempotency_key' => (string) Str::uuid(), 'confirm_repeat' => true])->assertRedirect();
        }
        $this->assertDatabaseCount('visits', 2);
        $this->assertDatabaseCount('visit_panel_note_commands', 2);
    }

    public function test_migration_refuses_to_drop_saved_notes_and_retry_evidence(): void
    {
        $ca = $this->actor('ca');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $visit = $this->register($ca, $this->patient($ca), ['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_note' => 'Synthetic retained note', 'panel_note_panel_id' => $panel->id]);
        $migration = require database_path('migrations/2026_10_15_000100_add_visit_panel_notes.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain saved notes and retry evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Panel notes and retry evidence must be retained.', $exception->getMessage());
        }
        $this->assertSame('Synthetic retained note', $visit->refresh()->coverage_panel_note);
        $this->assertDatabaseCount('visit_panel_note_commands', 1);
    }

    public function test_registration_and_own_queue_show_the_same_full_note_and_blank_self_pay(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $note = 'Synthetic employer approval note with enough text to truncate visually.';
        $visit = $this->consultationVisit($ca, $doctor, ['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_note' => $note, 'panel_note_panel_id' => $panel->id]);
        $selfPay = $this->consultationVisit($ca, $doctor);
        $otherDoctor = $this->doctor();
        $other = $this->consultationVisit($ca, $otherDoctor, ['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_note' => 'Other doctor private note', 'panel_note_panel_id' => $panel->id]);
        foreach ([$visit, $selfPay, $other] as $queued) {
            $this->send($ca, $queued);
        }
        $this->get(route('registration.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('visits.data', fn ($rows) => collect($rows)->firstWhere('visitNumber', $visit->visit_number)['panelNote'] === $note
                && collect($rows)->firstWhere('visitNumber', $selfPay->visit_number)['panelNote'] === null));
        $this->selectBranch($doctor);
        $this->get(route('queue.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('snapshot.waiting.data', 2)
            ->where('snapshot.waiting.data', fn ($rows) => collect($rows)->firstWhere('visitNumber', $visit->visit_number)['panelNote'] === $note
                && collect($rows)->firstWhere('visitNumber', $selfPay->visit_number)['panelNote'] === null));
        $this->postJson(route('queue.search'), ['expected_branch_id' => $this->branch->id])->assertOk()
            ->assertJsonCount(2, 'waiting.data')->assertDontSee('Other doctor private note');
        $queue = $visit->queueEntry()->firstOrFail();
        $queue = app(QueueEntryService::class)->call($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $this->startEncounter($doctor, $visit, $queue);
        $this->assertSame($note, app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['visit']['panelNote']);
        $this->selectBranch($this->actor('queue_display'));
        $feed = $this->getJson(route('queue-display.feed'))->assertOk()->assertJsonCount(1, 'calls');
        foreach ([$note, 'panelNote', 'coverage_panel_note'] as $excluded) {
            $feed->assertDontSee($excluded);
        }
    }

    public function test_administrator_and_technical_admin_cannot_read_notes_through_pages_json_or_domain_services(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        // Controlled fixture: the visit is already being served, so no runtime edit is permitted.
        $visit->forceFill(['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_name_snapshot' => $panel->name, 'coverage_panel_note' => 'Synthetic confidential Panel note'])->save();
        $this->startEncounter($doctor, $visit, $queue);
        foreach (['administrator', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            foreach (['registration.index', 'queue.index', 'registration.create'] as $route) {
                $this->get(route($route))->assertForbidden()->assertDontSee('Synthetic confidential Panel note');
            }
            foreach (['visits.show', 'visits.edit', 'encounters.show'] as $route) {
                $this->get(route($route, $visit))->assertForbidden()->assertDontSee('Synthetic confidential Panel note');
            }
            foreach (['queue.search', 'registration.search'] as $route) {
                $this->postJson(route($route), [])->assertForbidden()->assertDontSee('Synthetic confidential Panel note');
            }
            foreach ([
                fn () => app(VisitDirectoryService::class)->search($actor, []),
                fn () => app(VisitDirectoryService::class)->detail($actor, $visit),
                fn () => app(QueueDirectoryService::class)->snapshot($actor),
                fn () => app(ClinicalEncounterDirectoryService::class)->detail($actor, $visit),
            ] as $read) {
                try {
                    $read();
                    $this->fail($role.' must not receive a note projection.');
                } catch (AuthorizationException|ModelNotFoundException $exception) {
                    $this->assertContains($exception::class, [AuthorizationException::class, ModelNotFoundException::class]);
                }
            }
        }
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function editPayload(Visit $visit, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => $visit->lock_version,
            'queue_lock_version' => $visit->queueEntry?->lock_version,
            'visit_type' => $visit->visit_type, 'assigned_doctor_user_id' => $visit->assigned_doctor_user_id,
            'visit_reason_public_ids' => $this->visitReasonIds($visit), 'priority' => $visit->priority,
            'coverage_type' => $visit->coverage_type, 'panel_id' => $visit->panel_id,
            'coverage_member_reference' => $visit->coverage_member_reference,
            'coverage_panel_note' => $visit->coverage_panel_note, 'panel_note_panel_id' => $visit->panel_id,
            'panel_note_idempotency_key' => (string) Str::uuid(), ...$overrides,
        ];
    }
}
