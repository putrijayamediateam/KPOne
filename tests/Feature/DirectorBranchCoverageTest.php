<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;

class DirectorBranchCoverageTest extends VisitTestCase
{
    private function other(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
    }

    private function rows(User $user)
    {
        return StaffBranchAssignment::query()->where('staff_profile_id', $user->staffProfile->id)->orderBy('branch_id')->get();
    }

    private function payload(Branch $branch): array
    {
        return [
            'expected_branch_id' => $branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Clinic Group Ltd',
            'clinic_name' => 'Synthetic Puchong Clinic', 'address_line_1' => '1 Example Street', 'address_line_2' => '',
            'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State', 'country' => 'Malaysia',
            'phone' => '', 'email' => 'clinic@director-coverage.test', 'registration_type' => 'BRN',
            'registration_number' => 'SYN-BRN-002', 'remove_logo' => false,
        ];
    }

    public function test_director_with_one_branch_cannot_write_at_another_branch_until_covered(): void
    {
        $director = $this->actor('director');
        $puchong = $this->other();
        $this->selectBranch($director, $puchong);
        $this->post('/business-details', $this->payload($puchong))->assertForbidden();

        $this->assertSame(2, app(BranchAssignmentService::class)->ensureDirectorCoverage($director));
        $this->app['request'] = request(); // fresh branch cache
        $this->selectBranch($director->fresh(), $puchong);
        $this->post('/business-details', $this->payload($puchong))->assertRedirect(route('business-details.index'));
    }

    public function test_coverage_adds_non_primary_open_rows_keeps_primary_audits_and_is_idempotent(): void
    {
        $director = $this->actor('director');
        $service = app(BranchAssignmentService::class);

        $this->assertSame(2, $service->ensureDirectorCoverage($director));
        $this->assertSame(0, $service->ensureDirectorCoverage($director));

        $rows = $this->rows($director);
        $this->assertCount(3, $rows);
        $this->assertSame([$this->branch->id], $rows->where('is_primary', true)->pluck('branch_id')->all());
        foreach ($rows as $row) {
            $this->assertSame('permanent', $row->assignment_type);
            $this->assertNull($row->valid_until);
        }
        $audit = DB::table('audit_logs')->where('event', 'staff.director_branch_coverage.granted')->get();
        $this->assertCount(2, $audit);
        $this->assertNull($audit->first()->actor_user_id);
    }

    public function test_a_branch_opened_later_is_covered_by_the_next_run(): void
    {
        $director = $this->actor('director');
        $service = app(BranchAssignmentService::class);
        $service->ensureDirectorCoverage($director);
        $this->other()->forceFill(['is_active' => false])->save();
        StaffBranchAssignment::query()->where('staff_profile_id', $director->staffProfile->id)->where('branch_id', $this->other()->id)->delete();
        $this->assertSame(0, $service->ensureDirectorCoverage($director)); // inactive branch is skipped
        $this->other()->forceFill(['is_active' => true])->save();
        $this->assertSame(1, $service->ensureDirectorCoverage($director));
    }

    public function test_only_an_active_director_can_receive_it_and_no_other_role_gains_anything(): void
    {
        foreach (['ca', 'ca_supervisor', 'administrator', 'technical_admin', 'hr_manager', 'finance_officer'] as $role) {
            $user = $this->actor($role);
            try {
                app(BranchAssignmentService::class)->ensureDirectorCoverage($user);
                $this->fail("{$role} must be refused");
            } catch (ValidationException) {
            }
            $this->assertCount(1, $this->rows($user));
        }

        $inactive = $this->actor('director');
        $inactive->forceFill(['is_active' => false])->save();
        $this->expectException(ValidationException::class);
        app(BranchAssignmentService::class)->ensureDirectorCoverage($inactive);
    }

    public function test_command_covers_existing_directors_and_a_non_director_is_untouched(): void
    {
        $director = $this->actor('director');
        $ca = $this->actor('ca');

        $this->artisan('kpone:director-branches')->assertExitCode(0);
        $this->assertCount(3, $this->rows($director));
        $this->assertCount(1, $this->rows($ca));
        $this->artisan('kpone:director-branches')->expectsOutputToContain('already covers')->assertExitCode(0);
    }

    public function test_command_fails_without_a_director(): void
    {
        $this->artisan('kpone:director-branches')->assertExitCode(1);
    }

    public function test_it_cannot_be_used_from_a_web_request(): void
    {
        $director = $this->actor('director');
        $this->app['env'];
        $reflect = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $reflect->setValue($this->app, false);
        try {
            $this->expectException(AuthorizationException::class);
            app(BranchAssignmentService::class)->ensureDirectorCoverage($director);
        } finally {
            $reflect->setValue($this->app, true);
        }
    }
}
