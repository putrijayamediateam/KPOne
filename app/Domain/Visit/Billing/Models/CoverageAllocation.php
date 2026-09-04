<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $amount_sen
 * @property int $lock_version
 * @property int $expected_invoice_version
 * @property int $requested_by_user_id
 * @property string $status
 * @property string $panel_name_snapshot
 * @property string|null $member_reference
 */
#[Guarded(['*'])]
class CoverageAllocation extends Model
{
    use RetainsResponsibility;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'lock_version' => 'integer', 'expected_invoice_version' => 'integer', 'approved_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
