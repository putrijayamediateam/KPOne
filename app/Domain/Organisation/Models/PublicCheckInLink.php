<?php

namespace App\Domain\Organisation\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property string $token_hash
 * @property string|null $encrypted_token
 * @property string $label
 * @property bool $is_active
 * @property string|null $active_branch_guard
 * @property int $created_by_user_id
 * @property int|null $revoked_by_user_id
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $rotated_from_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organisation $organisation
 * @property-read Branch $branch
 * @property-read User $creator
 * @property-read User|null $revoker
 * @property-read PublicCheckInLink|null $rotatedFrom
 */
#[Guarded([])]
#[Hidden(['token_hash', 'encrypted_token', 'active_branch_guard'])]
class PublicCheckInLink extends Model
{
    protected $table = 'public_checkin_links';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'encrypted_token' => 'encrypted',
            'revoked_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /** @return BelongsTo<PublicCheckInLink, $this> */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }
}
