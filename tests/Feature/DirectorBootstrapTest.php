<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DirectorBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Department $leadership;

    private Branch $cheras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);

        $this->organisation = Organisation::query()
            ->where('code', 'KLINIK_PUTRIJAYA')
            ->firstOrFail();

        $this->leadership = Department::query()
            ->where('organisation_id', $this->organisation->id)
            ->where('code', 'LEADERSHIP')
            ->firstOrFail();

        $this->cheras = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->where('code', 'CHERAS')
            ->firstOrFail();
    }

    public function test_it_bootstraps_the_first_director_using_existing_reference_records(): void
    {
        $user = app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'UAT Director',
            'email' => 'director.bootstrap@example.test',
            'staff_number' => 'DIR-0001',
            'job_title' => 'Director',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'google_only',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'organisation_id' => $this->organisation->id,
            'email' => 'director.bootstrap@example.test',
            'is_active' => true,
        ]);

        $this->assertTrue(
            $user->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)
        );

        $this->assertNull($user->password);

        $profile = $user->staffProfile;

        $this->assertNotNull($profile);
        $this->assertSame($this->leadership->id, $profile->department_id);
        $this->assertSame('DIR-0001', $profile->staff_number);

        $assignments = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->get();

        $this->assertCount(1, $assignments);
        $this->assertTrue($assignments->first()->is_primary);
        $this->assertSame($this->cheras->id, $assignments->first()->branch_id);
        $this->assertSame('permanent', $assignments->first()->assignment_type);
        $this->assertTrue(
            $assignments->first()->valid_from->lte(now()->toDateString())
        );
        $this->assertNull($assignments->first()->valid_until);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.director_bootstrapped',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->cheras->id,
            'actor_user_id' => null,
        ]);
    }

    public function test_it_hashes_a_password_when_password_credentials_are_requested(): void
    {
        $user = app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Password Director',
            'email' => 'director.password@example.test',
            'staff_number' => 'DIR-0002',
            'job_title' => 'Director',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'password',
            'password' => 'StrongDirector!2026',
            'password_confirmation' => 'StrongDirector!2026',
        ]);

        $this->assertNotNull($user->password);
        $this->assertTrue(
            password_verify('StrongDirector!2026', $user->password)
        );
        $this->assertNotSame('StrongDirector!2026', $user->password);
    }

    public function test_it_rejects_a_second_active_director(): void
    {
        app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'First Director',
            'email' => 'director.first@example.test',
            'staff_number' => 'DIR-0001',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'google_only',
        ]);

        try {
            app(DirectorBootstrapService::class)->bootstrap([
                'name' => 'Second Director',
                'email' => 'director.second@example.test',
                'staff_number' => 'DIR-0002',
                'branch_id' => $this->cheras->id,
                'credential_strategy' => 'google_only',
            ]);

            $this->fail('Expected the second active Director bootstrap to be rejected.');
        } catch (ValidationException) {
            // Expected: the organisation already has an active Director.
        }

        $this->assertDatabaseCount('users', 1);

        $this->assertDatabaseHas('users', [
            'email' => 'director.first@example.test',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'director.second@example.test',
        ]);
    }

    public function test_it_rejects_a_branch_from_another_organisation(): void
    {
        $otherOrganisation = (new Organisation)
            ->forceFill([
                'code' => 'OTHER_ORG',
                'name' => 'Other Organisation',
                'is_active' => true,
            ]);

        $otherOrganisation->save();

        $otherBranch = (new Branch)
            ->forceFill([
                'organisation_id' => $otherOrganisation->id,
                'code' => 'OTHER_BRANCH',
                'name' => 'Other Branch',
                'timezone' => 'Asia/Kuala_Lumpur',
                'is_active' => true,
            ]);

        $otherBranch->save();

        $this->expectException(ValidationException::class);

        app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Foreign Branch Director',
            'email' => 'director.foreign@example.test',
            'staff_number' => 'DIR-0003',
            'branch_id' => $otherBranch->id,
            'credential_strategy' => 'google_only',
        ]);
    }

    public function test_it_rejects_an_inactive_branch(): void
    {
        $this->cheras->forceFill([
            'is_active' => false,
        ])->save();

        $this->expectException(ValidationException::class);

        app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Inactive Branch Director',
            'email' => 'director.inactive@example.test',
            'staff_number' => 'DIR-0004',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'google_only',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'director.inactive@example.test',
        ]);
    }

    public function test_it_rejects_invalid_password_credentials(): void
    {
        $this->expectException(ValidationException::class);

        app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Invalid Password Director',
            'email' => 'director.invalid@example.test',
            'staff_number' => 'DIR-0005',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'password',
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'director.invalid@example.test',
        ]);
    }

    public function test_it_rejects_duplicate_email_and_staff_number(): void
    {
        $existingUser = (new User)
            ->forceFill([
                'organisation_id' => $this->organisation->id,
                'name' => 'Existing User',
                'email' => 'existing@example.test',
                'is_active' => true,
            ]);

        $existingUser->save();

        $existingProfile = (new StaffProfile)
            ->forceFill([
                'user_id' => $existingUser->id,
                'department_id' => $this->leadership->id,
                'staff_number' => 'DIR-EXISTING',
                'job_title' => 'Existing Staff',
            ]);

        $existingProfile->save();

        $this->expectException(ValidationException::class);

        app(DirectorBootstrapService::class)->bootstrap([
            'name' => 'Duplicate Director',
            'email' => 'existing@example.test',
            'staff_number' => 'DIR-EXISTING',
            'branch_id' => $this->cheras->id,
            'credential_strategy' => 'google_only',
        ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'email' => 'existing@example.test',
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'director.invalid@example.test',
        ]);
    }
}
