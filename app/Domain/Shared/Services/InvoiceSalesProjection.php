<?php

namespace App\Domain\Shared\Services;

use Illuminate\Database\Query\Builder;

/** Read-only invoice cohort aggregates. Item rankings deliberately remain gross. */
final class InvoiceSalesProjection
{
    public const PAYABLE = 'kpone_invoice_payable(invoices.id)';

    /** @return array{grossSen: int, discountsSen: int, surchargesSen: int, netSen: int} */
    public static function reconciliation(Builder $invoices): array
    {
        $payable = self::PAYABLE;
        $row = (clone $invoices)->selectRaw("COALESCE(SUM(invoices.total_sen),0) AS gross, COALESCE(SUM(GREATEST(invoices.total_sen-{$payable},0)),0) AS discounts, COALESCE(SUM(GREATEST({$payable}-invoices.total_sen,0)),0) AS surcharges, COALESCE(SUM({$payable}),0) AS net")->first();

        return ['grossSen' => (int) $row->gross, 'discountsSen' => (int) $row->discounts, 'surchargesSen' => (int) $row->surcharges, 'netSen' => (int) $row->net];
    }
}
