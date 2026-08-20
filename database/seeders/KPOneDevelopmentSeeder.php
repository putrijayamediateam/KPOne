<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class KPOneDevelopmentSeeder extends Seeder
{
    public const EMAIL = 'dev.admin@kpone.test';

    public const PASSWORD = 'KPOne-Dev-Only!';

    public function run(): void
    {
        $organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $department = Department::query()
            ->where('organisation_id', $organisation->id)
            ->where('code', 'TECHNOLOGY')
            ->firstOrFail();

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'Development Administrator',
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'deactivated_at' => null,
        ])->save();

        $profile = StaffProfile::query()->firstOrNew(['user_id' => $user->id]);
        $profile->forceFill([
            'department_id' => $department->id,
            'staff_number' => 'DEV-0001',
            'job_title' => 'Development Administrator',
        ])->save();

        $user->syncRoles(['technical_admin']);

        foreach (Branch::query()->where('organisation_id', $organisation->id)->orderBy('id')->get() as $index => $branch) {
            $assignment = StaffBranchAssignment::query()->firstOrNew(
                [
                    'staff_profile_id' => $profile->id,
                    'branch_id' => $branch->id,
                    'assignment_type' => 'development_support',
                    'valid_from' => '2026-01-01',
                ],
            );
            $assignment->forceFill(['is_primary' => $index === 0, 'valid_until' => null])->save();
        }
    }
}
