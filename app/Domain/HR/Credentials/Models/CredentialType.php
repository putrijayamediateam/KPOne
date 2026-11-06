<?php

namespace App\Domain\HR\Credentials\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-5a: one clinic-entered credential type. Its code and whether it expires or is a health item are fixed; its name,
 * warning days and the roles that need it live in append-only versions (hr_credential_type_versions).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property string $code
 * @property bool $expires
 * @property bool $health_item
 * @property int $current_version
 * @property bool $is_active
 * @property int $created_by_user_id
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class CredentialType extends Model
{
    protected $table = 'hr_credential_types';

    protected function casts(): array
    {
        return ['expires' => 'boolean', 'health_item' => 'boolean', 'is_active' => 'boolean', 'current_version' => 'integer', 'lock_version' => 'integer',
            'created_by_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
