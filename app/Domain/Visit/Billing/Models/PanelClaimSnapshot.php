<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * PCA-2: one recorded version of a claim pack's totals. Version 1 is written when the claim is created; each confirmed
 * Panel claim adjustment on an undecided claim adds the next. Append-only (panel_claim_snapshots_guard).
 *
 * @property int $id
 * @property string $public_id
 * @property int $panel_claim_id
 * @property int $version
 * @property int $invoice_total_sen
 * @property int $panel_adjustment_sen
 * @property int $panel_share_sen
 * @property int $claim_amount_sen
 * @property int|null $panel_claim_adjustment_id
 * @property int $created_by_user_id
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class PanelClaimSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel claim versions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel claim versions are retained.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'invoice_total_sen' => 'integer',
            'panel_adjustment_sen' => 'integer',
            'panel_share_sen' => 'integer',
            'claim_amount_sen' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
