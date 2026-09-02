<?php

namespace App\Domain\Clinical\Dispensary\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class DispensaryHandoff extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_COMPLETED = 'completed';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary Handoffs are immutable history.'));
        static::updating(function (self $handoff): void {
            foreach (['public_id', 'organisation_id', 'branch_id', 'dispensary_case_id', 'attempt_number', 'treatment_plan_lock_version_received', 'sent_by_user_id', 'sent_at'] as $field) {
                if ($handoff->isDirty($field)) {
                    throw new LogicException('Dispensary Handoff identity and received Plan version are immutable.');
                }
            }
            if ($handoff->getOriginal('status') !== self::STATUS_OPEN) {
                throw new LogicException('Closed Dispensary Handoffs are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'treatment_plan_lock_version_received' => 'integer', 'sent_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<DispensaryCase, $this> */
    public function dispensaryCase(): BelongsTo
    {
        return $this->belongsTo(DispensaryCase::class);
    }

    /** @return HasMany<DispensaryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DispensaryItem::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
