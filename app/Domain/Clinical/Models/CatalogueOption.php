<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class CatalogueOption extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Catalogue options are retained; deactivate them instead.'));
        static::updating(function (self $option): void {
            if ($option->isDirty(['organisation_id', 'option_type', 'normalized_label'])) {
                throw new LogicException('Catalogue option identity is immutable.');
            }
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
