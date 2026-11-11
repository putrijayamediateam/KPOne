<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\OpenShifts\OpenShiftPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-15b open shifts (HR-PLAN 21.4; B.E.R.N.I.S.S for the owner, 2026-11-10). Synthetic data only.
 */
class OpenShiftsTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        // A Tuesday morning in the branch's time zone; the open shift is on the next Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_grants_are_exact_and_only_a_linked_locum_asks(): void
    {
        $roles = PermissionCatalogue::roles();
        $holders = fn (string $p): array => collect($roles)->filter(fn (array $ps) => in_array($p, $ps, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(OpenShiftPermissions::MANAGE));
        $this->assertSame(['locum'], $holders(OpenShiftPermissions::REQUEST_OWN));
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(OpenShiftPermissions::MANAGE));
        foreach (['ca', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('hr.open-shifts.index'))->assertForbidden();
        }
        $unlinked = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $this->selectBranch($unlinked);
        $this->get(route('hr.open-shifts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/OpenShifts')
            ->where('openShifts.tabs', ['available', 'mine'])->where('openShifts.linked', false)->where('openShifts.available', []));
        $shift = $this->post_($this->hr);
        $this->selectBranch($unlinked);
        $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('open_shift');
        $this->assertSame(0, DB::table('hr_open_shift_requests')->count());
    }

    public function test_hr_posts_locums_ask_and_a_manager_confirms_one_into_the_draft_roster(): void
    {
        $first = $this->linkedLocum('first');
        $second = $this->linkedLocum('second');
        $this->selectBranch($this->hr);
        $this->post(route('hr.open-shifts.store'), [...$this->shiftInput(), 'date' => '2026-11-09'])->assertSessionHasErrors('date'); // in the past
        $this->post(route('hr.open-shifts.store'), [...$this->shiftInput(), 'role_needed' => ''])->assertSessionHasErrors('role_needed');
        $shift = $this->post_($this->hr);

        foreach ([$first, $second] as $locum) {
            $this->selectBranch($locum);
            $this->get(route('hr.open-shifts.index'))->assertInertia(fn (Assert $page) => $page->where('openShifts.linked', true)
                ->has('openShifts.available', 1)->where('openShifts.available.0.id', $shift));
            $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('open_shift'); // once

        $this->selectBranch($this->hr);
        $this->get(route('hr.open-shifts.index', ['tab' => 'open']))->assertInertia(fn (Assert $page) => $page->has('openShifts.open', 1)
            ->has('openShifts.open.0.asks', 2)->where('openShifts.open.0.asks.0.locum', $first->name));
        $ask = DB::table('hr_open_shift_requests')->where('locum_user_id', $first->id)->first();
        $this->post(route('hr.open-shifts.confirm', $ask->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        // The shift is in a new draft of that week's roster, for the first locum; the second locum's ask is declined.
        $version = RosterVersion::query()->where('status', 'draft')->sole();
        $rosterShift = RosterShift::query()->where('roster_version_id', $version->id)->sole();
        $this->assertSame([$first->id, '2026-11-17 08:00'], [$rosterShift->staff_user_id,
            CarbonImmutable::parse($rosterShift->starts_at)->setTimezone($this->branch->timezone)->format('Y-m-d H:i')]);
        $this->assertSame(['confirmed', $rosterShift->id], [DB::table('hr_open_shift_requests')->where('id', $ask->id)->value('status'),
            (int) DB::table('hr_open_shift_requests')->where('id', $ask->id)->value('roster_shift_id')]);
        $this->assertSame(['declined', 'filled'], array_values((array) DB::table('hr_open_shift_requests')->where('locum_user_id', $second->id)
            ->first(['status', 'decline_reason'])));
        $this->assertSame('filled', DB::table('hr_open_shifts')->value('status'));

        // The locum sees it on My roster only once the week is approved.
        $this->selectBranch($first);
        $this->get(route('hr.roster.index', ['week' => '2026-11-17']))->assertInertia(fn (Assert $page) => $page->where('roster.my.days', []));
        $this->get(route('hr.open-shifts.index', ['tab' => 'mine']))->assertInertia(fn (Assert $page) => $page->where('openShifts.mine.0.requestStatus', 'confirmed'));
        $this->selectBranch($second);
        $this->get(route('hr.open-shifts.index', ['tab' => 'mine']))->assertInertia(fn (Assert $page) => $page
            ->where('openShifts.mine.0.requestStatusLabel', 'Not chosen: another locum was confirmed'));
    }

    public function test_a_week_waiting_for_approval_is_not_changed_and_a_withdrawn_shift_declines_its_asks(): void
    {
        $locum = $this->linkedLocum('one');
        $shift = $this->post_($this->hr);
        $this->selectBranch($locum);
        $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $ask = DB::table('hr_open_shift_requests')->sole();

        // HR sends the week for approval; a confirm then refuses, and nothing changes.
        $started = app(RosterService::class)->startDraft($this->hr, ['branch_id' => $this->branch->id, 'week' => '2026-11-17', 'mode' => 'blank',
            'idempotency_key' => (string) Str::uuid()]);
        $ca = $this->actor('ca');
        app(RosterService::class)->addShift($this->hr, $started['version']->public_id, ['staff_user_id' => $ca->id, 'date' => '2026-11-18',
            'start_time' => '08:00', 'end_time' => '12:00', 'break_minutes' => 0, 'session_type' => 'morning',
            'expected_version_lock' => $started['version']->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        app(RosterService::class)->submit($this->hr, $started['version']->public_id, ['expected_version_lock' => $started['version']->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($this->hr);
        $this->post(route('hr.open-shifts.confirm', $ask->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['open_shift' => 'The roster for the week of 16 Nov 2026 is waiting for approval. Return it to draft, then confirm.']);
        $this->assertSame(['asked', 0], [DB::table('hr_open_shift_requests')->value('status'), RosterShift::query()->where('staff_user_id', $locum->id)->count()]);

        // Withdrawing the shift declines the waiting ask.
        $this->post(route('hr.open-shifts.withdraw', $shift), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(['withdrawn', 'declined', 'shift_withdrawn'], [DB::table('hr_open_shifts')->value('status'),
            DB::table('hr_open_shift_requests')->value('status'), DB::table('hr_open_shift_requests')->value('decline_reason')]);
    }

    public function test_decline_with_a_reason_and_the_locum_withdraws_their_own_ask(): void
    {
        $one = $this->linkedLocum('one');
        $two = $this->linkedLocum('two');
        $shift = $this->post_($this->hr);
        foreach ([$one, $two] as $locum) {
            $this->selectBranch($locum);
            $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        [$first, $second] = DB::table('hr_open_shift_requests')->orderBy('id')->get()->all();
        $this->selectBranch($this->hr);
        $this->post(route('hr.open-shifts.decline', $first->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('reason');
        $this->post(route('hr.open-shifts.decline', $first->public_id), ['reason' => 'not_suitable', 'expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($one);
        $this->get(route('hr.open-shifts.index', ['tab' => 'mine']))->assertInertia(fn (Assert $page) => $page
            ->where('openShifts.mine.0.requestStatusLabel', 'Not chosen: Not suitable for this shift'));
        // Only the locum withdraws their own ask.
        $this->post(route('hr.open-shifts.ask-withdraw', $second->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertNotFound();
        $this->selectBranch($two);
        $this->post(route('hr.open-shifts.ask-withdraw', $second->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', DB::table('hr_open_shift_requests')->where('id', $second->id)->value('status'));
    }

    public function test_postgresql_keeps_the_rules(): void
    {
        $locum = $this->linkedLocum('one');
        $shift = $this->post_($this->hr);
        $row = DB::table('hr_open_shifts')->sole();
        $ca = $this->actor('ca');
        $unlinked = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $base = ['organisation_id' => $row->organisation_id, 'branch_id' => $row->branch_id, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at,
            'timezone' => $row->timezone, 'break_minutes' => 0, 'session_type' => 'morning', 'role_needed' => 'other', 'status' => 'open', 'posted_at' => now(),
            'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()];
        // Posted only by someone who handles open shifts; what was posted never changes.
        $this->refused('P0001', fn () => DB::table('hr_open_shifts')->insert([...$base, 'public_id' => (string) Str::uuid(), 'posted_by_user_id' => $ca->id]));
        $this->refused('P0001', fn () => DB::table('hr_open_shifts')->where('id', $row->id)->update(['break_minutes' => 30, 'lock_version' => 2]));
        $this->refused('P0001', fn () => DB::table('hr_open_shifts')->where('id', $row->id)->update(['status' => 'filled', 'closed_by_user_id' => $this->hr->id,
            'closed_at' => now(), 'lock_version' => 2])); // filled needs its confirmed locum
        // An ask only from a linked locum; a confirm points at the locum's roster shift.
        $ask = ['open_shift_id' => $row->id, 'organisation_id' => $row->organisation_id, 'status' => 'asked', 'asked_at' => now(), 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now()];
        $this->refused('23514', fn () => DB::table('hr_open_shift_requests')->insert([...$ask, 'public_id' => (string) Str::uuid(), 'locum_user_id' => $unlinked->id]));
        // ... assigned to the branch on that day, and before the shift starts (Suresh, m4).
        $otherBranch = DB::table('branches')->where('organisation_id', $row->organisation_id)->where('id', '<>', $row->branch_id)->value('id');
        $elsewhere = DB::table('hr_open_shifts')->insertGetId([...$base, 'public_id' => (string) Str::uuid(), 'branch_id' => $otherBranch, 'posted_by_user_id' => $this->hr->id]);
        $started = DB::table('hr_open_shifts')->insertGetId([...$base, 'public_id' => (string) Str::uuid(), 'posted_by_user_id' => $this->hr->id,
            'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2)]);
        foreach ([$elsewhere, $started] as $shiftId) {
            $this->refused('23514', fn () => DB::table('hr_open_shift_requests')->insert([...$ask, 'open_shift_id' => $shiftId, 'public_id' => (string) Str::uuid(),
                'locum_user_id' => $locum->id]));
        }
        $this->selectBranch($locum);
        $this->get(route('hr.open-shifts.index'))->assertInertia(fn (Assert $page) => $page->has('openShifts.available', 1)
            ->where('openShifts.available.0.id', $shift)); // neither the other branch's shift nor the started one
        // An assignment that starts after the shift's day hides it too (Suresh, m3).
        $profileId = DB::table('staff_profiles')->where('user_id', $locum->id)->value('id');
        $from = DB::table('staff_branch_assignments')->where('staff_profile_id', $profileId)->value('valid_from');
        DB::table('staff_branch_assignments')->where('staff_profile_id', $profileId)->update(['valid_from' => '2026-11-20']);
        $this->get(route('hr.open-shifts.index'))->assertInertia(fn (Assert $page) => $page->where('openShifts.available', []));
        DB::table('staff_branch_assignments')->where('staff_profile_id', $profileId)->update(['valid_from' => $from]);
        $this->selectBranch($locum);
        $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $request = DB::table('hr_open_shift_requests')->sole();
        $this->refused('23514', fn () => DB::table('hr_open_shift_requests')->where('id', $request->id)->update(['status' => 'confirmed',
            'decided_by_user_id' => $this->hr->id, 'decided_at' => now(), 'lock_version' => 2])); // no roster shift
        $this->refused('23514', fn () => DB::table('hr_open_shift_requests')->where('id', $request->id)->update(['status' => 'declined',
            'decline_reason' => 'other', 'decided_by_user_id' => $ca->id, 'decided_at' => now(), 'lock_version' => 2])); // not a manager
        $this->refused('P0001', fn () => DB::table('hr_open_shift_requests')->delete());
        $this->refused('P0001', fn () => DB::table('hr_open_shifts')->delete());
    }

    // ---- fixture ----

    private function linkedLocum(string $name): User
    {
        $locum = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $worker = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => "Synthetic Locum {$name}", 'phone' => '012-345 6789',
            'idempotency_key' => (string) Str::uuid()]);
        app(LocumSignIns::class)->request($this->hr, $worker->public_id, ['email' => $locum->email, 'idempotency_key' => (string) Str::uuid()]);
        $request = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->first();
        app(LocumSignIns::class)->link($this->director, $request->public_id, ['user_id' => $locum->id, 'expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()]);

        return $locum;
    }

    /** @return array<string, mixed> */
    private function shiftInput(): array
    {
        return ['branch_id' => $this->branch->id, 'date' => '2026-11-17', 'start_time' => '08:00', 'end_time' => '14:00', 'break_minutes' => '30',
            'session_type' => 'morning', 'role_needed' => 'resident_doctor', 'idempotency_key' => (string) Str::uuid()];
    }

    private function post_(User $by): string
    {
        $this->selectBranch($by);
        $this->post(route('hr.open-shifts.store'), $this->shiftInput())->assertSessionHasNoErrors();

        return (string) DB::table('hr_open_shifts')->orderByDesc('id')->value('public_id');
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
