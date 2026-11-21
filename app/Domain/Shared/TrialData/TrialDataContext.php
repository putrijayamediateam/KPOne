<?php

namespace App\Domain\Shared\TrialData;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TRIAL-DATA: what every phase needs: the organisation, the three branches, the one Director and the fictional
 * accounts. Built only after the command has checked its locks.
 */
final class TrialDataContext
{
    /** The main demo branch: organisation-wide set-up (catalogue, prices, Panels) is done from here. */
    public const MAIN_BRANCH = 'CHERAS';

    /** Every branch gets full data (owner, 21 Nov 2026). Branch code => short name used in fictional emails. */
    public const BRANCHES = ['CHERAS' => 'cheras', 'SUNGAI_BESI' => 'sungaibesi', 'PUCHONG' => 'puchong'];

    public const EMAIL_DOMAIN = 'kpjaya.test';

    /**
     * Each branch's own staff: two of each role that needs independent people, and the waiting-room TV.
     *
     * @var array<string, array{name: string, role: string, department: string, title: string}>
     */
    public const BRANCH_STAFF = [
        'doctor1' => ['name' => 'Doctor One', 'role' => 'resident_doctor', 'department' => 'CLINICAL', 'title' => 'Resident Doctor'],
        'doctor2' => ['name' => 'Doctor Two', 'role' => 'resident_doctor', 'department' => 'CLINICAL', 'title' => 'Resident Doctor'],
        'ca1' => ['name' => 'Clinic Assistant One', 'role' => 'ca', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant'],
        'ca2' => ['name' => 'Clinic Assistant Two', 'role' => 'ca', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant'],
        'supervisor1' => ['name' => 'CA Supervisor One', 'role' => 'ca_supervisor', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant Supervisor'],
        'supervisor2' => ['name' => 'CA Supervisor Two', 'role' => 'ca_supervisor', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Clinic Assistant Supervisor'],
        'tv' => ['name' => 'Waiting Room TV', 'role' => 'queue_display', 'department' => 'CLINIC_OPERATIONS', 'title' => 'Waiting Room TV'],
    ];

    /**
     * Organisation-wide roles: one person each, assigned to all three branches with CHERAS as primary.
     *
     * @var array<string, array{name: string, role: string, department: string, title: string}>
     */
    public const SHARED_STAFF = [
        'panel' => ['name' => 'Trial Panel Officer', 'role' => 'panel_officer', 'department' => 'PANEL', 'title' => 'Panel Officer'],
        'finance' => ['name' => 'Trial Finance Officer', 'role' => 'finance_officer', 'department' => 'FINANCE', 'title' => 'Finance Officer'],
        'administrator' => ['name' => 'Trial Administrator', 'role' => 'administrator', 'department' => 'LEADERSHIP', 'title' => 'Clinic Administrator'],
        'hr' => ['name' => 'Trial HR Manager', 'role' => 'hr_manager', 'department' => 'HR_MANAGEMENT', 'title' => 'HR Manager'],
        'bizdev' => ['name' => 'Trial Business Development', 'role' => 'business_development', 'department' => 'BUSINESS_DEVELOPMENT', 'title' => 'Business Development'],
        'marketing' => ['name' => 'Trial Marketing', 'role' => 'marketing', 'department' => 'MARKETING', 'title' => 'Marketing'],
        'techadmin' => ['name' => 'Trial Technical Administrator', 'role' => 'technical_admin', 'department' => 'TECHNOLOGY', 'title' => 'Technical Administrator'],
    ];

    /** @var Collection<string, Branch> */
    public readonly Collection $branches;

    /** @param Collection<string, Branch> $branches keyed by branch code */
    public function __construct(
        public readonly Organisation $organisation,
        Collection $branches,
        public readonly User $director,
    ) {
        $this->branches = $branches;
    }

    public function mainBranch(): Branch
    {
        return $this->branches->get(self::MAIN_BRANCH) ?? throw new \LogicException('The CHERAS branch is missing.');
    }

    /** A branch's own account key, for example "cheras.doctor1". */
    public static function key(string $branchCode, string $role): string
    {
        return self::BRANCHES[$branchCode].'.'.$role;
    }

    public static function email(string $key): string
    {
        return 'trial.'.$key.'@'.self::EMAIL_DOMAIN;
    }

    /**
     * Every fictional account, keyed by account key, with the branch codes it is assigned to (the first is primary).
     *
     * @return array<string, array{name: string, role: string, department: string, title: string, staff_number: string, branches: list<string>}>
     */
    public static function accounts(): array
    {
        $accounts = [];
        $number = 1;
        foreach (self::BRANCHES as $code => $short) {
            foreach (self::BRANCH_STAFF as $role => $definition) {
                $accounts[self::key($code, $role)] = [
                    ...$definition,
                    'name' => 'Trial '.ucwords(str_replace('_', ' ', strtolower($code))).' '.$definition['name'],
                    'staff_number' => sprintf('TRIAL-%04d', $number++),
                    'branches' => [$code],
                ];
            }
        }
        $all = array_keys(self::BRANCHES);
        foreach (self::SHARED_STAFF as $key => $definition) {
            $accounts[$key] = [...$definition, 'staff_number' => sprintf('TRIAL-%04d', $number++), 'branches' => $all];
        }

        return $accounts;
    }

    /** @return list<string> the account keys that do not exist yet */
    public function missingAccounts(): array
    {
        $keys = array_keys(self::accounts());
        $existing = User::query()->whereIn('email', array_map(self::email(...), $keys))->pluck('email')->all();

        return array_values(array_filter($keys, fn (string $key): bool => ! in_array(self::email($key), $existing, true)));
    }

    /**
     * The named fictional account, with the branch context reset as a fresh sign-in would, and the given branch
     * selected (the account's primary branch when none is given).
     */
    public function as(string $key, ?Branch $branch = null): User
    {
        $this->resetBranchContext($branch);

        return User::query()->where('email', self::email($key))->firstOrFail();
    }

    /** The one Director, working at the given branch (CHERAS when none is given). */
    public function asDirector(?Branch $branch = null): User
    {
        $this->resetBranchContext($branch ?? $this->mainBranch());

        return $this->director->fresh() ?? $this->director;
    }

    /** @return list<string> the branch codes where the Director has no current assignment */
    public function branchesWithoutDirector(): array
    {
        $profileId = $this->director->staffProfile?->id;
        $missing = [];
        foreach ($this->branches as $code => $branch) {
            $covered = $profileId !== null && StaffBranchAssignment::query()
                ->where('staff_profile_id', $profileId)
                ->where('branch_id', $branch->id)
                ->effectiveAt(now()->setTimezone($branch->timezone)->toDateString())
                ->exists();
            if (! $covered) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    private function resetBranchContext(?Branch $branch): void
    {
        app()->forgetScopedInstances();
        if ($branch === null) {
            session()->forget(BranchAccessService::SESSION_KEY);
        } else {
            session([BranchAccessService::SESSION_KEY => $branch->id]);
        }
    }
}
