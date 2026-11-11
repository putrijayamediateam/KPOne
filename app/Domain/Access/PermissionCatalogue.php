<?php

namespace App\Domain\Access;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Clinical\Documents\AttendancePermissions;
use App\Domain\Clinical\Documents\MedicalCertificatePermissions;
use App\Domain\Clinical\Documents\ReferralPermissions;
use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Appraisals\AppraisalPermissions;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Incidents\IncidentPermissions;
use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Letters\LetterPermissions;
use App\Domain\HR\Memos\MemoPermissions;
use App\Domain\HR\Messages\MessagePermissions;
use App\Domain\HR\OpenShifts\OpenShiftPermissions;
use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Sops\SopPermissions;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Website\WebsitePermissions;
use App\Domain\Queue\Readiness\CallHoldPermissions;
use App\Domain\Queue\Treatment\TreatmentStepPermissions;

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

    /**
     * HR-15a (HR-PLAN 21.3; B.E.R.N.I.S.S for the owner, 2026-11-10): a locum's own sign-in. Their own account settings
     * and their own approved shifts only; never patient, clinical, billing, inventory, Insights or staff-directory access.
     * HR-PLAN 21.3 pins the list exactly (tested).
     */
    public const LOCUM_ROLE = 'locum';

    /** The clinic manager's operational settings account; never patient, clinical or finance-approval access. */
    public const ADMINISTRATOR_ROLE = 'administrator';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            ...array_keys(CallHoldPermissions::grants()),
            ...array_keys(TreatmentStepPermissions::grants()),
            ...BillingPermissions::all(),
            ...array_keys(WebsitePermissions::grants()),
            ...array_keys(ReportPermissions::grants()),
            ...array_keys(MedicalCertificatePermissions::grants()),
            ...array_keys(ReferralPermissions::grants()),
            ...array_keys(AttendancePermissions::grants()),
            ...array_keys(AgentPermissions::grants()),
            ...array_keys(RosterPermissions::grants()),
            ...array_keys(LeavePermissions::grants()),
            ...array_keys(ClockPermissions::grants()),
            ...array_keys(RecordPermissions::grants()),
            ...array_keys(CredentialPermissions::grants()),
            ...array_keys(SopPermissions::grants()),
            ...array_keys(MemoPermissions::grants()),
            ...array_keys(LetterPermissions::grants()),
            ...array_keys(AppraisalPermissions::grants()),
            ...array_keys(DisciplinePermissions::grants()),
            ...array_keys(IncidentPermissions::grants()),
            ...array_keys(OpenShiftPermissions::grants()),
            ...array_keys(ClaimPermissions::grants()),
            ...array_keys(PayrollPermissions::grants()),
            ...array_keys(ApplicationPermissions::grants()),
            ...array_keys(MessagePermissions::grants()),
            ...array_keys(CompliancePermissions::grants()),
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
            'reports.payments.export.organisation',
            'reports.patient_billing.view.branch',
            'reports.patient_billing.export.branch',
            'system_events.view.organisation',
            'patients.search.organisation',
            'patients.view.organisation',
            'patients.create.organisation',
            'patients.update.organisation',
            'patients.identifiers.manage.organisation',
            'patients.merge.propose.organisation',
            'patients.merge.approve.organisation',
            'patients.history.view.organisation',
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
            'allergies.record_otc.branch',
            'dispensary.start.branch',
            'dispensary.update.branch',
            'dispensary.complete.branch',
            'dispensary.return_to_doctor.branch',
            'dispensary.otc.create.branch',
            'dispensary.acknowledge_partial.own',
            'inventory.variances.approve.branch',
            'inventory.supplier_returns.request.branch',
            'inventory.supplier_returns.approve.branch',
            'inventory.usage.record.branch',
            'inventory.cost.view.organisation',
            'inventory.stock_reviews.manage.branch',
            'inventory.view.branch',
            'inventory.opening_balance.branch',
            'inventory.opening.verify.branch',
            'inventory.batch_holds.place.branch',
            'inventory.batch_holds.release.branch',
            'inventory.transfer.branch',
            'inventory.transfer.organisation',
            'inventory.references.manage.organisation',
            'inventory.suppliers.manage.organisation',
            'inventory.purchase_orders.create.organisation',
            'inventory.purchase_orders.approve.organisation',
            'inventory.receiving.branch',
            'inventory.receiving.exceptions.approve.branch',
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
                'patients.merge.propose.organisation',
                'patients.merge.approve.organisation',
                'patients.history.view.organisation',
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
                'patients.merge.propose.organisation',
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
                'allergies.record_otc.branch',
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
                'patients.merge.propose.organisation',
                'patients.merge.approve.organisation',
                'patients.history.view.organisation',
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
                'allergies.record_otc.branch',
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
        foreach (['ca_supervisor', 'finance_officer', 'director'] as $role) {
            $roles[$role][] = 'inventory.opening.verify.branch';
        }

        foreach (BillingPermissions::roles() as $role => $permissions) {
            $roles[$role] = [...$roles[$role], ...$permissions];
        }

        foreach ([...MedicalCertificatePermissions::grants(), ...ReferralPermissions::grants(), ...AttendancePermissions::grants()] as $permission => $grants) {
            foreach ($grants as $role) {
                $roles[$role][] = $permission;
            }
        }

        foreach (['director', 'finance_officer'] as $role) {
            $roles[$role][] = 'reports.patient_billing.view.branch';
            $roles[$role][] = 'reports.patient_billing.export.branch';
        }

        foreach (['director', 'finance_officer', self::ADMINISTRATOR_ROLE] as $role) {
            $roles[$role][] = 'reports.sales.export.organisation';
            $roles[$role][] = 'reports.payments.export.organisation';
        }

        foreach (CallHoldPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        foreach (TreatmentStepPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        foreach (WebsitePermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        foreach (ReportPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
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

        // HR-15a: a locum sees their own settings and chooses among their own assigned branches (as the TV account
        // does; no data comes with it); their own shifts come with the HR roster grants below.
        $roles[self::LOCUM_ROLE] = ['profile.view.own', 'branch_context.switch.branch'];

        foreach (['ca_supervisor', 'finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.batch_holds.place.branch';
        }
        foreach (['finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.batch_holds.release.branch';
        }

        foreach (['ca_supervisor', 'finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.receiving.exceptions.approve.branch';
            $roles[$holder][] = 'inventory.stock_reviews.manage.branch';
        }

        foreach (['ca_supervisor', 'finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.variances.approve.branch';
        }

        // STK-7 (owner decision 2026-11-05): a CA Supervisor requests; a Finance Officer or Director approves.
        $roles['ca_supervisor'][] = 'inventory.supplier_returns.request.branch';
        foreach (['finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.supplier_returns.approve.branch';
        }

        // STK-8 (owner decision 2026-11-05): CA and CA Supervisor record treatment-room usage and waste.
        foreach (['ca', 'ca_supervisor'] as $holder) {
            $roles[$holder][] = 'inventory.usage.record.branch';
        }

        // STK-13 (owner decision 2026-11-11, "agree all"): only a Director or Finance Officer sees inventory cost.
        foreach (['director', 'finance_officer'] as $holder) {
            $roles[$holder][] = 'inventory.cost.view.organisation';
        }

        foreach (AgentPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        // HR-1 (B.E.R.N.I.S.S for the owner, 2026-11-05): roster planning; never people or access authority.
        foreach ([...RosterPermissions::grants(), ...LeavePermissions::grants(), ...ClockPermissions::grants(), ...RecordPermissions::grants(), ...CredentialPermissions::grants(), ...SopPermissions::grants(), ...MemoPermissions::grants(), ...LetterPermissions::grants(), ...AppraisalPermissions::grants(), ...DisciplinePermissions::grants(), ...IncidentPermissions::grants(), ...OpenShiftPermissions::grants(), ...ClaimPermissions::grants(), ...PayrollPermissions::grants(), ...ApplicationPermissions::grants(), ...MessagePermissions::grants(), ...CompliancePermissions::grants()] as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        return $roles;
    }

    public static function isAdministrativeAuthority(string $permission): bool
    {
        return in_array($permission, self::AUTHORITY_OVER_PEOPLE_AND_ACCESS, true);
    }
}
