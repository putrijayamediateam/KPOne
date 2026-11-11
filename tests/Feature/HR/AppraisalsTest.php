<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Appraisals\AppraisalPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-10b appraisal (HR-PLAN 20.4; owner, 2026-11-10). Synthetic data only.
 */
class AppraisalsTest extends VisitTestCase
{
    private const COMMENT = 'SYNTHETIC-APPRAISAL-COMMENT shows good teamwork';

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_grants_are_exact_and_staff_see_only_their_own_sent_appraisals(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(AppraisalPermissions::MANAGE));
        $this->assertCount(11, $holders(AppraisalPermissions::VIEW_OWN));
        $this->assertNotContains('queue_display', $holders(AppraisalPermissions::VIEW_OWN));
        foreach (array_keys(AppraisalPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.appraisals.index'))->assertForbidden();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.appraisals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Appraisals')
            ->where('appraisals.tabs', ['mine'])->where('appraisals.cycles', [])->where('appraisals.criteria', null));
        $this->post(route('hr.appraisals.criteria.store'), ['criteria' => [['title' => 'Teamwork']], 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_hr_rates_sends_the_person_comments_and_a_director_signs_off(): void
    {
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $this->criteria([['title' => 'Teamwork', 'description' => 'Works well with others'], ['title' => 'Punctuality']]);
        $cycle = $this->cycle([$worker]);
        $appraisal = DB::table('hr_appraisals')->sole();
        $this->assertSame('draft', $appraisal->status);

        // The person does not see a draft.
        $this->selectBranch($staff);
        $this->get(route('hr.appraisals.show', $appraisal->public_id))->assertNotFound();
        $this->get(route('hr.appraisals.index'))->assertInertia(fn (Assert $page) => $page->where('appraisals.mine', []));

        $this->selectBranch($this->hr);
        $this->rate($appraisal, [[4, 'Helps the team'], [null, '']], false, 1)->assertSessionHasNoErrors();
        $this->rate($appraisal, [[4, 'Helps the team'], [null, '']], true, 2)->assertSessionHasErrors('ratings.1.rating');
        $this->rate($appraisal, [[6, ''], [3, '']], true, 2)->assertSessionHasErrors('ratings.0.rating');
        $this->rate($appraisal, [[4, self::COMMENT], [3, 'On time most days']], true, 2)->assertSessionHasNoErrors();
        $sent = DB::table('hr_appraisals')->sole();
        $this->assertSame(['sent', $this->hr->id], [$sent->status, (int) $sent->appraiser_user_id]);
        $this->assertStringNotContainsString('SYNTHETIC-APPRAISAL', (string) $sent->ratings_encrypted);
        $this->assertStringNotContainsString('SYNTHETIC-APPRAISAL', DB::table('audit_logs')->get()->toJson());
        $summary = json_decode(Crypt::decryptString((string) $sent->summary_encrypted), true);
        $this->assertSame(['asOf' => '2026-11-10', 'partial' => true, 'rulesMissing' => true, 'late' => 0, 'lateMinutes' => 0, 'noClockIn' => 0, 'noClockOut' => 0,
            'leaveDays' => 0, 'overtimeMinutes' => 0], $summary);
        $this->rate($appraisal, [[5, ''], [5, '']], false, 3)->assertSessionHasErrors('appraisal'); // sent: no more changes

        // An HR Manager does not sign off; the person reads it and comments once.
        $this->post(route('hr.appraisals.sign-off', $appraisal->public_id), ['expected_lock_version' => 3, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('appraisal');
        $this->selectBranch($staff);
        $this->get(route('hr.appraisals.show', $appraisal->public_id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Appraisal')
            ->where('appraisal.own', true)->where('appraisal.ratings.0.rating', 4)->where('appraisal.can.comment', true)->where('appraisal.can.edit', false)
            ->where('appraisal.summaryLive', false));
        $this->post(route('hr.appraisals.comment', $appraisal->public_id), ['comment' => 'Thank you, I agree.', 'expected_lock_version' => 3, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->post(route('hr.appraisals.comment', $appraisal->public_id), ['comment' => 'Again', 'expected_lock_version' => 4, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('comment');

        $this->selectBranch($this->director);
        $this->post(route('hr.appraisals.sign-off', $appraisal->public_id), ['expected_lock_version' => 4, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('signed_off', DB::table('hr_appraisals')->value('status'));
        $this->get(route('hr.appraisals.index', ['tab' => 'cycles', 'cycle' => $cycle]))->assertInertia(fn (Assert $page) => $page
            ->where('appraisals.cycles.0.signedOff', 1)->where('appraisals.cycle.people.0.status', 'signed_off'));
        $this->assertSame(['hr.appraisals.criteria_saved', 'hr.appraisals.cycle_opened', 'hr.appraisals.saved', 'hr.appraisals.sent', 'hr.appraisals.commented', 'hr.appraisals.signed_off'],
            DB::table('audit_logs')->where('event', 'like', 'hr.appraisals.%')->where('event', '<>', 'hr.appraisals.opened')->orderBy('id')->pluck('event')->all());
        // Every open is recorded, with ids only: never a rating or a comment.
        $opens = DB::table('audit_logs')->where('event', 'hr.appraisals.opened')->get();
        $this->assertSame(1, $opens->count()); // the person's own open after send; the draft 404 records nothing
        foreach ($opens as $open) {
            $this->assertStringNotContainsString(self::COMMENT, (string) json_encode($open));
            $this->assertStringNotContainsString('Thank you', (string) json_encode($open));
        }
    }

    public function test_the_summary_counts_the_cycles_approved_leave_and_overtime(): void
    {
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $this->criteria([['title' => 'Teamwork']]);
        $this->cycle([$worker], '2026-10-01', '2026-10-31');
        // Synthetic approved leave: 2 days inside the cycle (4 halves), counted as stored.
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $staff->organisation_id, 'user_id' => $staff->id,
            'leave_type_id' => $this->leaveType(), 'branch_id' => $this->branch->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'start_part' => 'full',
            'end_part' => 'full', 'slot_start' => '2026-10-05 00:00:00', 'slot_end' => '2026-10-07 00:00:00', 'year' => 2026, 'halves' => 4, 'status' => 'pending',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->hr->id, 'decided_at' => now(), 'lock_version' => 2]);
        $this->selectBranch($this->hr);
        $appraisal = DB::table('hr_appraisals')->sole();
        $this->get(route('hr.appraisals.show', $appraisal->public_id))->assertInertia(fn (Assert $page) => $page->where('appraisal.summaryLive', true)
            ->where('appraisal.summary.leaveDays', 2)->where('appraisal.summary.partial', false)->where('appraisal.summary.asOf', '2026-10-31')
            ->where('appraisal.summary.rulesMissing', true));
        // Leave that runs past the cycle is counted on the branch's working days; with none set up it cannot be counted.
        $late = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $staff->organisation_id, 'user_id' => $staff->id,
            'leave_type_id' => $this->leaveType(), 'branch_id' => $this->branch->id, 'start_date' => '2026-10-30', 'end_date' => '2026-11-03', 'start_part' => 'full',
            'end_part' => 'full', 'slot_start' => '2026-10-30 00:00:00', 'slot_end' => '2026-11-04 00:00:00', 'year' => 2026, 'halves' => 6, 'status' => 'pending',
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $late)->update(['status' => 'approved', 'decided_by_user_id' => $this->hr->id, 'decided_at' => now(), 'lock_version' => 2]);
        $this->get(route('hr.appraisals.show', $appraisal->public_id))->assertInertia(fn (Assert $page) => $page->where('appraisal.summary.leaveDays', null));
    }

    public function test_who_may_appraise_whom(): void
    {
        $otherHr = $this->actor('hr_manager');
        $hrFile = $this->file($otherHr, $this->director);
        $ownFile = $this->file($this->hr, $this->director);
        $this->criteria([['title' => 'Teamwork']]);
        $this->cycle([$hrFile, $ownFile]);
        $rows = DB::table('hr_appraisals')->orderBy('id')->get()->keyBy('worker_id');

        $this->selectBranch($this->hr);
        $this->rate($rows[$ownFile->id], [[4, '']], false, 1)->assertSessionHasErrors('appraisal'); // yourself
        $this->rate($rows[$hrFile->id], [[4, '']], false, 1)->assertSessionHasErrors('appraisal'); // an HR Manager: only a Director
        $this->get(route('hr.appraisals.show', $rows[$hrFile->id]->public_id))->assertForbidden();
        $this->selectBranch($this->director);
        $this->rate($rows[$hrFile->id], [[4, '']], true, 1)->assertSessionHasNoErrors();
        // Sent before the period ended: the summary says it is partial.
        $this->get(route('hr.appraisals.show', $rows[$hrFile->id]->public_id))->assertInertia(fn (Assert $page) => $page->where('appraisal.summary.partial', true)
            ->where('appraisal.summary.asOf', '2026-11-10')->where('appraisal.can.signOff', false)->whereNot('appraisal.signOffFrom', null));
        // No comment yet: the person has 7 days before a Director may sign off.
        $this->post(route('hr.appraisals.sign-off', $rows[$hrFile->id]->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('appraisal');
        $this->travel(7)->days();
        $this->get(route('hr.appraisals.show', $rows[$hrFile->id]->public_id))->assertInertia(fn (Assert $page) => $page->where('appraisal.can.signOff', true)
            ->where('appraisal.signOffFrom', null));
        // A Director appraised the HR Manager, and a Director signs off: allowed (the one-Director case).
        $this->post(route('hr.appraisals.sign-off', $rows[$hrFile->id]->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_appraisals')->where('status', 'signed_off')->count());
        $this->assertGreaterThanOrEqual(3, DB::table('audit_logs')->where('event', 'authorization.denied')->count());
    }

    public function test_criteria_and_cycles_are_validated_and_closing_stops_drafts(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.appraisals.criteria.store'), ['criteria' => [], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('criteria');
        $this->post(route('hr.appraisals.criteria.store'), ['criteria' => array_fill(0, 16, ['title' => 'Same']), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('criteria');
        $this->post(route('hr.appraisals.criteria.store'), ['criteria' => [['title' => 'Teamwork'], ['title' => 'teamwork']], 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('criteria.1.title');
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $this->selectBranch($this->hr);
        $this->post(route('hr.appraisals.cycles.store'), ['name' => 'Year 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'worker_ids' => [$worker->public_id], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('criteria');
        $this->criteria([['title' => 'Teamwork']]);
        $this->criteria([['title' => 'Teamwork']], 'criteria'); // nothing changed
        $this->post(route('hr.appraisals.cycles.store'), ['name' => 'Year 2026', 'period_start' => '2026-12-31', 'period_end' => '2026-01-01',
            'worker_ids' => [$worker->public_id], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('period_end');
        $locum = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => 'Synthetic Locum', 'phone' => '012-345 6789', 'idempotency_key' => (string) Str::uuid()]);
        $this->post(route('hr.appraisals.cycles.store'), ['name' => 'Year 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'worker_ids' => [$locum->public_id], 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('worker_ids');
        $cycle = $this->cycle([$worker]);
        $row = DB::table('hr_appraisal_cycles')->sole();
        $this->post(route('hr.appraisals.cycles.close', $cycle), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->rate(DB::table('hr_appraisals')->sole(), [[4, '']], false, 1)->assertSessionHasErrors('appraisal');
        $this->assertSame('closed', DB::table('hr_appraisal_cycles')->where('id', $row->id)->value('status'));
    }

    public function test_postgresql_keeps_the_history(): void
    {
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $this->criteria([['title' => 'Teamwork']]);
        $this->cycle([$worker]);
        $appraisal = DB::table('hr_appraisals')->sole();
        $this->selectBranch($this->hr);
        $this->rate($appraisal, [[4, '']], true, 1)->assertSessionHasNoErrors();
        $sent = DB::table('hr_appraisals')->sole();

        $this->assertRefused('P0001', fn () => DB::table('hr_appraisals')->where('id', $sent->id)->update(['ratings_encrypted' => Crypt::encryptString('[]'), 'lock_version' => 3]));
        $this->assertRefused('P0001', fn () => DB::table('hr_appraisals')->where('id', $sent->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_appraisal_criteria_versions')->update(['version' => 9]));
        $this->assertRefused('P0001', fn () => DB::table('hr_appraisal_cycles')->update(['name' => 'Renamed', 'lock_version' => 2]));
        // The person is never their own appraiser or signer.
        $this->assertRefused('23514', fn () => DB::table('hr_appraisals')->where('id', $sent->id)->update(['status' => 'signed_off', 'signed_off_by_user_id' => $staff->id,
            'signed_off_at' => now(), 'lock_version' => 3]));
        // Only a Director signs off, and not inside the 7-day comment window without a comment.
        $this->assertRefused('23514', fn () => DB::table('hr_appraisals')->where('id', $sent->id)->update(['status' => 'signed_off', 'signed_off_by_user_id' => $this->hr->id,
            'signed_off_at' => now(), 'lock_version' => 3]));
        $this->assertRefused('23514', fn () => DB::table('hr_appraisals')->where('id', $sent->id)->update(['status' => 'signed_off', 'signed_off_by_user_id' => $this->director->id,
            'signed_off_at' => now(), 'lock_version' => 3]));
        // A Director's or HR Manager's appraisal is sent only by a Director.
        $otherHr = $this->actor('hr_manager');
        $this->cycle([$this->file($otherHr, $this->director)]);
        $hrDraft = DB::table('hr_appraisals')->where('user_id', $otherHr->id)->sole();
        $this->assertRefused('23514', fn () => DB::table('hr_appraisals')->where('id', $hrDraft->id)->update(['status' => 'sent', 'appraiser_user_id' => $this->hr->id,
            'ratings_encrypted' => Crypt::encryptString('[{"rating":4,"comment":""}]'), 'summary_encrypted' => Crypt::encryptString('{}'), 'sent_at' => now(), 'lock_version' => 2]));
        $other = $this->actor('ca');
        $otherFile = $this->file($other);
        $this->cycle([$otherFile]);
        $draft = DB::table('hr_appraisals')->where('user_id', $other->id)->sole();
        $this->assertRefused('23514', fn () => DB::table('hr_appraisals')->where('id', $draft->id)->update(['appraiser_user_id' => $other->id, 'lock_version' => 2]));
        $this->assertRefused('P0001', fn () => DB::table('hr_appraisals')->where('id', $draft->id)->update(['status' => 'signed_off', 'lock_version' => 2]));
    }

    // ---- fixture ----

    /** @param list<array<string, string>> $criteria */
    private function criteria(array $criteria, ?string $error = null): void
    {
        $this->selectBranch($this->hr);
        $response = $this->post(route('hr.appraisals.criteria.store'), ['criteria' => $criteria, 'idempotency_key' => (string) Str::uuid()]);
        $error === null ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors($error);
    }

    /** @param list<Worker> $workers */
    private function cycle(array $workers, string $start = '2026-01-01', string $end = '2026-12-31'): string
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.appraisals.cycles.store'), ['name' => 'Synthetic year 2026', 'period_start' => $start, 'period_end' => $end,
            'worker_ids' => array_map(fn (Worker $w): string => $w->public_id, $workers), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return (string) DB::table('hr_appraisal_cycles')->orderByDesc('id')->value('public_id');
    }

    /**
     * @param  list<array{0:int|null, 1:string}>  $ratings
     * @return TestResponse<Response>
     */
    private function rate(\stdClass $appraisal, array $ratings, bool $send, int $version): TestResponse
    {
        return $this->post(route('hr.appraisals.save', $appraisal->public_id), ['ratings' => array_map(fn (array $r): array => ['rating' => $r[0], 'comment' => $r[1]], $ratings),
            'send' => $send, 'expected_lock_version' => $version, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function file(User $subject, ?User $manager = null): Worker
    {
        return app(StaffFileService::class)->openFile($manager ?? $this->hr, ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function leaveType(): int
    {
        $existing = DB::table('hr_leave_types')->where('organisation_id', $this->organisation->id)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('hr_leave_types')->insertGetId(['organisation_id' => $this->organisation->id, 'code' => 'SYN', 'name' => 'Synthetic leave',
            'is_paid' => true, 'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id,
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
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
