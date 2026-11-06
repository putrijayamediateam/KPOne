<?php

namespace App\Domain\HR\Roster\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property array<string, array<string, int>> $minimums session => role => count
 * @property int $lock_version
 * @property int $updated_by_user_id
 */
#[Guarded(['*'])]
class MinimumStaffing extends Model
{
    protected $table = 'hr_minimum_staffing';

    protected function casts(): array
    {
        return ['minimums' => 'array', 'lock_version' => 'integer'];
    }
}
