<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-15: a branch's cash closing start date, one append-only revision per change; the latest revision is current.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $revision
 * @property CarbonImmutable $starts_on
 * @property int $set_by_user_id
 * @property CarbonImmutable $set_at
 * @property string $idempotency_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class CashClosingStartDate extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cash closing start dates are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Cash closing start dates are retained.'));
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'set_by_user_id' => 'integer', 'starts_on' => 'immutable_date', 'set_at' => 'immutable_datetime'];
    }
}
