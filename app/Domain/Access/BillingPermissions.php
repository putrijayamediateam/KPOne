<?php

namespace App\Domain\Access;

final class BillingPermissions
{
    /** @return array<string, list<string>> */
    public static function roles(): array
    {
        $ca = ['billing.bill_to.view.branch', 'billing.bill_to.manage.branch', 'billing.view.branch', 'billing.build.branch', 'billing.finalize.branch', 'billing.print.branch', 'payments.add.branch', 'payments.reconcile.branch', 'coverage.propose.branch', 'coverage.approve.branch', 'outstanding.request.branch', 'outstanding.view.branch', 'visits.complete.branch', 'billing.adjustments.add.branch', 'billing.notes.view.branch', 'billing.notes.add.branch'];

        return [
            'administrator' => ['pricing.references.manage.organisation', 'prices.publish.organisation', 'panels.manage.organisation', 'payment_methods.manage.organisation'],
            'ca' => $ca,
            'ca_supervisor' => [...$ca, 'billing.notes.retract.branch', 'billing.adjustments.override_limit.branch', 'billing.adjustments.reverse.branch', 'coverage.approve.branch', 'outstanding.approve.branch', 'pricing.references.manage.organisation', 'prices.publish.organisation'],
            'finance_officer' => ['billing.bill_to.view.branch', 'billing.bill_to.manage.branch', 'billing.notes.view.branch', 'billing.notes.add.branch', 'billing.notes.retract.branch', 'billing.adjustments.add.branch', 'billing.adjustments.override_limit.branch', 'billing.adjustments.reverse.branch', 'billing.summary.branch', 'billing.lines.view.branch', 'finance.work.view.branch', 'panel.invoices.export.branch', 'pricing.references.manage.organisation', 'panels.manage.organisation', 'payment_methods.manage.organisation', 'payments.reconcile.branch', 'prices.publish.organisation', 'payments.add.branch', 'payments.reverse.branch', 'invoices.void.branch', 'coverage.propose.branch', 'coverage.approve.branch', 'outstanding.view.branch', 'outstanding.request.branch', 'outstanding.approve.branch'],
            'panel_officer' => ['billing.bill_to.view.branch', 'billing.summary.branch', 'billing.lines.view.branch', 'panel.work.view.branch', 'panel.claims.manage.branch', 'panel.claims.clinical_filters.branch', 'panel.claims.patient_identity.view.branch', 'panel.invoices.export.branch', 'panels.manage.organisation', 'coverage.propose.branch', 'coverage.approve.branch'],
            'director' => ['billing.bill_to.view.branch', 'billing.bill_to.manage.branch', 'billing.notes.view.branch', 'billing.notes.add.branch', 'billing.notes.retract.branch', 'billing.adjustments.add.branch', 'billing.adjustments.override_limit.branch', 'billing.adjustments.reverse.branch', 'billing.summary.branch', 'billing.lines.view.branch', 'panel.work.view.branch', 'finance.work.view.branch', 'panel.invoices.export.branch', 'pricing.references.manage.organisation', 'panels.manage.organisation', 'payment_methods.manage.organisation', 'billing.approval_limits.manage.organisation', 'payments.reconcile.branch', 'coverage.approve.branch', 'outstanding.view.branch', 'outstanding.approve.branch'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::roles()))));
    }
}
