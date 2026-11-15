<?php

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property string $quantity_performed */
#[Guarded(['*'])]
class ServiceDelivery extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Service confirmation is immutable checkout evidence.'));
        static::deleting(fn (): never => throw new LogicException('Service confirmation is retained.'));
    }

    protected function casts(): array
    {
        return ['quantity_performed' => 'decimal:3', 'performed_at' => 'immutable_datetime', 'source_plan_version' => 'integer', 'lock_version' => 'integer'];
    }
}
