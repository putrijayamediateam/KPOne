<?php

namespace App\Domain\Access;

use App\Domain\Clinical\Documents\MedicalCertificatePermissions;

final class PermissionCatalogue
{
    public const PROTECTED_AUTHORITY_ROLE = 'director';

    /**
     * Authority over people and access, not capability over records. Holding all of a
     * target's permissions in this set is what lets one account administer another.
     * Operational `.manage.` permissions (catalogues, references, pricing, check-in links,
     * reorder levels) are deliberately absent. A new permission is never administrative
     * until it is added here on purpose.
     *
     * @var list<string>
     */
    public const AUTHORITY_OVER_PEOPLE_AND_ACCESS = [
        'staff.manage.organisation',
        'branches.manage.organisation',
        'access.manage.organisation',
        'clinician_credentials.manage.organisation',
    ];

    public const QUEUE_DISPLAY_ROLE = 'queue_display';

    /** The clinic manager's operational settings account; never patient, clinical or finance-approval access. */
    public const ADMINISTRATOR_ROLE = 'administrator';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            ...BillingPermissions::all(),
            ...array_keys(MedicalCertificatePermissions::grants()),
            'dashboard.view.own',
            'profile.view.own',
            'staff.view.own',
            'staff.view.branch',
            'staff.view.organisation',
            'staff.manage.organisation',
            'clinician_credentials.manage.organisation',
            'branches.view.branch',
            'branches.view.organisation',
            'branches.manage.organisation',
            'public_checkin_links.manage.organisation',
            'public_checkin_links.manage.branch',
            'public_intakes.review.branch',
            'medicines.manage.organisation',
            'clinical_services.manage.organisation',
            'catalogue.settings.manage.organisation',
            'business_details.manage.organisation',
            'branch_context.switch.branch',
            'branch_context.switch.organisation',
            'access.view.organisation',
            'access.manage.organisation',
            'audit.view.organisation',
            'insights.view.organisation',
            'reports.sales.export.organisation',
            'system_events.view.organisation',
            'patients.search.organisation',
            'patients.view.organisation',
            'patients.create.organisation',
            'patients.update.organisation',
            'patients.identifiers.manage.organisation',
            'visits.view.branch',
            'visits.history.view.branch',
            'visits.create.branch',
            'visits.update.branch',
            'visits.cancel.branch',
            'queue.view.own',
            'queue.view.branch',
            'queue.enter.branch',
            'queue.call.own',
            'queue.call.branch',
            'queue.room.select.own',
            'queue.display.branch',
            'queue_display.manage.organisation',
            'queue_display.manage.branch',
            'encounters.view.own',
            'encounters.start.own',
            'encounters.update.own',
            'encounters.history.view.organisation',
            'allergies.view.own',
            'allergies.update.own',
            'allergies.review.own',
            'problems.view.own',
            'problems.update.own',
            'treatment_plans.view.own',
            'treatment_plans.create.own',
            'treatment_plans.update.own',
            'treatment_plans.send_to_dispensary.own',
            'dispensary.view.branch',
            'dispensary.labels.print.branch',
            'dispensary.start.branch',
            'dispensary.update.branch',
            'dispensary.complete.branch',
            'dispensary.return_to_doctor.branch',
            'dispensary.otc.create.branch',
            'dispensary.acknowledge_partial.own',
            'inventory.view.branch',
            'inventory.opening_balance.branch',
            'inventory.transfer.branch',
            'inventory.transfer.organisation',
            'inventory.references.manage.organisation',
            'inventory.suppliers.manage.organisation',
            'inventory.purchase_orders.create.organisation',
            'inventory.purchase_orders.approve.organisation',
            'inventory.receiving.branch',
            'inventory.stock_requests.create.branch',
            'inventory.stock_requests.approve.branch',
            'inventory.transfers.dispatch.branch',
            'inventory.transfers.receive.branch',
            'inventory.stocktake.branch',
            'inventory.adjust.branch',
            'inventory.reorder.manage.branch',
            'inventory.warehouse.organisation',
            'consultations.complete.own',
            'consultations.hold.own',
            'consultations.reopen.own',
            'services.confirm.own',
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

