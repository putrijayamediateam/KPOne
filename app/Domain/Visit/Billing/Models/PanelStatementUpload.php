<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * PNL-7: a Panel remittance statement uploaded to suggest claim matches. The encrypted file lives on the
 * private disk at stored_path until delete_after; its rows are never stored in the database.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $panel_id
 * @property string $stored_path
 * @property int $size_bytes
 * @property string $content_hash
 * @property int $row_count
 * @property int $uploaded_by_user_id
 * @property CarbonImmutable $uploaded_at
 * @property CarbonImmutable $delete_after
 * @property CarbonImmutable|null $deleted_at
 */
#[Guarded(['*'])]
class PanelStatementUpload extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Panel statement records are kept; only the file is deleted.'));
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'uploaded_at' => 'immutable_datetime',
            'delete_after' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
