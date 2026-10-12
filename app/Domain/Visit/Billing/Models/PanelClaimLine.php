<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $line_number
 * @property string $line_type
 * @property string $display_name
 * @property string $code_snapshot
 * @property string $unit_snapshot
 * @property string $quantity
 * @property int $unit_price_sen
 * @property int $line_total_sen
 */
#[Guarded(['*'])]
class PanelClaimLine extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel claim line snapshots are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel claim line snapshots are retained.'));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_sen' => 'integer',
            'line_total_sen' => 'integer',
            'line_number' => 'integer',
        ];
    }
}
