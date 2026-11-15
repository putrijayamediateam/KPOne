<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-1: append-only evidence that the CA confirmed a possible allergy match ("I have checked with
 * the patient/doctor") before a medicine line was saved. Holds references only, never clinical text.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $dispensary_case_id
 * @property int $dispensary_handoff_id
 * @property int $dispensary_item_id
 * @property int $patient_allergy_record_id
 * @property int $allergy_profile_lock_version
 * @property string $action
 * @property int $item_lock_version
 * @property int $confirmed_by_user_id
 * @property CarbonImmutable $confirmed_at
 */
#[Guarded(['*'])]
class DispensaryAllergyConfirmation extends Model
{
    public const ACTION_ADD = 'add';

    public const ACTION_EDIT = 'edit';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary allergy confirmations are append-only.'));
        static::updating(fn (): never => throw new LogicException('Dispensary allergy confirmations are append-only.'));
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }
}
