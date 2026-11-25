<?php

namespace App\Domain\Visit\Assignment\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTO-1: the Director's per-branch switch for automatic doctor assignment (off when there is no row).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property bool $enabled
 * @property int $lock_version
 * @property int $updated_by_user_id
 */
#[Guarded(['*'])]
class BranchAutoAssignSetting extends Model
{
    protected $table = 'branch_auto_assign_settings';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