        $roles = [
            'director' => [
                ...$organisation,
                'clinician_credentials.manage.organisation',
                'staff.manage.organisation',
                'branches.manage.organisation',
                'public_checkin_links.manage.organisation',
                'medicines.manage.organisation',
                'clinical_services.manage.organisation',
                'catalogue.settings.manage.organisation',
                'business_details.manage.organisation',
                'inventory.references.manage.organisation',
                'inventory.view.branch',
                'inventory.suppliers.manage.organisation',
                'inventory.purchase_orders.approve.organisation',
                'access.view.organisation',
                'access.manage.organisation',
                'audit.view.organisation',
                'system_events.view.organisation',
                'patients.search.organisation',
                'patients.view.organisation',
                'patients.create.organisation',
                'patients.update.organisation',
                'patients.identifiers.manage.organisation',
                'visits.view.branch',
                'visits.history.view.branch',
                'visits.create.branch',
                'visits.update.branch',
                'visits.cancel.branch',
                'queue.view.branch',
                'queue.enter.branch',
                'queue.call.branch',
                'queue_display.manage.organisation',
                'public_intakes.review.branch',
            ],
            'resident_doctor' => [
                ...$branch,
                'patients.search.organisation',
                'patients.view.organisation',
                'visits.view.branch',
                'visits.history.view.branch',
                'queue.view.own',
                'queue.call.own',
                'queue.room.select.own',
                'encounters.view.own',
                'encounters.start.own',
                'encounters.update.own',
                'encounters.history.view.organisation',
                'allergies.view.own',
                'allergies.update.own',
                'allergies.review.own',
                'problems.view.own',
                'problems.update.own',
                'treatment_plans.view.own',
                'treatment_plans.create.own',
                'treatment_plans.update.own',
                'treatment_plans.send_to_dispensary.own',
                'dispensary.acknowledge_partial.own',
                'consultations.complete.own',
                'consultations.hold.own',
                'consultations.reopen.own',
                'services.confirm.own',
            ],
            'ca' => [
                ...$branch,
                'patients.search.organisation',
                'patients.view.organisation',
                'patients.create.organisation',
                'patients.update.organisation',
                'visits.view.branch',
                'visits.history.view.branch',
                'visits.create.branch',
                'visits.update.branch',
                'visits.cancel.branch',
                'queue.view.branch',
                'queue.enter.branch',
                'public_intakes.review.branch',
                'dispensary.view.branch',
                'dispensary.labels.print.branch',
                'dispensary.start.branch',
                'dispensary.update.branch',
                'dispensary.complete.branch',
                'dispensary.return_to_doctor.branch',
                'dispensary.otc.create.branch',
                'inventory.view.branch',
                'inventory.transfer.branch',
                'inventory.stock_requests.create.branch',
                'inventory.transfers.receive.branch',
            ],
            'ca_supervisor' => [
                ...$branch,
                'patients.search.organisation',
                'patients.view.organisation',
                'patients.create.organisation',
                'patients.update.organisation',
                'patients.identifiers.manage.organisation',
                'visits.view.branch',
                'visits.history.view.branch',
                'visits.create.branch',
                'visits.update.branch',
                'visits.cancel.branch',
                'queue.view.branch',
                'queue.enter.branch',
                'queue.call.branch',
                'public_checkin_links.manage.branch',
                'queue_display.manage.branch',
                'public_intakes.review.branch',
                'dispensary.view.branch',
                'dispensary.labels.print.branch',
                'dispensary.start.branch',
                'dispensary.update.branch',
                'dispensary.complete.branch',
                'dispensary.return_to_doctor.branch',
                'dispensary.otc.create.branch',
                'inventory.view.branch',
                'inventory.opening_balance.branch',
                'inventory.transfer.branch',
                'inventory.transfer.organisation',
                'medicines.manage.organisation',
                'clinical_services.manage.organisation',
                'inventory.references.manage.organisation',
                'inventory.purchase_orders.create.organisation',
                'inventory.receiving.branch',
                'inventory.stock_requests.create.branch',
                'inventory.stock_requests.approve.branch',
                'inventory.transfers.dispatch.branch',
                'inventory.transfers.receive.branch',
                'inventory.stocktake.branch',
                'inventory.adjust.branch',
                'inventory.reorder.manage.branch',
                'inventory.warehouse.organisation',
            ],
            'panel_officer' => [...$organisation, 'catalogue.settings.manage.organisation'],
            'finance_officer' => [
                ...$organisation,
                'catalogue.settings.manage.organisation',
                'inventory.view.branch',
                'inventory.suppliers.manage.organisation',
                'inventory.purchase_orders.create.organisation',
            ],
            // The clinic manager: settings, catalogues and explicitly authorised aggregate Sales exports only. No patient, clinical, billing-approval,
            // Panel Claims or people/access authority (see BillingPermissions for its pricing and
            // Panel settings grants).
            self::ADMINISTRATOR_ROLE => [
                ...$organisation,
                'medicines.manage.organisation',
                'clinical_services.manage.organisation',
                'catalogue.settings.manage.organisation',
                'business_details.manage.organisation',
                'inventory.view.branch',
                'inventory.references.manage.organisation',
                'inventory.suppliers.manage.organisation',
                'inventory.reorder.manage.branch',
                'public_checkin_links.manage.organisation',
                'queue_display.manage.organisation',
            ],
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
        foreach (BillingPermissions::roles() as $role => $permissions) {
            $roles[$role] = [...$roles[$role], ...$permissions];
        }

        foreach (MedicalCertificatePermissions::grants() as $permission => $grants) {
            foreach ($grants as $role) {
                $roles[$role][] = $permission;
            }
        }

        foreach (['director', 'finance_officer', self::ADMINISTRATOR_ROLE] as $role) {
            $roles[$role][] = 'reports.sales.export.organisation';
        }

        foreach ($roles as $role => $permissions) {
            $roles[$role] = [...$permissions, 'insights.view.organisation'];
        }

        // A shared waiting-room TV account: it sees only the called numbers and rooms of its own
        // branch, never patient, clinical or financial data, and so is deliberately left out of Insights.
        $roles[self::QUEUE_DISPLAY_ROLE] = [
            ...$own,
            'branches.view.branch',
            'branch_context.switch.branch',
            'queue.display.branch',
        ];

        return $roles;
    }

    public static function isAdministrativeAuthority(string $permission): bool
    {
        return in_array($permission, self::AUTHORITY_OVER_PEOPLE_AND_ACCESS, true);
    }
}
