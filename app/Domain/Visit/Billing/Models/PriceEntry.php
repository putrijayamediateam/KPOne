<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

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
