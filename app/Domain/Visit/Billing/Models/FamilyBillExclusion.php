<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-39: "Remove from family bill". An open row (restored_at null) keeps one member invoice out of its family bill;
 * putting it back sets restored_by/at once. No money, invoice or visit data changes. Never deleted.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $excluded_by_user_id
 * @property CarbonImmutable $excluded_at
 * @property int|null $restored_by_user_id
 * @property CarbonImmutable|null $restored_at
 */
#[Guarded(['*'])]
class FamilyBillExclusion extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Family bill exclusions are kept.'));
    }

    protected function casts(): array
    {
        return ['excluded_at' => 'immutable_datetime', 'restored_at' => 'immutable_datetime'];
    }
}
