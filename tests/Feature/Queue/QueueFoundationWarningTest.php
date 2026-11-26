<?php

namespace Tests\Feature\Queue;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\Triage\TriageTestCase;

class QueueFoundationWarningTest extends TriageTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_warning_survives_emergency_hydration_without_widening_own_scope(): void
    {
        $this->approvedGuideline();
        $week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $rostered = $this->doctor();
        $off = $this->doctor();
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        $this->post(route('hr.roster.shifts.store', $version->public_id), [
            'staff_user_id' => $rostered->id, 'date' => $week->toDateString(), 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 0,
            'session_type' => 'morning', 'branch_room_id' => null, 'branch_id' => $this->branch->id,
            'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        Date::setTestNow(CarbonImmutable::parse($week->toDateString().' 10:00', $this->branch->timezone)->utc());
        $red = $this->consultationVisit($ca, $off);
        $normal = $this->consultationVisit($ca, $off);
        $this->send($ca, $red);
        $this->send($ca, $normal);
        $this->triage($ca, $red->refresh(), ['chosen_zone' => 'red', 'pulse_bpm' => 130]);
        $this->selectBranch($ca);
        $registration = app(VisitDirectoryService::class)->search($ca, []);
        $queue = app(QueueDirectoryService::class)->snapshot($ca);
        $expected = ['code' => 'off_roster', 'label' => "Not on today's roster"];
        $this->assertSame($expected, $registration['emergency']['data'][0]['boardRow']['doctorWarning']);
        $this->assertSame($expected, $registration['data']->sole()['doctorWarning']);
        $this->assertSame($expected, $queue['emergency']['data'][0]['boardRow']['doctorWarning']);
        $this->assertSame($expected, $queue['waiting']['data']->sole()['doctorWarning']);
        $this->selectBranch($off);
        $own = app(QueueDirectoryService::class)->snapshot($off);
        $this->assertSame('own', $own['scope']);
        $this->assertSame($red->visit_number, $own['emergency']['data'][0]['visitNumber']);
        $this->assertNull($own['emergency']['data'][0]['boardRow']['doctorWarning']);
        $this->selectBranch($rostered);
        $this->assertSame(0, app(QueueDirectoryService::class)->snapshot($rostered)['emergency']['total']);
    }
}
