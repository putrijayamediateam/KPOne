<?php

namespace App\Domain\HR\Records\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-4a: a staff member's request to correct a field they cannot change themselves. The new value is encrypted.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $worker_id
 * @property string $kind
 * @property array<string, string> $new_value_encrypted
 * @property string $reason_code
 * @property string|null $note_encrypted
 * @property string $status
 * @property int $asked_by_user_id
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class RecordCorrection extends Model
{
    protected $table = 'hr_record_corrections';

    protected function casts(): array
    {
        return ['new_value_encrypted' => 'encrypted:array', 'note_encrypted' => 'encrypted', 'decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'worker_id' => 'integer', 'asked_by_user_id' => 'integer', 'decided_by_user_id' => 'integer', 'lock_version' => 'integer'];
    }
}
