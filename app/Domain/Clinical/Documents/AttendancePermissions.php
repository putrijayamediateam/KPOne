<?php

namespace App\Domain\Clinical\Documents;

final class AttendancePermissions
{
    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [...array_fill_keys(['attendance_documents.issue.own', 'attendance_documents.view.own', 'attendance_documents.print.own'], ['resident_doctor']), ...array_fill_keys(['attendance_documents.view.branch', 'attendance_documents.print.branch'], ['ca', 'ca_supervisor'])];
    }
}
