<?php

namespace App\Domain\Queue\Models;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property string|null $ticker_text
 * @property string|null $youtube_video_id
 * @property int $poster_seconds
 * @property string $call_display_mode
 * @property list<array{id: string, path: string, mime: string}> $posters
 * @property int $lock_version
 * @property int $updated_by_user_id
 * @property-read Branch $branch
 */
#[Guarded(['*'])]
class BranchDisplaySetting extends Model
{
    public const MAX_POSTERS = 8;

    /** How a called patient is shown and spoken: by queue number, by number and short name (Q-3), or by full registered name. */
    public const MODE_NUMBER = 'number';

    public const MODE_NAME = 'name';

    /** Q-3: the number plus the first two name words (ShortDisplayName); the full name never leaves the server. */
    public const MODE_SHORT_NAME = 'short_name';

    public const MODES = [self::MODE_NUMBER, self::MODE_SHORT_NAME, self::MODE_NAME];

    protected function casts(): array
    {
        return [
            'poster_seconds' => 'integer',
            'posters' => 'array',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
