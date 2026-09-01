<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Factories\MedicineCatalogueItemFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class MedicineCatalogueItem extends Model
{
    /** @use HasFactory<MedicineCatalogueItemFactory> */
    use HasFactory;

    public const AUTHORISATION_DOCTOR_REQUIRED = 'doctor_order_required';

    protected static function newFactory(): MedicineCatalogueItemFactory
    {
        return MedicineCatalogueItemFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            foreach (['public_id', 'organisation_id', 'created_by_user_id'] as $attribute) {
                if ($item->isDirty($attribute)) {
                    throw new LogicException('Medicine catalogue ownership and public identity are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
