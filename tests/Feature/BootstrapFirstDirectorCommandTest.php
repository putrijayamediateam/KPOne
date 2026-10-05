<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapFirstDirectorCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->where('code', 'CHERAS')
            ->firstOrFail();
    }

    public function test_it_interactively_bootstraps_the_first_google_only_director(): void
    {
        $this->artisan('kpone:bootstrap-director')
            ->expectsQuestion('Director full name', 'Synthetic Director')
            ->expectsQuestion('Director email address', 'director.command@example.test')
            ->expectsQuestion('Director staff number', 'DIR-CMD-001')
            ->expectsChoice('Primary branch', 'CHERAS — Cheras', ['CHERAS — Cheras', 'PUCHONG — Puchong', 'SUNGAI_BESI — Sungai Besi'])
            ->expectsChoice('Sign-in method', 'Google-only sign-in', ['Google-only sign-in', 'Password'])
            ->expectsOutputToContain('Google sign-in requires a verified Google email')
            ->assertExitCode(Command::SUCCESS);

        $director = User::query()
            ->where('email', 'director.command@example.test')
            ->sole();
        $profile = $director->staffProfile;
        $assignments = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->get();

        $this->assertTrue($director->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE));
        $this->assertNull($director->password);
        $this->assertSame(
            Department::query()
                ->where('organisation_id', $this->organisation->id)
                ->where('code', 'LEADERSHIP')
                ->value('id'),
            $profile->department_id,
        );
        $this->assertCount(1, $assignments);
        $this->assertSame($this->cheras->id, $assignments->sole()->branch_id);
        $this->assertTrue($assignments->sole()->is_primary);
        $this->assertSame('permanent', $assignments->sole()->assignment_type);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.director_bootstrapped',
            'subject_id' => $director->id,
            'actor_user_id' => null,
        ]);
    }

    public function test_password_is_collected_interactively_and_never_printed(): void
    {
        $password = 'SyntheticDirector!2026';

        $this->artisan('kpone:bootstrap-director')
            ->expectsQuestion('Director full name', 'Synthetic Password Director')
            ->expectsQuestion('Director email address', 'director.password-command@example.test')
            ->expectsQuestion('Director staff number', 'DIR-CMD-002')
            ->expectsChoice('Primary branch', 'CHERAS — Cheras', ['CHERAS — Cheras', 'PUCHONG — Puchong', 'SUNGAI_BESI — Sungai Besi'])
            ->expectsChoice('Sign-in method', 'Password', ['Google-only sign-in', 'Password'])
            ->expectsQuestion('Director password', $password)
            ->expectsQuestion('Confirm Director password', $password)
            ->doesntExpectOutput($password)
            ->assertExitCode(Command::SUCCESS);

        $director = User::query()
            ->where('email', 'director.password-command@example.test')
            ->sole();

        $this->assertNotNull($director->password);
        $this->assertTrue(password_verify($password, $director->password));
        $this->assertNotSame($password, $director->password);
    }

    public function test_it_refuses_non_interactive_invocation(): void
    {
        $this->artisan('kpone:bootstrap-director', ['--no-interaction' => true])
            ->expectsOutputToContain('requires an interactive terminal')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_a_second_active_director_before_prompting(): void
    {
        User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'is_active' => true,
        ])->assignRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE);

        $this->artisan('kpone:bootstrap-director')
            ->expectsOutputToContain('An active Director already exists')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('staff_profiles', 0);
    }
}
