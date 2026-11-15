<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $payment_method_id
 * @property CarbonImmutable $business_date
 * @property int $revision
 * @property int $terminal_sales_count
 * @property int $terminal_sales_sen
 * @property int $kpone_payment_count
 * @property int $kpone_payment_total_sen
 * @property int $payment_count_variance
 * @property int $payment_total_variance_sen
 * @property CarbonImmutable $reconciled_at
 */
#[Guarded(['*'])]
class PaymentReconciliation extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Payment reconciliation evidence is immutable; create a new revision.'));
        static::deleting(fn (): never => throw new LogicException('Payment reconciliation evidence is retained.'));
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'revision' => 'integer',
            'terminal_sales_count' => 'integer',
            'terminal_sales_sen' => 'integer',
            'terminal_refunds_count' => 'integer',
            'terminal_refunds_sen' => 'integer',
            'terminal_voids_count' => 'integer',
            'terminal_voids_sen' => 'integer',
            'kpone_payment_count' => 'integer',
            'kpone_payment_total_sen' => 'integer',
            'payment_count_variance' => 'integer',
            'payment_total_variance_sen' => 'integer',
            'reconciled_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
