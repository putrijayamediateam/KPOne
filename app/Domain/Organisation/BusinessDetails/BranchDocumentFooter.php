<?php

namespace App\Domain\Organisation\BusinessDetails;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property int $lock_version */
final class BranchDocumentFooter extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(static fn () => throw new LogicException('Document footer settings are retained.'));
    }
}
