<?php

namespace App\Domain\Clinical\Dispensary\Models;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DS-01a-services: the CA's final, confirmed version of one service of a consultation visit, per
 * Dispensary handoff. It starts from the doctor's confirmation; the doctor's order and `service_deliveries`
 * evidence are never changed. A line the CA adds has no treatment-plan order (source = 'ca').
 *
 * @property string $quantity_performed Exact decimal:3 cast; never floating-point quantity.
 */
#[Guarded(['*'])]
class DispensaryServiceLine extends Model
{
    public const SOURCE_DOCTOR = 'doctor';

    public const SOURCE_CA = 'ca';

    public const CHANGE_UNCHANGED = 'unchanged';

    public const CHANGE_EDITED = 'edited';

    public const CHANGE_ADDED = 'added';

    public const CHANGE_REMOVED = 'removed';

    public const DISPOSITION_PERFORMED = 'performed';

    public const DISPOSITION_NOT_PERFORMED = 'not_performed';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary service lines cannot be deleted.'));
        static::updating(function (self $line): void {
            foreach (['public_id', 'organisation_id', 'branch_id', 'dispensary_handoff_id', 'treatment_plan_service_order_id', 'clinical_service_catalogue_item_id', 'service_code_snapshot', 'service_name_snapshot', 'unit_snapshot', 'quantity_ordered', 'clinical_instruction', 'doctor_quantity_performed', 'source'] as $field) {
                if ($line->isDirty($field)) {
                    throw new LogicException('Dispensary service line identity and the doctor\'s snapshot are immutable.');
                }
            }
            $handoff = DispensaryHandoff::query()->whereKey($line->dispensary_handoff_id)->value('status');
            if ($handoff !== null && $handoff !== DispensaryHandoff::STATUS_OPEN) {
                throw new LogicException('Service lines of a closed Dispensary handoff are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['quantity_ordered' => 'decimal:3', 'quantity_performed' => 'decimal:3', 'doctor_quantity_performed' => 'decimal:3', 'performed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** The instruction to show: the CA's version when there is one, otherwise the doctor's. */
    public function effectiveInstruction(): ?string
    {
        return $this->final_instruction ?? $this->clinical_instruction;
    }

    /** @return BelongsTo<DispensaryHandoff, $this> */
    public function handoff(): BelongsTo
    {
        return $this->belongsTo(DispensaryHandoff::class, 'dispensary_handoff_id');
    }

    /** @return BelongsTo<TreatmentPlanServiceOrder, $this> */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlanServiceOrder::class, 'treatment_plan_service_order_id');
    }

    /** @return BelongsTo<ClinicalServiceCatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(ClinicalServiceCatalogueItem::class, 'clinical_service_catalogue_item_id');
    }
}
