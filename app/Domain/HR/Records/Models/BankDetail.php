<?php

namespace App\Domain\HR\Records\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-4a: one bank account entry, confirmed by someone other than the person who entered it and the worker.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $worker_id
 * @property string $bank_name_encrypted
 * @property string $holder_encrypted
 * @property string $account_encrypted
 * @property string $account_last4
 * @property string $status
 * @property int $entered_by_user_id
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class BankDetail extends Model
{
    protected $table = 'hr_bank_details';

    protected function casts(): array
    {
        return ['bank_name_encrypted' => 'encrypted', 'holder_encrypted' => 'encrypted', 'account_encrypted' => 'encrypted', 'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'worker_id' => 'integer', 'entered_by_user_id' => 'integer', 'decided_by_user_id' => 'integer', 'lock_version' => 'integer'];
    }
}
