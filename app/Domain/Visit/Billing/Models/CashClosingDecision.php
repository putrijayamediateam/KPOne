<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-4: the approval or return of a cash closing by someone other than the counter. Append-only, one per closing.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $cash_closing_id
 * @property string $decision
 * @property string|null $reason
 * @property int $decided_by_user_id
 * @property CarbonImmutable $decided_at
 * @property string $idempotency_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class CashClosingDecision extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cash closing decisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Cash closing decisions are retained.'));
    }

    protected function casts(): array
    {
        return ['cash_closing_id' => 'integer', 'decided_by_user_id' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
