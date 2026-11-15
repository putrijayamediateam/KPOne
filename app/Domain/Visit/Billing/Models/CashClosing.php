<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * BIL-4: one counted daily cash closing (takings only, float excluded). Append-only: a correction is a new revision.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property CarbonImmutable $business_date
 * @property int $revision
 * @property int $expected_receipt_count
 * @property int $expected_cash_sen
 * @property int $counted_cash_sen
 * @property int $variance_sen
 * @property string|null $variance_reason
 * @property int $counted_by_user_id
 * @property CarbonImmutable $counted_at
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property-read CashClosingDecision|null $decision
 */
#[Guarded(['*'])]
class CashClosing extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cash closing evidence is immutable; record a new count.'));
        static::deleting(fn (): never => throw new LogicException('Cash closing evidence is retained.'));
    }

    /** @return HasOne<CashClosingDecision, $this> */
    public function decision(): HasOne
    {
        return $this->hasOne(CashClosingDecision::class);
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date', 'revision' => 'integer', 'expected_receipt_count' => 'integer',
            'expected_cash_sen' => 'integer', 'counted_cash_sen' => 'integer', 'variance_sen' => 'integer',
            'counted_by_user_id' => 'integer', 'counted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        ];
    }
}
