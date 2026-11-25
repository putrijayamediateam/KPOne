<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Access\StaffKind;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Organisation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_active
 * @property string|null $google_subject
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organisation $organisation
 * @property-read StaffProfile|null $staffProfile
 */
#[Fillable([
    'name',
    'email',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable {
        HasRoles::hasPermissionTo as protected spatieHasPermissionTo;
        HasRoles::getAllPermissions as protected spatieGetAllPermissions;
    }

    /**
     * Roles and permissions live in the `web` guard. The connector's `api` (bearer token) guard becomes the default guard
     * on the MCP route, and Spatie would then look for `api` roles; pinning the name keeps every check on `web`.
     */
    protected string $guard_name = 'web';

    /**
     * HR-22b2: a locum (a locum doctor included) never holds the leave-request or staff-claim permissions, whatever other
     * role brings them. Every `can()`, route permission check and HR lock passes through here. Read permissions only
     * through `can()`, `hasPermissionTo()` or `getAllPermissions()`: Spatie's other readers (getPermissionsViaRoles,
     * getPermissionNames, getDirectPermissions) are not filtered (Suresh, m2).
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->withheldAsLocum($permission)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /** @return Collection<int, Permission> */
    public function getAllPermissions(): Collection
    {
        $all = $this->spatieGetAllPermissions();

        return StaffKind::isLocum($this)
            ? $all->reject(fn ($p): bool => in_array($p->name, StaffKind::WITHHELD_FROM_LOCUMS, true))->values()
            : $all;
    }

    private function withheldAsLocum(mixed $permission): bool
    {
        $name = match (true) {
            is_string($permission) => $permission,
            $permission instanceof Permission => $permission->name,
            $permission instanceof \BackedEnum => (string) $permission->value,
            // Spatie also takes a permission id (Suresh, m1).
            is_int($permission) => \Spatie\Permission\Models\Permission::query()->whereKey($permission)->value('name'),
            default => null,
        };

        return $name !== null && in_array($name, StaffKind::WITHHELD_FROM_LOCUMS, true) && StaffKind::isLocum($this);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasOne<StaffProfile, $this> */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }
}
