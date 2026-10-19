<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $actor_user_id
 * @property string $public_id
 * @property string $kind
 * @property array<string,string|null>|null $recipient
 * @property int $revision_version
 * @property CarbonImmutable $created_at
 */
final class InvoiceBillToRevision extends Model
{
    public $timestamps = false;

    // Keep the UTC instant explicit even when PostgreSQL uses another session timezone.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['id'];

    protected $hidden = ['recipient'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Bill to revisions are immutable.'));
        self::deleting(static fn () => throw new LogicException('Bill to revisions are retained.'));
    }
}
