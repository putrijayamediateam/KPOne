<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only history of a price change proposal: who did what, and when. Ids only; no amounts, references or notes.
 *
 * @property int $id
 * @property int $price_change_proposal_id
 * @property int $organisation_id
 * @property string $event
 * @property int $actor_user_id
 */
#[Guarded(['*'])]
class PriceChangeProposalEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Price change proposal evidence is retained.'));
        static::deleting(fn (): never => throw new LogicException('Price change proposal evidence is retained.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
