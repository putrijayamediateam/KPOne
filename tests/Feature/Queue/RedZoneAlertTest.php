<?php

namespace Tests\Feature\Queue;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Queue\Emergency\EmergencyAlert;
use App\Domain\Queue\Models\QueueRedZoneClaim;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\Triage\TriageTestCase;

/** Q-16b: the red-zone alert and "I'll take it" (owner decisions 25 Nov 2026). */
class RedZoneAlertTest extends TriageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-25 01:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, User, Visit} CA, the assigned doctor and a waiting red patient */
    private function red(string $zone = 'red'): array
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $assigned = $this->doctor();
        $visit = $this->consultationVisit($ca, $assigned);
        $this->send($ca, $visit);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => $zone]);

        return [$ca, $assigned, $visit->refresh()];
    }

    /** @return list<array<string, mixed>> */
    private function alerts(User $actor): array
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->getJson(route('queue.red-zone-alerts', ['expected_branch_id' => $this->branch->id]))->assertOk()->json('alerts');
    }

    private function episodeCommand(User $actor, Visit $visit): array
    {
        $alert = EmergencyAlert::query()->where('visit_id', $visit->id)->whereNull('closed_at')->first();
        if (! $alert) {
            return [];
        }
        $claim = $alert->taken_claim_id ? QueueRedZoneClaim::find($alert->taken_claim_id) : null;
        $event = $claim && $claim->claimed_by_user_id === $actor->id && $visit->refresh()->assigned_doctor_user_id === $actor->id
            ? DB::table('queue_emergency_alert_events')->where('claim_id', $claim->id)->first() : null;

        return ['episode_id' => $alert->id, 'lock_version' => $event ? $event->result_version - 1 : $alert->lock_version, 'idempotency_key' => $event?->idempotency_key ?? (string) Str::uuid()];
    }

    private function take(User $actor, Visit $visit)
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->post(route('queue.take-red-zone', $visit->visit_number), ['expected_branch_id' => $this->branch->id, ...$this->episodeCommand($actor, $visit)]);
    }

    public function test_every_branch_doctor_and_the_recorders_see_the_alert_with_only_number_and_minutes(): void
    {
        [$ca, $assigned, $visit] = $this->red();
        $other = $this->doctor();
        foreach ([$other, $assigned, $ca] as $viewer) {
            $rows = $this->alerts($viewer);
            $this->assertCount(1, $rows);
            $this->assertSame(['alertOrigin', 'alertStartedAt', 'canTake', 'episodeId', 'legacy', 'lockVersion', 'mine', 'queueNumber', 'seenByMe', 'takenBy', 'visitNumber', 'waitingMinutes'], collect($rows[0])->keys()->sort()->values()->all());
            $this->assertNull($rows[0]['takenBy']);
        }
        $this->assertTrue($this->alerts($other)[0]['canTake']);
        $this->assertFalse($this->alerts($ca)[0]['canTake']);
        $this->assertStringNotContainsString($visit->patient->full_name, json_encode($this->alerts($other)));
    }

    public function test_green_has_no_alert_and_new_not_ready_flag_on_red_is_refused(): void
    {
        [$ca, , $visit] = $this->red('green');
        $this->assertSame([], $this->alerts($ca));

        [$ca, $assigned, $visit] = $this->red();
        $this->selectBranch($ca);
        $this->actingAs($ca)->post(route('queue.readiness.set', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $visit->queueEntry->refresh()->lock_version,
            'reason_code' => 'other_administrative',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('queue');
        $this->assertCount(1, $this->alerts($assigned));
    }

    public function test_other_roles_and_branches_get_no_alerts(): void
    {
        $this->red();
        foreach (['director', 'finance_officer', 'panel_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor)->getJson(route('queue.red-zone-alerts', ['expected_branch_id' => $this->branch->id]))->assertForbidden();
        }
    }

    public function test_another_doctor_takes_it_and_the_patient_is_theirs_with_one_claim_and_audit(): void
    {
        [$ca, $assigned, $visit] = $this->red();
        $taker = $this->doctor();
        $before = $visit->lock_version;

        $this->take($taker, $visit)->assertRedirect(route('queue.index'));

        $visit->refresh();
        $this->assertSame($taker->id, $visit->assigned_doctor_user_id);
        $this->assertSame($before + 1, $visit->lock_version);
        $claim = QueueRedZoneClaim::query()->sole();
        $this->assertSame($taker->id, $claim->claimed_by_user_id);
        $this->assertSame($assigned->id, $claim->previous_doctor_user_id);
        $this->assertSame(1, AuditLog::query()->where('event', 'queue.red_zone_taken')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'visit.doctor.changed')->count());
        $this->assertSame($taker->name, $this->alerts($assigned)[0]['takenBy']);
        $this->assertTrue($this->alerts($taker)[0]['mine']);
    }

    public function test_the_second_doctor_is_refused_and_the_same_doctors_retry_succeeds(): void
    {
        [, , $visit] = $this->red();
        $first = $this->doctor();
        $second = $this->doctor();
        $this->take($first, $visit)->assertRedirect();
        $this->take($first, $visit->refresh())->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();

        $this->take($second, $visit->refresh())->assertSessionHasErrors('queue');
        $this->assertSame(1, QueueRedZoneClaim::query()->count());
        $this->assertSame($first->id, $visit->refresh()->assigned_doctor_user_id);
    }

    public function test_the_taking_doctor_then_sees_the_patient_as_their_next_and_the_old_doctor_does_not(): void
    {
        [, $assigned, $visit] = $this->red();
        $taker = $this->doctor();
        $this->take($taker, $visit)->assertRedirect();

        $this->selectBranch($taker);
        $this->assertSame($visit->visit_number, $this->actingAs($taker)->postJson(route('queue.search'), [])->json('callNext.candidate.visitNumber'));
        $this->selectBranch($assigned);
        $this->assertNull($this->actingAs($assigned)->postJson(route('queue.search'), [])->json('callNext.candidate'));
    }

    public function test_a_ca_cannot_take_and_a_green_patient_cannot_be_taken(): void
    {
        [$ca, , $visit] = $this->red();
        $this->take($ca, $visit)->assertForbidden();

        [, , $green] = $this->red('green');
        $this->take($this->doctor(), $green)->assertSessionHasErrors('queue');
        $this->assertSame(0, QueueRedZoneClaim::query()->count());
    }

    public function test_a_later_reassignment_brings_the_alert_back(): void
    {
        [, $assigned, $visit] = $this->red();
        $taker = $this->doctor();
        $this->take($taker, $visit)->assertRedirect();
        $visit->refresh()->forceFill(['assigned_doctor_user_id' => $assigned->id])->save();

        $this->assertNull($this->alerts($assigned)[0]['takenBy']);
        $this->take($taker, $visit->refresh())->assertRedirect();
        $this->assertSame(2, QueueRedZoneClaim::query()->count());
    }

    public function test_claims_are_append_only(): void
    {
        [, , $visit] = $this->red();
        $this->take($this->doctor(), $visit)->assertRedirect();
        $claim = QueueRedZoneClaim::query()->sole();

        $this->expectException(\LogicException::class);
        $claim->forceFill(['claimed_by_user_id' => $this->doctor()->id])->save();
    }

    public function test_a_take_from_an_open_consultation_returns_to_that_consultation(): void
    {
        [, , $red] = $this->red();
        $taker = $this->doctor();
        $own = $this->consultationVisit($this->actor('ca'), $taker);
        $this->selectBranch($taker);

        $this->actingAs($taker)->post(route('queue.take-red-zone', $red->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'from' => 'consultation',
            'from_visit' => $own->visit_number,
            ...$this->episodeCommand($taker, $red),
        ])->assertRedirect(route('encounters.show', $own->visit_number));
        $this->assertSame($taker->id, $red->refresh()->assigned_doctor_user_id);
    }

    public function test_postgres_refuses_to_change_or_delete_a_claim(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger test.');
        }
        [, , $visit] = $this->red();
        $this->take($this->doctor(), $visit)->assertRedirect();
        $this->expectException(QueryException::class);
        DB::table('queue_red_zone_claims')->delete();
    }
}
