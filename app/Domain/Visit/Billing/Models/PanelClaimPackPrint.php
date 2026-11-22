<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * PNL-25: one press of Print for a frozen claim pack version, labelled ORIGINAL, REVISED (first print of a version
 * after the first) or COPY.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $panel_claim_pack_document_id
 * @property string $label
 * @property int $printed_by_user_id
 * @property CarbonImmutable $printed_at
 * @property string $idempotency_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class PanelClaimPackPrint extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel claim pack prints are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel claim pack prints are retained.'));
    }

    /** @return BelongsTo<PanelClaimPackDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PanelClaimPackDocument::class, 'panel_claim_pack_document_id');
    }

    protected function casts(): array
    {
        return ['panel_claim_pack_document_id' => 'integer', 'printed_by_user_id' => 'integer', 'printed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
