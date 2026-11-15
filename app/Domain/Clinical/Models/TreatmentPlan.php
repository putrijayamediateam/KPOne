<?php

namespace App\Domain\Clinical\Models;

use App\Models\User;
use Database\Factories\TreatmentPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class TreatmentPlan extends Model
{
    /** @use HasFactory<TreatmentPlanFactory> */
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_READY_FOR_DISPENSING = 'ready_for_dispensing';

    protected static function newFactory(): TreatmentPlanFactory
    {
        return TreatmentPlanFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Treatment Plans are retained clinical records.'));
        static::updating(function (self $plan): void {
            foreach (['organisation_id', 'branch_id', 'clinical_encounter_id', 'created_by_user_id'] as $attribute) {
                if ($plan->isDirty($attribute)) {
                    throw new LogicException('Treatment Plan ownership and lifecycle are immutable in Phase 2B.');
                }
            }
            if ($plan->isDirty('status') && ! in_array([$plan->getOriginal('status'), $plan->status], [
                [self::STATUS_IN_PROGRESS, self::STATUS_READY_FOR_DISPENSING],
                [self::STATUS_READY_FOR_DISPENSING, self::STATUS_IN_PROGRESS],
            ], true)) {
                throw new LogicException('Invalid Treatment Plan lifecycle transition.');
            }
        });
    }

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    /** @return BelongsTo<ClinicalEncounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'clinical_encounter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<TreatmentPlanMedicineOrder, $this> */
    public function medicineOrders(): HasMany
    {
        return $this->hasMany(TreatmentPlanMedicineOrder::class)->orderBy('position');
    }

    /** @return HasMany<TreatmentPlanServiceOrder, $this> */
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(TreatmentPlanServiceOrder::class)->orderBy('position');
    }
}
