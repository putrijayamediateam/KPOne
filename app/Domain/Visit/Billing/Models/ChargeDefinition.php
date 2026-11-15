<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class ChargeDefinition extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(function (self $charge): void {
            if ($charge->isDirty(['public_id', 'organisation_id', 'code', 'type', 'source_key', 'unit', 'medicine_catalogue_item_id', 'clinical_service_catalogue_item_id'])) {
                throw new LogicException('Commercial identity and exact source mapping are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
