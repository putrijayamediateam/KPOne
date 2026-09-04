<?php

namespace App\Domain\Clinical\Dispensary\Models;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** @property string|null $quantity_dispensed Exact decimal:3 cast; never floating-point quantity. */
#[Guarded(['*'])]
class DispensaryItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NOT_DISPENSED = 'not_dispensed';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary Items cannot be deleted.'));
        static::updating(function (self $item): void {
            foreach (['public_id', 'organisation_id', 'branch_id', 'dispensary_handoff_id', 'treatment_plan_medicine_order_id', 'medicine_catalogue_item_id', 'medicine_order_public_id', 'medicine_code_snapshot', 'medicine_name_snapshot', 'strength_snapshot', 'dosage_form_snapshot', 'unit_snapshot', 'quantity_ordered', 'dosage', 'frequency', 'duration', 'route', 'administration_instruction', 'precaution', 'allergy_profile_version_validated'] as $field) {
                if ($item->isDirty($field)) {
                    throw new LogicException('Dispensary Item clinical snapshots are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['quantity_ordered' => 'decimal:3', 'quantity_dispensed' => 'decimal:3', 'allergy_profile_version_validated' => 'integer', 'lock_version' => 'integer', 'handled_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<DispensaryHandoff, $this> */
    public function handoff(): BelongsTo
    {
        return $this->belongsTo(DispensaryHandoff::class, 'dispensary_handoff_id');
    }

    /** @return BelongsTo<TreatmentPlanMedicineOrder, $this> */
    public function medicineOrder(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanMedicineOrder::class, 'treatment_plan_medicine_order_id');
    }

    /** @return BelongsTo<MedicineCatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(MedicineCatalogueItem::class, 'medicine_catalogue_item_id');
    }

    /** @return HasMany<DispensaryItemException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(DispensaryItemException::class);
    }

    /** @return HasMany<DispensaryItemBatchAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(DispensaryItemBatchAllocation::class);
    }
}
