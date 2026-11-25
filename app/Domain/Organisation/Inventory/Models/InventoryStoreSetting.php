<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * STK-60: the organisation's weekly store request day (ISO weekday 1 to 7). No row means Thursday. Written only by
 * CentralStoreService::updateRequestDay; every change also appends a version row.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $request_weekday
 * @property int $lock_version
 * @property int $updated_by_user_id
 */
#[Guarded(['*'])]
class InventoryStoreSetting extends Model
{
    protected $table = 'inventory_store_settings';

    protected static function booted(): void
    {
        static::updating(function (self $setting): void {
            if ($setting->isDirty('organisation_id')) {
                throw new LogicException('The store setting belongs to one organisation.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('The store setting is kept; change the day instead.'));
    }

    protected function casts(): array
    {
        return ['request_weekday' => 'integer', 'lock_version' => 'integer'];
    }
}
