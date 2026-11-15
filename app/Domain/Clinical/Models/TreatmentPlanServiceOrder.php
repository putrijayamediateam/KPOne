<?php

namespace App\Domain\Clinical\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TreatmentPlanServiceOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property CarbonImmutable|null $withdrawn_at */
#[Guarded(['*'])]
class TreatmentPlanServiceOrder extends Model
{
    /** @use HasFactory<TreatmentPlanServiceOrderFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected static function newFactory(): TreatmentPlanServiceOrderFactory
    {
        return TreatmentPlanServiceOrderFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Persisted service orders must be withdrawn, not deleted.'));
        static::updating(function (self $order): void {
            if ($order->getOriginal('status') === self::STATUS_WITHDRAWN) {
                throw new LogicException('Withdrawn service orders are immutable.');
            }

            foreach ([
                'public_id', 'organisation_id', 'branch_id', 'treatment_plan_id', 'clinical_service_catalogue_item_id',
                'service_code_snapshot', 'service_name_snapshot', 'unit_snapshot', 'recorded_by_user_id',
            ] as $attribute) {
                if ($order->isDirty($attribute)) {
                    throw new LogicException('Service order identity, ownership and catalogue snapshot are immutable.');
                }
            }

        });
    }

    protected function casts(): array
    {
        return ['quantity_ordered' => 'decimal:3', 'position' => 'integer', 'withdrawn_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<TreatmentPlan, $this> */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    /** @return BelongsTo<ClinicalServiceCatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalServiceCatalogueItem::class, 'clinical_service_catalogue_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_user_id');
    }
}
