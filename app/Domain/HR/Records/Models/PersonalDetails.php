<?php

namespace App\Domain\HR\Records\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-4a: encrypted personal details (TEAM.md decision 9). The identity hash exists only for the duplicate check.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $worker_id
 * @property string|null $legal_name_encrypted
 * @property string|null $preferred_name
 * @property string|null $identity_kind
 * @property string|null $identity_encrypted
 * @property string|null $identity_hash
 * @property string|null $identity_last4
 * @property string|null $passport_country
 * @property string|null $date_of_birth_encrypted
 * @property string|null $nationality
 * @property array<string, string>|null $address_encrypted
 * @property string|null $personal_phone_encrypted
 * @property array<string, string>|null $emergency_contact_encrypted
 * @property int $lock_version
 * @property int $updated_by_user_id
 */
#[Guarded(['*'])]
class PersonalDetails extends Model
{
    protected $table = 'hr_personal_details';

    protected function casts(): array
    {
        return ['legal_name_encrypted' => 'encrypted', 'identity_encrypted' => 'encrypted', 'date_of_birth_encrypted' => 'encrypted',
            'address_encrypted' => 'encrypted:array', 'personal_phone_encrypted' => 'encrypted', 'emergency_contact_encrypted' => 'encrypted:array',
            'worker_id' => 'integer', 'lock_version' => 'integer'];
    }
}
