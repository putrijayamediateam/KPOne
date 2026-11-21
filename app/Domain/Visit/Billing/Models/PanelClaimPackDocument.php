<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * PNL-25: one frozen version of a Panel claim pack as printed. The encrypted snapshot holds exactly the printed content
 * (letterhead text and logo bytes included). Append-only; a pack whose printed content changed gets a new version.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $panel_id
 * @property string $pack_key
 * @property list<int> $claim_ids
 * @property int $version
 * @property string $content_sha256
 * @property bool $shows_identity
 * @property string $encrypted_snapshot
 * @property string $ciphertext_sha256
 * @property int $issued_by_user_id
 * @property CarbonImmutable $issued_at
 */
#[Guarded(['*'])]
class PanelClaimPackDocument extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel claim pack copies are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel claim pack copies are retained.'));
    }

    /** @return HasMany<PanelClaimPackPrint, $this> */
    public function prints(): HasMany
    {
        return $this->hasMany(PanelClaimPackPrint::class);
    }

    protected function casts(): array
    {
        return ['claim_ids' => 'array', 'version' => 'integer', 'shows_identity' => 'boolean', 'issued_by_user_id' => 'integer',
            'issued_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
