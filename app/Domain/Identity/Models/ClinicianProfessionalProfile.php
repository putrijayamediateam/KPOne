<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $professional_name
 * @property string $mmc_number
 * @property bool $verified
 * @property int $lock_version
 */
#[Guarded(['*'])]
final class ClinicianProfessionalProfile extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $hidden = ['professional_name', 'mmc_number'];

    protected function casts(): array
    {
        return ['verified' => 'boolean', 'verified_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }
}
