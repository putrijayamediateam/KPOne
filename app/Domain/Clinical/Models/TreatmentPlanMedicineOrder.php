<?php

namespace App\Domain\Clinical\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TreatmentPlanMedicineOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property CarbonImmutable|null $withdrawn_at */
#[Guarded(['*'])]
class TreatmentPlanMedicineOrder extends Model
{
    /** @use HasFactory<TreatmentPlanMedicineOrderFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected static function newFactory(): TreatmentPlanMedicineOrderFactory
    {
        return TreatmentPlanMedicineOrderFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Persisted medicine orders must be withdrawn, not deleted.'));
        static::updating(function (self $order): void {
            if ($order->getOriginal('status') === self::STATUS_WITHDRAWN) {
                throw new LogicException('Withdrawn medicine orders are immutable.');
            }

            foreach ([
                'public_id', 'organisation_id', 'branch_id', 'treatment_plan_id', 'medicine_catalogue_item_id',
                'medicine_code_snapshot', 'medicine_name_snapshot', 'strength_snapshot', 'dosage_form_snapshot',
                'unit_snapshot', 'recorded_by_user_id',
            ] as $attribute) {
                if ($order->isDirty($attribute)) {
                    throw new LogicException('Medicine order identity, ownership and catalogue snapshot are immutable.');
                }
            }

        });
    }

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'allergy_profile_version_validated' => 'integer',
            'position' => 'integer',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TreatmentPlan, $this> */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    /** @return BelongsTo<MedicineCatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(MedicineCatalogueItem::class, 'medicine_catalogue_item_id');
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
