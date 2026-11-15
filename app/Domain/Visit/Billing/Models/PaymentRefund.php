<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * BIL-6: money handed back to a patient for a reversed receipt (its full amount). BIL-10: kind 'after_completion'
 * gives back part or all of a posted receipt of a completed visit, with an invoice credit of the same amount. Append-only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $payment_id
 * @property int $payment_method_id
 * @property string $method_snapshot
 * @property int $amount_sen
 * @property string $kind
 * @property string $reason_code
 * @property int $recorded_by_user_id
 * @property CarbonImmutable $paid_out_at
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property-read PaymentRefundDecision|null $decision
 * @property-read InvoiceCredit|null $credit
 */
#[Guarded(['*'])]
class PaymentRefund extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = ['overpaid' => 'Overpaid', 'service_not_given' => 'Service not given', 'invoice_corrected' => 'Invoice corrected', 'other' => 'Other'];

    /** BIL-10: reasons for a refund after completion (overpayment cannot happen, KPOne never takes more than is due). */
    public const AFTER_COMPLETION_REASONS = ['medicine_returned' => 'Medicine returned', 'service_not_given' => 'Service not given', 'invoice_corrected' => 'Invoice corrected', 'goodwill' => 'Goodwill', 'other' => 'Other'];

    public const KIND_REVERSAL = 'reversal';

    public const KIND_AFTER_COMPLETION = 'after_completion';

    public const REJECT_REASONS = ['amount_wrong' => 'Amount wrong', 'not_paid_out' => 'Money not paid out', 'duplicate' => 'Duplicate', 'other' => 'Other'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Refund evidence is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Refund evidence is retained.'));
    }

    /** @return HasOne<PaymentRefundDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(PaymentRefundDecision::class);
    }

    /** @return HasOne<InvoiceCredit, $this> */
    public function credit(): HasOne
    {
        return $this->hasOne(InvoiceCredit::class);
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'payment_id' => 'integer', 'payment_method_id' => 'integer', 'recorded_by_user_id' => 'integer',
            'paid_out_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
