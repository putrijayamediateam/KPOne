<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property string $proposed_quantity_dispensed Exact decimal:3 cast. */
#[Guarded(['*'])]
class DispensaryItemException extends Model
{
    public const REASON_PATIENT_DECLINED = 'patient_declined';

    public const STATUS_AWAITING = 'awaiting_acknowledgement';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_SUPERSEDED = 'superseded';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary exceptions are retained records.'));
        static::updating(function (self $exception): void {
            foreach (['public_id', 'organisation_id', 'branch_id', 'dispensary_case_id', 'dispensary_handoff_id', 'dispensary_item_id', 'proposed_quantity_dispensed', 'reason', 'expected_case_lock_version', 'expected_item_lock_version', 'created_by_user_id'] as $field) {
                if ($exception->isDirty($field)) {
                    throw new LogicException('Dispensary exception evidence is immutable.');
                }
            }
            $from = $exception->getOriginal('status');
            $to = $exception->status;
            $allowed = ($from === self::STATUS_AWAITING && in_array($to, [self::STATUS_ACKNOWLEDGED, self::STATUS_SUPERSEDED], true))
                || ($from === self::STATUS_ACKNOWLEDGED && $to === self::STATUS_SUPERSEDED);
            if (! $allowed) {
                throw new LogicException('Closed Dispensary exceptions are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['proposed_quantity_dispensed' => 'decimal:3', 'expected_case_lock_version' => 'integer', 'expected_item_lock_version' => 'integer', 'acknowledged_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
