<?php

namespace App\Domain\Visit\Billing\Models;

use App\Domain\Visit\Models\Panel;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class PriceBook extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(function (self $book): void {
            if ($book->isDirty(['public_id', 'organisation_id', 'branch_id', 'scope_key', 'currency', 'price_tier', 'panel_id'])) {
                throw new LogicException('Commercial book scope and currency are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'panel_id' => 'integer', 'branch_id' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Panel, $this> */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }
}
