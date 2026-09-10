<?php

namespace App\Domain\Access;

final class BillingPermissions
{
    /** @return array<string, list<string>> */
    public static function roles(): array
    {
        $ca = ['billing.view.branch', 'billing.build.branch', 'billing.finalize.branch', 'billing.print.branch', 'payments.add.branch', 'coverage.propose.branch', 'outstanding.request.branch', 'outstanding.view.branch', 'visits.complete.branch'];

        return [
            'ca' => $ca,
            'ca_supervisor' => [...$ca, 'coverage.approve.branch', 'outstanding.approve.branch'],
            'finance_officer' => ['billing.summary.branch', 'finance.work.view.branch', 'pricing.references.manage.organisation', 'prices.publish.organisation', 'payments.add.branch', 'payments.reverse.branch', 'invoices.void.branch', 'coverage.propose.branch', 'coverage.approve.branch', 'outstanding.view.branch', 'outstanding.request.branch', 'outstanding.approve.branch'],
            'panel_officer' => ['billing.summary.branch', 'panel.work.view.branch', 'coverage.propose.branch', 'coverage.approve.branch'],
            'director' => ['billing.summary.branch', 'panel.work.view.branch', 'finance.work.view.branch', 'pricing.references.manage.organisation', 'coverage.approve.branch', 'outstanding.view.branch', 'outstanding.approve.branch'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::roles()))));
    }
}
