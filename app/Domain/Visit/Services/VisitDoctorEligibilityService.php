<?php

namespace App\Domain\Visit\Services;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VisitDoctorEligibilityService
{
    /** @return Collection<int, User> */
    public function eligibleDoctors(Branch $branch, string $effectiveDate): Collection
    {
        return $this->eligibleQuery($branch, $effectiveDate)
            ->orderBy('name')
            ->get(['users.id', 'users.name']);
    }

    /** @return list<int> */
    public function eligibleDoctorIds(Branch $branch, string $effectiveDate): array
    {
        return array_values($this->eligibleQuery($branch, $effectiveDate)
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all());
    }

    public function lockAndValidate(int $doctorId, Branch $branch, string $effectiveDate): User
    {
        $doctor = User::query()
            ->whereKey($doctorId)
            ->where('organisation_id', $branch->organisation_id)
            ->lockForUpdate()
            ->first();

        if (! $doctor || ! $doctor->is_active || ! $doctor->hasRole('resident_doctor')) {
            $this->invalid();
        }

        $profile = StaffProfile::query()
            ->where('user_id', $doctor->id)
            ->lockForUpdate()
            ->first();
        if (! $profile) {
            $this->invalid();
        }

        $assignments = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->lockForUpdate()
            ->get();
        $eligible = $assignments->contains(fn (StaffBranchAssignment $assignment) => $assignment->branch_id === $branch->id
            && $assignment->valid_from->toDateString() <= $effectiveDate
            && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $effectiveDate));

        if (! $eligible) {
            $this->invalid();
        }

        return $doctor;
    }

    /** @return Builder<User> */
    private function eligibleQuery(Branch $branch, string $effectiveDate): Builder
    {
        return User::query()
            ->where('organisation_id', $branch->organisation_id)
            ->where('is_active', true)
            ->role('resident_doctor')
            ->whereHas('staffProfile.branchAssignments', fn (Builder $query) => $query
                ->where('branch_id', $branch->id)
                ->whereDate('valid_from', '<=', $effectiveDate)
                ->where(fn (Builder $period) => $period
                    ->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', $effectiveDate)));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'assigned_doctor_user_id' => 'Select a currently eligible doctor for this branch.',
        ]);
    }
}
