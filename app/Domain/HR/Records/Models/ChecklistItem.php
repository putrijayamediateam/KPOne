<?php

namespace App\Domain\HR\Records\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-4b: one clinic-entered onboarding or offboarding item. Its wording never changes; archive it and add a new one.
 * An item about the account only records that HR asked the account administrator; HR never changes an account.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property string $list
 * @property string $label
 * @property bool $about_account
 * @property int $position
 * @property bool $is_active
 * @property int $lock_version
 */
#[Guarded(['*'])]
class ChecklistItem extends Model
{
    protected $table = 'hr_checklist_items';

    protected function casts(): array
    {
        return ['about_account' => 'boolean', 'is_active' => 'boolean', 'position' => 'integer', 'lock_version' => 'integer'];
    }
}
