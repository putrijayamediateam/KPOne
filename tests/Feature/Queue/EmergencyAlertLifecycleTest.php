<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Emergency\EmergencyAlert;
use App\Domain\Queue\Emergency\EmergencyAlertService;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Queue\Services\RedZoneAlertService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Clinical\Triage\TriageTestCase;

class EmergencyAlertLifecycleTest extends TriageTestCase
{
    private function red(): array
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);

        return [$ca, $doctor, $visit->refresh()];
    }

    private function command(Visit $visit): array
    {
        $alert = EmergencyAlert::query()->where('visit_id', $visit->id)->whereNull('closed_at')->firstOrFail();

        return ['expected_branch_id' => $this->branch->id, 'episode_id' => $alert->id, 'lock_version' => $alert->lock_version, 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_red_retake_seen_retry_take_and_reassignment_keep_one_episode_and_history(): void
    {
        [$ca,$assigned,$visit] = $this->red();
        $this->triage($ca, $visit, ['chosen_zone' => 'red']);
        $this->assertDatabaseCount('queue_emergency_alerts', 1);
        $this->assertDatabaseCount('queue_emergency_alert_events', 1);
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        $service = app(EmergencyAlertService::class);
        $seen = $this->command($visit);
        $service->seen($doctor, $visit, $seen);
        $service->seen($doctor, $visit, $seen);
        $this->assertDatabaseCount('queue_emergency_alert_events', 2);
        $this->assertNull(app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id)[0]['takenBy']);
        $take = $this->command($visit);
        $claim = $service->take($doctor, $visit, $take);
        $service->take($doctor, $visit->refresh(), $take);
        $this->assertDatabaseCount('queue_red_zone_claims', 1);
        $this->assertDatabaseCount('queue_emergency_alert_events', 3);
        $this->assertSame('waiting', $visit->queueEntry->status);
        $visit->forceFill(['assigned_doctor_user_id' => $assigned->id])->save();
        $this->assertNull(app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id)[0]['takenBy']);
        $again = $service->take($doctor, $visit->refresh(), $this->command($visit));
        $this->assertGreaterThan($claim->id, $again->id);
        $this->assertDatabaseCount('queue_red_zone_claims', 2);
    }

    public function test_altered_retry_and_other_actor_or_branch_are_refused(): void
    {
        [,,$visit] = $this->red();
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        $input = $this->command($visit);
        $service = app(EmergencyAlertService::class);
        $service->seen($doctor, $visit, $input);
        try {
            $service->take($doctor, $visit, $input);
            $this->fail('Expected changed-action refusal');
        } catch (ValidationException) {
            $this->assertDatabaseCount('queue_red_zone_claims', 0);
        }
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->expectException(HttpException::class);
        $service->seen($ca, $visit, $this->command($visit));
    }

    public function test_activation_dry_run_then_same_second_seen_take_is_truthful_and_repeatable(): void
    {
        // Build a legacy row through the historical triage path with only the new lifecycle hook disabled.
        $legacy = \Mockery::mock(new EmergencyAlertService);
        $legacy->shouldReceive('triageLocked')->once();
        $this->instance(EmergencyAlertService::class, $legacy);
        [,,$visit] = $this->red();
        $this->forgetMock(EmergencyAlertService::class);
        $service = app(EmergencyAlertService::class);
        $dry = $service->activate($this->branch, false);
        $this->assertSame(1, $dry['created']);
        $this->assertDatabaseCount('queue_emergency_alerts', 0);
        $result = $service->activate($this->branch, true);
        $this->assertSame(0, $result['remaining']);
        $alert = EmergencyAlert::query()->sole();
        $this->assertSame('activation', $alert->raised_kind);
        $event = DB::table('queue_emergency_alert_events')->sole();
        $this->assertSame('activated', $event->event_code);
        $this->assertNull($event->actor_user_id);
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        $service->seen($doctor, $visit, $this->command($visit));
        $service->take($doctor, $visit, $this->command($visit));
        $this->assertSame(0, $service->activate($this->branch, true)['created']);
        $this->assertDatabaseCount('queue_emergency_alerts', 1);
    }

    public function test_activation_floor_requires_new_claim_even_for_same_assigned_doctor_and_same_second_close(): void
    {
        $legacy = \Mockery::mock(new EmergencyAlertService);
        $legacy->shouldReceive('triageLocked')->once();
        $this->instance(EmergencyAlertService::class, $legacy);
        [$ca,$doctor,$visit] = $this->red();
        $this->forgetMock(EmergencyAlertService::class);
        $this->selectBranch($doctor);
        $old = app(RedZoneAlertService::class)->take($doctor, $visit, $this->branch->id);
        $service = app(EmergencyAlertService::class);
        $service->activate($this->branch, true);
        $episode = EmergencyAlert::query()->sole();
        $this->assertSame($old->id, $episode->claim_floor_id);
        $this->assertNull($episode->taken_claim_id);
        $this->travelTo($episode->raised_at);
        try {
            $service->seen($doctor, $visit->refresh(), $this->command($visit));
            $new = $service->take($doctor, $visit->refresh(), $this->command($visit));
            $this->assertGreaterThan($old->id, $new->id);
            $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'green']);
            $this->assertSame($episode->raised_at->format('U'), $episode->fresh()->closed_at->format('U'));
            DB::statement('SET CONSTRAINTS queue_emergency_transition_evidence IMMEDIATE');
            $this->assertSame('zone_lowered', $episode->fresh()->close_code);
        } finally {
            $this->travelBack();
        }
    }

    public function test_lowering_closes_and_new_red_uses_new_episode_without_old_claim(): void
    {
        [$ca,$doctor,$visit] = $this->red();
        $this->selectBranch($doctor);
        $service = app(EmergencyAlertService::class);
        $service->take($doctor, $visit, $this->command($visit));
        $first = EmergencyAlert::query()->sole();
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'green']);
        $this->assertSame('zone_lowered', $first->fresh()->close_code);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
        $current = $service->open($visit->queueEntry->id);
        $this->assertNotSame($first->id, $current->id);
        $this->assertNull($current->taken_claim_id);
        $this->assertSame((int) DB::table('queue_red_zone_claims')->where('queue_entry_id', $visit->queueEntry->id)->max('id'), $current->claim_floor_id);
    }

    public function test_call_and_cancel_close_inside_source_transaction(): void
    {
        [$ca,$doctor,$visit] = $this->red();
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version]);
        $this->assertSame('left_waiting', EmergencyAlert::query()->sole()->close_code);
        // A separate Waiting episode is cancelled, never merely hidden by the read projection.
        $other = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $other);
        $this->triage($ca, $other->refresh(), ['chosen_zone' => 'red']);
        $other->refresh();
        $this->selectBranch($ca);
        app(VisitAdministrationService::class)->cancel($other, ['expected_branch_id' => $this->branch->id, 'lock_version' => $other->lock_version, 'queue_lock_version' => $other->queueEntry->lock_version, 'cancellation_reason' => 'Synthetic cancellation'], $ca);
        $this->assertSame('visit_cancelled', EmergencyAlert::query()->where('visit_id', $other->id)->sole()->close_code);
    }

    public function test_busy_doctor_must_hold_and_failed_take_leaves_hold(): void
    {
        [,,$visit] = $this->red();
        $taker = $this->doctor();
        [$doctor,,$own,$queue] = $this->servingFixture($taker);
        $encounter = $this->startEncounter($doctor, $own, $queue);
        $this->selectBranch($doctor);
        $service = app(EmergencyAlertService::class);
        $command = $this->command($visit);
        try {
            $service->take($doctor, $visit, $command);
            $this->fail('Expected busy refusal');
        } catch (ValidationException) {
            $this->assertDatabaseCount('queue_red_zone_claims', 0);
        }
        $this->holdEncounter($doctor, $own, $queue, $encounter);
        $winner = $this->doctor();
        $this->selectBranch($winner);
        $service->take($winner, $visit, $this->command($visit));
        $this->selectBranch($doctor);
        try {
            $service->take($doctor, $visit->refresh(), $command);
            $this->fail('Expected lost take');
        } catch (ValidationException) {
            $this->assertNotNull($encounter->fresh()->activeHold);
        }
    }

    public function test_preexisting_readiness_remains_while_new_flags_are_refused(): void
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $this->selectBranch($ca);
        app(CallHoldService::class)->change($ca, $visit, ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $visit->queueEntry->lock_version, 'reason_code' => 'other_administrative', 'idempotency_key' => (string) Str::uuid()], false);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
        $this->assertSame(1, DB::table('queue_call_holds')->whereNull('closed_at')->count());
        $this->selectBranch($doctor);
        $this->assertCount(1, app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id));
        app(EmergencyAlertService::class)->take($doctor, $visit->refresh(), $this->command($visit));
        $this->assertSame(1, DB::table('queue_call_holds')->whereNull('closed_at')->count());
    }

    public function test_raw_invalid_seen_and_missing_transition_event_are_refused(): void
    {
        [,,$visit] = $this->red();
        $alert = EmergencyAlert::query()->sole();
        try {
            DB::transaction(function () use ($alert) {
                DB::table('queue_emergency_alerts')->where('id', $alert->id)->increment('lock_version');
                DB::statement('SET CONSTRAINTS queue_emergency_transition_evidence IMMEDIATE');
            });
            $this->fail('Expected missing evidence refusal');
        } catch (QueryException) {
            $this->assertSame(1, $alert->fresh()->lock_version);
        }
        foreach (['delete', 'update'] as $operation) {
            try {
                DB::transaction(function () use ($operation) {
                    $q = DB::table('queue_emergency_alert_events');
                    $operation === 'delete' ? $q->delete() : $q->update(['event_code' => 'seen']);
                });
                $this->fail('Expected immutable event refusal');
            } catch (QueryException) {
                $this->assertDatabaseCount('queue_emergency_alert_events', 1);
            }
        }
    }

    public function test_seen_response_is_built_before_its_outer_locks_release_and_never_borrows_next_episode(): void
    {
        [$ca,$doctor,$visit] = $this->red();
        $this->selectBranch($doctor);
        $command = $this->command($visit);
        $base = DB::transactionLevel();
        $responseReadLevel = null;
        DB::listen(function ($query) use (&$responseReadLevel) {
            if (str_contains($query->sql, '"queue_emergency_alerts"."id" = ?')) {
                $responseReadLevel = DB::transactionLevel();
            }
        });
        $response = app(EmergencyAlertService::class)->seen($doctor, $visit, $command);
        $this->assertNotNull($responseReadLevel);
        $this->assertGreaterThan($base, $responseReadLevel);
        $this->triage($ca, $visit, ['chosen_zone' => 'green']);
        $this->triage($ca, $visit, ['chosen_zone' => 'red']);
        $this->assertSame($command['episode_id'], $response['episodeId']);
        $this->selectBranch($doctor);
        $new = app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id)[0];
        $this->assertNotSame($response['episodeId'], $new['episodeId']);
        $this->assertFalse($new['seenByMe']);
    }

    public function test_activation_refuses_inactive_organisation(): void
    {
        $this->organisation->forceFill(['is_active' => false])->save();
        $this->expectException(HttpException::class);
        app(EmergencyAlertService::class)->activate($this->branch->fresh(), true);
    }

    public function test_activation_health_reports_stale_open_episode_and_refuses_success(): void
    {
        [,,$visit] = $this->red();
        // Controlled synthetic bypass proves rollout health catches a missing source lifecycle hook.
        $visit->queueEntry->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $visit->assigned_doctor_user_id])->save();
        $result = app(EmergencyAlertService::class)->activate($this->branch, true);
        $this->assertSame(0, $result['remaining']);
        $this->assertSame(1, $result['invalidOpen']);
        $this->artisan('queue:activate-emergency-alerts', ['--organisation' => $this->organisation->id, '--branch' => $this->branch->id, '--execute' => true])->assertExitCode(1);
    }

    public function test_raw_seen_cannot_acknowledge_stale_source_even_with_matching_version_event(): void
    {
        [, $doctor, $visit] = $this->red();
        $alert = EmergencyAlert::query()->sole();
        $visit->queueEntry->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
        try {
            DB::transaction(function () use ($alert, $doctor) {
                DB::table('queue_emergency_alerts')->where('id', $alert->id)->increment('lock_version');
                DB::table('queue_emergency_alert_events')->insert([
                    'emergency_alert_id' => $alert->id, 'organisation_id' => $alert->organisation_id,
                    'branch_id' => $alert->branch_id, 'event_code' => 'seen', 'actor_user_id' => $doctor->id,
                    'occurred_at' => now()->format('Y-m-d H:i:sP'), 'idempotency_key' => (string) Str::uuid(),
                    'payload_hash' => str_repeat('a', 64), 'result_version' => 2,
                ]);
                DB::statement('SET CONSTRAINTS queue_emergency_transition_evidence IMMEDIATE');
            });
            $this->fail('Expected raw Seen eligibility refusal');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('emergency', strtolower($exception->getMessage()));
            $this->assertSame(1, $alert->fresh()->lock_version);
            $this->assertDatabaseCount('queue_emergency_alert_events', 1);
        }
    }

    public function test_seen_http_is_narrow_no_store_and_current_role_permission_required(): void
    {
        [$ca, $doctor, $visit] = $this->red();
        $input = $this->command($visit);
        $this->selectBranch($ca);
        $this->postJson(route('queue.emergency-seen', $visit), $input)->assertForbidden();
        $this->selectBranch($doctor);
        $this->postJson(route('queue.emergency-seen', $visit), $input)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertExactJson(['episodeId' => $input['episode_id'], 'lockVersion' => 2, 'seenByMe' => true]);
        $doctor->removeRole('resident_doctor');
        $doctor->givePermissionTo('queue.emergency.respond.branch');
        $this->postJson(route('queue.emergency-seen', $visit), $input)->assertForbidden();
        $this->assertDatabaseCount('queue_red_zone_claims', 0);
    }

    public function test_cascade_truncate_still_hits_triage_guard_and_preserves_episode_evidence(): void
    {
        $this->red();
        // Flush valid deferred episode evidence before testing TRUNCATE's immutable guards.
        DB::statement('SET CONSTRAINTS queue_emergency_transition_evidence IMMEDIATE');
        try {
            DB::transaction(fn () => DB::unprepared('TRUNCATE visit_triage_assessments CASCADE'));
            $this->fail('Expected triage append-only refusal');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0]);
            $this->assertStringContainsString('Triage evidence is append-only', $exception->getMessage());
            $this->assertDatabaseCount('visit_triage_assessments', 1);
            $this->assertDatabaseCount('queue_emergency_alerts', 1);
            $this->assertDatabaseCount('queue_emergency_alert_events', 1);
        }
    }

    public function test_emergency_permission_gates_http_read_and_take_independently_of_call_in(): void
    {
        [, $doctor, $visit] = $this->red();
        Role::findByName('resident_doctor', 'web')->revokePermissionTo('queue.call.own');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $doctor = $doctor->fresh();
        $this->selectBranch($doctor);
        $this->getJson(route('queue.red-zone-alerts', ['expected_branch_id' => $this->branch->id]))->assertOk();
        $this->post(route('queue.take-red-zone', $visit), $this->command($visit))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('queue_red_zone_claims', 1);
        $this->assertSame('waiting', $visit->queueEntry->fresh()->status);
    }
}
