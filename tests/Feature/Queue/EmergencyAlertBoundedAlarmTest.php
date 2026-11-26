<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Emergency\EmergencyAlert;
use App\Domain\Queue\Emergency\EmergencyAlertService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Models\QueueRedZoneClaim;
use App\Domain\Queue\Services\RedZoneAlertService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\Triage\TriageTestCase;

class EmergencyAlertBoundedAlarmTest extends TriageTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_older_taken_rows_do_not_hide_an_untaken_alarm_beyond_the_bounded_response(): void
    {
        $start = CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc();
        Date::setTestNow($start);
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $latest = null;
        for ($index = 0; $index < 26; $index++) {
            Date::setTestNow($start->addMinutes($index));
            $visit = $this->consultationVisit($ca, $doctor);
            $this->send($ca, $visit);
            $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
            if ($index < 25) {
                $this->selectBranch($doctor);
                $episode = EmergencyAlert::query()->where('visit_id', $visit->id)->sole();
                app(EmergencyAlertService::class)->take($doctor, $visit->refresh(), [
                    'expected_branch_id' => $this->branch->id, 'episode_id' => $episode->id,
                    'lock_version' => $episode->lock_version, 'idempotency_key' => (string) Str::uuid(),
                ]);
            }
            $latest = $visit;
        }
        $this->selectBranch($doctor);
        $alerts = app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id);
        $this->assertCount(25, $alerts, 'The global response must remain bounded.');
        $this->assertSame(26, EmergencyAlert::query()->whereNull('closed_at')->whereIn('queue_entry_id', QueueEntry::query()->where('status', QueueEntry::STATUS_WAITING)->select('id'))->count(), 'Taking preserves all open Waiting episodes for the later lane projection.');
        $this->assertSame(1, EmergencyAlert::query()->whereNull('closed_at')->whereNull('taken_claim_id')->count());
        $this->assertTrue(collect($alerts)->contains(fn (array $alert): bool => $alert['visitNumber'] === $latest->visit_number && $alert['takenBy'] === null), 'The one untaken emergency must stay visible to the alarm even behind 25 older taken rows.');
    }

    public function test_untaken_legacy_and_episode_entries_keep_existing_age_order_and_reassignment_reactivates_alarm(): void
    {
        $start = CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc();
        Date::setTestNow($start);
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $takenLegacy = $this->redVisit($ca, $doctor, true);
        $this->takeVisit($doctor, $takenLegacy);
        Date::setTestNow($start->addMinute());
        $takenEpisode = $this->redVisit($ca, $doctor);
        $this->takeVisit($doctor, $takenEpisode);
        Date::setTestNow($start->addMinutes(2));
        $untakenEpisode = $this->redVisit($ca, $doctor);
        Date::setTestNow($start->addMinutes(3));
        $untakenLegacy = $this->redVisit($ca, $doctor, true);
        $this->selectBranch($doctor);
        $episode = EmergencyAlert::query()->where('visit_id', $untakenEpisode->id)->sole();
        app(EmergencyAlertService::class)->seen($doctor, $untakenEpisode, ['expected_branch_id' => $this->branch->id, 'episode_id' => $episode->id, 'lock_version' => $episode->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $alerts = app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id);
        $this->assertSame([$untakenLegacy->visit_number, $untakenEpisode->visit_number, $takenLegacy->visit_number, $takenEpisode->visit_number], array_column($alerts, 'visitNumber'));
        $this->assertSame([null, null, $doctor->name, $doctor->name], array_column($alerts, 'takenBy'));
        $this->assertTrue($alerts[1]['seenByMe'], 'Seen must not move an untaken emergency behind taken entries.');

        $replacement = $this->doctor();
        $this->selectBranch($ca);
        $takenEpisode->refresh();
        app(VisitAdministrationService::class)->update($takenEpisode, [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $takenEpisode->lock_version,
            'queue_lock_version' => $takenEpisode->queueEntry->lock_version, 'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $replacement->id, 'priority' => $takenEpisode->priority, 'coverage_type' => 'self_pay',
            'visit_reason_public_ids' => $takenEpisode->reasonAssignments()->with('reason')->get()->pluck('reason.public_id')->all(),
        ], $ca);
        $this->selectBranch($doctor);
        $alerts = app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id);
        $this->assertSame([$untakenLegacy->visit_number, $takenEpisode->visit_number, $untakenEpisode->visit_number, $takenLegacy->visit_number], array_column($alerts, 'visitNumber'));
        $this->assertNull($alerts[1]['takenBy']);
        $this->assertTrue($alerts[1]['canTake']);
    }

    public function test_activation_does_not_rank_pre_floor_claim_as_effectively_taken(): void
    {
        $start = CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc();
        Date::setTestNow($start);
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $takenEpisode = $this->redVisit($ca, $doctor);
        $this->takeVisit($doctor, $takenEpisode);
        Date::setTestNow($start->addMinute());
        $legacy = $this->redVisit($ca, $doctor, true);
        $claim = $this->takeVisit($doctor, $legacy);
        app(EmergencyAlertService::class)->activate($this->branch, true);
        $activated = EmergencyAlert::query()->where('visit_id', $legacy->id)->sole();
        $this->assertSame($claim->id, $activated->claim_floor_id);
        $this->assertNull($activated->taken_claim_id);
        $this->selectBranch($doctor);
        $alerts = app(RedZoneAlertService::class)->alerts($doctor, $this->branch->id);
        $this->assertSame([$legacy->visit_number, $takenEpisode->visit_number], array_column($alerts, 'visitNumber'));
        $this->assertNull($alerts[0]['takenBy']);
        $this->assertTrue($alerts[0]['canTake']);
        $this->assertSame($doctor->name, $alerts[1]['takenBy']);
    }

    private function redVisit(User $ca, User $doctor, bool $legacy = false): Visit
    {
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        if ($legacy) {
            // Represent historical pre-TRI2 data without bypassing patient/visit/triage services.
            $service = \Mockery::mock(new EmergencyAlertService);
            $service->shouldReceive('triageLocked')->once();
            $this->instance(EmergencyAlertService::class, $service);
        }
        try {
            $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
        } finally {
            if ($legacy) {
                $this->forgetMock(EmergencyAlertService::class);
            }
        }

        return $visit->refresh();
    }

    private function takeVisit(User $doctor, Visit $visit): QueueRedZoneClaim
    {
        $this->selectBranch($doctor);
        $episode = EmergencyAlert::query()->where('visit_id', $visit->id)->whereNull('closed_at')->first();

        return app(RedZoneAlertService::class)->take($doctor, $visit, $this->branch->id, $episode ? [
            'episode_id' => $episode->id, 'lock_version' => $episode->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ] : []);
    }
}
