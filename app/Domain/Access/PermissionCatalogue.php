<?php

namespace App\Domain\Access;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Clinical\Documents\AttendancePermissions;
use App\Domain\Clinical\Documents\MedicalCertificatePermissions;
use App\Domain\Clinical\Documents\ReferralPermissions;
use App\Domain\Clinical\Suggestions\SuggestionPermissions;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPermissions;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Appraisals\AppraisalPermissions;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Availability\AvailabilityPermissions;
use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Incidents\IncidentPermissions;
use App\Domain\HR\LabourCost\LabourCostPermissions;
use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Letters\LetterPermissions;
use App\Domain\HR\LocumPay\LocumPayPermissions;
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
use App\Domain\Visit\Assignment\AutoAssignPermissions;

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

    /**
     * ROLE-1 (owner decisions 20-21 Nov 2026; permission table approved 21 Nov, plan docs/teams/staff-experience/ROLE-1-PLAN.md).
     * Branch Manager (per branch: views, daily-close approval, own-branch reports; void and refund approval come with Billing's
     * slices), Account Assistant (HQ finance: records and views, never approves) and Store Admin (central store: stock and
     * purchasing only). None holds anything in AUTHORITY_OVER_PEOPLE_AND_ACCESS.
     */
    public const BRANCH_MANAGER_ROLE = 'branch_manager';

    public const ACCOUNT_ASSISTANT_ROLE = 'account_assistant';

    public const STORE_ADMIN_ROLE = 'store_admin';

    /**
     * MA-1a (owner decision 23 Nov 2026; docs/teams/patients-registration/MA-ROLE-DESIGN.md): everything the CA holds, plus
     * the treatment room (and triage). Built as "the CA's list, then the additions" at the end of roles(), so the two
     * cannot drift. Never people or access authority; in practice only a Director provisions it (canAssignRoles).
     */
    public const MEDICAL_ASSISTANT_ROLE = 'medical_assistant';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            ...array_keys(CallHoldPermissions::grants()),
            ...array_keys(TreatmentStepPermissions::grants()),
            ...array_keys(TriagePermissions::grants()),
            ...array_keys(AutoAssignPermissions::grants()),
            ...array_keys(SuggestionPermissions::grants()),
            ...array_keys(TreatmentRoomPermissions::grants()),
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
            ...array_keys(AvailabilityPermissions::grants()),
            ...array_keys(LocumPayPermissions::grants()),
            ...array_keys(LabourCostPermissions::grants()),
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
            'queue.emergency.respond.branch',
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
            'dispensary.doctor_check.own',
            'dispensary.doctor_check.override.branch',
            'dispensary.settings.manage.branch',
            'dispensary.view_readonly.organisation',
            'dispensary.view_readonly.branch',
            'consultations.view_readonly.organisation',
            'inventory.variances.approve.branch',
            'inventory.supplier_returns.request.branch',
            'inventory.supplier_returns.approve.branch',
            'inventory.supplier_invoices.record.branch',
            'inventory.supplier_invoices.approve.branch',
            'inventory.usage.record.branch',
            'inventory.purchase_requests.create.branch',
            'inventory.controlled_register.view.branch',
            'inventory.controlled_register.check.branch',
            'inventory.recalls.manage.organisation',
            'inventory.recalls.lift.organisation',
            'inventory.cost.view.organisation',
            'inventory.valuation.view.organisation',
            'inventory.pack_conversions.verify.organisation',
            'inventory.medicine_sales.view.organisation',
            'inventory.differences.report.branch',
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
                'queue.emergency.respond.branch',
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
            self::BRANCH_MANAGER_ROLE => [
                ...$branch,
                'visits.view.branch',
                'visits.history.view.branch',
                'queue.view.branch',
                'billing.view.branch',
                'billing.summary.branch',
                'outstanding.view.branch',
                'payments.cash_closing.approve.branch',
                'inventory.view.branch',
            ],
            self::ACCOUNT_ASSISTANT_ROLE => [
                ...$organisation,
                'inventory.view.branch',
                'inventory.supplier_invoices.record.branch',
                'inventory.cost.view.organisation',
                'audit.view.organisation',
            ],
            self::STORE_ADMIN_ROLE => [
                ...$own,
                'staff.view.branch',
                'branches.view.organisation',
                'branch_context.switch.organisation',
                'inventory.view.branch',
                'inventory.receiving.branch',
                'inventory.stocktake.branch',
                'inventory.stock_reviews.manage.branch',
                'inventory.warehouse.organisation',
                'inventory.purchase_orders.create.organisation',
                'inventory.purchase_requests.create.branch',
                'inventory.stock_requests.approve.branch',
                'inventory.transfers.dispatch.branch',
                'inventory.transfer.organisation',
                'inventory.cost.view.organisation',
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

        // ROLE-1 (owner decision 21 Nov 2026, "33 = A"): the audit log, read only, for the Finance Officer and the Panel
        // Officer as well (the Account Assistant has it above); Director, HR Manager and Technical Admin keep it.
        foreach (['finance_officer', 'panel_officer'] as $role) {
            $roles[$role][] = 'audit.view.organisation';
        }

        foreach (['director', 'finance_officer'] as $role) {
            $roles[$role][] = 'reports.patient_billing.view.branch';
            $roles[$role][] = 'reports.patient_billing.export.branch';
        }

        // ROLE-1 follow-up (owner, 22 Nov 2026, "BM reports B"): the Branch Manager also downloads Sales summary and Payments;
        // the export services keep a branch-only account to its own branch (see BranchOnlyExportScope).
        foreach (['director', 'finance_officer', self::ADMINISTRATOR_ROLE, self::BRANCH_MANAGER_ROLE] as $role) {
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

        // TRI-1 (owner decisions 19 Nov 2026): triage readings for CA and CA Supervisor; the guideline for the
        // Director and the HR Manager only (the HR Manager never sees triage readings).
        foreach (TriagePermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        // SUG (owner, 25 Nov 2026): the doctor's review of the recommendation lists (Resident Doctor and Director).
        foreach (SuggestionPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        // AUTO-1 (owner, 24 Nov 2026): the Director's per-branch switch for automatic doctor assignment.
        foreach (AutoAssignPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        // MA-1a (owner decision 23 Nov 2026): the treatment room for the Medical Assistant and the CA Supervisor.
        foreach (TreatmentRoomPermissions::grants() as $permission => $holders) {
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

        // STK-19 (owner decision 2026-11-11, "agree all"): a Finance Officer approves purchase orders as well as the
        // Director, never one they raised or submitted (ProcurementService and PostgreSQL).
        $roles['finance_officer'][] = 'inventory.purchase_orders.approve.organisation';

        // STK-22 (owner decisions 2026-11-11, "agree all"): a CA Supervisor or Finance Officer records a supplier
        // invoice; a Finance Officer or Director, never the recorder, accepts or rejects a mismatch.
        foreach (['ca_supervisor', 'finance_officer'] as $holder) {
            $roles[$holder][] = 'inventory.supplier_invoices.record.branch';
        }
        foreach (['finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.supplier_invoices.approve.branch';
        }

        // STK-18 (owner decision 2026-11-12, "agree purchase requests"): a CA or CA Supervisor asks for stock with a
        // purchase request; converting it into a draft order uses the existing purchase order permission.
        foreach (['ca', 'ca_supervisor'] as $holder) {
            $roles[$holder][] = 'inventory.purchase_requests.create.branch';
        }

        // STK-24 (owner decisions 2026-11-11, "agree all"): the controlled-drug register (DRAFT pending pharmacist
        // review) is viewed and checked by a CA Supervisor, Finance Officer or Director; the checker is a second person.
        foreach (['ca_supervisor', 'finance_officer', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.controlled_register.view.branch';
            $roles[$holder][] = 'inventory.controlled_register.check.branch';
        }

        // STK-25 (owner programme 2026-11-11): a Director starts a recall across branches. A Director or a Finance
        // Officer lifts one, never the person who started it (owner decision 2026-11-12, "recall A").
        $roles['director'][] = 'inventory.recalls.manage.organisation';
        foreach (['director', 'finance_officer'] as $holder) {
            $roles[$holder][] = 'inventory.recalls.lift.organisation';
        }

        // STK-28 (owner programme 2026-11-11; owner decision 2026-11-15: Director only): the stock value, cost of
        // goods sold, margin and losses report. Director only; weighted average cost, approved verbally by the clinic's
        // accountant on 25 Nov 2026 (REL-STK).
        $roles['director'][] = 'inventory.valuation.view.organisation';

        // STK-52 (owner decision 2026-11-21, "A for the bottle check"): only a CA Supervisor or the Director does the blind
        // check of a pack conversion; anyone who manages inventory references proposes one. Never the proposer.
        foreach (['ca_supervisor', 'director'] as $holder) {
            $roles[$holder][] = 'inventory.pack_conversions.verify.organisation';
        }

        // STK-44 (owner, P9: "the medicine sales page with profit: Director, Finance Officer"): only these two open the
        // medicine sales page. It is separate from cost view, so the Store Admin and Account Assistant don't get it.
        foreach (['director', 'finance_officer'] as $holder) {
            $roles[$holder][] = 'inventory.medicine_sales.view.organisation';
        }

        // STK-45 (owner, P10, 25 Nov 2026: "Lulus nota + kebenaran"): report a difference on a stock line, which opens or
        // joins a governed count; it never moves stock. The Medical Assistant gets it through the CA rule below.
        foreach (['ca', 'ca_supervisor', 'store_admin', 'branch_manager'] as $holder) {
            $roles[$holder][] = 'inventory.differences.report.branch';
        }

        // DSP-23 (owner answers 21 Nov 2026: P3, 12 = A, 13 = B): the attending doctor checks the prepared medicine; a
        // CA Supervisor may check instead with a reason; the Director and CA Supervisor turn the branch switch on or off.
        $roles['resident_doctor'][] = 'dispensary.doctor_check.own';
        $roles['ca_supervisor'][] = 'dispensary.doctor_check.override.branch';
        foreach (['director', 'ca_supervisor'] as $holder) {
            $roles[$holder][] = 'dispensary.settings.manage.branch';
        }

        // DSP-25 (owner decision 4 of 20-21 Nov 2026; answer P5): the Director's read-only view of a dispensary case at
        // any branch, every open recorded. An explicit owner role decision for clinical access (AGENTS.md); it never
        // implies a Dispensary write permission.
        $roles['director'][] = 'dispensary.view_readonly.organisation';

        // DSP-26 (owner decision 24 Nov 2026, "pilih (a) lihat sahaja"): the Resident Doctor sees the Dispensary pages
        // at their active branch, view only. An explicit owner role decision for clinical access (AGENTS.md); it never
        // implies a Dispensary write permission, and it is not a CA grant (no medical_assistant mirror).
        $roles['resident_doctor'][] = 'dispensary.view_readonly.branch';

        // Item 4 (owner decision P4 of 20-21 Nov 2026): the Director's read-only view of a consultation at any branch, every
        // open recorded. An explicit owner role decision for clinical access (AGENTS.md); it never implies a write permission.
        $roles['director'][] = 'consultations.view_readonly.organisation';

        foreach (AgentPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        // HR-1 (B.E.R.N.I.S.S for the owner, 2026-11-05): roster planning; never people or access authority.
        foreach ([...RosterPermissions::grants(), ...LeavePermissions::grants(), ...ClockPermissions::grants(), ...RecordPermissions::grants(), ...CredentialPermissions::grants(), ...SopPermissions::grants(), ...MemoPermissions::grants(), ...LetterPermissions::grants(), ...AppraisalPermissions::grants(), ...DisciplinePermissions::grants(), ...IncidentPermissions::grants(), ...OpenShiftPermissions::grants(), ...AvailabilityPermissions::grants(), ...LocumPayPermissions::grants(), ...LabourCostPermissions::grants(), ...ClaimPermissions::grants(), ...PayrollPermissions::grants(), ...ApplicationPermissions::grants(), ...MessagePermissions::grants(), ...CompliancePermissions::grants()] as $permission => $holders) {
            foreach ($holders as $role) {
                $roles[$role][] = $permission;
            }
        }

        // MA-1a catalogue rule (MA-ROLE-DESIGN section 7): the Medical Assistant holds every permission the CA holds, plus
        // its own grants above. Built last, so a permission later granted to `ca` here reaches the MA automatically.
        $roles[self::MEDICAL_ASSISTANT_ROLE] = array_values(array_unique([...$roles['ca'], ...($roles[self::MEDICAL_ASSISTANT_ROLE] ?? [])]));

        return $roles;
    }

    public static function isAdministrativeAuthority(string $permission): bool
    {
        return in_array($permission, self::AUTHORITY_OVER_PEOPLE_AND_ACCESS, true);
    }
}
