<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * BIL-10: lowers what a completed visit's invoice charges by the amount of its refund after completion. It counts
 * only once that refund is approved. Append-only.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $payment_refund_id
 * @property int $amount_sen
 * @property CarbonImmutable $created_at
 * @property-read PaymentRefund $refund
 */
#[Guarded(['*'])]
class InvoiceCredit extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Invoice credits are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Invoice credits are retained.'));
    }

    /** @return BelongsTo<PaymentRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'invoice_id' => 'integer', 'payment_refund_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
