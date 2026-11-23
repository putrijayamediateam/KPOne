<?php

declare(strict_types=1);

namespace App\Domain\Patient\Services;

use Illuminate\Support\Facades\DB;

/**
 * BIL-29 (owner decision 12, 20-21 Nov 2026; round 2: 17 = A, 28 = A): whether a patient counts as foreign for SST.
 *
 * Foreign = no current Malaysian IC AND a current passport issued by a country other than Malaysia. A Malaysian with a
 * Malaysian passport, or a person holding both an IC and a foreign passport, is not foreign. The optional nationality
 * field is not used (it is often blank); see BIL-29-DESIGN.md section 2.
 *
 * Read only. It answers yes or no and never exposes the identifier value or its country. Nothing is charged on it:
 * SST is calculated only after the accountant confirms the rate and rules, in its own slice, using this same rule.
 */
final class ForeignPatientRule
{
    public function isForeign(int $organisationId, int $patientId): bool
    {
        $current = DB::table('patient_identifiers')
            ->where('organisation_id', $organisationId)
            ->where('patient_id', $patientId)
            ->whereNull('retired_at')
            ->get(['identifier_type', 'issuing_country_code']);

        if ($current->contains(fn (object $row): bool => $row->identifier_type === 'nric')) {
            return false;
        }

        return $current->contains(
            fn (object $row): bool => $row->identifier_type === 'passport' && strtoupper((string) $row->issuing_country_code) !== 'MY',
        );
    }
}
