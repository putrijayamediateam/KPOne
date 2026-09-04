<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property string $quantity
 * @property int $unit_price_sen
 * @property int $line_total_sen
 * @property int $price_version
 */
#[Guarded(['*'])]
class InvoiceLine extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(fn (): never => throw new LogicException('Financial snapshot evidence is immutable.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price_sen' => 'integer', 'line_total_sen' => 'integer', 'price_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
