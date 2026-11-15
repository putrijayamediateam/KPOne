<?php

namespace App\Domain\Clinical\Documents;

final class MedicalCertificatePermissions
{
    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            'medical_certificates.issue.own' => ['resident_doctor'],
            'medical_certificates.view.own' => ['resident_doctor'],
            'medical_certificates.view.branch' => ['ca', 'ca_supervisor'],
            'medical_certificates.print.branch' => ['resident_doctor', 'ca', 'ca_supervisor'],
        ];
    }
}
