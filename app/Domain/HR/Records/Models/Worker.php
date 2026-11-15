<?php

namespace App\Domain\HR\Records\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HR-4a: one staff file. A staff worker belongs to an account; a locum has no KPOne sign-in (decision 8).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int|null $user_id
 * @property string $kind
 * @property string|null $display_name
 * @property string|null $locum_phone_encrypted
 * @property int $lock_version
 * @property int $created_by_user_id
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 * @property-read PersonalDetails|null $personal
 */
#[Guarded(['*'])]
class Worker extends Model
{
    protected $table = 'hr_workers';

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'lock_version' => 'integer', 'created_at' => 'immutable_datetime', 'locum_phone_encrypted' => 'encrypted'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasOne<PersonalDetails, $this> */
    public function personal(): HasOne
    {
        return $this->hasOne(PersonalDetails::class, 'worker_id');
    }

    /** @return HasMany<EmploymentPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(EmploymentPeriod::class, 'worker_id')->orderBy('starts_on');
    }

    public function name(): string
    {
        return $this->kind === 'locum' ? (string) $this->display_name : ($this->user !== null ? $this->user->name : 'Staff member');
    }
}
