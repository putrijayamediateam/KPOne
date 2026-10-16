<?php

namespace App\Domain\Organisation\BusinessDetails;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property string $clinic_name
 * @property string $address_line_1
 * @property string|null $address_line_2
 * @property string $postcode
 * @property string $city
 * @property string $state
 * @property string $country
 * @property string|null $phone
 * @property string|null $email
 * @property int $lock_version
 */
final class BranchBusinessProfile extends Model
{
    protected $table = 'branch_business_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(static fn () => throw new LogicException('Business details are retained and cannot be deleted.'));
    }
}
