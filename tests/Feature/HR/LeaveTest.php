<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Leave\Services\LeaveEvidenceService;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-2a leave (B.E.R.N.I.S.S for the owner, 2026-11-05). The clock is frozen on a Monday morning in Kuala Lumpur, so
 * every date is branch-local and never the UTC clock (BASELINE section 6 item 21).
 */
class LeaveTest extends VisitTestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2027-03-01 09:00', $this->branch->timezone);
        $this->travelTo($this->today);
    }

    public function test_grants_are_exact_and_the_tv_account_gets_none(): void
    {
        $roles = PermissionCatalogue::roles();
        foreach (LeavePermissions::grants() as $permission => $holders) {
            $actual = collect($roles)->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
            $this->assertSame(collect($holders)->sort()->values()->all(), $actual, $permission);
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
            $this->assertEqualsCanonicalizing($holders, Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->pluck('name')->all(), "seeded {$permission}");
        }
        $this->assertEqualsCanonicalizing(RosterPermissions::HUMAN_ROLES, LeavePermissions::grants()[LeavePermissions::REQUEST_OWN]);
        $this->assertSame(['director', 'hr_manager'], LeavePermissions::grants()[LeavePermissions::APPROVE]);
        $this->assertSame(['director', 'hr_manager'], LeavePermissions::grants()[LeavePermissions::MANAGE]);
    }

    /** @return list<array{string, int, list<string>}> */
    public static function viewers(): array
    {
        $all = ['my', 'approvals', 'calendar', 'settings'];

        return [['director', 200, $all], ['hr_manager', 200, $all], ['ca', 200, ['my']], ['ca_supervisor', 200, ['my']], ['resident_doctor', 200, ['my']],
            ['finance_officer', 200, ['my']], ['technical_admin', 200, ['my']], ['administrator', 200, ['my']], ['queue_display', 403, []]];
    }

    /** @param list<string> $tabs */
    #[DataProvider('viewers')]
    public function test_tabs_follow_permissions_and_the_hub_links_the_page(string $role, int $status, array $tabs): void
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $response = $this->get(route('hr.leave.index', ['tab' => 'settings']))->assertStatus($status);
        $links = collect(app(ClinicManagementNavigation::class)->groups($actor))->flatMap(fn ($g) => $g['links'])->pluck('href')->all();
        $this->assertSame($status === 200, in_array('/hr/leave', $links, true));
        if ($status !== 200) {
            return;
        }
        $response->assertInertia(fn (Assert $page) => $page->component('HR/Leave')->where('leave.tabs', $tabs)
            ->where('leave.tab', in_array('settings', $tabs, true) ? 'settings' : 'my')->where('leave.setUp', false));
        $this->put(route('hr.leave.types.save'), [])->assertStatus(in_array('settings', $tabs, true) ? 302 : 403);
    }

    public function test_nothing_ships_and_staff_cannot_apply_until_hr_sets_leave_up(): void
    {
        $this->assertSame(0, LeaveType::query()->count());
        $this->assertSame(0, DB::table('hr_public_holidays')->count() + DB::table('hr_branch_working_days')->count() + DB::table('hr_leave_entitlements')->count());
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->apply($ca, ['leave_type_id' => 999])->assertSessionHasErrors(['leave_type_id' => 'Choose a leave type.']);

        $type = $this->leaveType($hr);
        $this->apply($ca, ['leave_type_id' => $type->id])->assertSessionHasErrors(['start_date' => "Working days are not set up for {$this->branch->name} yet. Ask HR."]);
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_days_count_branch_working_days_minus_holidays_with_half_days(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 20);
        $this->holiday($hr, '2027-03-10', 'Synthetic Holiday')->assertSessionHasNoErrors();

        // Mon 8 to Fri 12 March with a holiday on Wednesday: 4 days.
        $this->selectBranch($ca);
        $this->postJson(route('hr.leave.preview'), ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-12'])
            ->assertOk()->assertJson(['halves' => 8, 'days' => '4', 'skipped' => ['public holiday on 10 Mar'], 'leftAfter' => '16', 'shortfall' => null]);
        // Too long: the shortfall is said in words and no negative figure is shown.
        $this->postJson(route('hr.leave.preview'), ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-04-30'])
            ->assertOk()->assertJson(['leftAfter' => null, 'shortfall' => 'Not enough leave left: this request needs 39 days and you have 20.']);
        // Friday afternoon to Monday midday across a weekend: 1 day.
        $this->postJson(route('hr.leave.preview'), ['leave_type_id' => $type->id, 'start_date' => '2027-03-12', 'end_date' => '2027-03-15', 'start_part' => 'pm', 'end_part' => 'am'])
            ->assertOk()->assertJson(['halves' => 2, 'days' => '1']);
        // One morning: half a day. A weekend alone has nothing to take.
        $this->postJson(route('hr.leave.preview'), ['leave_type_id' => $type->id, 'start_date' => '2027-03-16', 'end_date' => '2027-03-16', 'start_part' => 'am'])
            ->assertOk()->assertJson(['halves' => 1, 'days' => '0.5']);
        $this->postJson(route('hr.leave.preview'), ['leave_type_id' => $type->id, 'start_date' => '2027-03-13', 'end_date' => '2027-03-14'])
            ->assertStatus(422)->assertJsonPath('errors.start_date', 'Those dates have no working days to take as leave.');
        // Across the new year is two requests; end before start is refused.
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-12-30', 'end_date' => '2028-01-03'])->assertSessionHasErrors('end_date');
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-10', 'end_date' => '2027-03-09'])
            ->assertSessionHasErrors(['end_date' => 'End date must be on or after the start date.']);
        // Half days only where the type allows them.
        $whole = $this->leaveType($hr, ['code' => 'WHOLE', 'name' => 'Whole days', 'allow_half_days' => false, 'uses_balance' => false]);
        $this->apply($ca, ['leave_type_id' => $whole->id, 'start_date' => '2027-03-16', 'end_date' => '2027-03-16', 'start_part' => 'pm'])
            ->assertSessionHasErrors(['start_part' => 'Whole days is taken in whole days.']);
    }

    public function test_apply_approve_and_cancel_keep_an_append_only_ledger_and_audit_no_reasons(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $before = $this->untouchedRows();
        $type = $this->ready($hr, $ca, 5);

        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-10', 'reason' => 'Family trip to Ipoh'])->assertSessionHasNoErrors();
        $request = LeaveRequest::query()->sole();
        $this->assertSame(['pending', 6, false], [$request->status, $request->halves, $request->late]);
        // Pending days are booked: 5 − 3 = 2 left, so 2.5 more is refused and 2 is not overlapping only if dates differ.
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-15', 'end_date' => '2027-03-17'])
            ->assertSessionHasErrors(['end_date' => 'Not enough leave left: this request needs 3 days and you have 2.']);
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-10', 'end_date' => '2027-03-10'])
            ->assertSessionHasErrors(['start_date' => 'You already have leave on 10 Mar 2027.']);

        $this->selectBranch($ca);
        $this->get(route('hr.leave.index'))->assertInertia(fn (Assert $page) => $page->where('leave.my.balances.0.entitled', '5')
            ->where('leave.my.balances.0.booked', '3')->where('leave.my.balances.0.left', '2')->where('leave.my.requests.0.canCancel', true));

        $this->decide($hr, $request, 'approve')->assertSessionHasNoErrors();
        $this->assertSame(['approved', $hr->id], [$request->refresh()->status, $request->decided_by_user_id]);
        $this->assertSame([['grant', 10], ['taken', -6]], DB::table('hr_leave_ledger')->orderBy('id')->get(['kind', 'halves'])->map(fn ($r) => [$r->kind, $r->halves])->all());

        // Cancelled before the start: the days come back as a reversal line; nothing is deleted.
        $this->cancel($ca, $request)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $request->refresh()->status);
        $this->assertSame(['grant', 'taken', 'reversal'], DB::table('hr_leave_ledger')->orderBy('id')->pluck('kind')->all());
        $this->assertSame(10, (int) DB::table('hr_leave_ledger')->sum('halves'));
        $this->assertSame(['applied', 'approved', 'cancelled'], DB::table('hr_leave_request_actions')->orderBy('id')->pluck('action')->all());

        $metadata = DB::table('audit_logs')->where('event', 'like', 'hr.leave.%')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('Ipoh', $metadata);
        $this->assertStringNotContainsString($ca->name, $metadata);
        $this->assertStringNotContainsString('Ipoh', (string) DB::table('hr_leave_requests')->value('reason_encrypted'));
        $this->assertSame($before, $this->untouchedRows());

        foreach (['UPDATE hr_leave_ledger SET halves = 99', 'DELETE FROM hr_leave_ledger', 'TRUNCATE hr_leave_ledger', 'DELETE FROM hr_leave_requests', 'DELETE FROM hr_leave_request_actions'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Allowed: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('HR leave', $e->getMessage());
            }
        }
    }

    public function test_nobody_decides_their_own_leave_in_the_service_and_in_postgresql(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $type = $this->ready($director, $hr, 10);
        $this->workingDays($director, [1, 2, 3, 4, 5]);
        $this->entitle($director, $type, 'director', '10');

        $this->apply($hr, ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08'])->assertSessionHasNoErrors();
        $own = LeaveRequest::query()->where('user_id', $hr->id)->sole();
        $this->selectBranch($hr);
        $this->get(route('hr.leave.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page->where('leave.approvals.pending', [])->where('leave.approvalCount', 0));
        $this->decide($hr, $own, 'approve')->assertForbidden();
        $this->decide($hr, $own, 'reject', ['reason' => 'other'])->assertForbidden();
        $this->assertSame('pending', $own->refresh()->status);

        // The Director decides the HR Manager's request; the HR Manager decides the Director's.
        $this->decide($director, $own, 'approve')->assertSessionHasNoErrors();
        $this->apply($director, ['leave_type_id' => $type->id, 'start_date' => '2027-03-09', 'end_date' => '2027-03-09'])->assertSessionHasNoErrors();
        $directors = LeaveRequest::query()->where('user_id', $director->id)->sole();
        $this->decide($hr, $directors, 'reject')->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->decide($hr, $directors, 'reject', ['reason' => 'not_enough_cover'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'not_enough_cover'], [$directors->refresh()->status, $directors->decision_reason]);

        $this->apply($director, ['leave_type_id' => $type->id, 'start_date' => '2027-03-11', 'end_date' => '2027-03-11'])->assertSessionHasNoErrors();
        $this->expectException(QueryException::class);
        DB::table('hr_leave_requests')->where('user_id', $director->id)->where('status', 'pending')
            ->update(['status' => 'approved', 'decided_by_user_id' => $director->id, 'decided_at' => now()]);
    }

    public function test_after_the_start_only_a_manager_cancels_with_a_reason_and_evidence_must_be_seen(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10, ['evidence_needed' => true, 'code' => 'MC', 'name' => 'Sick leave']);

        // Backdated sick leave is allowed and flagged late; the evidence reference stays encrypted.
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-01', 'end_date' => '2027-03-01', 'evidence_reference' => 'MC serial SYN-123'])->assertSessionHasNoErrors();
        $request = LeaveRequest::query()->sole();
        $this->assertTrue($request->late);
        $this->assertStringNotContainsString('SYN-123', (string) DB::table('hr_leave_requests')->value('evidence_reference_encrypted'));
        $this->decide($hr, $request, 'approve')->assertSessionHasErrors('evidence_seen');
        $this->decide($hr, $request, 'approve', ['evidence_seen' => true])->assertSessionHasNoErrors();
        $this->assertTrue($request->refresh()->evidence_seen);

        $this->cancel($ca, $request)->assertSessionHasErrors(['leave' => 'This leave has started. Ask HR to cancel it.']);
        $this->cancel($hr, $request, ['tab' => 'approvals'])->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->cancel($hr, $request, ['reason' => 'entered_in_error'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', $hr->id, 'entered_in_error'], [$request->refresh()->status, $request->cancelled_by_user_id, $request->decision_reason]);
        $this->assertSame(20, (int) DB::table('hr_leave_ledger')->sum('halves'));
    }

    public function test_stale_versions_retries_and_reused_keys(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);
        $key = (string) Str::uuid();
        $input = ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08', 'idempotency_key' => $key];
        $this->apply($ca, $input)->assertSessionHasNoErrors();
        $this->apply($ca, $input)->assertSessionHasNoErrors(); // A retry replays.
        $this->assertSame(1, LeaveRequest::query()->count());
        $this->apply($ca, [...$input, 'end_date' => '2027-03-09'])->assertSessionHasErrors('leave');

        $request = LeaveRequest::query()->sole();
        $this->selectBranch($hr);
        $this->post(route('hr.leave.requests.approve', $request->public_id), ['expected_lock_version' => 99, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['leave' => 'This was changed by someone else. Reload to see the latest version.']);
        $approveKey = (string) Str::uuid();
        $this->post(route('hr.leave.requests.approve', $request->public_id), ['expected_lock_version' => 1, 'idempotency_key' => $approveKey])->assertSessionHasNoErrors();
        $this->post(route('hr.leave.requests.approve', $request->public_id), ['expected_lock_version' => 1, 'idempotency_key' => $approveKey])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_leave_ledger')->where('kind', 'taken')->count());
    }

    public function test_openings_and_adjustments_need_a_second_manager(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 0);

        $this->entry($hr, 'openings', ['user_id' => $ca->id, 'leave_type_id' => $type->id, 'days' => '3.5'])->assertSessionHasNoErrors();
        $this->entry($hr, 'openings', ['user_id' => $ca->id, 'leave_type_id' => $type->id, 'days' => '1'])
            ->assertSessionHasErrors(['user_id' => 'This person already has an opening balance for this type and year.']);
        $opening = DB::table('hr_leave_openings')->sole();
        $this->assertSame(0, DB::table('hr_leave_ledger')->count()); // Unconfirmed gives no balance.
        $this->selectBranch($hr);
        $this->post(route('hr.leave.entries.decide', ['openings', $opening->public_id, 'confirm']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->selectBranch($director);
        $this->post(route('hr.leave.entries.decide', ['openings', $opening->public_id, 'confirm']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame([['opening', 7]], DB::table('hr_leave_ledger')->get(['kind', 'halves'])->map(fn ($r) => [$r->kind, $r->halves])->all());

        // Removing more than is left is refused at confirmation; the note is encrypted and never audited.
        $this->entry($hr, 'adjustments', ['user_id' => $ca->id, 'leave_type_id' => $type->id, 'days' => '-4', 'reason_code' => 'correction', 'note' => 'Synthetic note text'])->assertSessionHasNoErrors();
        $adjustment = DB::table('hr_leave_adjustments')->sole();
        $this->assertStringNotContainsString('Synthetic note', (string) $adjustment->note_encrypted);
        $this->selectBranch($director);
        $this->post(route('hr.leave.entries.decide', ['adjustments', $adjustment->public_id, 'confirm']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('leave');
        $this->post(route('hr.leave.entries.decide', ['adjustments', $adjustment->public_id, 'reject']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(7, (int) DB::table('hr_leave_ledger')->sum('halves'));
        $this->assertStringNotContainsString('Synthetic note', DB::table('audit_logs')->where('event', 'like', 'hr.leave.%')->pluck('metadata')->implode(' '));

        $this->expectException(QueryException::class);
        DB::table('hr_leave_adjustments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'user_id' => $ca->id, 'leave_type_id' => $type->id,
            'year' => 2027, 'halves' => 2, 'reason_code' => 'other', 'status' => 'confirmed', 'entered_by_user_id' => $hr->id, 'decided_by_user_id' => $hr->id, 'decided_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_grant_adds_only_the_difference_and_a_person_row_wins(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $second = $this->actor('ca');
        $type = $this->ready($hr, $ca, 8);
        $this->assertSame(16, (int) DB::table('hr_leave_ledger')->where('user_id', $second->id)->sum('halves'));

        $this->grant($hr)->assertSessionHasNoErrors(); // Nothing changed.
        $this->assertSame(2, DB::table('hr_leave_ledger')->count());
        $this->selectBranch($hr);
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $type->id, 'user_id' => $second->id, 'days' => '10.5']]])->assertSessionHasNoErrors();
        $this->get(route('hr.leave.index', ['tab' => 'settings', 'year' => 2027]))->assertInertia(fn (Assert $page) => $page->where('leave.settings.grantPreview', ['people' => 1, 'lines' => 1]));
        $this->grant($hr)->assertSessionHasNoErrors();
        $this->assertSame(21, (int) DB::table('hr_leave_ledger')->where('user_id', $second->id)->sum('halves'));
        $this->assertSame(16, (int) DB::table('hr_leave_ledger')->where('user_id', $ca->id)->sum('halves'));
    }

    public function test_carry_forward_and_expiry_are_manager_actions(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10, ['carry_max_days' => '3', 'carry_expires_on' => '03-31']);
        $this->post(route('hr.leave.carry-forward'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('year');

        $this->travelTo(CarbonImmutable::parse('2028-01-05 09:00', $this->branch->timezone));
        $this->post(route('hr.leave.carry-forward'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.leave.carry-forward'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors(); // Once only.
        $this->assertSame([6], DB::table('hr_leave_ledger')->where('kind', 'carry_forward')->where('year', 2028)->pluck('halves')->all());

        // One carried day is used before 31 March; the other two expire.
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2028-01-10', 'end_date' => '2028-01-10'])->assertSessionHasNoErrors();
        $this->decide($hr, LeaveRequest::query()->sole(), 'approve')->assertSessionHasNoErrors();
        $this->post(route('hr.leave.expire'), ['year' => 2028, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['year' => 'No carried days are due to expire yet.']);
        $this->travelTo(CarbonImmutable::parse('2028-03-31 09:00', $this->branch->timezone));
        $this->post(route('hr.leave.expire'), ['year' => 2028, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame([-4], DB::table('hr_leave_ledger')->where('kind', 'expiry')->pluck('halves')->all());
    }

    public function test_a_holiday_counted_by_a_request_cannot_be_added_or_removed(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);
        $this->holiday($hr, '2027-03-10', 'Synthetic Holiday')->assertSessionHasNoErrors();
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-09', 'end_date' => '2027-03-11'])->assertSessionHasNoErrors();
        $id = DB::table('hr_public_holidays')->value('id');
        $this->selectBranch($hr);
        $this->delete(route('hr.leave.holidays.destroy', $id))->assertSessionHasErrors(['holiday' => 'Used by 1 request. It cannot be removed.']);
        $this->holiday($hr, '2027-03-11', 'Second')->assertSessionHasErrors(['holiday_date' => 'Already counted by 1 request. Cancel them first, or add holidays before staff apply.']);
        $this->holiday($hr, '2027-04-01', 'Later')->assertSessionHasNoErrors();
        $this->delete(route('hr.leave.holidays.destroy', DB::table('hr_public_holidays')->where('name', 'Later')->value('id')))->assertSessionHasNoErrors();
    }

    public function test_a_waiting_request_says_when_it_was_sent(): void
    {
        // For the HR space's "oldest waiting" line (Daniel, Kavitha): when the request was sent, ISO and in UTC.
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);
        $this->travelTo(CarbonImmutable::parse('2027-03-01 01:30:00', 'UTC'));
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-10', 'end_date' => '2027-03-10'])->assertSessionHasNoErrors();
        $this->travelBack();
        $this->selectBranch($hr);
        $this->get(route('hr.leave.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page
            ->where('leave.approvals.pending.0.sentAt', '2027-03-01T01:30:00+00:00'));
    }

    public function test_the_roster_refuses_shifts_on_approved_leave_and_warns_for_pending_or_existing(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $other = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);

        // An approved shift exists before the leave is approved: approving warns, never changes the shift.
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => '2027-03-08', 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        $this->shift($hr, $version, $ca, '2027-03-08')->assertSessionHasNoErrors();
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-09'])->assertSessionHasNoErrors();
        $this->apply($other, ['leave_type_id' => $type->id, 'start_date' => '2027-03-10', 'end_date' => '2027-03-10'])->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => '2027-03-08']))->assertInertia(fn (Assert $page) => $page
            ->where('roster.planner.warnings', fn ($w) => in_array("{$ca->name} has asked for leave on 8 Mar 2027.", collect($w)->all(), true)));
        $this->get(route('hr.leave.index', ['tab' => 'approvals']))->assertInertia(fn (Assert $page) => $page->where('leave.approvalCount', 2));

        $mine = LeaveRequest::query()->where('user_id', $ca->id)->sole();
        $this->decide($director, $mine, 'approve')->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_roster_shifts')->count());
        $this->shift($hr, $version, $ca, '2027-03-09')->assertSessionHasErrors(['staff_user_id' => "{$ca->name} is on approved leave on 9 Mar 2027."]);
        $this->shift($hr, $version, $other, '2027-03-10')->assertSessionHasNoErrors(); // Pending leave only warns.

        $this->selectBranch($hr);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => '2027-03-08']))->assertInertia(fn (Assert $page) => $page
            ->where('roster.planner.warnings', fn ($w) => in_array("{$ca->name} is on approved leave on 8 Mar 2027. Change or remove this shift.", collect($w)->all(), true)
                && in_array("{$other->name} has asked for leave on 10 Mar 2027.", collect($w)->all(), true))
            ->where('roster.planner.rows', fn ($rows) => collect($rows)->firstWhere('staffUserId', $ca->id)['leaveDays'] === ['2027-03-08' => 'full', '2027-03-09' => 'full']));
        // The roster and calendar never carry the leave type or reason.
        $this->assertStringNotContainsString($type->name, json_encode($this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => '2027-03-08']))->viewData('page')['props']['roster']));
        $this->get(route('hr.leave.index', ['tab' => 'calendar', 'month' => '2027-03']))->assertInertia(fn (Assert $page) => $page
            ->where('leave.calendar.rows.0.cells', ['2027-03-08' => 'full', '2027-03-09' => 'full'])
            ->where('leave.calendar.rows', fn ($rows) => ! str_contains(json_encode($rows), $type->name)));
    }

    public function test_an_existing_shift_on_approved_leave_only_warns_so_the_week_can_still_be_approved(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => '2027-03-08', 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        $this->shift($hr, $version, $ca, '2027-03-08')->assertSessionHasNoErrors();
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08'])->assertSessionHasNoErrors();
        $this->decide($director, LeaveRequest::query()->sole(), 'approve')->assertSessionHasNoErrors();

        // Editing the existing shift's session keeps it; moving it to another leave day is refused.
        $shift = DB::table('hr_roster_shifts')->sole();
        $edit = ['staff_user_id' => $ca->id, 'date' => '2027-03-08', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30, 'session_type' => 'full_day',
            'branch_room_id' => null, 'branch_id' => $this->branch->id, 'week' => '2027-03-08'];
        $this->selectBranch($hr);
        $this->put(route('hr.roster.shifts.update', [$version->public_id, $shift->public_id]), [...$edit, 'expected_version_lock' => $version->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->put(route('hr.roster.shifts.update', [$version->public_id, $shift->public_id]), [...$edit, 'start_time' => '10:00', 'expected_version_lock' => $version->refresh()->lock_version])
            ->assertSessionHasErrors(['staff_user_id' => "{$ca->name} is on approved leave on 8 Mar 2027."]);

        $this->selectBranch($hr);
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('approved', $version->refresh()->status);
    }

    public function test_a_year_carried_forward_is_closed_for_new_spending(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10, ['carry_max_days' => '3']);
        $this->travelTo(CarbonImmutable::parse('2028-01-05 09:00', $this->branch->timezone));
        $this->post(route('hr.leave.carry-forward'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-12-30', 'end_date' => '2027-12-31'])
            ->assertSessionHasErrors(['start_date' => 'Annual leave for 2027 is closed because its unused days were carried forward. Ask HR.']);
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_late_and_has_started_follow_the_branch_date_around_midnight(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08'])->assertSessionHasNoErrors();
        $this->decide($hr, LeaveRequest::query()->sole(), 'approve')->assertSessionHasNoErrors();

        // 23:30 on Sunday 7 March in Kuala Lumpur: not started yet, still cancellable.
        $this->travelTo(CarbonImmutable::parse('2027-03-07 23:30', $this->branch->timezone));
        $this->selectBranch($ca);
        $this->get(route('hr.leave.index'))->assertInertia(fn (Assert $page) => $page->where('leave.my.requests.0.canCancel', true));
        // 00:10 on Monday 8 March in Kuala Lumpur is still 7 March in UTC: the leave has started and a new request is late.
        $this->travelTo(CarbonImmutable::parse('2027-03-08 00:10', $this->branch->timezone));
        $this->selectBranch($ca);
        $this->get(route('hr.leave.index'))->assertInertia(fn (Assert $page) => $page->where('leave.my.requests.0.canCancel', false));
        $this->cancel($ca, LeaveRequest::query()->sole())->assertSessionHasErrors(['leave' => 'This leave has started. Ask HR to cancel it.']);
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-09', 'end_date' => '2027-03-09'])->assertSessionHasNoErrors();
        $this->assertFalse(LeaveRequest::query()->where('start_date', '2027-03-09')->sole()->late);
        $this->travelTo(CarbonImmutable::parse('2027-03-12 00:05', $this->branch->timezone));
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-12', 'end_date' => '2027-03-12'])->assertSessionHasNoErrors();
        $this->assertTrue(LeaveRequest::query()->where('start_date', '2027-03-12')->sole()->late);
    }

    public function test_other_organisations_ids_are_never_reached(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->ready($hr, $ca, 10);
        $org = DB::table('organisations')->insertGetId(['code' => 'OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['organisation_id' => $org, 'code' => 'OTH'.Str::upper(Str::random(4)), 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['organisation_id' => $org, 'email' => 'leave.other.'.Str::lower(Str::random(8)).'@kpone.test']);
        $type = DB::table('hr_leave_types')->insertGetId(['organisation_id' => $org, 'code' => 'AL', 'name' => 'Other annual', 'is_paid' => true, 'uses_balance' => true,
            'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $stranger->id, 'created_at' => now(), 'updated_at' => now()]);
        $holiday = DB::table('hr_public_holidays')->insertGetId(['organisation_id' => $org, 'branch_id' => $branch, 'holiday_date' => '2027-05-01', 'name' => 'Other',
            'created_by_user_id' => $stranger->id, 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) Str::uuid();
        DB::table('hr_leave_requests')->insert(['public_id' => $uuid, 'organisation_id' => $org, 'user_id' => $stranger->id, 'leave_type_id' => $type, 'branch_id' => $branch,
            'start_date' => '2027-03-08', 'end_date' => '2027-03-08', 'start_part' => 'full', 'end_part' => 'full', 'slot_start' => '2027-03-08 00:00:00', 'slot_end' => '2027-03-09 00:00:00',
            'year' => 2027, 'halves' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $this->apply($ca, ['leave_type_id' => $type])->assertSessionHasErrors(['leave_type_id' => 'Choose a leave type.']);
        $this->selectBranch($hr);
        $this->post(route('hr.leave.requests.approve', $uuid), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.leave.requests.cancel', $uuid), ['expected_lock_version' => 1, 'reason' => 'other', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->delete(route('hr.leave.holidays.destroy', $holiday))->assertNotFound();
        $this->put(route('hr.leave.types.save'), ['leave_type_id' => $type, 'expected_lock_version' => 1, 'name' => 'Taken over'])->assertNotFound();
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => LeaveType::query()->where('organisation_id', $hr->organisation_id)->value('id'), 'user_id' => $stranger->id, 'days' => '5']]])
            ->assertSessionHasErrors(['rows.0.user_id' => 'Choose a person.']);
        $this->entry($hr, 'openings', ['user_id' => $stranger->id, 'leave_type_id' => $type, 'days' => '1'])->assertSessionHasErrors(['user_id' => 'Choose a person.']);
        $this->assertSame('pending', DB::table('hr_leave_requests')->where('public_id', $uuid)->value('status'));
        $this->assertSame('Other annual', DB::table('hr_leave_types')->where('id', $type)->value('name'));
    }

    public function test_postgresql_refuses_a_ledger_or_action_row_that_points_at_another_organisations_request(): void
    {
        // TEAM.md section 7 hardening (HR-2a known limit): the foreign keys to requests, openings and adjustments carry the
        // organisation, so PostgreSQL itself refuses a cross-organisation pointer, not only the service.
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 10);
        $org = DB::table('organisations')->insertGetId(['code' => 'OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['organisation_id' => $org, 'code' => 'OTH'.Str::upper(Str::random(4)), 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['organisation_id' => $org, 'email' => 'leave.other.'.Str::lower(Str::random(8)).'@kpone.test']);
        $otherType = DB::table('hr_leave_types')->insertGetId(['organisation_id' => $org, 'code' => 'AL', 'name' => 'Other annual', 'is_paid' => true, 'uses_balance' => true,
            'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $stranger->id, 'created_at' => now(), 'updated_at' => now()]);
        $otherRequest = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'user_id' => $stranger->id, 'leave_type_id' => $otherType,
            'branch_id' => $branch, 'start_date' => '2027-03-08', 'end_date' => '2027-03-08', 'start_part' => 'full', 'end_part' => 'full', 'slot_start' => '2027-03-08 00:00:00',
            'slot_end' => '2027-03-09 00:00:00', 'year' => 2027, 'halves' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $refused = function (callable $write): void {
            try {
                DB::transaction(fn () => $write());
                $this->fail('Expected PostgreSQL to refuse a cross-organisation foreign key.');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0] ?? null, $e->getMessage());
            }
        };
        $refused(fn () => DB::table('hr_leave_request_actions')->insert(['organisation_id' => $hr->organisation_id, 'leave_request_id' => $otherRequest, 'action' => 'applied',
            'actor_user_id' => $hr->id, 'occurred_at' => now()]));
        $refused(fn () => DB::table('hr_leave_ledger')->insert(['organisation_id' => $hr->organisation_id, 'user_id' => $ca->id, 'leave_type_id' => $type->id, 'year' => 2027,
            'kind' => 'taken', 'halves' => -2, 'leave_request_id' => $otherRequest, 'created_by_user_id' => $hr->id, 'created_at' => now()]));
        $this->assertSame(0, DB::table('hr_leave_request_actions')->where('leave_request_id', $otherRequest)->count());
        $this->assertSame(0, DB::table('hr_leave_ledger')->where('leave_request_id', $otherRequest)->count());
    }

    public function test_a_retried_confirm_replays_lowering_an_entitlement_never_goes_below_zero_and_checked_rows_are_frozen(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $type = $this->ready($hr, $ca, 2);
        $this->entry($hr, 'openings', ['user_id' => $ca->id, 'leave_type_id' => $type->id, 'days' => '1'])->assertSessionHasNoErrors();
        $opening = DB::table('hr_leave_openings')->sole();
        $key = (string) Str::uuid();
        $this->selectBranch($director);
        $this->post(route('hr.leave.entries.decide', ['openings', $opening->public_id, 'confirm']), ['expected_lock_version' => 1, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->post(route('hr.leave.entries.decide', ['openings', $opening->public_id, 'confirm']), ['expected_lock_version' => 1, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_leave_ledger')->where('kind', 'opening')->count());

        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => '2027-03-08', 'end_date' => '2027-03-10'])->assertSessionHasNoErrors();
        $request = LeaveRequest::query()->sole();
        $this->decide($director, $request, 'approve')->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $type->id, 'role' => 'ca', 'days' => '0.5']]])->assertSessionHasNoErrors();
        $this->grant($hr)->assertSessionHasErrors(['year' => 'Lowering the entitlement would take 1 person below zero. Use an adjustment for them instead.']);

        foreach ([['hr_leave_requests', ['late' => true]], ['hr_leave_requests', ['decided_by_user_id' => $hr->id]], ['hr_leave_requests', ['reason_encrypted' => 'changed']],
            ['hr_leave_openings', ['halves' => 99]]] as [$table, $change]) {
            try {
                DB::transaction(fn () => DB::table($table)->update($change));
                $this->fail("Allowed: {$table} ".json_encode($change));
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/immutable|never|checked as entered/', $e->getMessage());
            }
        }
    }

    // ---- helpers ----

    /** @param array<string, mixed> $overrides */
    public function test_leave_years_older_than_seven_years_are_purged_with_their_evidence_and_only_by_the_purge(): void
    {
        // PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use). PostgreSQL checks the leave year
        // against its own clock, so this test works in real time: a year Y goes once the real year is Y + 8 or later.
        Storage::fake(LeaveEvidenceService::DISK);
        $real = CarbonImmutable::createFromTimestamp(time(), 'UTC');
        $gone = $real->year - 8;
        $kept = $real->year - 7;
        $this->travelTo(CarbonImmutable::parse("{$gone}-03-01 09:00", $this->branch->timezone));
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $type = $this->leaveType($hr);
        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        $year = function (int $year) use ($hr, $ca, $type): LeaveRequest {
            $this->travelTo(CarbonImmutable::parse("{$year}-03-01 09:00", $this->branch->timezone));
            $this->selectBranch($hr);
            $this->put(route('hr.leave.entitlements.save'), ['year' => $year, 'rows' => [['leave_type_id' => $type->id, 'role' => 'ca', 'days' => '14']]])->assertSessionHasNoErrors();
            $this->post(route('hr.leave.grant'), ['year' => $year, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
            $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => "{$year}-03-12", 'end_date' => "{$year}-03-12"])->assertSessionHasNoErrors();
            $request = LeaveRequest::query()->where('year', $year)->sole();
            $this->decide($hr, $request, 'approve')->assertSessionHasNoErrors();

            return $request;
        };
        $old = $year($gone);
        // A request of the old year never decided is abandoned and goes too (Suresh).
        $this->apply($ca, ['leave_type_id' => $type->id, 'start_date' => "{$gone}-03-19", 'end_date' => "{$gone}-03-19"])->assertSessionHasNoErrors();
        $pending = LeaveRequest::query()->where('status', 'pending')->sole();
        // Evidence on the old request: the row, its event and the bytes all go.
        Storage::disk(LeaveEvidenceService::DISK)->put('hr-leave-evidence/synthetic.bin', 'synthetic');
        $file = DB::table('hr_leave_evidence_files')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $old->organisation_id,
            'leave_request_id' => $old->id, 'uploaded_by_user_id' => $ca->id, 'on_behalf' => false, 'kind' => 'pdf', 'bytes' => 9, 'sha256' => str_repeat('a', 64),
            'stored_path' => 'hr-leave-evidence/synthetic.bin', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_evidence_events')->insert(['organisation_id' => $old->organisation_id, 'evidence_file_id' => $file, 'action' => 'added',
            'actor_user_id' => $ca->id, 'occurred_at' => now()]);
        $recent = $year($kept);
        $this->travelBack();

        $this->artisan('hr-leave:purge')->expectsOutputToContain('Deleted 2 leave requests, 0 entries, 1 other ledger lines, 1 entitlements and 1 evidence files.')->assertSuccessful();
        $this->assertSame([$recent->id], LeaveRequest::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DB::table('hr_leave_request_actions')->whereIn('leave_request_id', [$old->id, $pending->id])->count());
        $this->assertSame([$kept, $kept], DB::table('hr_leave_ledger')->orderBy('id')->pluck('year')->map(fn ($y): int => (int) $y)->all()); // grant and taken
        $this->assertSame([$kept], DB::table('hr_leave_entitlements')->pluck('year')->map(fn ($y): int => (int) $y)->all());
        $this->assertSame(0, DB::table('hr_leave_evidence_files')->count());
        $this->assertSame(0, DB::table('hr_leave_evidence_events')->count());
        $this->assertFalse(Storage::disk(LeaveEvidenceService::DISK)->exists('hr-leave-evidence/synthetic.bin'));
        $this->assertSame(1, LeaveType::query()->count()); // settings stay
        $this->assertSame([['requests' => 2, 'entries' => 0, 'ledger' => 1, 'entitlements' => 1, 'files' => 1, 'retention_years' => 7]], DB::table('audit_logs')
            ->where('event', 'hr.leave.retention_purged')->pluck('metadata')->map(fn ($m): mixed => json_decode((string) $m, true))->all());
        $this->artisan('hr-leave:purge')->expectsOutputToContain('Deleted 0 leave requests, 0 entries, 0 other ledger lines, 0 entitlements and 0 evidence files.')->assertSuccessful();
        $this->assertSame(['0 0 * * *'], collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'hr-leave:purge'))->pluck('expression')->values()->all());

        // Only the purge deletes, and only rows of a year past the 7 years; TRUNCATE stays refused.
        $refused = function (callable $write): void {
            try {
                DB::transaction(function () use ($write): void {
                    $write();
                });
                $this->fail('PostgreSQL accepted a delete it must refuse.');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0] ?? null);
            }
        };
        $refused(fn () => DB::table('hr_leave_requests')->where('id', $recent->id)->delete());
        $refused(function () use ($recent): void {
            DB::statement("SELECT set_config('kpone.hr_leave_purge', 'on', true)");
            DB::table('hr_leave_ledger')->where('leave_request_id', $recent->id)->delete();
        });
        $refused(function (): void {
            DB::statement("SELECT set_config('kpone.hr_leave_purge', 'on', true)");
            DB::table('hr_leave_entitlements')->delete();
        });
        $refused(fn () => DB::statement('TRUNCATE hr_leave_ledger CASCADE'));
        $this->assertSame(1, LeaveRequest::query()->count());
    }

    private function leaveType(User $manager, array $overrides = []): LeaveType
    {
        $this->selectBranch($manager);
        $code = $overrides['code'] ?? 'AL';
        $this->put(route('hr.leave.types.save'), ['code' => $code, 'name' => 'Annual leave', 'is_paid' => true, 'uses_balance' => true, 'allow_half_days' => true,
            'evidence_needed' => false, ...$overrides])->assertSessionHasNoErrors();

        return LeaveType::query()->where('code', $code)->sole();
    }

    /**
     * A type, Monday-to-Friday working days, a CA entitlement for 2027 and the grant.
     *
     * @param  array<string, mixed>  $type
     */
    private function ready(User $manager, User $person, int $days, array $type = []): LeaveType
    {
        $leaveType = $this->leaveType($manager, $type);
        $this->workingDays($manager, [1, 2, 3, 4, 5]);
        $role = $person->roles->first()->name;
        if ($days > 0) {
            $this->entitle($manager, $leaveType, $role, (string) $days);
        }

        return $leaveType;
    }

    /** @param list<int> $days */
    private function workingDays(User $manager, array $days): void
    {
        $this->selectBranch($manager);
        $lock = DB::table('hr_branch_working_days')->where('branch_id', $this->branch->id)->value('lock_version');
        $this->put(route('hr.leave.working-days'), ['branch_id' => $this->branch->id, 'weekdays' => $days, 'expected_lock_version' => $lock])->assertSessionHasNoErrors();
    }

    private function entitle(User $manager, LeaveType $type, string $role, string $days): void
    {
        $this->selectBranch($manager);
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $type->id, 'role' => $role, 'days' => $days]]])->assertSessionHasNoErrors();
        $this->grant($manager)->assertSessionHasNoErrors();
    }

    private function grant(User $manager): TestResponse
    {
        $this->selectBranch($manager);

        return $this->post(route('hr.leave.grant'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function holiday(User $manager, string $date, string $name): TestResponse
    {
        $this->selectBranch($manager);

        return $this->post(route('hr.leave.holidays.store'), ['branch_id' => $this->branch->id, 'holiday_date' => $date, 'name' => $name]);
    }

    /** @param array<string, mixed> $input */
    private function apply(User $person, array $input): TestResponse
    {
        $this->selectBranch($person);

        return $this->post(route('hr.leave.requests.store'), ['start_date' => '2027-03-08', 'end_date' => '2027-03-08', 'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function decide(User $manager, LeaveRequest $request, string $action, array $input = []): TestResponse
    {
        $this->selectBranch($manager);

        return $this->post(route("hr.leave.requests.{$action}", $request->public_id), ['expected_lock_version' => $request->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function cancel(User $actor, LeaveRequest $request, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.leave.requests.cancel', $request->public_id), ['expected_lock_version' => $request->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function entry(User $manager, string $kind, array $input): TestResponse
    {
        $this->selectBranch($manager);

        return $this->post(route('hr.leave.entries.store', $kind), ['year' => 2027, 'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    private function shift(User $manager, RosterVersion $version, User $subject, string $date): TestResponse
    {
        $this->selectBranch($manager);

        return $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $subject->id, 'date' => $date, 'start_time' => '09:00', 'end_time' => '17:00',
            'break_minutes' => 0, 'session_type' => 'full_day', 'branch_room_id' => null, 'branch_id' => $this->branch->id, 'week' => '2027-03-08',
            'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    private function untouchedRows(): array
    {
        return json_decode((string) json_encode([
            'users' => DB::table('users')->orderBy('id')->get(['id', 'is_active', 'organisation_id', 'email'])->toArray(),
            'roles' => DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()->toArray(),
            'assignments' => DB::table('staff_branch_assignments')->orderBy('id')->get()->toArray(),
            'shifts' => DB::table('hr_roster_shifts')->count(),
            'visits' => DB::table('visits')->count(),
            'queue' => DB::table('queue_entries')->count(),
        ]), true);
    }
}
