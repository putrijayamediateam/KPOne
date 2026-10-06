<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\DirectorBootstrapService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

class KPOnePresentationSeeder extends Seeder
{
    public const PASSWORD = 'KPOne-Presentation-Only-2026!';

    /** @var array<string, array{department: string, title: string}> */
    private const DEMO_ROLES = [
        'resident_doctor' => ['department' => 'CLINICAL', 'title' => 'Resident Doctor'],
        'ca' => ['department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant'],
        'ca_supervisor' => ['department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant Supervisor'],
        'panel_officer' => ['department' => 'PANEL', 'title' => 'Panel Officer'],
        'finance_officer' => ['department' => 'FINANCE', 'title' => 'Finance Officer'],
        'business_development' => ['department' => 'BUSINESS_DEVELOPMENT', 'title' => 'Business Development'],
        'marketing' => ['department' => 'MARKETING', 'title' => 'Marketing'],
        'hr_manager' => ['department' => 'HR_MANAGEMENT', 'title' => 'HR Manager'],
        'technical_admin' => ['department' => 'TECHNOLOGY', 'title' => 'Technical Administrator'],
        'queue_display' => ['department' => 'CLINIC_OPERATIONS', 'title' => 'Waiting Room TV'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Presentation data is restricted to local and testing environments.');
        }

        $organisation = Organisation::query()
            ->where('code', 'KLINIK_PUTRIJAYA')
            ->where('is_active', true)
            ->firstOrFail();
        $branches = Branch::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->keyBy('code');
        $branch = $branches->get('CHERAS');
        if ($branch === null || $branches->count() !== 3) {
            throw new LogicException('All three active presentation branches must be seeded first.');
        }

        $director = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('email', 'demo.director@kpone.test')
            ->first();

        if ($director === null) {
            $director = app(DirectorBootstrapService::class)->bootstrap([
                'name' => 'Demo Director',
                'email' => 'demo.director@kpone.test',
                'staff_number' => 'DEMO-0001',
                'job_title' => 'Director',
                'branch_id' => $branch->id,
                'credential_strategy' => 'password',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ]);
        }

        $branchList = array_values($branches->all());
        $this->seedDirectorAdditionalBranches($director, $branchList, $branch->id);
        $this->seedRoleAccounts($organisation, $branchList);
        $this->seedPatients($director);
    }

    /** @param list<Branch> $branches */
    private function seedRoleAccounts(Organisation $organisation, array $branches): void
    {
        $index = 2;
        foreach (self::DEMO_ROLES as $role => $details) {
            $branch = $branches[($index - 2) % count($branches)];
            $email = "demo.{$role}@kpone.test";
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'organisation_id' => $organisation->id,
                'name' => 'Demo '.str($role)->replace('_', ' ')->title(),
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
                'deactivated_at' => null,
            ])->save();

            $department = Department::query()
                ->where('organisation_id', $organisation->id)
                ->where('code', $details['department'])
                ->where('is_active', true)
                ->firstOrFail();
            $profile = StaffProfile::query()->firstOrNew(['user_id' => $user->id]);
            $profile->forceFill([
                'department_id' => $department->id,
                'staff_number' => sprintf('DEMO-%04d', $index),
                'job_title' => $details['title'],
            ])->save();

            StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->where('is_primary', true)
                ->whereNull('valid_until')
                ->where('branch_id', '!=', $branch->id)
                ->update(['is_primary' => false]);

            $assignment = StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->where('branch_id', $branch->id)
                ->where('assignment_type', 'permanent')
                ->whereNull('valid_until')
                ->first();
            if ($assignment === null) {
                $assignment = new StaffBranchAssignment;
                $assignment->forceFill([
                    'staff_profile_id' => $profile->id,
                    'branch_id' => $branch->id,
                    'assignment_type' => 'permanent',
                    'valid_from' => '2026-01-01',
                ]);
            }
            $assignment->forceFill([
                'is_primary' => true,
                'valid_until' => null,
            ])->save();

            $user->syncRoles([$role]);
            $index++;
        }
    }

    /** @param list<Branch> $branches */
    private function seedDirectorAdditionalBranches(User $director, array $branches, int $primaryBranchId): void
    {
        $profile = StaffProfile::query()->where('user_id', $director->id)->firstOrFail();

        foreach ($branches as $branch) {
            if ($branch->id === $primaryBranchId) {
                continue;
            }

            $assignment = StaffBranchAssignment::query()->firstOrNew([
                'staff_profile_id' => $profile->id,
                'branch_id' => $branch->id,
                'assignment_type' => 'permanent',
                'valid_from' => '2026-01-01',
            ]);
            $assignment->forceFill([
                'is_primary' => false,
                'valid_until' => null,
            ])->save();
        }
    }

    private function seedPatients(User $actor): void
    {
        for ($index = 1; $index <= 10; $index++) {
            $name = sprintf('Demo Patient %02d', $index);
            if (Patient::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('full_name', $name)
                ->exists()) {
                continue;
            }

            app(PatientAdministrationService::class)->create($actor, [
                'full_name' => $name,
                'date_of_birth' => sprintf('%04d-05-15', 1980 + $index),
                'sex' => $index % 2 === 0 ? 'female' : 'male',
                'nationality_code' => 'MY',
                'mobile_phone' => '+60123456789',
                'phone_country' => 'MY',
                'email' => sprintf('patient-%02d@demo.kpone.test', $index),
                'country_code' => 'MY',
                'identifiers' => [[
                    'identifier_type' => 'passport',
                    'issuing_country_code' => 'MY',
                    'value' => sprintf('KPONEDEMO%04d', $index),
                ]],
                'duplicate_override' => true,
            ]);
        }
    }
}
