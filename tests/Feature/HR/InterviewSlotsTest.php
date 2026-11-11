<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\HiringTemplates;
use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\HiringDeadlines;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Mail\HiringMessageMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-17c interview slots (HR-PLAN 19.6; owner, 2026-11-10: up to "10 working days" ahead, one interview per slot for the
 * "Whole group"). Synthetic `.test` data only; nothing is really sent (Mail::fake).
 */
class InterviewSlotsTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CV-CONTENT\n%%EOF\n";

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(JobApplicationIntake::DISK);
        Mail::fake();
        RateLimiter::clear('hr-apply-all');
        // A Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        foreach (DB::table('branches')->where('organisation_id', $this->hr->organisation_id)->pluck('id') as $branchId) {
            DB::table('hr_branch_working_days')->insert(['organisation_id' => $this->hr->organisation_id, 'branch_id' => $branchId,
                'weekdays' => json_encode([1, 2, 3, 4, 5]), 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('hr_public_holidays')->insert(['organisation_id' => $this->hr->organisation_id, 'branch_id' => $branchId, 'holiday_date' => '2026-11-13',
                'name' => 'Synthetic holiday', 'created_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_slots_cover_ten_working_days_from_the_next_one_without_holidays_or_taken_times(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $days = app(InterviewSlots::class)->available($this->row($id));
        // Wed 11, Thu 12, (Fri 13 holiday), Mon 16 ... Wed 25: ten working days.
        $this->assertSame(['2026-11-11', '2026-11-12', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-23', '2026-11-24', '2026-11-25'],
            array_column($days, 'date'));
        $this->assertSame(['9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '2:00 PM', '3:00 PM', '4:00 PM'], array_column($days[0]['slots'], 'label'));
        $this->assertSame('Wed 11 Nov 2026', $days[0]['label']);

        // The candidate's page offers them; a slot confirmed for someone else is not offered (the whole group).
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p
            ->where('application.stage', 'scheduling')->has('application.interview.days', 10)->where('application.interview.picked', []));
        [$other, $otherToken] = $this->scheduled('Synthetic Assistant Two');
        $first = $days[0]['slots'][0]['start'];
        $this->pick($otherToken, [$first, $days[0]['slots'][1]['start'], $days[0]['slots'][2]['start']])->assertSessionHasNoErrors();
        $this->confirm($other, $first)->assertSessionHasNoErrors();
        $again = app(InterviewSlots::class)->available($this->row($id));
        $this->assertNotContains($first, array_column($again[0]['slots'], 'start'));
        $this->assertCount(6, $again[0]['slots']);
    }

    public function test_the_candidate_picks_exactly_three_offered_times_once_a_round(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 2))->assertSessionHasErrors('starts');
        $this->pick($token, [...array_slice($slots, 0, 2), '2026-11-14T01:00:00Z'])->assertSessionHasErrors('starts'); // a Saturday
        $this->pick($token, [$slots[0], $slots[0], $slots[1]])->assertSessionHasErrors('starts');
        $this->assertSame(0, DB::table('hr_job_interview_picks')->count());

        $this->pick($token, array_slice($slots, 0, 3))->assertRedirect(route('hr-applications.status'))->assertSessionHasNoErrors();
        $pick = DB::table('hr_job_interview_picks')->sole();
        $this->assertSame(1, (int) $pick->round);
        $this->assertSame(array_slice($slots, 0, 3), json_decode((string) $pick->starts, true));
        $this->pick($token, array_slice($slots, 3, 3))->assertSessionHasErrors('starts'); // once a round
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p
            ->where('application.interview.picked', ['Wed 11 Nov 2026, 9:00 AM', 'Wed 11 Nov 2026, 10:00 AM', 'Wed 11 Nov 2026, 11:00 AM'])
            ->where('application.interview.days', []));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.slots_picked')->count());

        // Picks are kept as sent.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_interview_picks')->update(['round' => 2]));
        // Not before Scheduling.
        [, $newToken] = $this->filedWithToken('Synthetic Intern');
        $this->pick($newToken, array_slice($slots, 0, 3))->assertSessionHasErrors('starts');
    }

    public function test_hr_confirms_one_time_and_the_candidate_is_told_with_the_date_time_and_branch(): void
    {
        config(['hr-applications.email_enabled' => true]);
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        // A second candidate picks the same three times before either is confirmed.
        [$other, $otherToken] = $this->scheduled('Synthetic Assistant Two');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->pick($otherToken, array_slice($slots, 0, 3))->assertSessionHasNoErrors();

        // HR sees the three times; a time that is not one of them is refused.
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['status' => 'scheduling']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.applications.rows.0.interview.picks.1.label', 'Wed 11 Nov 2026, 10:00 AM')
            ->where('applications.applications.rows.0.interview.picks.1.taken', false)
            ->where('applications.needsYou.picks.count', 2));
        $this->confirm($id, $slots[5])->assertSessionHasErrors('start');

        $this->confirm($id, $slots[1])->assertSessionHasNoErrors();
        $row = $this->row($id);
        $this->assertSame('interview', $row->status);
        $interview = DB::table('hr_job_interviews')->sole();
        $this->assertSame(['confirmed', 1], [$interview->status, (int) $interview->round]);
        $this->assertSame(['slots_picked', 'interview_confirmed', 'status_changed'], DB::table('hr_job_application_events')->where('application_id', $row->id)
            ->whereIn('action', ['slots_picked', 'interview_confirmed', 'status_changed'])->orderByDesc('id')->limit(3)->pluck('action')->reverse()->values()->all());
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $m): bool => str_contains($m->bodyText, 'Wed 11 Nov 2026 at 10:00 AM')
            && str_contains($m->bodyText, (string) DB::table('branches')->where('id', $interview->branch_id)->value('name')));
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p
            ->where('application.stage', 'interview')->where('application.interview.confirmed', 'Wed 11 Nov 2026, 10:00 AM'));

        // The other candidate's same time is now taken: HR is told plainly, and the drawer marks it.
        $this->confirm($other, $slots[1])->assertSessionHasErrors('start');
        $this->get(route('hr.applications.index', ['status' => 'scheduling']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.applications.rows.0.interview.picks.1.taken', true));

        // PostgreSQL: one confirmed interview per slot for the organisation, and only one of the candidate's picks.
        $otherPick = DB::table('hr_job_interview_picks')->where('application_id', $this->row($other)->id)->sole();
        $insert = fn (string $start) => DB::table('hr_job_interviews')->insert(['organisation_id' => $this->hr->organisation_id,
            'application_id' => $this->row($other)->id, 'pick_id' => $otherPick->id, 'round' => 1, 'branch_id' => $otherPick->branch_id,
            'starts_at' => CarbonImmutable::parse($start), 'status' => 'confirmed', 'confirmed_by_user_id' => $this->hr->id, 'confirmed_at' => now()]);
        $this->assertDatabaseRefuses(fn () => $insert($slots[1]), '23505');
        $this->assertDatabaseRefuses(fn () => $insert('2026-11-30T01:00:00Z'));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_interviews')->where('id', $interview->id)->update(['starts_at' => now()->addDays(3)]));
    }

    public function test_hr_asks_for_new_times_and_cancels_a_confirmed_interview_which_frees_its_slot(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->askAgain($id)->assertSessionHasErrors('start'); // nothing picked yet
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();

        $this->askAgain($id)->assertSessionHasNoErrors();
        $this->assertSame(2, (int) $this->row($id)->interview_round);
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p
            ->where('application.interview.picked', [])->has('application.interview.days', 10)->where('application.messages.0.label', 'Asked for new times'));
        $this->pick($token, array_slice($slots, 3, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[3])->assertSessionHasNoErrors();

        $this->cancel($id, null)->assertSessionHasErrors('reason');
        $this->cancel($id, 'clinic_cannot')->assertSessionHasNoErrors();
        $row = $this->row($id);
        $this->assertSame(['scheduling', 3], [$row->status, (int) $row->interview_round]);
        $this->assertSame('cancelled', DB::table('hr_job_interviews')->value('status'));
        $this->assertContains($slots[3], $this->starts($id)); // the slot is free again
        $this->assertSame(['received', 'screening_passed', 'pick_again', 'interview_confirmed', 'interview_cancelled'],
            DB::table('hr_job_application_messages')->where('application_id', $row->id)->orderBy('id')->pluck('template')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.interview_cancelled')->count());

        // Back to Scheduling is refused while an interview stays confirmed.
        $this->pick($token, array_slice($slots, 6, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[6])->assertSessionHasNoErrors();
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $row->id)->update(['status' => 'scheduling', 'stage_entered_at' => now()->addMinute()]));
    }

    public function test_a_withdrawal_at_interview_frees_the_slot(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[0])->assertSessionHasNoErrors();
        auth()->logout();
        $this->withCookie('kpone_career_status', $token)->post(route('hr-applications.status.withdraw'))->assertSessionHasNoErrors();
        $interview = DB::table('hr_job_interviews')->sole();
        $this->assertSame(['cancelled', 'withdrawn'], [$interview->status, $interview->cancel_reason]);
        [$other] = $this->scheduled('Synthetic Assistant Two');
        $this->assertContains($slots[0], $this->starts($other));
    }

    public function test_a_result_waits_for_the_interview_and_a_new_holiday_blocks_a_confirm(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        // A public holiday added on the picked day after the candidate chose.
        foreach (DB::table('branches')->where('organisation_id', $this->hr->organisation_id)->pluck('id') as $branchId) {
            DB::table('hr_public_holidays')->insert(['organisation_id' => $this->hr->organisation_id, 'branch_id' => $branchId, 'holiday_date' => '2026-11-11',
                'name' => 'Synthetic new holiday', 'created_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->confirm($id, $slots[0])->assertSessionHasErrors('start');
        DB::table('hr_public_holidays')->where('holiday_date', '2026-11-11')->delete();
        $this->confirm($id, $slots[0])->assertSessionHasNoErrors();

        // Passed or Failed waits until the interview has started.
        $move = fn (string $to) => $this->post(route('hr.applications.move', $id), ['to' => $to, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
        $move('passed')->assertSessionHasErrors('to');
        $this->assertSame('interview', $this->row($id)->status);
        $this->travelTo(CarbonImmutable::parse($slots[0])->addMinutes(30));
        $this->selectBranch($this->hr);
        $move('passed')->assertSessionHasNoErrors();
        $this->assertSame('passed', $this->row($id)->status);
    }

    public function test_needs_you_lists_todays_interviews(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[2])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00', $this->branch->timezone));
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $p) => $p
            ->where('applications.needsYou.today.count', 1)->where('applications.needsYou.today.rows.0.why', 'Interview today at 11:00 AM.')
            ->where('applications.needsYou.picks.count', 0));
    }

    public function test_an_unanswered_interview_invitation_is_auto_rejected_after_three_working_days(): void
    {
        // Owner decision 2026-11-11. Invited on Tuesday 10 Nov; working days after it: Wed 11, Thu 12, (Fri 13 holiday),
        // Mon 16 = reminder, Tue 17 = closes. Once the candidate picks, it waits for HR; HR asking again restarts the count.
        [$silent] = $this->scheduled('Synthetic Silent');
        [$picked, $pickedToken] = $this->scheduled('Synthetic Picked');
        [$again, $againToken] = $this->scheduled('Synthetic Again');
        $this->pick($pickedToken, array_slice($this->starts($picked), 0, 3))->assertSessionHasNoErrors();
        $this->pick($againToken, array_slice($this->starts($again), 0, 3))->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', $this->branch->timezone));
        $this->askAgain($again)->assertSessionHasNoErrors(); // counts from Wed 11: Thu 12, Mon 16, Tue 17 = reminder, Wed 18 = closes
        $run = fn (string $at): array => [$this->travelTo(CarbonImmutable::parse($at, $this->branch->timezone)), app(HiringDeadlines::class)->run()][1];

        $this->assertSame([0, 0], $run('2026-11-13 09:00'));
        $this->assertSame([1, 0], $run('2026-11-16 08:00'));
        $reminder = DB::table('hr_job_application_messages')->where('template', 'pick_reminder')->sole();
        $this->assertSame(['mailbox', 'scheduling', (int) $this->row($silent)->interview_round, $this->row($silent)->id], [$reminder->recipient, $reminder->stage,
            (int) $reminder->round, (int) $reminder->application_id]);
        $this->assertSame([1, 1], $run('2026-11-17 08:20')); // the silent one closes; the asked-again one gets its reminder (its new round)
        $this->assertSame(['auto_rejected', 'scheduling', 'scheduling'], [$this->row($silent)->status, $this->row($picked)->status, $this->row($again)->status]);
        $this->assertSame((int) $this->row($again)->interview_round, (int) DB::table('hr_job_application_messages')->where('template', 'pick_reminder')
            ->where('application_id', $this->row($again)->id)->value('round'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.auto_rejected')->count());
        $this->assertSame('auto_rejected', DB::table('hr_job_application_messages')->where('application_id', $this->row($silent)->id)->orderByDesc('id')->value('template'));
        $this->assertSame([0, 1], $run('2026-11-18 08:20'));
        $this->assertSame(['auto_rejected', 'scheduling'], [$this->row($again)->status, $this->row($picked)->status]);
        $this->assertSame([0, 0], $run('2026-11-25 09:00')); // the one with picks waits for HR, however long

        // PostgreSQL: Scheduling closes as Auto-rejected only while the round has no picks.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('public_id', $picked)->update(['status' => 'auto_rejected', 'closed_at' => now()]));
    }

    public function test_the_candidate_is_told_the_last_day_to_choose(): void
    {
        // Owner, 2026-11-11 (#484 follow-up): the pick-times emails give the limit, and the private page shows the last day.
        foreach (['screening_passed' => [], 'pick_again' => [], 'interview_cancelled' => ['date' => 'Tue 17 Nov 2026', 'time' => '9:00 AM']] as $template => $extra) {
            $body = HiringTemplates::render($template, ['position' => 'Synthetic Assistant', ...$extra])['body'];
            $this->assertStringContainsString('Please choose within 3 working days; after that the application closes.', $body, $template);
            $this->assertStringContainsString('Sila pilih dalam masa 3 hari bekerja; selepas itu permohonan ini ditutup.', $body, $template);
        }
        // The number follows the auto-reject setting, so the email and the closing cannot disagree.
        config(['hr-applications.auto_reject_working_days' => 5]);
        $this->assertStringContainsString('Please choose within 5 working days;', HiringTemplates::render('pick_again', ['position' => 'Synthetic Assistant'])['body']);
        config(['hr-applications.auto_reject_working_days' => 3]);

        // Invited on Tuesday 10 Nov: Wed 11, Thu 12, (Fri 13 holiday), Mon 16 is the last day to choose.
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $page = fn () => $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'));
        $page()->assertInertia(fn (Assert $p) => $p->where('application.interview.chooseBy', 'Mon 16 Nov 2026'));
        $this->pick($token, array_slice($this->starts($id), 0, 3))->assertSessionHasNoErrors();
        $page()->assertInertia(fn (Assert $p) => $p->where('application.interview.chooseBy', null)); // picked: HR's turn
        // HR asks for new times on Wed 11: Thu 12, Mon 16, Tue 17.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', $this->branch->timezone));
        $this->askAgain($id)->assertSessionHasNoErrors();
        auth()->logout();
        $page()->assertInertia(fn (Assert $p) => $p->where('application.interview.chooseBy', 'Tue 17 Nov 2026'));
        // The day after, the line goes; picking stays open until the daily check closes the application.
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00', $this->branch->timezone));
        $page()->assertInertia(fn (Assert $p) => $p->where('application.interview.chooseBy', null)->where('application.stage', 'scheduling'));
    }

    /** @return list<string> every slot start offered now */
    private function starts(string $id): array
    {
        return collect(app(InterviewSlots::class)->available($this->row($id)))->flatMap(fn (array $d): array => array_column($d['slots'], 'start'))->values()->all();
    }

    private function row(string $id): \stdClass
    {
        return DB::table('hr_job_applications')->where('public_id', $id)->sole();
    }

    /** @return array{0:string, 1:string} an application at Scheduling and its private link token */
    private function scheduled(string $title): array
    {
        [$id, $token] = $this->filedWithToken($title);
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.move', $id), ['to' => 'phone_screening', 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.applications.screen', $id), ['outcome' => 'pass', 'rating' => 4, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('scheduling', $this->row($id)->status);
        auth()->logout();

        return [$id, $token];
    }

    /** @return array{0:string, 1:string} */
    private function filedWithToken(string $title): array
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.positions.store'), ['title' => $title, 'branch_id' => 'any', 'position_type' => 'ca', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        if (! DB::table('hr_job_application_settings')->exists()) {
            $this->post(route('hr.applications.retention'), ['retention_days' => 3650, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $position = (string) DB::table('hr_job_positions')->where('title', $title)->value('public_id');
        config(['hr-applications.enabled' => true]);
        auth()->logout();
        // Each send in its own minute, the form opened 30 seconds before (kept within the start day).
        $this->travel(1)->minutes();
        $formToken = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();
        $this->post(route('hr-applications.submit'), ['form_token' => $formToken, 'position' => $position, 'name' => 'Synthetic Applicant',
            'phone' => '012-345 6789', 'email' => 'applicant@example.test', 'privacy_accepted' => '1', 'website' => '', 'preferred_branch' => 'any',
            'start_date' => now()->addDays(14)->toDateString(), 'education' => 'degree', 'years_experience' => '2', 'languages' => ['malay', 'english'],
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', self::PDF)])->assertRedirect(route('hr-applications.thanks'));
        $link = null;
        $this->get(route('hr-applications.thanks'))->assertInertia(function (Assert $page) use (&$link): void {
            $link = $page->toArray()['props']['statusLink'];
        });
        $token = Str::after((string) $link, '#');
        $this->assertTrue(CandidateLink::valid($token));

        return [(string) DB::table('hr_job_applications')->orderByDesc('id')->value('public_id'), $token];
    }

    /** @param list<string> $starts */
    private function pick(string $token, array $starts): TestResponse
    {
        auth()->logout();

        return $this->withCookie('kpone_career_status', $token)->post(route('hr-applications.status.picks'), ['starts' => $starts]);
    }

    private function confirm(string $id, string $start): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.interview.confirm', $id), ['start' => $start, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function askAgain(string $id): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.interview.ask-again', $id), ['expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function cancel(string $id, ?string $reason): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.interview.cancel', $id), ['reason' => $reason, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function assertDatabaseRefuses(callable $write, string $code = 'P0001'): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail('PostgreSQL accepted a write it must refuse.');
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
