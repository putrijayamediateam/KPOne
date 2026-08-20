<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\StaffDirectoryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    private Branch $puchong;

    private Department $clinical;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $this->puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
        $this->clinical = Department::query()->where('name', 'Clinical')->firstOrFail();
        $this->finance = Department::query()->where('name', 'Finance')->firstOrFail();
    }

    public function test_organisation_scoped_directory_supports_server_side_search_and_filters(): void
    {
        $viewer = $this->createStaff('director', $this->clinical, []);
        $matching = $this->createStaff('finance_officer', $this->finance, [$this->puchong], 'find.me@kpone.test');
        $this->createStaff('ca', $this->clinical, [$this->cheras], 'not.me@kpone.test');

        $this->actingAs($viewer)->get(route('staff.index', [
            'search' => 'find.me',
            'branch' => $this->puchong->id,
            'department' => $this->finance->id,
            'role' => 'finance_officer',
            'status' => 'active',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Staff/Index')
            ->has('staff.data', 1)
            ->where('staff.data.0.id', $matching->id)
            ->where('staff.data.0.department', 'Finance')
            ->where('staff.data.0.primaryBranch.code', 'PUCHONG'));
    }

    public function test_branch_scoped_directory_only_returns_visible_staff_and_visible_assignment_data(): void
    {
        $viewer = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $subject = $this->createStaff('finance_officer', $this->finance, [$this->cheras, $this->puchong], 'shared.staff@kpone.test');

        $this->actingAs($viewer)->get(route('staff.index', ['search' => $subject->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('staff.data', 1)
                ->where('staff.data.0.id', $subject->id)
                ->has('staff.data.0.currentAssignments', 1)
                ->where('staff.data.0.currentAssignments.0.branch.code', 'CHERAS'));
    }

    public function test_branch_scoped_filter_cannot_probe_an_unassigned_branch(): void
    {
        $viewer = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $this->createStaff('finance_officer', $this->finance, [$this->cheras, $this->puchong], 'multi.branch@kpone.test');

        $this->actingAs($viewer)->get(route('staff.index', ['branch' => $this->puchong->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('staff.data', 0));
    }

    public function test_branch_scoped_user_cannot_open_staff_detail_outside_their_scope(): void
    {
        $viewer = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $subject = $this->createStaff('finance_officer', $this->finance, [$this->puchong]);

        $this->actingAs($viewer)->get(route('staff.show', $subject))->assertForbidden();
    }

    public function test_staff_detail_answers_status_role_department_and_effective_assignment_questions(): void
    {
        $viewer = $this->createStaff('director', $this->clinical, []);
        $subject = $this->createStaff('finance_officer', $this->finance, [$this->cheras]);
        StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->puchong, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->addDay()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($viewer)->get(route('staff.show', $subject))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Show')
                ->where('staff.isActive', true)
                ->where('staff.department', 'Finance')
                ->where('staff.primaryBranch.code', 'CHERAS')
                ->where('staff.roles.0', 'finance_officer')
                ->has('staff.assignments', 2)
                ->where('staff.assignments.1.state', 'future'));
    }

    public function test_staff_detail_projects_safe_sign_in_configuration_states(): void
    {
        $viewer = $this->createStaff('director', $this->clinical, []);
        $password = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $awaitingGoogle = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $awaitingGoogle->forceFill(['password' => null])->save();
        $linkedGoogle = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $linkedGoogle->forceFill([
            'password' => null,
            'google_subject' => 'synthetic-linked-google-subject',
        ])->save();
        $directory = app(StaffDirectoryService::class);

        $this->assertSame('password', $directory->detail($viewer, $password)['signInConfiguration']);
        $this->assertSame(
            'google_awaiting_first_sign_in',
            $directory->detail($viewer, $awaitingGoogle)['signInConfiguration'],
        );
        $linkedDetail = $directory->detail($viewer, $linkedGoogle);
        $this->assertSame('google_linked', $linkedDetail['signInConfiguration']);
        $this->assertArrayNotHasKey('google_subject', $linkedDetail);
        $this->assertArrayNotHasKey('googleSubject', $linkedDetail);
    }

    public function test_staff_detail_marks_expiring_assignment_ineligible_for_active_primary(): void
    {
        $viewer = $this->createStaff('director', $this->clinical, []);
        $subject = $this->createStaff('ca', $this->clinical, [$this->cheras]);
        $temporary = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->puchong, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
        ]);

        $detail = app(StaffDirectoryService::class)->detail($viewer, $subject);
        $projected = collect($detail['assignments'])->firstWhere('id', $temporary->id);

        $this->assertSame('current', $projected['state']);
        $this->assertFalse($projected['canBecomePrimary']);
    }

    /** @param list<Branch> $branches */
    private function createStaff(
        string $role,
        Department $department,
        array $branches,
        ?string $email = null,
    ): User {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Synthetic '.Str::random(8),
            'email' => $email ?? 'synthetic.'.Str::uuid().'@kpone.test',
        ]);
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'staff_number' => 'SYN-'.Str::upper(Str::random(8)),
            'job_title' => 'Synthetic Test Role',
        ]);

        foreach ($branches as $index => $branch) {
            StaffBranchAssignmentBootstrapper::create($profile, $branch, [
                'assignment_type' => 'permanent',
                'is_primary' => $index === 0,
                'valid_from' => now()->toDateString(),
            ]);
        }

        $user->assignRole($role);

        return $user->refresh()->load('staffProfile');
    }
}
