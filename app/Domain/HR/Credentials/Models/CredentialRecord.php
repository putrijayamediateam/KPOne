<?php

namespace App\Domain\HR\Credentials\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-5a: one version of one person's credential. It waits until someone else accepts it; only the newest accepted
 * version is in force. The reference number is encrypted and masked to its last four.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $worker_id
 * @property int $type_id
 * @property int $version
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $expires_on
 * @property string|null $issuing_body
 * @property string|null $reference_encrypted
 * @property string|null $reference_last4
 * @property int|null $document_id
 * @property bool $document_created
 * @property bool|null $health_done
 * @property string $status
 * @property int $entered_by_user_id
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class CredentialRecord extends Model
{
    protected $table = 'hr_credential_records';

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'reference_encrypted' => 'encrypted', 'health_done' => 'boolean', 'document_created' => 'boolean', 'worker_id' => 'integer', 'type_id' => 'integer', 'version' => 'integer',
            'document_id' => 'integer', 'entered_by_user_id' => 'integer', 'decided_by_user_id' => 'integer', 'lock_version' => 'integer'];
    }
}
