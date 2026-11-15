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
            'reports.stock_movement.view.branch' => ['director', 'finance_officer', 'administrator'],
            'reports.stock_movement.export.branch' => ['director', 'finance_officer', 'administrator'],
            'reports.panel_summary.view.branch' => ['director', 'finance_officer', 'panel_officer'],
            'reports.panel_summary.export.branch' => ['director', 'finance_officer', 'panel_officer'],
            'reports.daily_summary.view.branch' => ['director', 'finance_officer', 'administrator'],
            'reports.daily_summary.export.branch' => ['director', 'finance_officer', 'administrator'],
        ];
    }
}
