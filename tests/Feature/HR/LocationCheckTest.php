<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Services\LocationCheckService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-16a location check at clock-in (HR-PLAN 22; B.E.R.N.I.S.S for the owner, 2026-11-10). Off by default, one branch
 * at a time, a refusal never blocks, and a person's position is never stored, logged, audited or flashed. Synthetic
 * positions only. The clock is frozen in Kuala Lumpur.
 */
class LocationCheckTest extends VisitTestCase
{
    private const CENTRE = ['lat' => 3.1, 'lng' => 101.7];

    /** About 33 m north of the centre. */
    private const NEAR = ['latitude' => 3.1003, 'longitude' => 101.7, 'accuracy' => 20];

    /** About 1.1 km north of the centre. */
    private const FAR = ['latitude' => 3.11, 'longitude' => 101.7, 'accuracy' => 120];

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 07:00', $this->branch->timezone));
        $this->director = $this->actor('director');
    }

    public function test_only_the_director_turns_it_on_and_it_is_off_by_default(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(ClockPermissions::LOCATION_MANAGE, $p, true))->keys()->values()->all();
        $this->assertSame(['director'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(ClockPermissions::LOCATION_MANAGE));
        foreach (['hr_manager', 'ca', 'technical_admin'] as $role) {
            $this->turnOn($this->actor($role))->assertForbidden();
        }

        // Off: whatever the browser sends is ignored and nothing is kept.
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.location', null));
        $this->clock($ca, 'in', ['clock_position' => self::NEAR, 'location_consent' => true])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('hr_clock_location_checks')->count() + DB::table('hr_location_consents')->count());
        $this->selectBranch($this->director);
        $this->get(route('hr.attendance.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.settings.location.suggestedRadius', 150)
            ->where('attendance.settings.location.branches', fn ($branches) => collect($branches)->every(fn ($b) => $b['enabled'] === false)));
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('attendance.settings.location', null));
    }

    public function test_consent_once_then_inside_or_outside_and_a_refusal_never_blocks(): void
    {
        $this->turnOn($this->director)->assertSessionHasNoErrors();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.location.consentNeeded', true)
            ->where('attendance.my.location.consentEn', LocationCheckService::DRAFT_CONSENT_EN)->where('attendance.my.location.consentBm', LocationCheckService::DRAFT_CONSENT_BM));

        // Clock in without location: the press is recorded, the result is "not checked".
        $this->clock($ca, 'in', ['location_unavailable' => 'declined'])->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $this->assertSame('open', $session->status);
        $this->assertSame(['unavailable', null, 'declined'], $this->checkOf($session, 'in'));
        $this->assertSame(0, DB::table('hr_location_consents')->count());

        // A position without consent is never checked.
        $this->travelTo(CarbonImmutable::parse('2027-03-08 12:00', $this->branch->timezone));
        $this->clock($ca, 'out', ['clock_position' => self::NEAR])->assertSessionHasNoErrors();
        $this->assertSame(['unavailable', null, 'declined'], $this->checkOf($session, 'out'));

        // Consent once, then the next press is checked; the consent is not asked again.
        $this->travelTo(CarbonImmutable::parse('2027-03-08 13:00', $this->branch->timezone));
        $this->clock($ca, 'in', ['clock_position' => self::NEAR, 'location_consent' => true])->assertSessionHasNoErrors();
        $second = ClockSession::query()->where('status', 'open')->sole();
        $this->assertSame(['inside', 'good', null], $this->checkOf($second, 'in'));
        $this->assertSame(1, DB::table('hr_location_consents')->where('user_id', $ca->id)->count());
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.location.consentNeeded', false)
            ->where('attendance.my.location.consentEn', null)
            ->where('attendance.my.location.lastResult', 'Your last clock-in (8 Mar 2027): Inside the branch area, accurate to about 50 m'));
        $this->travelTo(CarbonImmutable::parse('2027-03-08 17:00', $this->branch->timezone));
        $this->clock($ca, 'out', ['clock_position' => self::FAR])->assertSessionHasNoErrors();
        $this->assertSame(['outside', 'fair', null], $this->checkOf($second, 'out'));
        $this->assertSame('closed', $second->refresh()->status);

        // Review shows plain words, never a distance or a position.
        $this->selectBranch($this->director);
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'branch' => $this->branch->id, 'date' => '2027-03-08']))
            ->assertInertia(fn (Assert $page) => $page->where('attendance.review.rows', function ($rows) use ($second) {
                $row = collect($rows)->first(fn ($r) => ($r['session']['publicId'] ?? null) === $second->public_id);

                return $row['location'] === ['in' => 'Inside the branch area, accurate to about 50 m', 'out' => 'Outside the branch area, accurate to about 200 m', 'outside' => true];
            }));

        // A changed consent text is a new version, so it is asked again.
        $this->turnOn($this->director, ['consent_en' => 'Synthetic changed consent text.'])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.location.consentNeeded', true)
            ->where('attendance.my.location.consentEn', 'Synthetic changed consent text.'));
    }

    public function test_denied_failed_and_rough_readings_are_not_available(): void
    {
        $this->turnOn($this->director)->assertSessionHasNoErrors();
        $cases = [
            [['location_unavailable' => 'denied', 'location_consent' => true], 'denied'],
            [['location_unavailable' => 'failed', 'location_consent' => true], 'failed'],
            [['clock_position' => ['latitude' => 'abc', 'longitude' => 101.7, 'accuracy' => 10], 'location_consent' => true], 'failed'],
            [['clock_position' => ['latitude' => 95, 'longitude' => 101.7, 'accuracy' => 10], 'location_consent' => true], 'failed'],
            [['clock_position' => [...self::NEAR, 'accuracy' => 5000], 'location_consent' => true], 'imprecise'],
        ];
        foreach ($cases as [$payload, $reason]) {
            $person = $this->actor('ca');
            $this->clock($person, 'in', $payload)->assertSessionHasNoErrors();
            $session = ClockSession::query()->where('user_id', $person->id)->sole();
            $this->assertSame(['unavailable', null, $reason], $this->checkOf($session, 'in'), $reason);
        }
    }

    public function test_a_position_is_never_stored_logged_audited_or_flashed(): void
    {
        $this->turnOn($this->director)->assertSessionHasNoErrors();
        $ca = $this->actor('ca');
        $marker = ['latitude' => 3.1234567, 'longitude' => 101.7654321, 'accuracy' => 37.5];
        $this->clock($ca, 'in', ['clock_position' => $marker, 'location_consent' => true])->assertSessionHasNoErrors();
        $this->assertSame(['outside', 'good', null], $this->checkOf(ClockSession::query()->sole(), 'in'));

        foreach (['hr_clock_sessions', 'hr_clock_events', 'hr_clock_location_checks', 'hr_location_consents', 'hr_attendance_retries', 'audit_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('3.123', $dump, $table);
            $this->assertStringNotContainsString('101.765', $dump, $table);
        }
        // No column for a person's position, distance or device in any press table.
        $columns = collect(['hr_clock_sessions', 'hr_clock_events', 'hr_clock_location_checks', 'hr_location_consents'])
            ->flatMap(fn (string $t) => DB::getSchemaBuilder()->getColumnListing($t));
        $this->assertEmpty($columns->filter(fn (string $c) => preg_match('/lat|lng|lon|coord|position|distance|ip|device|agent/i', $c) === 1)->all());
        // The result stays behind Review: the audit log says only that a press was checked (Suresh, M1).
        $this->assertSame(['kind', 'setting_id'], array_keys(json_decode((string) DB::table('audit_logs')->where('event', 'hr.attendance.location_checked')->value('metadata'), true)));
        $this->assertContains('clock_position', (new \ReflectionProperty(Handler::class, 'dontFlash'))->getValue(app(ExceptionHandler::class)));
    }

    public function test_one_branch_at_a_time_and_the_settings_are_checked(): void
    {
        $this->turnOn($this->director, ['radius_metres' => 10, 'consent_bm' => ''])->assertSessionHasErrors(['radius_metres', 'consent_bm']);
        $this->turnOn($this->director)->assertSessionHasNoErrors();
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->where('is_active', true)->firstOrFail();
        $this->turnOn($this->director, ['branch_id' => $other->id])
            ->assertSessionHasErrors(['branch_id' => "The location check is on at {$this->branch->name}. Turn it off there first: it runs at one branch at a time."]);
        $this->selectBranch($this->director);
        $this->put(route('hr.attendance.location-check'), ['branch_id' => $this->branch->id, 'enabled' => false, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->turnOn($this->director, ['branch_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame(['hr.attendance.location_check_on', 'hr.attendance.location_check_off', 'hr.attendance.location_check_on'],
            DB::table('audit_logs')->where('event', 'like', 'hr.attendance.location_check_%')->orderBy('id')->pluck('event')->all());
    }

    public function test_postgresql_keeps_the_rules(): void
    {
        $this->turnOn($this->director)->assertSessionHasNoErrors();
        $setting = DB::table('hr_location_check_settings')->sole();
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->firstOrFail();
        $row = ['organisation_id' => $this->organisation->id, 'enabled' => true, 'geofence_centre_lat' => 3.1, 'geofence_centre_lng' => 101.7,
            'radius_metres' => 150, 'consent_en' => 'x', 'consent_bm' => 'x', 'created_at' => now()];
        $this->refused('23514', fn () => DB::table('hr_location_check_settings')->insert([...$row, 'branch_id' => $other->id, 'created_by_user_id' => $this->director->id])); // one branch
        $this->refused('23514', fn () => DB::table('hr_location_check_settings')->insert([...$row, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->actor('hr_manager')->id]));
        $this->refused('23514', fn () => DB::table('hr_location_check_settings')->insert([...$row, 'branch_id' => $this->branch->id, 'radius_metres' => null,
            'created_by_user_id' => $this->director->id])); // on needs a radius
        $this->refused('P0001', fn () => DB::table('hr_location_check_settings')->where('id', $setting->id)->update(['radius_metres' => 300]));

        $ca = $this->actor('ca');
        $this->clock($ca, 'in', ['location_unavailable' => 'declined'])->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $check = ['organisation_id' => $this->organisation->id, 'clock_session_id' => $session->id, 'kind' => 'out', 'setting_id' => $setting->id, 'created_at' => now()];
        $this->refused('23514', fn () => DB::table('hr_clock_location_checks')->insert([...$check, 'result' => 'inside', 'accuracy_band' => 'good'])); // no consent
        $this->refused('23514', fn () => DB::table('hr_clock_location_checks')->insert([...$check, 'result' => 'inside', 'accuracy_band' => null])); // shape
        $this->refused('P0001', fn () => DB::table('hr_clock_location_checks')->where('clock_session_id', $session->id)->update(['result' => 'inside', 'accuracy_band' => 'good', 'unavailable_reason' => null]));
        $this->refused('P0001', fn () => DB::table('hr_clock_location_checks')->delete());
        // A result against a version no longer in force is refused (Suresh, m2).
        $this->turnOn($this->director, ['radius_metres' => 200])->assertSessionHasNoErrors();
        $this->refused('23514', fn () => DB::table('hr_clock_location_checks')->insert([...$check, 'result' => 'unavailable', 'unavailable_reason' => 'failed']));
    }

    public function test_results_are_purged_after_90_days_and_only_by_the_purge(): void
    {
        // Retention (owner, 2026-11-11): a location result is kept 90 days, then deleted by the daily purge. The clock
        // presses and the consent acknowledgements stay. PostgreSQL checks the 90 days against its own clock, so this
        // test works in real time: presses 100 and 89 days ago, then the purge now.
        $real = CarbonImmutable::createFromTimestamp(time(), 'UTC');
        $this->travelTo($real->subDays(100));
        $this->turnOn($this->director)->assertSessionHasNoErrors();
        $ca = $this->actor('ca');
        $this->clock($ca, 'in', ['clock_position' => self::NEAR, 'location_consent' => true])->assertSessionHasNoErrors();
        $this->travelTo($real->subDays(100)->addHours(4));
        $this->clock($ca, 'out', ['location_unavailable' => 'declined'])->assertSessionHasNoErrors();
        $this->travelTo($real->subDays(89));
        $this->clock($ca, 'in', ['clock_position' => self::FAR])->assertSessionHasNoErrors();
        $recent = ClockSession::query()->where('status', 'open')->sole();
        $this->travelBack();

        $this->artisan('hr-location-checks:purge')->expectsOutputToContain('Deleted 2 location-check results.')->assertSuccessful();
        $this->assertSame([$recent->id], DB::table('hr_clock_location_checks')->pluck('clock_session_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(2, ClockSession::query()->count()); // the presses stay
        $this->assertSame(1, DB::table('hr_location_consents')->count());
        $this->assertSame([['count' => 2, 'retention_days' => 90]], DB::table('audit_logs')->where('event', 'hr.attendance.location_results_purged')
            ->pluck('metadata')->map(fn ($m): mixed => json_decode((string) $m, true))->all());
        $this->artisan('hr-location-checks:purge')->expectsOutputToContain('Deleted 0 location-check results.')->assertSuccessful();

        // It runs daily from the scheduler.
        $this->assertSame(['0 0 * * *'], collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'hr-location-checks:purge'))->pluck('expression')->values()->all());

        // Only the purge deletes, and only rows past 90 days; TRUNCATE stays refused.
        $this->refused('P0001', fn () => DB::table('hr_clock_location_checks')->delete());
        $this->refused('P0001', function (): void {
            DB::statement("SELECT set_config('kpone.hr_location_purge', 'on', true)");
            DB::table('hr_clock_location_checks')->delete(); // 89 days old
        });
        $this->refused('P0001', fn () => DB::statement('TRUNCATE hr_clock_location_checks'));
        $this->assertSame(1, DB::table('hr_clock_location_checks')->count());
    }

    // ---- fixture ----

    /** @param array<string, mixed> $input */
    private function turnOn(User $by, array $input = []): TestResponse
    {
        $this->selectBranch($by);

        return $this->put(route('hr.attendance.location-check'), ['branch_id' => $this->branch->id, 'enabled' => true, 'centre_lat' => self::CENTRE['lat'],
            'centre_lng' => self::CENTRE['lng'], 'radius_metres' => 150, 'consent_en' => LocationCheckService::DRAFT_CONSENT_EN,
            'consent_bm' => LocationCheckService::DRAFT_CONSENT_BM, 'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $extra */
    private function clock(User $actor, string $kind, array $extra = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid(), ...$extra]);
    }

    /** @return array{0: string, 1: ?string, 2: ?string} */
    private function checkOf(ClockSession $session, string $kind): array
    {
        $row = DB::table('hr_clock_location_checks')->where('clock_session_id', $session->id)->where('kind', $kind)->sole();

        return [(string) $row->result, $row->accuracy_band, $row->unavailable_reason];
    }

    private function refused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
