<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * PNL-6: the reversal of one wrongly recorded claim payment. The payment row is never changed; every figure
 * that adds up claim payments leaves reversed payments out. Append-only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $panel_claim_id
 * @property int $panel_claim_payment_id
 * @property int|null $panel_remittance_id
 * @property int $amount_sen
 * @property string $reason_code
 * @property string $note
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property CarbonImmutable $reversed_at
 * @property int $reversed_by_user_id
 */
#[Guarded(['*'])]
class PanelClaimPaymentReversal extends Model
{
    /** SQL condition: the claim payment (table panel_claim_payments) has not been reversed. */
    public const NOT_REVERSED = 'not exists (select 1 from panel_claim_payment_reversals rv where rv.panel_claim_payment_id = panel_claim_payments.id)';

    /** The same condition for a query that names panel_claim_payments "p". */
    public const NOT_REVERSED_P = 'not exists (select 1 from panel_claim_payment_reversals rv where rv.panel_claim_payment_id = p.id)';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel payment reversals are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel payment reversals are retained.'));
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'reversed_at' => 'immutable_datetime'];
    }
}
