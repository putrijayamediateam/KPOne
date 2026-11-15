<?php

namespace App\Domain\Shared\Services;

use Illuminate\Database\Query\Builder;

/**
 * DSP-4 (owner decision 2026-11-05): the Provider · net invoice sales table adds one fixed
 * "OTC (no doctor)" row for medicine-only (OTC) invoices, which have no attending doctor and so were
 * missing from the table. It is always last, outside the 100-row doctor cap, shown only when no doctor
 * filter is applied and only when the period has OTC sales. Doctor rows are unchanged.
 */
final class OtcProviderRow
{
    public const LABEL = 'OTC (no doctor)';

    /**
     * @param  Builder  $scoped  invoices query already scoped to organisation, finalized status, period and branch
     * @return array{name: string, salesSen: int, patients: int, fixed: true}|null
     */
    public static function from(Builder $scoped): ?array
    {
        $row = (clone $scoped)->whereNotNull('invoices.dispensary_case_id')
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(kpone_invoice_payable(invoices.id)), 0) as sales_sen, COUNT(DISTINCT invoices.patient_id) as patients')
            ->first();

        if ($row === null || (int) $row->invoices === 0) {
            return null;
        }

        // `fixed` tells the screen to keep this row last whatever the user sorts by.
        return ['name' => self::LABEL, 'salesSen' => (int) $row->sales_sen, 'patients' => (int) $row->patients, 'fixed' => true];
    }
}
