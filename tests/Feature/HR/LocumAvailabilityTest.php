<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Availability\AvailabilityPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22f locum free times (HR-PLAN 29.7; owner, via B.E.R.N.I.S.S, 24 Nov 2026). A linked locum proposes; a roster
 * planner who is not the locum uses it (a draft roster shift inside the offered time) or declines it; the locum may
 * withdraw it while it waits. Synthetic data only.
 */
class LocumAvailabilityTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        // A Tuesday morning in the branch's time zone; free times are on the next Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_only_a_locum_holds_the_new_permission_and_it_is_not_authority(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p): bool => in_array(AvailabilityPermissions::PROPOSE_OWN, $p, true))->keys()->values()->all();
        $this->assertSame(['locum'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(AvailabilityPermissions::PROPOSE_OWN));
        $this->assertTrue(DB::table('roles')->join('role_has_permissions as rp', 'rp.role_id', '=', 'roles.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('roles.name', 'locum')->where('p.name', AvailabilityPermissions::PROPOSE_OWN)->exists(), 'seeded by the migration');
        $this->assertFalse($this->actor('resident_doctor')->can(AvailabilityPermissions::PROPOSE_OWN));
    }

    public function test_a_linked_locum_proposes_with_an_encrypted_note_and_bad_proposals_are_refused(): void
    {
        $locum = $this->linkedLocum('A');
        $this->propose($locum, ['note' => 'Can stay late if needed'])->assertSessionHasNoErrors();
        $row = DB::table('hr_locum_availability')->sole();
        $this->assertSame(['waiting', $locum->id, $this->branch->id], [$row->status, (int) $row->locum_user_id, (int) $row->branch_id]);
        $this->assertStringNotContainsString('stay late', (string) $row->note_encrypted);
        $this->assertSame(['availability_id' => (int) $row->id, 'has_note' => true],
            json_decode((string) DB::table('audit_logs')->where('event', 'hr.locum_availability.proposed')->value('metadata'), true));

        $this->propose($locum, ['date' => '2026-11-09'])->assertSessionHasErrors('date'); // in the past
        $this->propose($locum, ['note' => str_repeat('x', 201)])->assertSessionHasErrors('note');
        $this->propose($locum, ['end_time' => '07:00', 'start_time' => '06:00', 'date' => '2026-11-17', 'branch_id' => 999999])->assertSessionHasErrors('branch_id');
        $unlinked = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $this->propose($unlinked)->assertSessionHasErrors('availability');
        $this->selectBranch($this->hr);
        $this->post(route('hr.free-times.store'), $this->input())->assertForbidden(); // only a locum proposes
        $this->assertSame(1, DB::table('hr_locum_availability')->count());
    }

    public function test_hr_uses_a_free_time_inside_the_offered_time_and_the_locum_sees_it_used(): void
    {
        $locum = $this->linkedLocum('B');
        $this->propose($locum)->assertSessionHasNoErrors();
        $row = DB::table('hr_locum_availability')->sole();

        $this->selectBranch($this->hr);
        $this->use_($row, ['start_time' => '07:00', 'end_time' => '12:00'])->assertSessionHasErrors('start_time'); // starts before the offer
        $this->use_($row, ['start_time' => '09:00', 'end_time' => '13:00'])->assertSessionHasNoErrors();
        $row = DB::table('hr_locum_availability')->sole();
        $this->assertSame(['used', $this->hr->id], [$row->status, (int) $row->decided_by_user_id]);
        $shift = RosterShift::query()->findOrFail($row->roster_shift_id);
        $this->assertSame([$locum->id, '09:00', '13:00', 'draft'], [$shift->staff_user_id, $shift->starts_at->setTimezone($this->branch->timezone)->format('H:i'),
            $shift->ends_at->setTimezone($this->branch->timezone)->format('H:i'), $shift->version->status]);

        $this->use_($row, ['start_time' => '09:00', 'end_time' => '13:00'])->assertSessionHasErrors('availability'); // once only
        $this->decline($row)->assertSessionHasErrors('availability');
        $this->selectBranch($locum);
        $this->get(route('hr.open-shifts.index', ['tab' => 'free']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('freeTimes.mine.0.status', 'used')->where('freeTimes.mine.0.note', null)->where('freeTimes.canDecide', false)->where('freeTimes.waiting', []));
    }

    public function test_decline_and_withdraw_happen_once_and_postgresql_keeps_a_decided_proposal(): void
    {
        $locum = $this->linkedLocum('C');
        $this->propose($locum)->assertSessionHasNoErrors();
        $this->propose($locum, ['date' => '2026-11-18'])->assertSessionHasNoErrors();
        [$first, $second] = DB::table('hr_locum_availability')->orderBy('id')->get()->all();

        $this->selectBranch($this->hr);
        $this->decline($first, ['reason' => 'maybe'])->assertSessionHasErrors('reason');
        $this->decline($first)->assertSessionHasNoErrors();
        $this->assertSame(['declined', 'not_needed'], [DB::table('hr_locum_availability')->where('id', $first->id)->value('status'),
            DB::table('hr_locum_availability')->where('id', $first->id)->value('decline_reason')]);

        $this->selectBranch($locum);
        $this->post(route('hr.free-times.withdraw', $first->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('availability');
        $this->post(route('hr.free-times.withdraw', $second->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', DB::table('hr_locum_availability')->where('id', $second->id)->value('status'));

        $this->refused('P0001', fn () => DB::table('hr_locum_availability')->where('id', $first->id)->update(['status' => 'waiting', 'decline_reason' => null,
            'decided_by_user_id' => null, 'decided_at' => null, 'lock_version' => 3]));
        $this->refused('P0001', fn () => DB::table('hr_locum_availability')->where('id', $first->id)->delete());
    }

    public function test_hr_sees_waiting_free_times_with_the_note_and_a_past_one_expires(): void
    {
        $locum = $this->linkedLocum('D');
        $this->propose($locum, ['note' => 'Prefer mornings'])->assertSessionHasNoErrors();
        $this->selectBranch($this->hr);
        $this->get(route('hr.open-shifts.index', ['tab' => 'free']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('openShifts.tab', 'free')->where('freeTimes.canDecide', true)
            ->where('freeTimes.waiting.0.note', 'Prefer mornings')->where('freeTimes.waiting.0.locum', $locum->name)->where('freeTimes.waiting.0.startTime', '08:00'));

        $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00', $this->branch->timezone)); // after the offered time ends
        $this->get(route('hr.open-shifts.index', ['tab' => 'free']))->assertInertia(fn (Assert $page) => $page->where('freeTimes.waiting', []));
        $this->selectBranch($locum);
        $this->get(route('hr.open-shifts.index', ['tab' => 'free']))->assertInertia(fn (Assert $page) => $page
            ->where('freeTimes.mine.0.status', 'expired')->where('freeTimes.mine.0.statusLabel', 'Expired')->where('freeTimes.waitingCount', 0));
    }

    public function test_at_most_thirty_waiting_free_times(): void
    {
        $locum = $this->linkedLocum('E');
        for ($i = 0; $i < 30; $i++) {
            $this->propose($locum, ['date' => CarbonImmutable::parse('2026-11-11')->addDays($i)->toDateString()])->assertSessionHasNoErrors();
        }
        $this->travel(61)->seconds(); // past the route's 30-a-minute limit, so the cap is what refuses
        $this->propose($locum, ['date' => '2026-12-20'])->assertSessionHasErrors(['availability' => 'You already have 30 free times waiting. Withdraw one first.']);
        $this->assertSame(30, DB::table('hr_locum_availability')->count());
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

        return $locum->refresh();
    }

    /** @return array<string, mixed> */
    private function input(array $over = []): array
    {
        return [...['branch_id' => $this->branch->id, 'date' => '2026-11-17', 'start_time' => '08:00', 'end_time' => '14:00',
            'idempotency_key' => (string) Str::uuid()], ...$over];
    }

    /** @param array<string, mixed> $over */
    private function propose(User $locum, array $over = []): TestResponse
    {
        $this->selectBranch($locum);

        return $this->post(route('hr.free-times.store'), $this->input($over));
    }

    /** @param array<string, mixed> $over */
    private function use_(\stdClass $row, array $over = []): TestResponse
    {
        return $this->post(route('hr.free-times.use', $row->public_id), [...['start_time' => '08:00', 'end_time' => '14:00', 'break_minutes' => 0,
            'session_type' => 'morning', 'branch_room_id' => null, 'station' => null,
            'expected_lock_version' => (int) DB::table('hr_locum_availability')->where('id', $row->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid()], ...$over]);
    }

    /** @param array<string, mixed> $over */
    private function decline(\stdClass $row, array $over = []): TestResponse
    {
        return $this->post(route('hr.free-times.decline', $row->public_id), [...['reason' => 'not_needed',
            'expected_lock_version' => (int) DB::table('hr_locum_availability')->where('id', $row->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid()], ...$over]);
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
