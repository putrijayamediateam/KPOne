<?php

namespace Tests\Support;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use LogicException;

/**
 * Creates synthetic assignment fixtures without impersonating a runtime actor.
 *
 * This test-autoloaded boundary deliberately bypasses runtime authorization and
 * auditing. Application code must use BranchAssignmentService instead.
 */
final class StaffBranchAssignmentBootstrapper
{
    /** @param array<string, mixed> $attributes */
    public static function create(
        StaffProfile $profile,
        Branch $branch,
        array $attributes = [],
    ): StaffBranchAssignment {
        if (! app()->environment('testing')) {
            throw new LogicException('Synthetic branch assignment bootstrap is restricted to testing.');
        }

        $values = [
            'staff_profile_id' => $profile->id,
            'branch_id' => $branch->id,
            'assignment_type' => 'permanent',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => null,
            ...$attributes,
        ];

        if ($values['is_primary']) {
            StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $assignment = new StaffBranchAssignment;
        $assignment->forceFill($values)->save();

        return $assignment->refresh();
    }
}
