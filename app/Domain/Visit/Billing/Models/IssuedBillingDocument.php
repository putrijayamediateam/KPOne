<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * BIL-5: one frozen version of an invoice or receipt as printed. The encrypted snapshot holds exactly the printed
 * content (letterhead text and logo bytes included). Append-only; a changed document gets a new version.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int|null $payment_id
 * @property string $document_type
 * @property int $version
 * @property string $content_sha256
 * @property string $encrypted_snapshot
 * @property string $ciphertext_sha256
 * @property int $issued_by_user_id
 * @property CarbonImmutable $issued_at
 */
#[Guarded(['*'])]
class IssuedBillingDocument extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Issued billing documents are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Issued billing documents are retained.'));
    }

    /** @return HasMany<IssuedBillingDocumentPrint, $this> */
    public function prints(): HasMany
    {
        return $this->hasMany(IssuedBillingDocumentPrint::class);
    }

    protected function casts(): array
    {
        return ['invoice_id' => 'integer', 'payment_id' => 'integer', 'version' => 'integer', 'issued_by_user_id' => 'integer',
            'issued_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
