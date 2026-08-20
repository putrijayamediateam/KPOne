<?php

namespace App\Domain\Audit\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int|null $organisation_id
 * @property int|null $branch_id
 * @property int|null $actor_user_id
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 * @property-read Organisation|null $organisation
 * @property-read Branch|null $branch
 * @property-read User|null $actor
 */
#[Guarded([])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        if (! in_array($this->event, ['access.role.attached', 'access.role.detached'], true)) {
            return [];
        }

        $roles = $this->metadata['roles'] ?? [];

        if (! is_array($roles)) {
            return [];
        }

        $roleNames = [];

        foreach ($roles as $role) {
            if (is_string($role)
                && preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $role) === 1
                && ! in_array($role, $roleNames, true)) {
                $roleNames[] = $role;
            }
        }

        return $roleNames;
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
