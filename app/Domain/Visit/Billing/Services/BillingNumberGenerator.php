<?php

namespace App\Domain\Visit\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class BillingNumberGenerator
{
    public function next(int $organisationId, string $type): string
    {
        if (DB::transactionLevel() < 1 || ! in_array($type, ['invoice', 'receipt'], true)) {
            throw new LogicException('Financial numbers require an owning transaction.');
        }
        DB::table('billing_document_counters')->insertOrIgnore(['organisation_id' => $organisationId, 'document_type' => $type, 'next_value' => 1]);
        $row = DB::table('billing_document_counters')->where('organisation_id', $organisationId)->where('document_type', $type)->lockForUpdate()->first();
        if (! $row || $row->next_value > 99_999_999) {
            throw ValidationException::withMessages(['invoice' => 'Document numbering needs administrator attention.']);
        }
        DB::table('billing_document_counters')->where('id', $row->id)->update(['next_value' => $row->next_value + 1]);

        return sprintf('%s-%08d', $type === 'invoice' ? 'KPI' : 'KPR', $row->next_value);
    }
}
