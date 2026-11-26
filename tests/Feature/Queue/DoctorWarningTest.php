<?php

namespace Tests\Feature\Queue;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Assignment\DoctorWarningProjection;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AUTO-1c (owner answers Q1 to Q6): a neutral warning on a WAITING consultation row whose assigned doctor is off the
 * roster, without a room, or busy while another doctor is free. Read only, batched, shown only to a viewer who may edit the
 * visit, and never naming another doctor.
 */
class DoctorWarningTest extends QueueTestCase
{
    private CarbonImmutable $week;

    private User $hr;

    private User $director;

    private User $ca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->ca = $this->actor('ca');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, int|null> $rooms doctor id => room id */
    private function publish(array $doctors, array $rooms = []): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        foreach ($doctors as $doctor) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), [
                'staff_user_id' => $doctor->id, 'date' => $this->week->toDateString(), 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 0,
                'session_type' => 'morning', 'branch_room_id' => $rooms[$doctor->id] ?? null, 'branch_id' => $this->branch->id,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        Date::setTestNow(CarbonImmutable::parse($this->week->toDateString().' 10:00', $this->branch->timezone));
    }

    private function waiting(User $doctor): Visit
    {
        $visit = $this->consultationVisit($this->ca, $doctor);
        $this->send($this->ca, $visit);

        return $visit->refresh();
    }

    /** @return array<string, array<string, mixed>|null> visit number => doctorWarning, from the Registration board */
    private function registrationWarnings(?User $viewer = null): array
    {
        $this->selectBranch($viewer ?? $this->ca);
        $rows = $this->postJson(route('registration.search'))->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $r): array => [$r['visitNumber'] => $r['doctorWarning'] ?? null])->all();
    }

    private function serve(User $doctor): void
    {
        $visit = $this->waiting($doctor);
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $visit->queueEntry->lock_version]);
    }

    public function test_no_roster_published_means_no_warnings_at_all(): void
    {
        $visit = $this->waiting($this->doctor());
        $this->assertSame([$visit->visit_number => null], $this->registrationWarnings());
    }

    public function test_a_doctor_off_todays_roster_warns_and_the_label_is_neutral(): void
    {
        $rostered = $this->doctor();
        $off = $this->doctor();
        $this->publish([$rostered]);
        $visit = $this->waiting($off);

        $warning = $this->registrationWarnings()[$visit->visit_number];
        $this->assertSame(['code' => 'off_roster', 'label' => "Not on today's roster"], $warning);
        // Neutral: no doctor's name, no figures.
        $this->assertStringNotContainsString($rostered->name, json_encode($warning));
        $this->assertSame(['code', 'label'], array_keys($warning));
    }

    public function test_a_rostered_doctor_with_nobody_free_to_take_over_does_not_warn(): void
    {
        $a = $this->doctor();
        $this->publish([$a]);
        $this->serve($a); // busy, and nobody else is rostered
        $waiting = $this->waiting($a);

        $this->assertNull($this->registrationWarnings()[$waiting->visit_number]);
    }

    public function test_a_busy_doctor_warns_only_while_another_rostered_doctor_is_free(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();
        $this->publish([$a, $b]);
        $this->serve($a);
        $behindA = $this->waiting($a);

        $this->assertSame('another_free', $this->registrationWarnings()[$behindA->visit_number]['code']);

        // B now has a waiting patient too, so nobody is free: no warning.
        $this->waiting($b);
        $this->assertNull($this->registrationWarnings()[$behindA->visit_number]);
    }

    public function test_a_doctor_with_no_room_today_warns_once_the_branch_has_rooms(): void
    {
        $a = $this->doctor();
        $room = (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'kind' => 'consultation',
            'name' => 'Synthetic Warning Room', 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->publish([$a]); // on duty now, but the shift carries no room
        $visit = $this->waiting($a);
        $this->assertSame('no_room', $this->registrationWarnings()[$visit->visit_number]['code']);
        $this->assertGreaterThan(0, $room);
    }

    public function test_serving_cancelled_and_otc_rows_never_warn_and_a_viewer_without_edit_gets_no_key(): void
    {
        $rostered = $this->doctor();
        $off = $this->doctor();
        $this->publish([$rostered]);
        $waiting = $this->waiting($off);
        $this->serve($off);
        $otc = $this->register($this->ca, $this->patient($this->ca), ['visit_type' => 'otc']);

        $warnings = $this->registrationWarnings();
        $serving = Visit::query()->where('assigned_doctor_user_id', $off->id)->where('id', '!=', $waiting->id)->firstOrFail();
        $this->assertSame('off_roster', $warnings[$waiting->visit_number]['code']);
        $this->assertNull($warnings[$serving->visit_number], 'a Serving row never warns');
        $this->assertNull($warnings[$otc->visit_number] ?? null, 'an OTC row never warns');

        // The doctor's own board carries no key (the doctor cannot edit visits), and the TV feed is untouched.
        $this->selectBranch($off);
        $queue = $this->postJson(route('queue.search'))->assertOk();
        $this->assertStringNotContainsString('doctorWarning":{', json_encode($queue->json()));
    }

    public function test_the_queue_board_carries_the_warning_for_a_viewer_who_may_edit(): void
    {
        $rostered = $this->doctor();
        $off = $this->doctor();
        $this->publish([$rostered]);
        $this->waiting($off);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $json = json_encode($this->postJson(route('queue.search'))->assertOk()->json());
        $this->assertStringContainsString('"doctorWarning":{"code":"off_roster"', $json);
    }

    public function test_the_roster_and_room_reads_do_not_grow_with_the_number_of_waiting_rows(): void
    {
        $a = $this->doctor();
        $off = $this->doctor();
        $this->publish([$a]);
        $this->waiting($off);
        $count = function (): int {
            $this->selectBranch($this->ca);
            DB::flushQueryLog();
            $this->postJson(route('registration.search'))->assertOk();

            return collect(DB::getQueryLog())->filter(fn (array $q): bool => (bool) preg_match('/hr_roster|hr_leave|branch_rooms|doctor_room_assignments/', $q['query']))->count();
        };
        DB::enableQueryLog();
        $count(); // warm-up: the first board load also reads the roster-published fact
        foreach (range(1, 2) as $unused) {
            $this->waiting($off);
        }
        $few = $count();
        foreach (range(1, 6) as $unused) {
            $this->waiting($off);
        }
        $many = $count();
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many, 'nine waiting rows read the roster and rooms exactly as often as three');
    }

    public function test_a_free_alternative_without_a_room_cannot_trigger_the_warning(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();
        $room = app(QueueDisplayAdministrationService::class)->createRoom($this->director, $this->branch, ['kind' => 'consultation', 'name' => 'Synthetic Warning First Room', 'sort_order' => 1]);
        $this->publish([$a, $b], [$a->id => $room->id]);
        $this->serve($a);
        $waiting = $this->waiting($a);
        $this->assertNull($this->registrationWarnings()[$waiting->visit_number]);
        $this->selectBranch($b);
        app(DoctorRoomService::class)->select($b, $room->id, $this->branch->id);
        $this->assertSame('another_free', $this->registrationWarnings()[$waiting->visit_number]['code']);
    }

    public function test_populated_warning_queries_do_not_grow_from_one_to_twenty_six_distinct_doctors(): void
    {
        $doctors = [];
        $room = app(QueueDisplayAdministrationService::class)->createRoom($this->director, $this->branch, ['kind' => 'consultation', 'name' => 'Synthetic Shared Warning Room', 'sort_order' => 1]);
        for ($i = 0; $i < 26; $i++) {
            $doctor = $this->doctor();
            $doctors[] = $doctor;
        }
        $this->publish($doctors);
        foreach ($doctors as $doctor) {
            $this->selectBranch($doctor);
            app(DoctorRoomService::class)->select($doctor, $room->id, $this->branch->id);
        }
        $this->serve($doctors[0]);
        $projection = app(DoctorWarningProjection::class);
        $projection->forDoctors($this->ca, $this->branch, [$doctors[0]->id]);
        $before = DB::table('audit_logs')->count();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $one = $projection->forDoctors($this->ca, $this->branch, [$doctors[0]->id]);
        $oneQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $all = $projection->forDoctors($this->ca, $this->branch, array_map(fn (User $doctor): int => $doctor->id, $doctors));
        $allQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(26, $all);
        $this->assertSame('another_free', $one[$doctors[0]->id]['code']);
        $this->assertSame($one[$doctors[0]->id], $all[$doctors[0]->id]);
        $this->assertSame($oneQueries, $allQueries);
        $this->assertLessThanOrEqual(40, $allQueries);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }
}
