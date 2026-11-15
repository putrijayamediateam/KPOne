<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-15: one batch share of a split quarantine check, with its own STK-11 dispensary_return movement. Append-only;
 * PostgreSQL repeats the pairing and per-batch rules and checks at commit that the shares add up to the return.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $dispensary_return_check_id
 * @property int $inventory_batch_id
 * @property string $quantity
 * @property int $stock_movement_id
 */
#[Guarded(['*'])]
class DispensaryReturnCheckBatch extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary return check batches are append-only.'));
        static::updating(fn (): never => throw new LogicException('Dispensary return check batches are append-only.'));
    }
}
