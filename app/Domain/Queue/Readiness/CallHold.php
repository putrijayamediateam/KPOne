<?php

namespace App\Domain\Queue\Readiness;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $queue_entry_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $set_by_user_id
 * @property string $reason_code
 * @property string $state
 * @property int $lock_version
 */
class CallHold extends Model
{
    protected $table = 'queue_call_holds';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'closed_at' => 'immutable_datetime'];
    }
}
