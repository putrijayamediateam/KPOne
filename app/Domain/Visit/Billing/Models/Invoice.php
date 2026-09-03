<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $patient_id
 * @property int $visit_id
 * @property int $consultation_checkout_id
 * @property int|null $current_visit_guard
 * @property string|null $replaces_public_id
 * @property string|null $invoice_number
 * @property string $currency
 * @property string $status
 * @property int $subtotal_sen
 * @property int $total_sen
 * @property array<string,mixed> $source_manifest
 * @property string $source_hash
 * @property bool $source_stale
 * @property bool $correction_hold
 * @property int $lock_version
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $voided_at
 */
#[Guarded(['*'])]
class Invoice extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(function (self $invoice): void {
            if ($invoice->isDirty('status') && ! (($invoice->getOriginal('status') === 'draft' && $invoice->status === 'finalized') || ($invoice->getOriginal('status') === 'finalized' && $invoice->status === 'voided'))) {
                throw new LogicException('Invalid Invoice lifecycle transition.');
            }
            foreach (['public_id', 'organisation_id', 'branch_id', 'patient_id', 'visit_id', 'created_by_user_id'] as $field) {
                if ($invoice->isDirty($field)) {
                    throw new LogicException('Invoice ownership is immutable.');
                }
            }
            if ($invoice->getOriginal('status') !== 'draft' && array_diff(array_keys($invoice->getDirty()), ['status', 'lock_version', 'current_visit_guard', 'voided_at', 'voided_by_user_id', 'void_reason', 'correction_hold', 'updated_at']) !== []) {
                throw new LogicException('Finalized Invoice snapshots are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['subtotal_sen' => 'integer', 'total_sen' => 'integer', 'lock_version' => 'integer', 'source_manifest' => 'array', 'source_stale' => 'boolean', 'correction_hold' => 'boolean', 'finalized_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
