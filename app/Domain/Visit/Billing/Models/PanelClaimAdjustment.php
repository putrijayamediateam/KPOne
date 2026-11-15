<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * PCA-2: a requested, confirmed or withdrawn change to an invoice's Panel share. Never edited or deleted; a mistake is
 * put right with an opposite adjustment. PostgreSQL enforces the same (panel_claim_adjustments_guard).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $coverage_allocation_id
 * @property int|null $panel_claim_id
 * @property string $direction
 * @property int $amount_sen
 * @property string $reason_code
 * @property string|null $note
 * @property string $status
 * @property string|null $claim_status_at_request
 * @property int $invoice_version
 * @property int $requested_by_user_id
 * @property CarbonImmutable $requested_at
 * @property string $request_idempotency_key
 * @property string $request_payload_hash
 * @property int|null $confirmed_by_user_id
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $confirm_idempotency_key
 * @property int|null $withdrawn_by_user_id
 * @property CarbonImmutable|null $withdrawn_at
 * @property int $lock_version
 */
#[Guarded(['*'])]
class PanelClaimAdjustment extends Model
{
    public const REASONS = [
        'panel_service_charge' => 'Panel service charge',
        'panel_tax' => 'Panel tax',
        'panel_tariff_change' => 'Panel tariff change',
        'panel_company_correction' => 'Panel company correction',
        'clinic_correction' => 'Clinic correction',
        'other' => 'Other',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Panel claim adjustment evidence is retained.'));
        static::updating(function (self $adjustment): void {
            $allowed = ['status', 'confirmed_by_user_id', 'confirmed_at', 'confirm_idempotency_key', 'withdrawn_by_user_id', 'withdrawn_at', 'lock_version', 'updated_at'];
            if ($adjustment->getOriginal('status') !== 'requested' || array_diff(array_keys($adjustment->getDirty()), $allowed) !== []) {
                throw new LogicException('A Panel claim adjustment can only be confirmed or withdrawn once.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount_sen' => 'integer',
            'invoice_version' => 'integer',
            'lock_version' => 'integer',
            'requested_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Signed amount: plus for add, minus for deduct. */
    public function signedSen(): int
    {
        return $this->direction === 'add' ? $this->amount_sen : -$this->amount_sen;
    }
}
