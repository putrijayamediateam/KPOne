<?php

namespace App\Domain\Queue\Treatment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $queue_entry_id
 * @property string $step_code
 * @property string $state
 * @property int $started_by_user_id
 * @property Carbon $started_at
 * @property int|null $done_by_user_id
 * @property Carbon|null $done_at
 * @property string|null $cancel_reason_code
 * @property Carbon|null $closed_at
 * @property int $lock_version
 */
class TreatmentStep extends Model
{
    public const STATE_STARTED = 'started';

    public const STATE_DONE = 'done';

    protected $table = 'queue_treatment_steps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
            'started_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
