<?php

namespace Tests\Support;

use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * STK-11: a "patient returns" location at a branch, created through the governed reference service (never a raw
 * insert). For DSP-7 and other tests: the actor needs inventory.references.manage.organisation (Director, CA
 * Supervisor or Administrator).
 */
final class ReturnsLocationFixture
{
    public static function create(User $referenceManager, Branch $branch, string $type = InventoryLocation::TYPE_DISPENSARY_RETURNS): InventoryLocation
    {
        return app(InventoryReferenceAdministrationService::class)->createLocation($referenceManager, $branch, null, [
            'code' => 'SYN-RET-'.Str::upper(Str::random(6)),
            'name' => 'Synthetic patient returns',
            'type' => $type,
        ]);
    }
}
