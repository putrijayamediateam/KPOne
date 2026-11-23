<?php

namespace App\Domain\Access;

final class ReportPermissions
{
    /** @return array<string,list<string>> */
    public static function grants(): array
    {
        return [
            'reports.transactions.view.branch' => ['director', 'finance_officer'],
            'reports.transactions.export.branch' => ['director', 'finance_officer'],
            'reports.stock_movement.view.branch' => ['director', 'finance_officer', 'administrator', 'branch_manager', 'account_assistant'],
            'reports.stock_movement.export.branch' => ['director', 'finance_officer', 'administrator', 'branch_manager'],
            'reports.panel_summary.view.branch' => ['director', 'finance_officer', 'panel_officer', 'account_assistant', 'branch_manager'],
            'reports.panel_summary.export.branch' => ['director', 'finance_officer', 'panel_officer', 'branch_manager'],
            'reports.daily_summary.view.branch' => ['director', 'finance_officer', 'administrator', 'branch_manager', 'account_assistant'],
            'reports.daily_summary.export.branch' => ['director', 'finance_officer', 'administrator', 'branch_manager'],
        ];
    }
}
