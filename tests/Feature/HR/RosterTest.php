<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\StaffBranchAssignmentBootstrapper;

/**
 * HR-1 roster planner (B.E.R.N.I.S.S for the owner, 2026-11-05): Director and HR Manager plan, the Director approves
 * but never a week they sent, each human staff member sees only their own approved shifts, and nothing touches
 * accounts, roles, branch assignments, rooms or clinical work.
 */
class RosterTest extends VisitTestCase
{
    private Branch $puchong;

    private CarbonImmutable $week;

    protected function setUp(): void
    {
        parent::setUp();
        $this->puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        // Next week's Monday in the branch time zone (never the UTC clock: BASELINE section 6 item 21).
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
    }

    public function test_grants_are_exact_operational_and_the_tv_account_gets_none(): void
    {
        $roles = PermissionCatalogue::roles();
        foreach (RosterPermissions::grants() as $permission => $holders) {
            $actual = collect($roles)->filter(fn (array $permissions) => in_array($permission, $permissions, true))->keys()->sort()->values()->all();
            $this->assertSame(collect($holders)->sort()->values()->all(), $actual, $permission);
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
            $this->assertContains($permission, PermissionCatalogue::all());
            $this->assertEqualsCanonicalizing($holders, Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->pluck('name')->all(), "seeded {$permission}");
        }
        $this->assertSame(['director', 'hr_manager'], RosterPermissions::grants()[RosterPermissions::MANAGE]);
        $this->assertSame(['director'], RosterPermissions::grants()[RosterPermissions::APPROVE]);
        $this->assertNotContains(PermissionCatalogue::QUEUE_DISPLAY_ROLE, RosterPermissions::grants()[RosterPermissions::VIEW_OWN]);
        $this->assertEqualsCanonicalizing(array_keys(array_diff_key($roles, [PermissionCatalogue::QUEUE_DISPLAY_ROLE => true])), RosterPermissions::HUMAN_ROLES);
    }

    /** @return list<array{string, int, bool}> */
    public static function viewers(): array
    {
        return [['director', 200, true], ['hr_manager', 200, true], ['ca', 200, false], ['ca_supervisor', 200, false], ['resident_doctor', 200, false],
            ['finance_officer', 200, false], ['panel_officer', 200, false], ['administrator', 200, false], ['technical_admin', 200, false],
            ['marketing', 200, false], ['business_development', 200, false], ['queue_display', 403, false]];
    }

