<?php

namespace App\Domain\Clinical\Dispensary\Models;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class DispensaryCase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPENSING = 'dispensing';

    public const STATUS_RETURNED = 'returned_to_doctor';

    public const STATUS_COMPLETED = 'completed';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary Cases are retained clinical-operational records.'));
        static::updating(function (self $case): void {
            foreach (['public_id', 'organisation_id', 'branch_id', 'visit_id', 'clinical_encounter_id', 'treatment_plan_id'] as $field) {
                if ($case->isDirty($field)) {
                    throw new LogicException('Dispensary Case ownership is immutable.');
                }
            }
            if ($case->getOriginal('status') === self::STATUS_COMPLETED) {
                throw new LogicException('Completed Dispensary Cases are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer', 'received_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<ClinicalEncounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'clinical_encounter_id');
    }

    /** @return BelongsTo<TreatmentPlan, $this> */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function currentHandler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_handler_user_id');
    }

    /** @return HasMany<DispensaryHandoff, $this> */
    public function handoffs(): HasMany
    {
        return $this->hasMany(DispensaryHandoff::class)->orderBy('attempt_number');
    }
}
