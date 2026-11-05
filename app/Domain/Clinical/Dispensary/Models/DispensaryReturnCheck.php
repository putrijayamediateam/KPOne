<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-7: the one check of a recorded return by someone other than its recorder. Append-only. Quarantine pairs with
 * one STK-11 dispensary_return stock movement; Dispose and Refuse move no stock.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $dispensary_return_id
 * @property string $outcome
 * @property string|null $refuse_reason
 * @property string|null $refuse_note_encrypted
 * @property int|null $inventory_location_id
 * @property int|null $inventory_batch_id
 * @property int|null $stock_movement_id
 * @property int $checked_by_user_id
 * @property CarbonImmutable $checked_at
 * @property string $retry_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class DispensaryReturnCheck extends Model
{
    public const OUTCOME_DISPOSE = 'dispose';

    public const OUTCOME_QUARANTINE = 'quarantine';

    public const OUTCOME_REFUSE = 'refuse';

    public const OUTCOMES = [self::OUTCOME_DISPOSE, self::OUTCOME_QUARANTINE, self::OUTCOME_REFUSE];

    public const REFUSE_REASONS = ['wrong_case_or_patient', 'wrong_medicine_line', 'wrong_quantity', 'recorded_twice', 'other'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary return checks are append-only.'));
        static::updating(fn (): never => throw new LogicException('Dispensary return checks are append-only.'));
    }

    protected function casts(): array
    {
        return ['refuse_note_encrypted' => 'encrypted', 'checked_at' => 'immutable_datetime'];
    }
}
