<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class PanelClaimEvent extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel claim history is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel claim history is retained.'));
    }

    protected function casts(): array
    {
        return [
            'previous_amount_sen' => 'integer',
            'amount_sen' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
