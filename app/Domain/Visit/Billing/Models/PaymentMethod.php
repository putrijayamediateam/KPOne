<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class PaymentMethod extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(function (self $method): void {
            if ($method->isDirty(['organisation_id', 'code'])) {
                throw new LogicException('Payment Method tenant and code are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['requires_reference' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @param Builder<self> $query */
    public function scopeOperational(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
