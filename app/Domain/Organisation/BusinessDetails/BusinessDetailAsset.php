<?php

namespace App\Domain\Organisation\BusinessDetails;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $public_id
 * @property string $path
 * @property string $mime
 * @property string $sha256
 * @property int $bytes
 * @property int $width
 * @property int $height
 */
final class BusinessDetailAsset extends Model
{
    protected $table = 'business_detail_assets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'retired_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::deleting(static fn () => throw new LogicException('Business details are retained and cannot be deleted.'));
    }
}
