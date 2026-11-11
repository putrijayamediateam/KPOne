<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffAdministrationService;
use App\Domain\Identity\Services\StaffDirectoryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Finder\Finder;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * Staff branch assignment periods are branch-local calendar dates.
 *
 * Every test freezes the clock at 01:00 Kuala Lumpur, which is 17:00 UTC the
 * previous day, so the UTC date is one day behind the branch. The dates are
 * built from the real clock, so the tests never age.
 */
class StaffAssignmentBranchDayTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    private Branch $puchong;

    private Department $department;

    /** Branch-local "today" at the frozen instant. */
    private string $branchToday;

    /** Branch-local "yesterday", which is also the UTC date at the frozen instant. */
    private string $branchYesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureReferenceData();
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $this->puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
        $this->department = Department::query()->where('organisation_id', $this->organisation->id)->firstOrFail();

        $this->assertSame('Asia/Kuala_Lumpur', $this->cheras->timezone);
        $this->assertSame('Asia/Kuala_Lumpur', $this->puchong->timezone);

        $frozen = now('Asia/Kuala_Lumpur')->addDay()->setTime(1, 0);
        $this->travelTo($frozen);

        $this->branchToday = $frozen->toDateString();
        $this->branchYesterday = $frozen->copy()->subDay()->toDateString();

        // The premise of every test below: UTC is still on the branch's yesterday.
        $this->assertSame($this->branchYesterday, now('UTC')->toDateString());
        $this->assertSame(17, now('UTC')->hour);
    }

    public function test_directory_branch_filter_uses_the_filtered_branch_calendar_day(): void
    {
        $viewer = $this->createStaff('director', []);
        $starter = $this->createStaff('ca', [], 'branch.day.starter@kpone.test');
        StaffBranchAssignmentBootstrapper::create($starter->staffProfile, $this->puchong, [
            'is_primary' => true,
            'valid_from' => $this->branchToday,
        ]);
        $leaver = $this->createStaff('ca', [], 'branch.day.leaver@kpone.test');
        StaffBranchAssignmentBootstrapper::create($leaver->staffProfile, $this->puchong, [
            'assignment_type' => 'temporary',
            'is_primary' => true,
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_until' => $this->branchYesterday,
        ]);

        $this->actingAs($viewer)->get(route('staff.index', [
            'search' => 'branch.day.',
            'branch' => $this->puchong->id,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Staff/Index')
            ->has('staff.data', 1)
            ->where('staff.data.0.id', $starter->id));
    }

    public function test_assignment_state_label_uses_the_assignment_branch_calendar_day(): void
    {
        $viewer = $this->createStaff('director', []);
        $subject = $this->createStaff('ca', [$this->cheras]);
        $starts = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->puchong, [
            'valid_from' => $this->branchToday,
        ]);
        $ended = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->puchong, [
            'assignment_type' => 'temporary',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_until' => $this->branchYesterday,
        ]);

        $assignments = collect(app(StaffDirectoryService::class)->detail($viewer, $subject)['assignments']);

        $this->assertSame('current', $assignments->firstWhere('id', $starts->id)['state']);
        $this->assertSame('ended', $assignments->firstWhere('id', $ended->id)['state']);
    }

    public function test_provisioning_accepts_a_primary_that_starts_today_at_its_branch(): void
    {
        $actor = $this->createStaff('director', []);

        $response = $this->actingAs($actor)->post(route('staff.store'), [
            'name' => 'Synthetic Branch Day Starter',
            'email' => 'branch.day.provisioned@kpone.test',
            'credential_strategy' => 'google_only',
            'department_id' => $this->department->id,
            'staff_number' => 'SYN-DAY-0001',
            'job_title' => 'Synthetic Operations Role',
            'is_active' => true,
            'roles' => ['ca'],
            'assignments' => [[
                'branch_id' => $this->cheras->id,
                'assignment_type' => 'permanent',
                'is_primary' => true,
                'valid_from' => $this->branchToday,
                'valid_until' => null,
            ]],
        ]);

        $staff = User::query()->where('email', 'branch.day.provisioned@kpone.test')->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('staff.show', $staff));
        $this->assertSame($this->branchToday, $staff->staffProfile->branchAssignments()->sole()->valid_from->toDateString());
    }

    public function test_reactivation_accepts_a_primary_that_starts_today_at_its_branch(): void
    {
        $actor = $this->createStaff('director', [$this->cheras]);
        $subject = $this->createStaff('ca', [], null, false);
        StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->cheras, [
            'is_primary' => true,
            'valid_from' => $this->branchToday,
        ]);

        app(StaffAdministrationService::class)->setActive($subject, true, $actor);

        $this->assertTrue($subject->fresh()->is_active);
    }

    public function test_reactivation_refuses_a_primary_that_ended_yesterday_at_its_branch(): void
    {
        $actor = $this->createStaff('director', [$this->cheras]);
        $subject = $this->createStaff('ca', [], null, false);
        StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->cheras, [
            'assignment_type' => 'temporary',
            'is_primary' => true,
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_until' => $this->branchYesterday,
        ]);

        $this->actingAs($actor)->patch(route('staff.status.update', $subject), ['is_active' => true])
            ->assertSessionHasErrors('is_active');
        $this->assertFalse($subject->fresh()->is_active);
    }

    public function test_change_primary_accepts_an_assignment_that_starts_today_at_its_branch(): void
    {
        $actor = $this->createStaff('director', [$this->cheras]);
        $subject = $this->createStaff('ca', [$this->cheras]);
        $starts = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->puchong, [
            'valid_from' => $this->branchToday,
        ]);

        app(BranchAssignmentService::class)->changePrimary($subject->staffProfile, $starts, $actor);

        $this->assertTrue($starts->fresh()->is_primary);
        $this->assertSame(1, $subject->staffProfile->branchAssignments()->where('is_primary', true)->count());
    }

    public function test_active_primary_may_be_moved_to_start_today_at_its_branch(): void
    {
        $actor = $this->createStaff('director', [$this->cheras]);
        $subject = $this->createStaff('ca', []);
        $primary = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->cheras, [
            'is_primary' => true,
            'valid_from' => now()->subMonth()->toDateString(),
        ]);

        app(BranchAssignmentService::class)->update($primary, ['valid_from' => $this->branchToday], $actor);

        $this->assertSame($this->branchToday, $primary->fresh()->valid_from->toDateString());
    }

    public function test_application_code_always_passes_a_date_to_the_effective_at_scope(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match('/->effectiveAt\(\s*\)/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Pass the branch-local date, for example StaffBranchAssignment::todayAt($branch).');
    }

    public function test_today_at_returns_the_branch_calendar_day_not_the_utc_day(): void
    {
        $this->assertSame($this->branchToday, StaffBranchAssignment::todayAt($this->cheras));
        $this->assertSame($this->branchToday, StaffBranchAssignment::todayForBranchId($this->cheras->id, [$this->cheras->id => 'Asia/Kuala_Lumpur']));
        $this->assertSame($this->branchYesterday, StaffBranchAssignment::todayForBranchId('not-a-branch', []));
    }

    /** @param list<Branch> $branches */
    private function createStaff(string $role, array $branches, ?string $email = null, bool $active = true): User
    {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Synthetic '.Str::random(8),
            'email' => $email ?? 'synthetic.'.Str::uuid().'@kpone.test',
            'is_active' => $active,
            'deactivated_at' => $active ? null : now(),
        ]);
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id,
            'department_id' => $this->department->id,
            'staff_number' => 'SYN-'.Str::upper(Str::random(8)),
            'job_title' => 'Synthetic Test Role',
        ]);

        foreach ($branches as $index => $branch) {
            StaffBranchAssignmentBootstrapper::create($profile, $branch, [
                'is_primary' => $index === 0,
                'valid_from' => now()->subMonth()->toDateString(),
            ]);
        }

        $user->assignRole($role);

        return $user->refresh()->load('staffProfile');
    }
}