    #[DataProvider('viewers')]
    public function test_only_roster_managers_see_the_planner_and_everyone_human_sees_their_own(string $role, int $status, bool $planner): void
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $response = $this->get(route('hr.roster.index', ['tab' => 'planner']))->assertStatus($status);
        if ($status !== 200) {
            return;
        }
        $response->assertInertia(fn (Assert $page) => $page->component('HR/Roster')
            ->where('roster.tab', $planner ? 'planner' : 'my')
            ->where('roster.planner', $planner ? fn ($p) => $p !== null : null)
            ->where('roster.minimums', $role === 'hr_manager' || $role === 'director' ? fn ($m) => $m !== null : null)
            ->has('roster.my'));
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus($planner ? 302 : 403);
    }

    public function test_hr_drafts_the_director_approves_and_staff_see_only_their_own_approved_shifts(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $other = $this->actor('ca');
        $before = $this->untouchedRows();

        $version = $this->startDraft($hr);
        $this->addShift($hr, $version, $ca, 0, '08:00', '14:00', ['break_minutes' => 30, 'session_type' => 'morning'])->assertSessionHasNoErrors();
        $this->addShift($hr, $version, $other, 0, '14:00', '20:00', ['session_type' => 'afternoon'])->assertSessionHasNoErrors(); // Back-to-back with nobody else is fine.

        // Drafts are invisible to staff.
        $this->selectBranch($ca);
        $this->get(route('hr.roster.index', ['week' => $this->week->toDateString()]))->assertInertia(fn (Assert $page) => $page->where('roster.my.days', []));

        $this->submit($hr, $version)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $version->refresh()->status);
        // The HR Manager cannot approve (no permission) and editing a waiting week is refused.
        $this->selectBranch($hr);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->addShift($hr, $version, $ca, 1, '08:00', '14:00')->assertSessionHasErrors('roster');

        $this->approve($director, $version)->assertSessionHasNoErrors();
        $this->assertSame(['approved', $director->id, $hr->id], [$version->refresh()->status, $version->decided_by_user_id, $version->submitted_by_user_id]);

        $this->selectBranch($ca);
        $this->get(route('hr.roster.index', ['week' => $this->week->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('roster.planner', null)
            ->has('roster.my.days', 1)
            ->where('roster.my.days.0.shifts.0.startLabel', '8:00 AM')
            ->where('roster.my.days.0.shifts.0.endLabel', '2:00 PM')
            ->where('roster.my.days.0.shifts.0.breakMinutes', 30)
            ->where('roster.my.days.0.shifts.0.branch', $this->branch->name)
            ->has('roster.my.days.0.shifts', 1)); // Only the CA's own shift, never the colleague's.

        // Structural audit only: ids and field names, never names or times.
        $events = DB::table('audit_logs')->where('event', 'like', 'hr.roster.%')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['hr.roster.draft_started', 'hr.roster.shift_added', 'hr.roster.shift_added', 'hr.roster.submitted', 'hr.roster.approved'], $events);
        $metadata = DB::table('audit_logs')->where('event', 'like', 'hr.roster.%')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString($ca->name, $metadata);
        $this->assertStringNotContainsString($ca->email, $metadata);
        $this->assertStringNotContainsString('08:00', $metadata);
        $this->assertSame(['draft_started', 'shift_added', 'shift_added', 'submitted', 'approved'], DB::table('hr_roster_actions')->orderBy('id')->pluck('action')->all());
        $this->assertSame($before, $this->untouchedRows());
    }

    public function test_the_person_who_sent_a_week_never_approves_or_returns_it_even_a_director(): void
    {
        $director = $this->actor('director');
        $secondDirector = $this->actor('director');
        $ca = $this->actor('ca');
        $version = $this->startDraft($director);
        $this->addShift($director, $version, $ca, 2, '09:00', '17:00')->assertSessionHasNoErrors();
        $this->submit($director, $version)->assertSessionHasNoErrors();

        $this->approve($director, $version)->assertSessionHasErrors(['roster' => 'You sent this week for approval. Someone else must approve it.']);
        $this->post(route('hr.roster.return', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'reason' => 'other', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('roster');
        $this->selectBranch($director);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'branch' => $this->branch->id, 'week' => $this->week->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->where('roster.planner.can.decide', false)->where('roster.planner.shown.sentByYou', true));
        $this->assertSame('submitted', $version->refresh()->status);

        $this->approve($secondDirector, $version)->assertSessionHasNoErrors();
        $this->assertSame('approved', $version->refresh()->status);

        // PostgreSQL refuses a return by the person who sent the week.
        $returned = $this->startDraft($director, $this->week->addWeeks(2));
        $this->addShift($director, $returned, $ca, 0, '09:00', '17:00', [], $this->week->addWeeks(2))->assertSessionHasNoErrors();
        $this->submit($director, $returned->refresh())->assertSessionHasNoErrors();
        DB::beginTransaction();
        try {
            DB::table('hr_roster_versions')->where('id', $returned->id)->update(['status' => 'draft', 'submitted_by_user_id' => null, 'submitted_at' => null, 'returned_by_user_id' => $director->id, 'return_reason' => 'other']);
            $this->fail('A return by the sender was not refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }

        // PostgreSQL refuses a self-approval written around the service.
        $other = $this->startDraft($director, $this->week->addWeek());
        $this->addShift($director, $other, $ca, 0, '09:00', '17:00', [], $this->week->addWeek())->assertSessionHasNoErrors();
        $this->submit($director, $other->refresh())->assertSessionHasNoErrors();
        $this->expectException(QueryException::class);
        DB::table('hr_roster_versions')->where('id', $other->id)->update(['status' => 'approved', 'decided_by_user_id' => $director->id, 'decided_at' => now()]);
    }

    public function test_a_shift_outside_the_persons_branch_assignment_is_refused_on_every_date_it_touches(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca'); // Assigned to Cheras only.
        $version = $this->startDraft($hr, branch: $this->puchong);
        $label = $this->week->addDays(1)->format('j M Y');
        $this->addShift($hr, $version, $ca, 1, '08:00', '14:00', [], branch: $this->puchong)
            ->assertSessionHasErrors(['staff_user_id' => "{$ca->name} is not assigned to {$this->puchong->name} on {$label}."]);

        // An assignment ending on the start date still refuses an overnight shift that runs into the next day.
        $profile = StaffProfile::query()->where('user_id', $ca->id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($profile, $this->puchong, ['assignment_type' => 'temporary',
            'valid_from' => $this->week->toDateString(), 'valid_until' => $this->week->addDays(1)->toDateString()]);
        $this->addShift($hr, $version, $ca, 1, '20:00', '08:00', [], branch: $this->puchong)
            ->assertSessionHasErrors(['staff_user_id' => "{$ca->name} is not assigned to {$this->puchong->name} on {$this->week->addDays(2)->format('j M Y')}."]);
        $this->addShift($hr, $version, $ca, 1, '08:00', '14:00', [], branch: $this->puchong)->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('staff_branch_assignments')->where('staff_profile_id', $profile->id)->where('valid_until', '>', $this->week->addDays(1)->toDateString())->count());

        // The TV account and inactive accounts are never rostered.
        $tv = $this->actor('queue_display');
        $this->addShift($hr, $version, $tv, 3, '08:00', '14:00', [], branch: $this->puchong)->assertSessionHasErrors(['staff_user_id' => 'Choose an active staff member.']);
    }

    public function test_one_person_cannot_overlap_across_branches_but_back_to_back_and_other_drafts_are_allowed(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        StaffBranchAssignmentBootstrapper::create(StaffProfile::query()->where('user_id', $ca->id)->firstOrFail(), $this->puchong, ['valid_from' => now()->subDay()->toDateString()]);

        $cheras = $this->startDraft($hr);
        $this->addShift($hr, $cheras, $ca, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->addShift($hr, $cheras, $ca, 0, '13:00', '15:00')->assertSessionHasErrors('staff_user_id'); // Same draft.

        // A Puchong draft may overlap the Cheras *draft* (warning only), back-to-back is fine.
        $puchong = $this->startDraft($hr, branch: $this->puchong);
        $this->addShift($hr, $puchong, $ca, 0, '10:00', '12:00', [], branch: $this->puchong)->assertSessionHasNoErrors();
        $this->addShift($hr, $puchong, $ca, 0, '14:00', '18:00', [], branch: $this->puchong)->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'branch' => $this->puchong->id, 'week' => $this->week->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->where('roster.planner.warnings', fn ($w) => collect($w)->contains(fn ($m) => str_contains($m, "{$ca->name} also has a draft shift at {$this->branch->name}"))));

        // Once Cheras is approved, the overlapping Puchong shift blocks the Puchong approval, with a plain reason.
        $this->submit($hr, $cheras->refresh())->assertSessionHasNoErrors();
        $this->approve($director, $cheras->refresh())->assertSessionHasNoErrors();
        $this->submit($hr, $puchong->refresh())->assertSessionHasNoErrors();
        $this->approve($director, $puchong->refresh())->assertSessionHasErrors(['roster' => "{$ca->name} already has a shift from 8:00 AM to 2:00 PM at {$this->branch->name} on {$this->week->format('j M Y')}."]);
        $this->assertSame('submitted', $puchong->refresh()->status);

        // And a new overlapping shift is refused straight away.
        $this->post(route('hr.roster.return', $puchong->public_id), ['expected_version_lock' => $puchong->lock_version, 'reason' => 'wrong_times', 'idempotency_key' => (string) Str::uuid(), 'branch_id' => $this->puchong->id])
            ->assertSessionHasNoErrors();
        $this->addShift($hr, $puchong->refresh(), $ca, 0, '11:00', '13:00', [], branch: $this->puchong)->assertSessionHasErrors('staff_user_id');
    }

    public function test_overnight_shifts_length_break_session_and_room_rules(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $version = $this->startDraft($hr);
        $this->addShift($hr, $version, $ca, 6, '22:00', '06:00', ['session_type' => 'night'])->assertSessionHasNoErrors();
        $shift = RosterShift::query()->sole();
        $this->assertSame(8 * 60, (int) $shift->starts_at->diffInMinutes($shift->ends_at));
        $this->assertSame($this->week->addDays(6)->setTime(22, 0)->utc()->toIso8601String(), $shift->starts_at->utc()->toIso8601String());
        $this->assertSame($this->branch->timezone, $shift->timezone);

        $this->addShift($hr, $version, $ca, 1, '06:00', '23:00')->assertSessionHasErrors(['end_time' => 'The shift is longer than 16 hours.']);
        $this->addShift($hr, $version, $ca, 1, '08:00', '09:00', ['break_minutes' => 60])->assertSessionHasErrors(['break_minutes' => 'The break must be shorter than the shift.']);
        $this->addShift($hr, $version, $ca, 1, '08:00', '09:00', ['session_type' => 'graveyard'])->assertSessionHasErrors('session_type');
        $this->addShift($hr, $version, $ca, 7, '08:00', '09:00')->assertSessionHasErrors('date'); // Outside the week.

        $room = $this->room($this->puchong);
        $this->addShift($hr, $version, $ca, 2, '08:00', '12:00', ['branch_room_id' => $room])->assertSessionHasErrors(['branch_room_id' => 'Choose a room at this branch.']);
        $mine = $this->room($this->branch);
        $other = $this->actor('ca');
        $this->addShift($hr, $version, $ca, 2, '08:00', '12:00', ['branch_room_id' => $mine])->assertSessionHasNoErrors();
        $this->addShift($hr, $version, $other, 2, '09:00', '11:00', ['branch_room_id' => $mine])->assertSessionHasNoErrors(); // Shared rooms are a warning.
        $this->selectBranch($hr);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->week->toDateString(), 'branch' => $this->branch->id]))
            ->assertInertia(fn (Assert $page) => $page->where('roster.planner.warnings', fn ($w) => collect($w)->contains(fn ($m) => str_contains($m, 'is proposed for two people on'))));
        $this->assertSame(0, DB::table('doctor_room_assignments')->count());
    }

    public function test_stale_versions_retries_and_reused_keys(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $version = $this->startDraft($hr);
        $key = (string) Str::uuid();
        $lock = $version->refresh()->lock_version;
        $this->addShift($hr, $version, $ca, 0, '08:00', '14:00', ['idempotency_key' => $key, 'expected_version_lock' => $lock])->assertSessionHasNoErrors();
        $this->addShift($hr, $version, $ca, 0, '08:00', '14:00', ['idempotency_key' => $key, 'expected_version_lock' => $lock])->assertSessionHasNoErrors(); // Same retry.
        $this->assertSame(1, RosterShift::query()->count());
        // A retry after the week was sent is still answered from the stored result.
        $this->submit($hr, $version->refresh())->assertSessionHasNoErrors();
        $this->addShift($hr, $version, $ca, 0, '08:00', '14:00', ['idempotency_key' => $key, 'expected_version_lock' => $lock])->assertSessionHasNoErrors();
        $this->selectBranch($this->actor('director'));
        $this->post(route('hr.roster.return', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'reason' => 'other', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->addShift($hr, $version, $ca, 1, '08:00', '14:00', ['idempotency_key' => $key, 'expected_version_lock' => $lock])
            ->assertSessionHasErrors(['roster' => 'This request was already used for a different change. Reload and try again.']);
        $this->addShift($hr, $version, $ca, 1, '08:00', '14:00', ['expected_version_lock' => $lock])
            ->assertSessionHasErrors(['roster' => 'This week was changed by someone else. Reload to see the latest version.']);
        $this->assertSame(1, RosterShift::query()->count());

        // Update and remove follow the version lock too.
        $shift = RosterShift::query()->sole();
        $this->put(route('hr.roster.shifts.update', [$version->public_id, $shift->public_id]), $this->shiftInput($ca, 0, '09:00', '15:00', ['expected_version_lock' => $lock]))
            ->assertSessionHasErrors('roster');
        $this->put(route('hr.roster.shifts.update', [$version->public_id, $shift->public_id]), $this->shiftInput($ca, 0, '09:00', '15:00', ['expected_version_lock' => $version->refresh()->lock_version]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['starts_at', 'ends_at'], json_decode((string) DB::table('audit_logs')->where('event', 'hr.roster.shift_updated')->value('metadata'), true)['changed_fields']);
        $this->delete(route('hr.roster.shifts.destroy', [$version->public_id, $shift->public_id]), ['expected_version_lock' => $version->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertSame(0, RosterShift::query()->count());
        $this->submit($hr, $version->refresh())->assertSessionHasErrors(['roster' => 'Add at least one shift before sending the week for approval.']);
    }

    public function test_a_change_after_approval_is_a_new_version_and_the_approved_week_never_changes(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $v1 = $this->startDraft($hr);
        $this->addShift($hr, $v1, $ca, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->submit($hr, $v1->refresh());
        $this->approve($director, $v1->refresh())->assertSessionHasNoErrors();

        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('roster'); // Use "Change this week".
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'change', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $v2 = RosterVersion::query()->where('version_number', 2)->sole();
        $this->assertSame([$v1->id, 1], [$v2->based_on_version_id, $v2->shifts()->count()]);
        $moved = $v2->shifts()->sole();
        $this->put(route('hr.roster.shifts.update', [$v2->public_id, $moved->public_id]), $this->shiftInput($ca, 0, '10:00', '16:00', ['expected_version_lock' => $v2->lock_version]))->assertSessionHasNoErrors();

        // Staff still see version 1 until version 2 is approved.
        $this->selectBranch($ca);
        $this->get(route('hr.roster.index', ['week' => $this->week->toDateString()]))->assertInertia(fn (Assert $p) => $p->where('roster.my.days.0.shifts.0.startLabel', '8:00 AM'));
        $this->submit($hr, $v2->refresh());
        $this->approve($director, $v2->refresh())->assertSessionHasNoErrors();
        $this->assertSame(['superseded', 'approved'], [$v1->refresh()->status, $v2->refresh()->status]);
        $this->selectBranch($ca);
        $this->get(route('hr.roster.index', ['week' => $this->week->toDateString()]))->assertInertia(fn (Assert $p) => $p->where('roster.my.days.0.shifts.0.startLabel', '10:00 AM'));

        // PostgreSQL: shifts of a non-draft version and replaced versions never change; versions are never deleted.
        foreach ([
            fn () => DB::table('hr_roster_shifts')->where('roster_version_id', $v1->id)->update(['break_minutes' => 5]),
            fn () => DB::table('hr_roster_shifts')->where('roster_version_id', $v2->id)->delete(),
            fn () => DB::table('hr_roster_versions')->where('id', $v1->id)->update(['status' => 'approved']),
            fn () => DB::table('hr_roster_versions')->where('id', $v2->id)->delete(),
            fn () => DB::table('hr_roster_actions')->limit(1)->delete(),
        ] as $i => $write) {
            DB::beginTransaction();
            try {
                $write();
                $this->fail("Write {$i} was not refused.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_copy_last_approved_week_leaves_out_shifts_that_are_no_longer_allowed(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $stays = $this->actor('ca');
        $leaves = $this->actor('ca');
        $v1 = $this->startDraft($hr);
        $this->addShift($hr, $v1, $stays, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->addShift($hr, $v1, $leaves, 1, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->submit($hr, $v1->refresh());
        $this->approve($director, $v1->refresh())->assertSessionHasNoErrors();
        DB::table('users')->where('id', $leaves->id)->update(['is_active' => false]);

        $this->selectBranch($hr);
        $next = $this->week->addWeek();
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $next->toDateString(), 'mode' => 'copy', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors()->assertInertiaFlash('rosterSkipped', ["{$leaves->name} on {$this->week->addWeek()->addDay()->format('D j M')}: Choose an active staff member."]);
        $copy = RosterVersion::query()->where('roster_week_id', '<>', $v1->roster_week_id)->sole();
        $shift = $copy->shifts()->sole();
        $this->assertSame([$stays->id, $next->setTime(8, 0)->utc()->toIso8601String()], [$shift->staff_user_id, $shift->starts_at->utc()->toIso8601String()]);
        $this->assertSame('week_copied', DB::table('hr_roster_actions')->where('roster_version_id', $copy->id)->value('action'));
    }

    public function test_minimum_staffing_is_a_warning_only_and_versioned(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->selectBranch($hr);
        $values = ['morning' => ['ca' => 2, 'resident_doctor' => 1]];
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => $values, 'expected_lock_version' => 0])->assertSessionHasNoErrors();
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => $values, 'expected_lock_version' => 0])->assertSessionHasErrors('minimums'); // Stale.
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => ['morning' => ['ca' => 21]], 'expected_lock_version' => 1])->assertSessionHasErrors('minimums.morning.ca');
        $this->assertEqualsCanonicalizing(['morning.ca', 'morning.resident_doctor'], json_decode((string) DB::table('audit_logs')->where('event', 'hr.roster.minimum_staffing_updated')->value('metadata'), true)['changed_fields']);

        $version = $this->startDraft($hr);
        $this->addShift($hr, $version, $ca, 0, '08:00', '14:00', ['session_type' => 'morning'])->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $day = $this->week->format('D j M');
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->week->toDateString(), 'branch' => $this->branch->id]))->assertInertia(fn (Assert $p) => $p
            ->where('roster.planner.warnings', fn ($w) => collect($w)->contains("Short on {$day}: Morning 0 Resident Doctors (minimum 1); Morning 1 CA (minimum 2)."))
            ->where('roster.planner.shortDays.0.date', $this->week->toDateString()));
        // A full-day doctor shift covers the Morning minimum.
        $this->addShift($hr, $version, $this->actor('resident_doctor'), 0, '09:00', '17:00', ['session_type' => 'full_day'])->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->week->toDateString(), 'branch' => $this->branch->id]))
            ->assertInertia(fn (Assert $p) => $p->where('roster.planner.warnings', fn ($w) => collect($w)->contains("Short on {$day}: Morning 1 CA (minimum 2)."))
                ->where('roster.planner.totals.0', 2));
        $this->submit($hr, $version->refresh())->assertSessionHasNoErrors();
        $this->approve($director, $version->refresh())->assertSessionHasNoErrors(); // Never blocks.

        $ca2 = $this->actor('ca');
        $this->selectBranch($ca2);
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => $values, 'expected_lock_version' => 1])->assertForbidden();
    }

    public function test_return_and_discard(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $version = $this->startDraft($hr);
        $this->addShift($hr, $version, $ca, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->submit($hr, $version->refresh());
        $this->selectBranch($director);
        $this->post(route('hr.roster.return', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'reason' => 'nonsense', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('reason');
        $this->post(route('hr.roster.return', $version->public_id), ['expected_version_lock' => $version->lock_version, 'reason' => 'wrong_staff', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame(['draft', 'wrong_staff', null], [$version->refresh()->status, $version->return_reason, $version->submitted_by_user_id]);

        $this->selectBranch($hr);
        $this->post(route('hr.roster.discard', $version->public_id), ['expected_version_lock' => $version->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('discarded', $version->refresh()->status);
        $this->assertSame(1, RosterShift::query()->count()); // Kept as evidence.
        $this->startDraft($hr); // A fresh draft can start.
        $this->assertSame(2, RosterVersion::query()->count());
    }

    public function test_hub_shows_the_roster_link_to_every_human_role_only(): void
    {
        foreach (['director', 'hr_manager', 'ca', 'technical_admin', 'queue_display'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $links = collect(app(ClinicManagementNavigation::class)->groups($actor))->flatMap(fn ($g) => $g['links'])->pluck('href')->all();
            $this->assertSame($role !== 'queue_display', in_array('/hr/roster', $links, true), $role);
        }
    }

    private function startDraft(User $actor, ?CarbonImmutable $week = null, ?Branch $branch = null): RosterVersion
    {
        $this->selectBranch($actor);
        $branch ??= $this->branch;
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $branch->id, 'week' => ($week ?? $this->week)->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $branch->id)->whereIn('status', ['draft'])->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function addShift(User $actor, RosterVersion $version, User $subject, int $day, string $start, string $end, array $overrides = [], ?CarbonImmutable $week = null, ?Branch $branch = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.roster.shifts.store', $version->public_id), $this->shiftInput($subject, $day, $start, $end, [
            'expected_version_lock' => $version->refresh()->lock_version, 'branch_id' => ($branch ?? $this->branch)->id, ...$overrides,
        ], $week));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function shiftInput(User $subject, int $day, string $start, string $end, array $overrides = [], ?CarbonImmutable $week = null): array
    {
        return ['staff_user_id' => $subject->id, 'date' => ($week ?? $this->week)->addDays($day)->toDateString(), 'start_time' => $start, 'end_time' => $end,
            'break_minutes' => 0, 'session_type' => 'morning', 'branch_room_id' => null, 'idempotency_key' => (string) Str::uuid(), ...$overrides];
    }

    private function submit(User $actor, RosterVersion $version): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function approve(User $actor, RosterVersion $version): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function room(Branch $branch): int
    {
        $by = User::query()->where('organisation_id', $branch->organisation_id)->value('id');

        return (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'kind' => 'consultation',
            'name' => 'Synthetic Room '.Str::random(4), 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $by, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> Rows HR must never write: accounts, roles, assignments, rooms and clinical work. */
    private function untouchedRows(): array
    {
        return json_decode((string) json_encode([
            'users' => DB::table('users')->orderBy('id')->get(['id', 'is_active', 'organisation_id', 'email'])->toArray(),
            'roles' => DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()->toArray(),
            'assignments' => DB::table('staff_branch_assignments')->orderBy('id')->get()->toArray(),
            'rooms' => DB::table('doctor_room_assignments')->count(),
            'visits' => DB::table('visits')->count(),
            'queue' => DB::table('queue_entries')->count(),
        ]), true);
    }
}
