<?php

namespace Tests\Feature;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ClockPhotoFixture;

/**
 * DS-13: the Me home ring and week strip come from My attendance's own rows, so the Late rule (grace minutes included)
 * is exactly the one HR Review uses. The clock is frozen in Kuala Lumpur on Monday 8 Mar 2027.
 */
class MeHomeClockTest extends VisitTestCase
{
    private const WEEK = '2027-03-08';

    public function test_the_ring_and_week_use_the_same_late_rule_and_grace_as_review(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $late = $this->actor('ca');
        $within = $this->actor('ca');
        $this->roster($hr, $director, [[$late, '08:00', '14:00', 30], [$within, '08:00', '14:00', 30]]);
        $this->settings($hr);

        $this->at('2027-03-08 08:12');
        $this->clock($late, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-08 08:03');
        $this->clock($within, 'in')->assertSessionHasNoErrors();

        $this->at('2027-03-08 08:40');
        $this->selectBranch($late);
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p
            ->where('me.ring.state', 'in')
            ->where('me.ring.onTime', false)
            ->where('me.ring.lateMinutes', 12)
            ->where('me.ring.clockedInAt', '2027-03-08T00:12:00+00:00')
            ->where('me.ring.rosteredMinutes', 330)
            ->where('me.greeting.text', fn ($t) => str_starts_with((string) $t, 'Have a good shift'))
            ->where('me.week.days.0', fn ($d) => $d['day'] === 'Mon' && $d['result'] === 'late' && $d['word'] === 'Late')
            ->where('me.week.summary', '1 late'));

        $this->selectBranch($within);
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p
            ->where('me.ring.onTime', true)
            ->where('me.ring.lateMinutes', null)
            ->where('me.week.days.0.result', 'ontime')
            ->where('me.week.summary', '1 on time'));

        // The same read-only state, with the extra facts, from the clock-state endpoint.
        $this->getJson(route('hr.attendance.clock-state'))->assertOk()
            ->assertJson(['state' => 'in', 'onTime' => true, 'rosteredMinutes' => 330, 'timezone' => $this->branch->timezone]);

        // After the clock-out the ring reads "Done for today".
        $this->at('2027-03-08 13:00');
        $this->clock($within, 'out')->assertSessionHasNoErrors();
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p->where('me.ring.state', 'done'));
    }

    public function test_a_missed_shift_shows_in_the_week_and_a_day_off_shows_a_dash(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $absent = $this->actor('ca');
        $this->roster($hr, $director, [[$absent, '08:00', '14:00', 0]]);
        $this->settings($hr);

        $this->at('2027-03-09 09:00'); // Tuesday: Monday's shift was never clocked.
        $this->selectBranch($absent);
        $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p
            ->where('me.week.days.0.result', 'missed')
            ->where('me.week.days.1.result', 'none')
            ->where('me.week.days.4.word', 'Not yet')
            ->where('me.week.summary', '1 missed'));
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, $this->branch->timezone));
    }

    private function clock(User $actor, string $kind): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, $kind)]);
    }

    private function settings(User $actor): TestResponse
    {
        $this->selectBranch($actor);

        return $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7,
            'idempotency_key' => (string) Str::uuid(), 'effective_from' => self::WEEK]);
    }

    /** @param list<array{User, string, string, int}> $shifts */
    private function roster(User $hr, User $director, array $shifts): void
    {
        $this->at('2027-03-08 07:00');
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => self::WEEK, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        foreach ($shifts as [$person, $start, $end, $break]) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $person->id, 'date' => self::WEEK, 'start_time' => $start, 'end_time' => $end,
                'break_minutes' => $break, 'session_type' => 'full_day', 'branch_room_id' => null, 'branch_id' => $this->branch->id, 'week' => self::WEEK,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }
}
