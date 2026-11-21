<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $price_book_id
 * @property int $charge_definition_id
 * @property int $unit_price_sen
 * @property int $version
 * @property CarbonImmutable $effective_at
 * @property int $published_by_user_id
 */
#[Guarded(['*'])]
class PriceEntry extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(fn (): never => throw new LogicException('Financial snapshot evidence is immutable.'));
    }

    protected function casts(): array
    {
        return ['unit_price_sen' => 'integer', 'version' => 'integer', 'effective_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
