<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepPermissions as P;
use App\Domain\Queue\Treatment\TreatmentStepProjection;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Clinical\ClinicalTestCase;

/** Q-2 treatment progress (owner decisions 2026-11-05). */
class TreatmentProgressTest extends ClinicalTestCase
{
    private function service(): TreatmentStepService
    {
        return app(TreatmentStepService::class);
    }

    /** @return array<string,mixed> */
    private function startInput(string $code = 'nebuliser'): array
    {
        return ['expected_branch_id' => $this->branch->id, 'step_code' => $code, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string,mixed> */
    private function stepInput(TreatmentStep $step, array $extra = []): array
    {
        $step->refresh();

        return ['expected_branch_id' => $this->branch->id, 'treatment_step_id' => $step->id, 'lock_version' => $step->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function start(User $actor, Visit $visit, string $code = 'nebuliser'): TreatmentStep
    {
        $this->selectBranch($actor);
        $result = $this->service()->start($actor, $visit, $this->startInput($code));

        return TreatmentStep::query()->findOrFail($result['id']);
    }

    private function refused(callable $action, string $text): void
    {
        try {
            $action();
            $this->fail("Expected refusal: $text");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($text, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    public function test_exact_grants_match_every_catalogue_role_and_the_migration_replays_without_revoking(): void
    {
        foreach (PermissionCatalogue::roles() as $name => $permissions) {
            $actor = $this->actor($name);
            $this->assertSame(in_array($name, ['ca', 'ca_supervisor'], true), in_array(P::SET_BRANCH, $permissions, true), "$name catalogue branch");
            $this->assertSame($name === 'resident_doctor', in_array(P::SET_OWN, $permissions, true), "$name catalogue own");
            $this->assertSame(in_array($name, ['ca', 'ca_supervisor'], true), $actor->can(P::SET_BRANCH), "$name installed branch");
            $this->assertSame($name === 'resident_doctor', $actor->can(P::SET_OWN), "$name installed own");
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(P::SET_BRANCH));
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(P::SET_OWN));
        }
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require base_path('database/migrations/2026_11_08_000200_grant_queue_treatment_progress_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_ca_starts_and_marks_done_and_the_attending_doctor_is_alerted_once(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $projection = app(TreatmentStepProjection::class);
        $this->selectBranch($doctor);
        $seed = $projection->updates($doctor, $this->branch->id, null, true);
        $this->assertFalse($seed['ready']);
        $this->assertSame(0, $seed['doneCount']);

        $step = $this->start($ca, $visit);
        $this->assertSame('started', $step->state);
        $this->assertSame('nebuliser', $step->step_code);
        $this->selectBranch($doctor);
        $this->assertFalse($projection->updates($doctor, $this->branch->id, $seed['cursor'], true)['ready'], 'started is not an alert');

        $this->selectBranch($ca);
        $this->service()->complete($ca, $visit, $this->stepInput($step));
        $this->assertSame('done', $step->refresh()->state);
        $this->assertSame($ca->id, $step->done_by_user_id);

        $this->selectBranch($doctor);
        $update = $projection->updates($doctor, $this->branch->id, $seed['cursor'], true);
        $this->assertTrue($update['ready']);
        $this->assertSame(1, $update['doneCount']);
        $this->assertSame([QueueNumberFormat::format($queue->refresh()->queue_number).' Nebuliser'], $update['numbers']);
        $again = $projection->updates($doctor, $this->branch->id, $update['cursor'], true);
        $this->assertFalse($again['ready'], 'a refresh never repeats the alert');
        $this->assertSame(1, $again['doneCount']);

        $events = DB::table('queue_treatment_step_events')->where('treatment_step_id', $step->id)->orderBy('id')->get();
        $this->assertSame(['started', 'done'], $events->pluck('event_type')->all());
        $this->assertSame([null, $doctor->id], $events->pluck('doctor_user_id')->map(fn ($id) => $id === null ? null : (int) $id)->all());
        $audit = AuditLog::query()->where('event', 'queue.treatment_step.done')->sole();
        $this->assertSame('nebuliser', $audit->metadata['step_code']);
        $this->assertStringNotContainsString($visit->patient->full_name, json_encode($audit->metadata, JSON_THROW_ON_ERROR));

        $row = collect(app(QueueDirectoryService::class)->snapshot($ca)['serving'])->firstWhere('visitNumber', $visit->visit_number);
        $this->assertSame(['id' => $step->id, 'code' => 'nebuliser', 'label' => 'Nebuliser', 'state' => 'done', 'startedAt' => $step->started_at->toIso8601String(), 'doneAt' => $step->done_at->toIso8601String(), 'lockVersion' => 2, 'canManage' => true], $row['treatmentProgress']);
    }

    public function test_the_doctor_marking_their_own_step_done_raises_no_alert_for_themselves(): void
    {
        [$doctor, , $visit] = $this->servingFixture();
        $step = $this->start($doctor, $visit, 'ecg');
        $this->selectBranch($doctor);
        $seed = app(TreatmentStepProjection::class)->updates($doctor, $this->branch->id, null, false);
        $this->service()->complete($doctor, $visit, $this->stepInput($step));

        $update = app(TreatmentStepProjection::class)->updates($doctor, $this->branch->id, $seed['cursor'], false);
        $this->assertFalse($update['ready']);
        $this->assertSame(1, $update['doneCount']);
        $this->assertArrayNotHasKey('numbers', $update);
    }

    public function test_only_branch_staff_and_the_attending_doctor_may_mark_steps(): void
    {
        [$doctor, $ca, $visit] = $this->servingFixture();
        $otherDoctor = $this->doctor();
        foreach ([$otherDoctor, $this->actor('director'), $this->actor('technical_admin'), $this->actor('administrator'), $this->actor('finance_officer')] as $actor) {
            $this->selectBranch($actor);
            try {
                $this->service()->start($actor, $visit, $this->startInput());
                $this->fail($actor->roles->pluck('name')->implode(',').' must be refused');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('queue_treatment_steps', 0);

        $this->actingAs($otherDoctor)->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('queue.treatment.start', $visit), $this->startInput())->assertForbidden();
        $this->actingAs($this->actor('director'))->post(route('queue.treatment.start', $visit), $this->startInput())->assertForbidden();
        $this->actingAs($ca)->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('queue.treatment.start', $visit), [...$this->startInput(), 'from' => 'queue'])->assertRedirect(route('queue.index'));
        $this->assertSame(1, TreatmentStep::query()->count());
    }

    public function test_rules_waiting_entry_second_open_step_stale_version_retry_and_cancel_reason(): void
    {
        $ca = $this->actor('ca');
        $waitingVisit = $this->consultationVisit($ca, $this->doctor());
        $this->send($ca, $waitingVisit);
        $this->selectBranch($ca);
        $this->refused(fn () => $this->service()->start($ca, $waitingVisit, $this->startInput()), 'only be updated while the consultation is in progress or on hold');

        [, $ca, $visit] = $this->servingFixture(null, $ca);
        $input = $this->startInput();
        $this->selectBranch($ca);
        $first = $this->service()->start($ca, $visit, $input);
        $this->assertSame($first, $this->service()->start($ca, $visit, $input), 'identical retry returns the same step');
        $this->refused(fn () => $this->service()->start($ca, $visit, [...$input, 'step_code' => 'ecg']), 'retry key has already been used');
        $this->refused(fn () => $this->service()->start($ca, $visit, $this->startInput('ecg')), 'already has an open treatment step');

        $step = TreatmentStep::query()->findOrFail($first['id']);
        $this->refused(fn () => $this->service()->complete($ca, $visit, [...$this->stepInput($step), 'lock_version' => 9]), 'changed by someone else');
        $this->expectException(ValidationException::class);
        $this->service()->cancel($ca, $visit, $this->stepInput($step));
    }

    public function test_cancel_keeps_history_and_allows_a_new_step(): void
    {
        [, $ca, $visit] = $this->servingFixture();
        $step = $this->start($ca, $visit);
        $this->service()->cancel($ca, $visit, $this->stepInput($step, ['reason_code' => 'wrong_step']));
        $this->assertSame('cancelled', $step->refresh()->state);
        $this->assertSame('wrong_step', $step->cancel_reason_code);
        $this->assertNotNull($step->closed_at);
        $next = $this->start($ca, $visit, 'wound_dressing');
        $this->assertSame('started', $next->state);
        $this->assertSame(['started', 'cancelled'], DB::table('queue_treatment_step_events')->where('treatment_step_id', $step->id)->orderBy('id')->pluck('event_type')->all());
    }

    public function test_resume_and_opening_the_consultation_clear_ready_to_resume(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $step = $this->start($ca, $visit);
        $this->service()->complete($ca, $visit, $this->stepInput($step));

        $this->selectBranch($doctor);
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertSame('acknowledged', $step->refresh()->state);
        $this->assertSame(0, app(TreatmentStepProjection::class)->updates($doctor, $this->branch->id, null, false)['doneCount']);

        $second = $this->start($ca, $visit, 'iv_drip');
        $this->service()->complete($ca, $visit, $this->stepInput($second));
        $this->actingAs($ca)->withSession(['active_branch_id' => $this->branch->id])->get(route('encounters.show', $visit));
        $this->assertSame('done', $second->refresh()->state, 'only the attending doctor acknowledges');
        $this->actingAs($doctor)->withSession(['active_branch_id' => $this->branch->id])->get(route('encounters.show', $visit))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Clinical/Show')->where('treatmentProgress.id', null)->where('treatmentProgress.canManage', true));
        $this->assertSame('acknowledged', $second->refresh()->state);
    }

    public function test_leaving_serving_closes_the_open_step_through_the_model_and_the_database(): void
    {
        [, $ca, $visit, $queue] = $this->servingFixture();
        $step = $this->start($ca, $visit);
        DB::transaction(fn () => $queue->refresh()->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now(), 'updated_by_user_id' => $ca->id])->save());
        $this->assertSame('system_closed', $step->refresh()->state);
        $this->assertSame(1, DB::table('queue_treatment_step_events')->where('treatment_step_id', $step->id)->where('event_type', 'system_closed')->count());

        [, $ca2, $visit2, $queue2] = $this->servingFixture();
        $other = $this->start($ca2, $visit2, 'ecg');
        DB::table('queue_entries')->where('id', $queue2->id)->update(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()]);
        $this->assertSame('system_closed', $other->refresh()->state);
    }

    public function test_postgres_refuses_out_of_scope_inserts_edits_and_deletes(): void
    {
        [, $ca, $visit] = $this->servingFixture();
        $step = $this->start($ca, $visit);
        $event = DB::table('queue_treatment_step_events')->where('treatment_step_id', $step->id)->value('id');
        foreach ([
            fn () => DB::table('queue_treatment_step_events')->where('id', $event)->update(['event_type' => 'done']),
            fn () => DB::table('queue_treatment_step_events')->where('id', $event)->delete(),
            fn () => DB::table('queue_treatment_steps')->where('id', $step->id)->delete(),
            fn () => DB::table('queue_treatment_steps')->where('id', $step->id)->update(['step_code' => 'ecg', 'lock_version' => 2]),
            fn () => DB::table('queue_treatment_steps')->where('id', $step->id)->update(['state' => 'acknowledged', 'lock_version' => 2, 'closed_at' => now()]),
        ] as $index => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("attempt $index must be refused");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $waiting = $this->consultationVisit($ca, $this->doctor());
        $entry = $this->send($ca, $waiting);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('queue_treatment_steps')->insert(['organisation_id' => $ca->organisation_id, 'branch_id' => $this->branch->id, 'queue_entry_id' => $entry->id, 'step_code' => 'ecg', 'state' => 'started', 'started_by_user_id' => $ca->id, 'started_at' => now(), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_tv_feed_and_waiting_rows_carry_no_treatment_progress(): void
    {
        [, $ca, $visit] = $this->servingFixture();
        $this->start($ca, $visit);
        $waitingVisit = $this->consultationVisit($ca, $this->doctor());
        $this->send($ca, $waitingVisit);
        $snapshot = app(QueueDirectoryService::class)->snapshot($ca);
        $waitingRow = collect($snapshot['waiting']['data'])->firstWhere('visitNumber', $waitingVisit->visit_number);
        $this->assertNull($waitingRow['treatmentProgress']);
        $feed = json_encode(app(QueueDisplayFeedService::class)->feed($this->branch), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('nebuliser', strtolower($feed));
    }

    public function test_supervisor_may_act_while_other_branch_inactive_and_non_attending_staff_may_not(): void
    {
        [$doctor, , $visit] = $this->servingFixture();
        $supervisor = $this->actor('ca_supervisor');
        $step = $this->start($supervisor, $visit, 'other_procedure');
        $this->assertSame($supervisor->id, $step->started_by_user_id);

        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $foreignCa = $this->actor('ca', $other);
        $this->selectBranch($foreignCa, $other);
        $inactive = $this->actor('ca');
        $inactive->forceFill(['is_active' => false])->save();
        $otherDoctor = $this->doctor();
        foreach ([$foreignCa, $inactive, $otherDoctor] as $actor) {
            if ($actor !== $foreignCa) {
                $this->selectBranch($actor);
            }
            foreach (['complete' => [], 'cancel' => ['reason_code' => 'wrong_step']] as $method => $extra) {
                try {
                    $this->service()->{$method}($actor, $visit, $this->stepInput($step, $extra));
                    $this->fail("$method must be refused");
                } catch (AuthorizationException|ValidationException|HttpException|ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame('started', $step->refresh()->state);
        $this->assertSame(1, $step->lock_version);
        $this->assertNotSame($doctor->id, $otherDoctor->id);
    }

    public function test_http_done_and_cancel_redirect_to_their_page_and_refusals_keep_the_error(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $step = $this->start($ca, $visit);
        $session = ['active_branch_id' => $this->branch->id];
        $this->actingAs($ca)->withSession($session)
            ->patch(route('queue.treatment.done', $visit), [...$this->stepInput($step), 'from' => 'queue'])
            ->assertRedirect(route('queue.index'));
        $this->assertSame('done', $step->refresh()->state);
        $this->actingAs($ca)->withSession($session)
            ->patch(route('queue.treatment.done', $visit), [...$this->stepInput($step), 'from' => 'consultation'])
            ->assertRedirect(route('encounters.show', $visit))->assertSessionHasErrors('treatment');
        $this->actingAs($doctor)->withSession($session)
            ->patch(route('queue.treatment.cancel', $visit), [...$this->stepInput($step, ['reason_code' => 'marked_too_early']), 'from' => 'consultation'])
            ->assertRedirect(route('encounters.show', $visit));
        $this->assertSame('cancelled', $step->refresh()->state);
    }

    public function test_done_alerts_and_counts_never_reach_another_doctor(): void
    {
        [$doctor, $ca, $visit] = $this->servingFixture();
        $otherDoctor = $this->doctor();
        $this->selectBranch($otherDoctor);
        $seed = app(TreatmentStepProjection::class)->updates($otherDoctor, $this->branch->id, null, true);
        $step = $this->start($ca, $visit);
        $this->service()->complete($ca, $visit, $this->stepInput($step));
        $this->selectBranch($otherDoctor);
        $this->assertSame(['cursor' => $seed['cursor'], 'ready' => false, 'doneCount' => 0, 'numbers' => []], app(TreatmentStepProjection::class)->updates($otherDoctor, $this->branch->id, $seed['cursor'], true));
        $this->selectBranch($ca);
        $this->assertSame(['cursor' => 0, 'ready' => false, 'doneCount' => 0], app(TreatmentStepProjection::class)->updates($ca, $this->branch->id, null, false));
        $this->actingAs($ca)->withSession(['active_branch_id' => $this->branch->id])
            ->getJson(route('queue.treatment.updates', ['expected_branch_id' => $this->branch->id]))->assertForbidden();
        $this->assertNotNull($doctor->id);
    }
}
