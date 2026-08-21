<?php

namespace App\Domain\Access;

final class PermissionCatalogue
{
    public const PROTECTED_AUTHORITY_ROLE = 'director';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'dashboard.view.own',
            'profile.view.own',
            'staff.view.own',
            'staff.view.branch',
            'staff.view.organisation',
            'staff.manage.organisation',
            'branches.view.branch',
            'branches.view.organisation',
            'branches.manage.organisation',
            'branch_context.switch.branch',
            'branch_context.switch.organisation',
            'access.view.organisation',
            'access.manage.organisation',
            'audit.view.organisation',
            'system_events.view.organisation',
            'patients.search.organisation',
            'patients.view.organisation',
            'patients.create.organisation',
            'patients.update.organisation',
            'patients.identifiers.manage.organisation',
        ];
    }

    /** @return array<string, list<string>> */
    public static function roles(): array
    {
        $own = ['dashboard.view.own', 'profile.view.own', 'staff.view.own'];
        $branch = [
            ...$own,
            'staff.view.branch',
            'branches.view.branch',
            'branch_context.switch.branch',
        ];
        $organisation = [
            ...$own,
            'staff.view.organisation',
            'branches.view.organisation',
            'branch_context.switch.organisation',
        ];

        return [
            'director' => [
                ...$organisation,
                'staff.manage.organisation',
                'branches.manage.organisation',
                'access.view.organisation',
                'access.manage.organisation',
                'audit.view.organisation',
                'system_events.view.organisation',
                'patients.search.organisation',
                'patients.view.organisation',
                'patients.create.organisation',
                'patients.update.organisation',
                'patients.identifiers.manage.organisation',
            ],
            'resident_doctor' => [...$branch, 'patients.search.organisation', 'patients.view.organisation'],
            'ca' => [
                ...$branch,
                'patients.search.organisation',
                'patients.view.organisation',
                'patients.create.organisation',
                'patients.update.organisation',
            ],
            'ca_supervisor' => [
                ...$branch,
                'patients.search.organisation',
                'patients.view.organisation',
                'patients.create.organisation',
                'patients.update.organisation',
                'patients.identifiers.manage.organisation',
            ],
            'panel_officer' => $organisation,
            'finance_officer' => $organisation,
            'business_development' => $organisation,
            'marketing' => $organisation,
            'hr_manager' => [
                ...$organisation,
                'staff.manage.organisation',
                'access.view.organisation',
                'audit.view.organisation',
            ],
            'technical_admin' => [
                ...$organisation,
                'staff.manage.organisation',
                'branches.manage.organisation',
                'access.view.organisation',
                'access.manage.organisation',
                'audit.view.organisation',
                'system_events.view.organisation',
            ],
        ];
    }

    public static function isAdministrativeAuthority(string $permission): bool
    {
        return str_contains($permission, '.manage.');
    }
}
