<?php

namespace App\Domain\HR\Attendance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-PHOTO: the live photo taken at one button clock-in or clock-out. Never changed; deleted only with its attendance
 * record after 7 years.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $user_id
 * @property int $clock_session_id
 * @property string $kind
 * @property int $capture_token_id
 * @property string $stored_path
 * @property int $bytes
 * @property string $thumb_path
 * @property int $thumb_bytes
 * @property int $width
 * @property int $height
 * @property string $sha256
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class ClockPhoto extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr_clock_photos';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'user_id' => 'integer', 'clock_session_id' => 'integer', 'capture_token_id' => 'integer',
            'bytes' => 'integer', 'thumb_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
