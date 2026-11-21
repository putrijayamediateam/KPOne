<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A proposed Panel price, decided once by someone other than the proposer (approved, rejected) or withdrawn by the
 * proposer. Never edited after a decision and never deleted; PostgreSQL enforces the same (price_change_proposals_guard).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $price_book_id
 * @property int $charge_definition_id
 * @property int $proposed_unit_price_sen
 * @property string $agreement_reference
 * @property CarbonImmutable $effective_at
 * @property int $base_version
 * @property string $status
 * @property int $proposed_by_user_id
 * @property CarbonImmutable $proposed_at
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $reason_code
 * @property string|null $decision_note
 * @property int|null $price_entry_id
 * @property CarbonImmutable|null $applied_effective_at
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int $lock_version
 */
#[Guarded(['*'])]
class PriceChangeProposal extends Model
{
    public const REJECT_REASONS = [
        'wrong_amount' => 'Wrong amount',
        'wrong_panel' => 'Wrong Panel',
        'no_agreement_seen' => 'Agreement not seen',
        'other' => 'Other',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Price change proposal evidence is retained.'));
        static::updating(function (self $proposal): void {
            $allowed = ['status', 'decided_by_user_id', 'decided_at', 'reason_code', 'decision_note', 'price_entry_id', 'applied_effective_at', 'lock_version', 'updated_at'];
            if ($proposal->getOriginal('status') !== 'proposed' || array_diff(array_keys($proposal->getDirty()), $allowed) !== []) {
                throw new LogicException('A price change proposal can only be decided once.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'proposed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'applied_effective_at' => 'immutable_datetime',
            'decision_note' => 'encrypted',
            'proposed_unit_price_sen' => 'integer',
            'base_version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<PriceBook, $this> */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    /** @return BelongsTo<ChargeDefinition, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(ChargeDefinition::class, 'charge_definition_id');
    }
}
