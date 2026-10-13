<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $panel_claim_id
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_path
 * @property int $uploaded_by_user_id
 * @property CarbonImmutable|null $created_at
 */
#[Guarded(['*'])]
class PanelClaimDocument extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel claim documents are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel claim documents are retained.'));
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
