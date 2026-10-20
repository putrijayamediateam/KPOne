<?php

namespace App\Domain\Access;

final class ReportPermissions
{
    /** @return array<string,list<string>> */
    public static function grants(): array
    {
        return [
            'reports.panel_summary.view.branch' => ['director', 'finance_officer', 'panel_officer'],
            'reports.panel_summary.export.branch' => ['director', 'finance_officer', 'panel_officer'],
            'reports.daily_summary.view.branch' => ['director', 'finance_officer', 'administrator'],
            'reports.daily_summary.export.branch' => ['director', 'finance_officer', 'administrator'],
        ];
    }
}
