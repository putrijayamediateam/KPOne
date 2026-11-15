<?php

namespace Tests\Support;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Support\Str;

final class GuardianFixture
{
    public static function assign(User $actor): void
    {
        $profile = StaffProfile::query()->where('user_id', $actor->id)->first();
        if (! $profile) {
            $profile = new StaffProfile;
            $profile->forceFill(['user_id' => $actor->id, 'department_id' => Department::query()->where('organisation_id', $actor->organisation_id)->value('id')])->save();
        }
        $branch = Branch::query()->where('organisation_id', $actor->organisation_id)->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true]);
        $actor->unsetRelation('staffProfile');
        app()->forgetInstance(BranchAccessService::class);
    }

    public static function contact(): array
    {
        return ['relationship' => 'mother', 'full_name' => 'Synthetic Guardian', 'phone' => '+12025550120', 'phone_country' => 'US', 'email' => 'guardian@kpone.test', 'lock_version' => 0, 'retry_key' => (string) Str::uuid()];
    }
}
