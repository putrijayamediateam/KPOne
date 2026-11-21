<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;

/**
 * TRIAL-DATA: what every phase needs: the organisation, the main branch, the one Director and the fictional
 * accounts. Built only after TrialDataGuard has checked the locks.
 */
final class TrialDataContext
{
    public const BRANCH_CODE = 'CHERAS';

    public const EMAIL_DOMAIN = 'kpjaya.test';

    /**
     * One fictional account per role, with a second person where independent approvals need one.
     *
     * @var array<string, array{name: string, role: string, department: string, title: string, staff_number: string}>
     */
    public const ACCOUNTS = [
        'doctor1' => ['name' => 'Trial Doctor One', 'role' => 'resident_doctor', 'department' => 'CLINICAL', 'title' => 'Resident Doctor', 'staff_number' => 'TRIAL-0001'],
        'doctor2' => ['name' => 'Trial Doctor Two', 'role' => 'resident_doctor', 'department' => 'CLINICAL', 'title' => 'Resident Doctor', 'staff_number' => 'TRIAL-0002'],
        'ca1' => ['name' => 'Trial Clinic Assistant One', 'role' => 'ca', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant', 'staff_number' => 'TRIAL-0003'],
        'ca2' => ['name' => 'Trial Clinic Assistant Two', 'role' => 'ca', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant', 'staff_number' => 'TRIAL-0004'],
        'supervisor1' => ['name' => 'Trial CA Supervisor One', 'role' => 'ca_supervisor', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant Supervisor', 'staff_number' => 'TRIAL-0005'],
        'supervisor2' => ['name' => 'Trial CA Supervisor Two', 'role' => 'ca_supervisor', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant Supervisor', 'staff_number' => 'TRIAL-0006'],
        'panel' => ['name' => 'Trial Panel Officer', 'role' => 'panel_officer', 'department' => 'PANEL', 'title' => 'Panel Officer', 'staff_number' => 'TRIAL-0007'],
        'finance' => ['name' => 'Trial Finance Officer', 'role' => 'finance_officer', 'department' => 'FINANCE', 'title' => 'Finance Officer', 'staff_number' => 'TRIAL-0008'],
        'administrator' => ['name' => 'Trial Administrator', 'role' => 'administrator', 'department' => 'LEADERSHIP', 'title' => 'Clinic Administrator', 'staff_number' => 'TRIAL-0009'],
        'hr' => ['name' => 'Trial HR Manager', 'role' => 'hr_manager', 'department' => 'HR_MANAGEMENT', 'title' => 'HR Manager', 'staff_number' => 'TRIAL-0010'],
        'bizdev' => ['name' => 'Trial Business Development', 'role' => 'business_development', 'department' => 'BUSINESS_DEVELOPMENT', 'title' => 'Business Development', 'staff_number' => 'TRIAL-0011'],
        'marketing' => ['name' => 'Trial Marketing', 'role' => 'marketing', 'department' => 'MARKETING', 'title' => 'Marketing', 'staff_number' => 'TRIAL-0012'],
        'techadmin' => ['name' => 'Trial Technical Administrator', 'role' => 'technical_admin', 'department' => 'TECHNOLOGY', 'title' => 'Technical Administrator', 'staff_number' => 'TRIAL-0013'],
        'tv' => ['name' => 'Trial Waiting Room TV', 'role' => 'queue_display', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Waiting Room TV', 'staff_number' => 'TRIAL-0014'],
    ];

    public function __construct(
        public readonly Organisation $organisation,
        public readonly Branch $branch,
        public readonly User $director,
    ) {}

    public static function email(string $key): string
    {
        return 'trial.'.$key.'@'.self::EMAIL_DOMAIN;
    }

    /** @return list<string> the account keys that do not exist yet */
    public function missingAccounts(): array
    {
        $existing = User::query()
            ->whereIn('email', array_map(self::email(...), array_keys(self::ACCOUNTS)))
            ->pluck('email')
            ->all();

        return array_values(array_filter(array_keys(self::ACCOUNTS), fn (string $key): bool => ! in_array(self::email($key), $existing, true)));
    }

    /**
     * Resets the cached branch context, as a fresh sign-in would, and returns the named fictional account.
     */
    public function as(string $key): User
    {
        $this->resetBranchContext();

        return User::query()->where('email', self::email($key))->firstOrFail();
    }

    /** The one Director, with the branch context reset. */
    public function asDirector(): User
    {
        $this->resetBranchContext();

        return $this->director->fresh() ?? $this->director;
    }

    public function directorCoversBranch(): bool
    {
        $profileId = $this->director->staffProfile?->id;

        return $profileId !== null && StaffBranchAssignment::query()
            ->where('staff_profile_id', $profileId)
            ->where('branch_id', $this->branch->id)
            ->effectiveAt(now()->setTimezone($this->branch->timezone)->toDateString())
            ->exists();
    }

    private function resetBranchContext(): void
    {
        app()->forgetScopedInstances();
        session()->forget(BranchAccessService::SESSION_KEY);
    }
}
