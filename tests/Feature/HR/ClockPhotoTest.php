<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockPhoto;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Photos\ClockPhotoFiles;
use App\Domain\HR\Attendance\Photos\ClockPhotoLabels;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ClockPhotoFixture;

/**
 * HR-PHOTO (owner, 2026-11-12, relayed by B.E.R.N.I.S.S: "B, grace yes, OK photo test"; option 2 "Camera app"). Every
 * button press carries a live photo; other people's photos open only behind an audited Show. The clock is frozen in
 * Kuala Lumpur, so every date is branch-local. Photos are synthetic noise, never a face.
 */
class ClockPhotoTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ClockPhotoService::DISK); // the encrypted files of this class's presses only
        $this->travelTo(CarbonImmutable::parse('2027-03-08 07:00', $this->branch->timezone));
    }

    public function test_a_press_needs_a_photo_and_a_fresh_capture_token_of_the_same_kind(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->press('in', ['capture_token' => ClockPhotoFixture::token($this, 'in')])->assertSessionHasErrors(['photo' => ClockPhotoFiles::NEEDED]);
        $this->press('in', ['photo' => ClockPhotoFixture::upload()])->assertSessionHasErrors(['photo' => 'The camera was open too long. Take the photo again.']);
        $this->press('in', ['photo' => ClockPhotoFixture::upload(), 'capture_token' => ClockPhotoFixture::token($this, 'out')])
            ->assertSessionHasErrors(['photo' => 'The camera was open too long. Take the photo again.']);
        $stale = ClockPhotoFixture::token($this, 'in');
        $this->travel(ClockPhotoService::TOKEN_MINUTES + 1)->minutes();
        $this->press('in', ['photo' => ClockPhotoFixture::upload(), 'capture_token' => $stale])
            ->assertSessionHasErrors(['photo' => 'The camera was open too long. Take the photo again.']);
        // Someone else's token never works for you.
        $other = $this->actor('ca');
        $this->selectBranch($other);
        $theirs = ClockPhotoFixture::token($this, 'in');
        $this->selectBranch($ca);
        $this->press('in', ['photo' => ClockPhotoFixture::upload(), 'capture_token' => $theirs])
            ->assertSessionHasErrors(['photo' => 'The camera was open too long. Take the photo again.']);
        // Not an image at all.
        $this->press('in', ['photo' => UploadedFile::fake()->createWithContent('photo.jpg', 'not a photo'), 'capture_token' => ClockPhotoFixture::token($this, 'in')])
            ->assertSessionHasErrors(['photo' => ClockPhotoFiles::UNREADABLE]);
        $this->assertSame(0, ClockSession::query()->count());
        $this->assertSame(0, ClockPhoto::query()->count());
        $this->assertSame([], Storage::disk(ClockPhotoService::DISK)->allFiles('hr-clock-photos'));

        $token = ClockPhotoFixture::token($this, 'in');
        $this->press('in', ['photo' => ClockPhotoFixture::upload(), 'capture_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame(1, ClockSession::query()->count());
        // A token is good for one press.
        $this->travel(2)->minutes();
        $this->press('out', ['photo' => ClockPhotoFixture::upload(), 'capture_token' => $token])
            ->assertSessionHasErrors(['photo' => 'The camera was open too long. Take the photo again.']);
        $this->assertSame('open', ClockSession::query()->sole()->status);
    }

    public function test_the_photo_is_re_encoded_encrypted_and_never_counts_twice(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $bytes = ClockPhotoFixture::bytes(1600, 1200);
        $this->press('in', ['photo' => UploadedFile::fake()->createWithContent('photo.jpg', $bytes), 'capture_token' => ClockPhotoFixture::token($this, 'in')])
            ->assertSessionHasNoErrors();
        $photo = ClockPhoto::query()->sole();
        $this->assertSame([1280, 960], [$photo->width, $photo->height]); // scaled to 1280 px on the long edge
        $stored = (string) Storage::disk(ClockPhotoService::DISK)->get($photo->stored_path);
        $this->assertStringNotContainsString("\xFF\xD8\xFF", substr($stored, 0, 16)); // encrypted at rest
        $content = Crypt::decryptString($stored);
        $this->assertSame("\xFF\xD8\xFF", substr($content, 0, 3));
        $this->assertNotSame($bytes, $content);
        $this->assertSame(hash('sha256', $content), $photo->sha256);
        $this->assertStringNotContainsString('Exif', $content);
        $thumb = getimagesizefromstring(Crypt::decryptString((string) Storage::disk(ClockPhotoService::DISK)->get($photo->thumb_path)));
        $this->assertNotFalse($thumb);
        $this->assertSame(240, max($thumb[0], $thumb[1]));
        $this->assertStringStartsWith("hr-clock-photos/{$this->organisation->id}/", $photo->stored_path);
        $this->assertStringNotContainsString('photo.jpg', $photo->stored_path);

        // The same picture sent again is refused (a saved photo sent later).
        $this->travel(2)->hours();
        $this->press('out', ['photo' => UploadedFile::fake()->createWithContent('photo.jpg', $bytes), 'capture_token' => ClockPhotoFixture::token($this, 'out')])
            ->assertSessionHasErrors(['photo' => 'This photo was used before. Take a new one.']);
        $this->assertSame('open', ClockSession::query()->sole()->status);
        $this->assertCount(2, Storage::disk(ClockPhotoService::DISK)->allFiles('hr-clock-photos')); // the refused press left nothing
        // Audit holds no photo data.
        $this->assertSame(['local_date'], array_keys(json_decode((string) DB::table('audit_logs')->where('event', 'hr.attendance.clocked_in')->value('metadata'), true)));
    }

    public function test_own_photos_open_directly_and_anyone_elses_only_for_a_reviewer_after_an_audit_row(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->press('in', ClockPhotoFixture::press($this, 'in'))->assertSessionHasNoErrors();
        $photo = ClockPhoto::query()->sole();

        foreach (['full', 'thumb'] as $size) {
            $response = $this->get(route('hr.attendance.photo', [$photo->public_id, $size]))->assertOk();
            $response->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        }
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'hr.attendance.photo_shown')->count()); // own: no Show step

        $colleague = $this->actor('ca');
        $this->selectBranch($colleague);
        $this->get(route('hr.attendance.photo', [$photo->public_id, 'full']))->assertForbidden();
        $this->get(route('hr.attendance.photo', [(string) Str::uuid(), 'full']))->assertNotFound();
        $this->get('/hr/attendance/photos/'.$photo->public_id.'/original')->assertNotFound();

        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.photo', [$photo->public_id, 'thumb']), ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'hr.attendance.photo_shown')->count()); // a cross-site link records nothing
        $this->get(route('hr.attendance.photo', [$photo->public_id, 'thumb']))->assertOk();
        $this->get(route('hr.attendance.photo', [$photo->public_id, 'full']))->assertOk();
        $rows = DB::table('audit_logs')->where('event', 'hr.attendance.photo_shown')->orderBy('id')->get();
        $this->assertSame([['photo_id' => $photo->id, 'size' => 'thumb'], ['photo_id' => $photo->id, 'size' => 'full']],
            $rows->map(fn ($r): mixed => json_decode((string) $r->metadata, true))->all());
        $this->assertSame([$hr->id, $hr->id], $rows->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_postgres_keeps_photos_unchanged_and_refuses_a_button_press_without_one(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->press('in', ClockPhotoFixture::press($this, 'in'))->assertSessionHasNoErrors();
        $photo = ClockPhoto::query()->sole();
        $session = ClockSession::query()->sole();
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_photos')->where('id', $photo->id)->update(['bytes' => 1]));
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_photos')->where('id', $photo->id)->delete());
        $this->assertRefused('P0001', function () use ($photo): void {
            DB::statement("SELECT set_config('kpone.hr_attendance_purge', 'on', true)"); // marked, but not 7 years old
            DB::table('hr_clock_photos')->where('id', $photo->id)->delete();
        });
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_clock_photos'));
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_capture_tokens')->where('id', $photo->capture_token_id)->update(['used_at' => now()->utc()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_capture_tokens')->where('id', $photo->capture_token_id)->delete());
        // A button press with no photo, written straight to the table, is refused; a correction never needs one.
        $this->assertRefused('23514', fn () => DB::table('hr_clock_events')->insert(['organisation_id' => $this->organisation->id, 'clock_session_id' => $session->id,
            'kind' => 'out', 'occurred_at' => now()->utc(), 'method' => 'button', 'recorded_by_user_id' => $ca->id, 'created_at' => now()->utc()]));
        // A photo with someone else's session, or a token of the wrong kind, is refused.
        $other = $this->actor('ca');
        $token = DB::table('hr_clock_capture_tokens')->insertGetId(['organisation_id' => $this->organisation->id, 'user_id' => $other->id, 'kind' => 'out',
            'token_hash' => hash('sha256', 'x'), 'issued_at' => now()->utc(), 'expires_at' => now()->utc()->addMinutes(5)]);
        $this->assertRefused('23514', fn () => DB::table('hr_clock_photos')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id,
            'user_id' => $other->id, 'clock_session_id' => $session->id, 'kind' => 'out', 'capture_token_id' => $token, 'stored_path' => 'x', 'bytes' => 1,
            'thumb_path' => 'y', 'thumb_bytes' => 1, 'width' => 1, 'height' => 1, 'sha256' => str_repeat('a', 64), 'created_at' => now()->utc()]));
        $this->assertSame(1, ClockPhoto::query()->count());
    }

    public function test_photos_go_with_the_seven_year_purge_and_the_backstop_tidies_tokens_and_stray_files(): void
    {
        // PostgreSQL checks the 7 years against its own clock, so this part works in real time.
        $real = CarbonImmutable::createFromTimestamp(time(), 'UTC');
        $this->travelTo($real->subYears(7)->subDays(30));
        $ca = $this->actor('ca'); // works at the branch from 7 years ago
        $this->selectBranch($ca);
        $this->press('in', ClockPhotoFixture::press($this, 'in'))->assertSessionHasNoErrors();
        $this->travelTo($real->subYears(7)->subDays(30)->addHours(6));
        $this->press('out', ClockPhotoFixture::press($this, 'out'))->assertSessionHasNoErrors();
        $this->travelBack();
        $this->press('in', ClockPhotoFixture::press($this, 'in'))->assertSessionHasNoErrors();
        $old = ClockPhoto::query()->orderBy('id')->take(2)->get();
        $kept = ClockPhoto::query()->orderByDesc('id')->firstOrFail();
        $disk = Storage::disk(ClockPhotoService::DISK);
        $this->assertCount(6, $disk->allFiles('hr-clock-photos'));

        $this->artisan('hr-attendance:purge')->assertSuccessful();
        $this->assertSame([$kept->id], ClockPhoto::query()->pluck('id')->all());
        foreach ($old as $photo) {
            $this->assertFalse($disk->exists($photo->stored_path));
            $this->assertFalse($disk->exists($photo->thumb_path));
        }
        $this->assertTrue($disk->exists($kept->stored_path) && $disk->exists($kept->thumb_path));

        // Backstop: tokens a day past running out go (a used one stays while its photo does); files no row points at go
        // once a day old.
        $this->selectBranch($ca);
        ClockPhotoFixture::token($this, 'out'); // unused, still fresh
        DB::table('hr_clock_capture_tokens')->insert(['organisation_id' => $this->organisation->id, 'user_id' => $ca->id, 'kind' => 'in',
            'token_hash' => hash('sha256', 'old'), 'issued_at' => $real->subDays(3), 'expires_at' => $real->subDays(3)->addMinutes(5)]);
        $disk->put("hr-clock-photos/{$this->organisation->id}/stray.bin", 'x');
        $disk->put("hr-clock-photos/{$this->organisation->id}/fresh.bin", 'x');
        touch($disk->path("hr-clock-photos/{$this->organisation->id}/stray.bin"), $real->subDays(2)->getTimestamp());
        $tokensBefore = DB::table('hr_clock_capture_tokens')->count();
        $this->artisan('hr-clock-photos:purge')->expectsOutputToContain('leftover photo files')->assertSuccessful();
        $this->assertFalse($disk->exists("hr-clock-photos/{$this->organisation->id}/stray.bin"));
        $this->assertTrue($disk->exists("hr-clock-photos/{$this->organisation->id}/fresh.bin"));
        $this->assertTrue($disk->exists($kept->stored_path));
        $this->assertSame(0, DB::table('hr_clock_capture_tokens')->where('token_hash', hash('sha256', 'old'))->count());
        // The old token and the two used ones whose photos the purge removed go; the fresh one and the kept photo's stay.
        $this->assertSame($tokensBefore - 3, DB::table('hr_clock_capture_tokens')->count());
        $this->assertSame(1, DB::table('hr_clock_capture_tokens')->where('id', $kept->capture_token_id)->count());
        $this->assertSame(['30 1 * * *'], collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'hr-clock-photos:purge'))->pluck('expression')->values()->all());
    }

    public function test_pages_show_your_own_photo_and_keep_anyone_elses_behind_show(): void
    {
        $ca = $this->actor('ca');
        $hr = $this->actor('hr_manager');
        $this->selectBranch($ca);
        $this->press('in', ClockPhotoFixture::press($this, 'in'))->assertSessionHasNoErrors();
        $photo = ClockPhoto::query()->sole();
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.my.week', fn ($week): bool => collect($week)->contains(fn (array $r): bool => ($r['photos']['in']['id'] ?? null) === $photo->public_id
                && $r['photos']['in']['own'] === true && $r['photos']['in']['needsShow'] === false && $r['photos']['out'] === null)));
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => '2027-03-08']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.review.rows', fn ($rows): bool => collect($rows)->contains(fn (array $r): bool => ($r['photos']['in']['id'] ?? null) === $photo->public_id
                && $r['photos']['in']['needsShow'] === true && $r['photos']['in']['own'] === false)));
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'hr.attendance.photo_shown')->count()); // listing opens nothing

        // A time added by a correction has no photo and says so.
        $session = ClockSession::query()->sole();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 18:00', $this->branch->timezone));
        $this->selectBranch($hr);
        $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('hr.attendance.corrections.store'), ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T17:00',
            'reason_code' => 'forgot', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $correction = AttendanceCorrection::query()->sole();
        $this->selectBranch($hr);
        $this->post(route('hr.attendance.corrections.decide', [$correction->public_id, 'approve']), ['expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $labels = app(ClockPhotoLabels::class)->forSessions(ClockSession::query()->get(), $hr);
        $this->assertSame(ClockPhotoLabels::BY_CORRECTION, $labels[$session->public_id]['out']['label']);
        $this->assertSame($photo->public_id, $labels[$session->public_id]['in']['id']);
        $this->assertNull($labels[$session->public_id]['out']['thumbUrl']);
    }

    public function test_the_camera_dialog_gets_the_time_and_a_chip_using_hr_grace_minutes(): void
    {
        $ca = $this->actor('ca');
        $other = $this->actor('ca');
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->selectBranch($hr);
        $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->roster($hr, $director, [[$ca, '08:00', '16:00', 60]]);

        // 8:12 is 12 minutes after the shift starts: past the 5 grace minutes, the same as Review's "Late 12 min".
        $this->travelTo(CarbonImmutable::parse('2027-03-08 08:12', $this->branch->timezone));
        $this->selectBranch($ca);
        $this->postJson(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, 'in')])->assertOk()
            ->assertExactJson(['kind' => 'in', 'title' => 'Clocked in', 'time' => '8:12 AM', 'branch' => $this->branch->name, 'chip' => ['code' => 'late', 'label' => 'Late 12 min']]);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 15:57', $this->branch->timezone));
        $this->postJson(route('hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, 'out')])->assertOk()
            ->assertJsonPath('chip', ['code' => 'on_time', 'label' => 'On time'])->assertJsonPath('title', 'Clocked out')->assertJsonPath('time', '3:57 PM');
        $this->get(route('hr.attendance.index', ['tab' => 'my']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.my.week', fn ($week): bool => collect($week)->contains(fn (array $r): bool => collect($r['flags'])->contains('label', 'Late 12 min'))));

        // No approved shift: "No shift set". A refused press answers 422 with the field's message.
        $this->selectBranch($other);
        $this->postJson(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid(), 'capture_token' => ClockPhotoFixture::token($this, 'in')])
            ->assertUnprocessable()->assertJsonPath('errors.photo.0', ClockPhotoFiles::NEEDED);
        $this->postJson(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, 'in')])->assertOk()
            ->assertJsonPath('chip', ['code' => 'no_shift', 'label' => 'No shift set']);
    }

    public function test_the_tv_account_cannot_take_a_capture_token(): void
    {
        $this->selectBranch($this->actor('queue_display'));
        $this->postJson(route('hr.attendance.capture'), ['kind' => 'in'])->assertForbidden();
        $this->selectBranch($this->actor('ca'));
        $this->postJson(route('hr.attendance.capture'), ['kind' => 'sideways'])->assertUnprocessable();
        $response = $this->postJson(route('hr.attendance.capture'), ['kind' => 'in'])->assertOk()->assertJsonStructure(['token', 'expiresAt']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('hr_clock_capture_tokens')->count());
        $this->assertNotSame(DB::table('hr_clock_capture_tokens')->value('token_hash'), $this->postJson(route('hr.attendance.capture'), ['kind' => 'in'])->json('token'));
    }

    /** @param array<string, mixed> $input */
    private function press(string $kind, array $input): TestResponse
    {
        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param list<array{User, string, string, int}> $shifts */
    private function roster(User $hr, User $director, array $shifts): void
    {
        $week = '2027-03-08';
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $week, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        foreach ($shifts as [$person, $start, $end, $break]) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $person->id, 'date' => $week, 'start_time' => $start, 'end_time' => $end,
                'break_minutes' => $break, 'session_type' => 'full_day', 'branch_room_id' => null, 'branch_id' => $this->branch->id, 'week' => $week,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function assertRefused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
