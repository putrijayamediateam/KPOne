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

    public const SOURCE_DOCTOR = 'doctor';

    public const SOURCE_CA = 'ca';

    public const CHANGE_UNCHANGED = 'unchanged';

    public const CHANGE_EDITED = 'edited';

    public const CHANGE_ADDED = 'added';

    public const CHANGE_REMOVED = 'removed';

    public const REASON_CA_REMOVED = 'ca_removed';

    /**
     * The text fields a CA may change at Dispensary, mapped to the column holding the CA's version.
     * The doctor's snapshot column is immutable; a null final_* column means the snapshot stands.
     *
     * @var array<string, string>
     */
    public const EDITABLE_TEXT = [
        'dosage' => 'final_dosage',
        'frequency' => 'final_frequency',
        'duration' => 'final_duration',
        'route' => 'final_route',
        'administration_instruction' => 'final_administration_instruction',
        'precaution' => 'final_precaution',
    ];

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

    /** The value to dispense and print: the CA's version when there is one, otherwise the doctor's. */
    public function effective(string $field): ?string
    {
        $final = self::EDITABLE_TEXT[$field] ?? null;

        return ($final !== null ? $this->getAttribute($final) : null) ?? $this->getAttribute($field);
    }

    protected function casts(): array
    {
        return ['edited_at' => 'immutable_datetime', 'quantity_ordered' => 'decimal:3', 'quantity_dispensed' => 'decimal:3', 'allergy_profile_version_validated' => 'integer', 'lock_version' => 'integer', 'handled_at' => 'immutable_datetime'];
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
