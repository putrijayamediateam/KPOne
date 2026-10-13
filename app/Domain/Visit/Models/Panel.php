<?php

namespace App\Domain\Visit\Models;

use App\Domain\Organisation\Models\Organisation;
use Database\Factories\PanelFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property int $claim_due_days
 * @property string|null $verification_url
 * @property string|null $panel_type
 * @property string|null $company_registration_number
 * @property string|null $company_name
 * @property string|null $person_in_charge
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $postcode
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string $verification_mode
 * @property string|null $verification_remark
 * @property string|null $income_tax_number
 * @property int $lock_version
 * @property-read Organisation $organisation
 */
#[Guarded(['*'])]
class Panel extends Model
{
    /** @use HasFactory<PanelFactory> */
    use HasFactory;

    protected static function newFactory(): PanelFactory
    {
        return PanelFactory::new();
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

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
