<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Models\Visit;

/**
 * PCA-1 (owner decision 2026-11-13): a Panel patient never gets a printed invoice, because the real Panel charge is only
 * known once the Panel company answers the claim (service charge, tax). A receipt still prints for cash the patient paid.
 * A visit counts as Panel when it was registered with Panel coverage, or its invoice has a proposed or approved Panel
 * share. Invoice copies issued before this rule stay viewable; they are not reprinted.
 */
final class PanelInvoicePrinting
{
    public const MESSAGE = 'Panel patient: no invoice is printed. A receipt prints only for cash the patient paid.';

    public static function blocked(Visit $visit, Invoice $invoice): bool
    {
        return $visit->coverage_type === 'panel'
            || CoverageAllocation::query()->where('invoice_id', $invoice->id)->whereIn('status', ['proposed', 'approved'])->exists();
    }
}
