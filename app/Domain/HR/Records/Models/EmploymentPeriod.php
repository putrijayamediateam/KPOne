<?php

namespace App\Domain\HR\Records\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $worker_id
 * @property string $type
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $probation_ends_on
 * @property CarbonImmutable|null $confirmed_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $end_reason
 * @property string|null $note_encrypted
 * @property int $lock_version
 */
#[Guarded(['*'])]
class EmploymentPeriod extends Model
{
    protected $table = 'hr_employment_periods';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'probation_ends_on' => 'immutable_date', 'confirmed_on' => 'immutable_date', 'ends_on' => 'immutable_date',
            'note_encrypted' => 'encrypted', 'worker_id' => 'integer', 'lock_version' => 'integer'];
    }
}
