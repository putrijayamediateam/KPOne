<?php

namespace App\Domain\HR\Records\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-4b: one staff document. The bytes are encrypted on the private disk under a generated name; no original file
 * name is kept. A removed or purged document keeps its row and loses only its bytes.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $worker_id
 * @property string $category
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $expires_on
 * @property string $kind
 * @property int $bytes
 * @property string $sha256
 * @property string $stored_path
 * @property string $status
 * @property int $uploaded_by_user_id
 * @property int|null $removed_by_user_id
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removal_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class Document extends Model
{
    protected $table = 'hr_documents';

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'removed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'worker_id' => 'integer', 'bytes' => 'integer', 'uploaded_by_user_id' => 'integer', 'removed_by_user_id' => 'integer', 'lock_version' => 'integer'];
    }

    public function mime(): string
    {
        return ['pdf' => 'application/pdf', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$this->kind] ?? 'application/octet-stream';
    }
}
