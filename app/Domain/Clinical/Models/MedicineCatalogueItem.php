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
        static::deleting(fn (): never => throw new LogicException('Medicine catalogue items must be deactivated, not deleted.'));
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
        return [
            'is_active' => 'boolean',
            'is_controlled' => 'boolean',
            'allergy_groups' => 'array',
            'allergy_aliases' => 'array',
            'default_dosage_amount' => 'decimal:3',
        ];
    }

    /**
     * DSP-1b: drug groups used by the Dispensary allergy warning.
     *
     * @return list<string>
     */
    public function allergyGroupList(): array
    {
        return $this->textList('allergy_groups');
    }

    /**
     * DSP-1c: other names (brand names, common misspellings) used by the Dispensary allergy warning only.
     *
     * @return list<string>
     */
    public function allergyAliasList(): array
    {
        return $this->textList('allergy_aliases');
    }

    /**
     * Every term the Dispensary allergy warning compares with a patient's allergy text (DSP-1, DSP-1b, DSP-1c):
     * the display name, generic name, drug groups and other names.
     *
     * @return list<string>
     */
    public function allergyMatchTerms(): array
    {
        return [(string) $this->display_name, (string) $this->generic_name, ...$this->allergyGroupList(), ...$this->allergyAliasList()];
    }

    /** @return list<string> */
    private function textList(string $attribute): array
    {
        $items = $this->getAttribute($attribute);

        // Only text items count; anything else is ignored rather than allowed to break a page.
        return is_array($items) ? array_values(array_filter($items, fn (mixed $item): bool => is_string($item) && $item !== '')) : [];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
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
