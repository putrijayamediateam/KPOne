<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Readiness\CallHoldPermissions as P;
use App\Domain\Queue\Readiness\CallHoldProjection;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CallReadinessTest extends QueueTestCase
{
    private function input(QueueEntry $queue, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $queue->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function set(User $ca, Visit $visit, QueueEntry $queue): array
    {
        $this->selectBranch($ca);

        return app(CallHoldService::class)->change($ca, $visit, $this->input($queue, ['reason_code' => 'awaiting_panel_confirmation']), false);
    }

    private function invalid(callable $action, string $text = ''): void
    {
        try {
            $action();
            $this->fail('Expected refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($text, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    public function test_exact_grants_match_every_catalogue_role_and_additive_migration_replays_without_revoking(): void
    {
        foreach (PermissionCatalogue::roles() as $name => $permissions) {
            $actor = $this->actor($name);
            foreach ([P::SET, P::CLEAR, P::OVERRIDE] as $permission) {
                $expected = $permission === P::OVERRIDE ? $name === 'resident_doctor' : in_array($name, ['ca', 'ca_supervisor'], true);
                $this->assertSame($expected, in_array($permission, $permissions, true), "$name catalogue $permission");
                $this->assertSame($expected, $actor->can($permission), "$name installed $permission");
            }
        }
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require base_path('database/migrations/2026_10_29_000100_create_queue_call_readiness.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_ca_blocks_call_and_another_ca_can_clear_without_changing_source_rows(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $other = $this->actor('ca_supervisor');
        $before = $this->sources();
        $flag = $flag = $this->set($ca, $visit, $queue);
        $this->selectBranch($doctor);
        $call = ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version];
        $this->invalid(fn () => app(QueueEntryService::class)->call($doctor, $visit, $call), 'Awaiting Panel confirmation');
        $this->selectBranch($other);
        $result = app(CallHoldService::class)->change($other, $visit, $this->input($queue, ['call_hold_id' => $flag['id'], 'lock_version' => $flag['lockVersion']]), true);
        $this->assertSame('cleared', $result['state']);
        $this->assertSame($before, $this->sources());
        $audit = DB::table('audit_logs')->where('event', 'queue.call_hold.cleared')->first();
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($ca->id, $metadata['set_by_user_id']);
        $this->assertSame($other->id, $metadata['action_by_user_id']);
        $this->assertStringNotContainsString($visit->patient->full_name, $audit->metadata);
        $this->selectBranch($doctor);
        $this->assertSame('serving', app(QueueEntryService::class)->call($doctor, $visit, $call)->status);
    }

    public function test_retries_conflicts_stale_flag_version_double_set_and_non_waiting_are_refused(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $input = $this->input($queue, ['reason_code' => 'awaiting_patient_details']);
        $this->selectBranch($ca);
        $service = app(CallHoldService::class);
        $flag = $service->change($ca, $visit, $input, false);
        $this->assertSame($flag, $service->change($ca, $visit, $input, false));
        $this->invalid(fn () => $service->change($ca, $visit, [...$input, 'reason_code' => 'other_administrative'], false), 'The retry key');
        $this->invalid(fn () => $service->change($ca, $visit, [...$input, 'idempotency_key' => (string) Str::uuid()], false), 'already marked');
        $this->invalid(fn () => $service->change($ca, $visit, $this->input($queue, ['call_hold_id' => $flag['id'], 'lock_version' => 99]), true), 'has changed');
        $this->assertSame(1, DB::table('queue_call_holds')->count());
        $clear = $this->input($queue, ['call_hold_id' => $flag['id'], 'lock_version' => 1]);
        $this->assertSame($service->change($ca, $visit, $clear, true), $service->change($ca, $visit, $clear, true));
    }

    public function test_attending_doctor_override_keeps_flag_and_has_an_immutable_serving_witness_and_retry(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $flag = $flag = $this->set($ca, $visit, $queue);
        $this->selectBranch($doctor);
        $input = [...$this->input($queue), 'visit_lock_version' => $visit->lock_version, 'call_hold_id' => $flag['id'], 'call_hold_lock_version' => $flag['lockVersion'], 'override_reason' => 'patient_at_door'];
        $called = app(QueueEntryService::class)->call($doctor, $visit, $input);
        $this->assertSame('serving', $called->status);
        $this->assertSame('overridden', DB::table('queue_call_holds')->where('id', $flag['id'])->value('state'));
        $witness = DB::table('queue_call_hold_overrides')->sole();
        $this->assertSame($doctor->id, $witness->actor_user_id);
        $this->assertSame($queue->id, DB::table('queue_calls')->where('id', $witness->queue_call_id)->value('queue_entry_id'));
        $this->assertSame($called->lock_version, app(QueueEntryService::class)->call($doctor, $visit, $input)->lock_version);
        $this->assertSame(1, DB::table('queue_call_hold_overrides')->count());
        $this->invalid(fn () => app(QueueEntryService::class)->call($doctor, $visit, [...$input, 'override_reason' => 'patient_is_urgent']), 'The retry key');
    }

    public function test_fresh_revocation_blocks_loaded_ca_and_doctor_authority(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $ca->load('roles.permissions');
        Role::findByName('ca')->revokePermissionTo(P::SET);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($ca);
        try {
            app(CallHoldService::class)->change($ca, $visit, $this->input($queue, ['reason_code' => 'other_administrative']), false);
            $this->fail('Revoked set must fail');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->status() ?? 403);
        }
        $this->assertSame(0, DB::table('queue_call_holds')->count());
    }

    public function test_audit_failure_rolls_back_flag_event_and_retry_and_leaves_all_sources_identical(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $before = $this->sources();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        try {
            $this->set($ca, $visit, $queue);
            $this->fail('Audit failure must refuse');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        foreach (['queue_call_holds', 'queue_call_hold_events', 'queue_call_hold_commands'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame($before, $this->sources());
    }

    public function test_waiting_departure_closes_flag_with_a_system_event_and_no_ready_notification(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $this->set($ca, $visit, $queue);
        app(VisitAdministrationService::class)->cancel($visit, ['expected_branch_id' => $this->branch->id, 'lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'cancellation_reason' => 'Synthetic cancellation'], $ca);
        $this->assertSame('system_closed', DB::table('queue_call_holds')->value('state'));
        $this->assertSame(1, DB::table('system_events')->where('name', 'queue.call_hold.system_closed')->count());
        $this->assertSame(0, DB::table('queue_call_hold_events')->where('event_type', 'cleared')->count());
    }

    public function test_postgresql_refuses_event_override_and_command_mutation_and_truncation(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $flag = $this->set($ca, $visit, $queue);
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $visit, [...$this->input($queue), 'visit_lock_version' => $visit->lock_version, 'call_hold_id' => $flag['id'], 'call_hold_lock_version' => $flag['lockVersion'], 'override_reason' => 'patient_is_urgent']);
        foreach (['queue_call_hold_events', 'queue_call_hold_overrides', 'queue_call_hold_commands'] as $table) {
            $before = DB::table($table)->orderBy('id')->get()->toJson();
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Evidence mutation must fail');
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0]);
                }
                $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
            }
        }
    }

    public function test_foreign_branch_and_unknown_visits_are_not_disclosed_and_all_ungranted_roles_are_denied(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            if (in_array($role, ['ca', 'ca_supervisor'], true)) {
                continue;
            }
            $this->selectBranch($this->actor($role));
            $this->post(route('queue.readiness.set', $visit->visit_number), $this->input($queue, ['reason_code' => 'other_administrative']))->assertForbidden();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->postJson('/visits/KPV-UNKNOWN/queue/readiness', $this->input($queue, ['reason_code' => 'other_administrative']))->assertForbidden();
            $this->patchJson('/visits/KPV-UNKNOWN/queue/readiness', $this->input($queue, ['call_hold_id' => 1, 'lock_version' => 1]))->assertForbidden();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('"visits"', $query['query']);
                $this->assertStringNotContainsString('"queue_entries"', $query['query']);
            }

        }
        $otherBranch = $this->organisation->branches()->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $otherBranch);
        $otherDoctor = $this->doctor($otherBranch);
        $foreign = Visit::factory()->create(['organisation_id' => $this->organisation->id, 'branch_id' => $otherBranch->id, 'visit_type' => 'consultation', 'visit_reason' => 'Synthetic foreign queue reason', 'assigned_doctor_user_id' => $otherDoctor->id]);
        $foreignQueue = QueueEntry::factory()->create(['visit_id' => $foreign->id, 'organisation_id' => $this->organisation->id, 'branch_id' => $otherBranch->id]);
        $this->selectBranch($ca);
        $this->post(route('queue.readiness.set', $foreign->visit_number), $this->input($foreignQueue, ['reason_code' => 'other_administrative']))->assertNotFound();
        $this->post('/visits/KPV-UNKNOWN/queue/readiness', $this->input($queue, ['reason_code' => 'other_administrative']))->assertNotFound();
        $this->assertSame(0, DB::table('queue_call_holds')->count());
    }

    public function test_a_stale_clear_or_override_cannot_target_a_new_flag_with_the_same_version(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $old = $this->set($ca, $visit, $queue);
        $clear = $this->input($queue, ['call_hold_id' => $old['id'], 'lock_version' => $old['lockVersion']]);
        app(CallHoldService::class)->change($ca, $visit, $clear, true);
        $new = $this->set($ca, $visit, $queue);
        $this->invalid(fn () => app(CallHoldService::class)->change($ca, $visit, [...$clear, 'idempotency_key' => (string) Str::uuid()], true), 'has changed');
        $this->selectBranch($doctor);
        $this->invalid(fn () => app(QueueEntryService::class)->call($doctor, $visit, [...$this->input($queue), 'visit_lock_version' => $visit->lock_version, 'override_reason' => 'patient_at_door', 'call_hold_id' => $old['id'], 'call_hold_lock_version' => $old['lockVersion']]), 'has changed');
        $this->assertSame('active', DB::table('queue_call_holds')->where('id', $new['id'])->value('state'));
        $this->assertSame('waiting', $queue->refresh()->status);
    }

    public function test_no_flag_call_retains_its_original_transition_audit_and_all_other_source_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 03:00:00', 'UTC'));
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $before = $this->sources();
        $original = DB::table('queue_entries')->where('id', $queue->id)->first();
        $auditCount = DB::table('audit_logs')->count();
        $systemCount = DB::table('system_events')->count();
        $this->selectBranch($doctor);
        $called = app(QueueEntryService::class)->call($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $after = $this->sources();
        foreach (['queue_entries', 'queue_calls'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after);
        $this->assertSame('serving', $called->status);
        $this->assertSame($original->lock_version + 1, $called->lock_version);
        $this->assertSame($original->queue_number, $called->queue_number);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->count());
        $this->assertSame($systemCount, DB::table('system_events')->count());
        $this->assertSame(0, DB::table('queue_call_holds')->count());
        $audit = DB::table('audit_logs')->where('event', 'queue.called')->sole();
        $this->assertSame(['from_state' => 'waiting', 'to_state' => 'serving', 'record_version' => $called->lock_version, 'branch_room_id' => null], json_decode($audit->metadata, true));
    }

    public function test_flags_do_not_change_the_exact_tv_feed_or_public_qr_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 03:00:00', 'UTC'));
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $feed = app(QueueDisplayFeedService::class);
        $before = $feed->feed($this->branch);
        config(['public-intake.enabled' => true]);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $link = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Synthetic readiness privacy test')['link'];
        $session = PublicIntakeSession::query()->create([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'public_checkin_link_id' => $link->id,
            'nonce_digest' => hash('sha256', (string) Str::uuid()), 'status_receipt_digest' => hash('sha256', (string) Str::uuid()), 'submission_idempotency_key' => (string) Str::uuid(), 'expires_at' => now()->addDay(), 'consumed_at' => now(),
        ]);
        PublicPatientIntake::query()->create([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'public_checkin_link_id' => $link->id, 'public_intake_session_id' => $session->id,
            'status' => 'accepted', 'submission_type' => 'patient', 'encrypted_payload' => ['patient' => ['full_name' => 'Synthetic Readiness Person']], 'payload_fingerprint' => hash('sha256', 'synthetic'), 'privacy_notice_version' => 'synthetic-test',
            'consented_at' => now(), 'submitted_at' => now(), 'expires_at' => now()->addDay(), 'payload_purge_at' => now()->addDays(30), 'accepted_at' => now(), 'accepted_by_user_id' => $ca->id, 'patient_id' => $visit->patient_id, 'visit_id' => $visit->id, 'queue_entry_id' => $queue->id,
        ]);
        $public = app(PublicPatientIntakeService::class);
        $qrBefore = $public->status($session);
        $flag = $this->set($ca, $visit, $queue);
        $this->assertSame($before, $feed->feed($this->branch));
        $this->assertSame($qrBefore, $public->status($session));
        app(CallHoldService::class)->change($ca, $visit, $this->input($queue, ['call_hold_id' => $flag['id'], 'lock_version' => $flag['lockVersion']]), true);
        $this->assertSame($before, $feed->feed($this->branch));
        $this->assertSame($qrBefore, $public->status($session));
        $this->assertStringNotContainsString('call_hold', json_encode($before));
        $this->assertStringNotContainsString('readiness', json_encode($before));
    }

    public function test_ca_and_non_attending_doctor_cannot_override_and_fresh_override_revocation_is_effective(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $flag = $this->set($ca, $visit, $queue);
        $input = [...$this->input($queue), 'visit_lock_version' => $visit->lock_version, 'call_hold_id' => $flag['id'], 'call_hold_lock_version' => $flag['lockVersion'], 'override_reason' => 'patient_at_door'];
        foreach ([$ca, $other] as $actor) {
            $this->selectBranch($actor);
            $this->patch(route('queue.call', $visit->visit_number), $input)->assertForbidden();
        }
        $this->selectBranch($doctor);
        $withoutReason = $input;
        unset($withoutReason['override_reason']);
        $this->patch(route('queue.call', $visit->visit_number), $withoutReason)->assertSessionHasErrors('queue');
        $doctor->load('roles.permissions');
        Role::findByName('resident_doctor')->revokePermissionTo(P::OVERRIDE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->patch(route('queue.call', $visit->visit_number), $input)->assertForbidden();
        $this->assertSame('waiting', $queue->refresh()->status);
        $this->assertSame('active', DB::table('queue_call_holds')->where('id', $flag['id'])->value('state'));
        $this->assertSame(0, DB::table('queue_call_hold_overrides')->count());
    }

    public function test_audit_failure_blocks_clear_and_override_and_rolls_back_the_serving_witness(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $flag = $this->set($ca, $visit, $queue);
        $auditCount = DB::table('audit_logs')->count();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturnNull();
        try {
            app(CallHoldService::class)->change($ca, $visit, $this->input($queue, ['call_hold_id' => $flag['id'], 'lock_version' => $flag['lockVersion']]), true);
            $this->fail('Clear must refuse without audit');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->selectBranch($doctor);
        try {
            app(QueueEntryService::class)->call($doctor, $visit, [...$this->input($queue), 'visit_lock_version' => $visit->lock_version, 'call_hold_id' => $flag['id'], 'call_hold_lock_version' => $flag['lockVersion'], 'override_reason' => 'patient_is_urgent']);
            $this->fail('Override must refuse without audit');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame('active', DB::table('queue_call_holds')->where('id', $flag['id'])->value('state'));
        $this->assertSame('waiting', $queue->refresh()->status);
        $this->assertSame(0, DB::table('queue_calls')->count());
        $this->assertSame(0, DB::table('queue_call_hold_overrides')->count());
        $this->assertSame(1, DB::table('queue_call_hold_events')->count());
    }

    public function test_board_projection_uses_two_bounded_queries_for_any_number_of_visible_rows(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $visit = $this->consultationVisit($ca, $doctor);
            $ids[] = $this->send($ca, $visit)->id;
        }
        foreach ([P::SET, P::CLEAR, P::OVERRIDE] as $permission) {
            $ca->can($permission);
        }
        foreach ([array_slice($ids, 0, 1), $ids] as $visible) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = app(CallHoldProjection::class)->rows($ca, $visible);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount(count($visible), $result);
            $this->assertCount(2, $queries);
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('"patients"', $query['query']);
            }
        }
    }

    public function test_otc_has_no_readiness_entry_and_database_uniqueness_cannot_be_bypassed(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otc = $this->register($ca, $this->patient($ca));
        app(OtcQueueService::class)->enter($ca, $otc, ['expected_branch_id' => $this->branch->id]);
        $this->post(route('queue.readiness.set', $otc->visit_number), ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => 1, 'reason_code' => 'other_administrative', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $flag = $this->set($ca, $visit, $queue);
        $row = (array) DB::table('queue_call_holds')->where('id', $flag['id'])->first();
        unset($row['id']);
        try {
            DB::transaction(fn () => DB::table('queue_call_holds')->insert($row));
            $this->fail('Only one active flag may exist');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
        }
        // RefreshDatabase retains the service savepoint inside its outer transaction.
        // Flush deferred consistency events so PostgreSQL reaches the retained-record trigger.
        DB::statement('SET CONSTRAINTS call_hold_consistency IMMEDIATE');
        foreach (['TRUNCATE queue_call_holds CASCADE' => 'P0001', 'TRUNCATE queue_call_holds' => '0A000'] as $sql => $code) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Retained flags must survive truncate');
            } catch (QueryException $e) {
                $this->assertSame($code, $e->errorInfo[0]);
            }
            $this->assertSame(1, DB::table('queue_call_holds')->count());
            $this->assertSame(1, DB::table('queue_call_hold_events')->count());
        }
    }

    /** Full ID-ordered row comparison, including clinical, billing, stock and existing document evidence. */
    private function sources(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            $name = $table->tablename;
            if (str_starts_with($name, 'queue_call_hold') || in_array($name, ['audit_logs', 'system_events'], true)) {
                continue;
            }
            $columns = array_column(DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', ['public', $name]), 'column_name');
            $query = DB::table($name);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $snapshot[$name] = $query->get()->toJson();
        }

        return $snapshot;
    }
}
