<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $coverage_allocation_id
 * @property int $invoice_id
 * @property int $patient_id
 * @property int $panel_id
 * @property string $invoice_number
 * @property string $visit_number
 * @property string $patient_name_snapshot
 * @property string $panel_name_snapshot
 * @property string|null $member_reference
 * @property string $branch_name_snapshot
 * @property CarbonImmutable $visit_date
 * @property int $invoice_total_sen
 * @property int $claim_amount_sen
 * @property int|null $accepted_amount_sen
 * @property string $status
 * @property int $lock_version
 * @property string|null $remarks
 */
#[Guarded(['*'])]
class PanelClaim extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Panel claim records are retained.'));
        static::updating(function (self $claim): void {
            $allowed = ['claim_amount_sen', 'accepted_amount_sen', 'status', 'lock_version', 'remarks', 'updated_at'];
            if (array_diff(array_keys($claim->getDirty()), $allowed) !== []) {
                throw new LogicException('Panel claim identity and source snapshots are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'visit_date' => 'immutable_date',
            'invoice_total_sen' => 'integer',
            'claim_amount_sen' => 'integer',
            'accepted_amount_sen' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** @return HasMany<PanelClaimLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PanelClaimLine::class)->orderBy('line_number');
    }

    /** @return HasMany<PanelClaimEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PanelClaimEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** @return HasMany<PanelClaimDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PanelClaimDocument::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<PanelClaimPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PanelClaimPayment::class)->orderBy('received_at')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
