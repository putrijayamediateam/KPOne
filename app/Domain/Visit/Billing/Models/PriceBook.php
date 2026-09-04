<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class PriceBook extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(function (self $book): void {
            if ($book->isDirty(['public_id', 'organisation_id', 'branch_id', 'scope_key', 'currency'])) {
                throw new LogicException('Commercial book scope and currency are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
