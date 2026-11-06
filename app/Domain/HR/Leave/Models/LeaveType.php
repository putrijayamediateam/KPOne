<?php

namespace App\Domain\HR\Leave\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property bool $is_paid
 * @property bool $uses_balance
 * @property bool $allow_half_days
 * @property bool $evidence_needed
 * @property bool $allow_negative
 * @property int|null $carry_max_halves
 * @property string|null $carry_expires_on
 * @property bool $is_active
 * @property int $lock_version
 */
#[Guarded(['*'])]
class LeaveType extends Model
{
    protected $table = 'hr_leave_types';

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'uses_balance' => 'boolean', 'allow_half_days' => 'boolean', 'evidence_needed' => 'boolean',
            'allow_negative' => 'boolean', 'is_active' => 'boolean', 'carry_max_halves' => 'integer', 'lock_version' => 'integer'];
    }
}
