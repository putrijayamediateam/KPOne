<?php

namespace App\Domain\Organisation\BusinessDetails;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $legal_name
 * @property string|null $registration_type
 * @property string|null $registration_number
 * @property int|null $logo_asset_id
 * @property int $lock_version
 */
final class OrganisationBusinessProfile extends Model
{
    protected $table = 'organisation_business_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'logo_asset_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(static fn () => throw new LogicException('Business details are retained and cannot be deleted.'));
    }
}
