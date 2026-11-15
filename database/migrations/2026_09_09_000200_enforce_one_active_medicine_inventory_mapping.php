<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'medicine_inventory_one_active_unique';

    public function up(): void
    {
        $duplicate = DB::table('medicine_catalogue_inventory_skus')
            ->select(['organisation_id', 'medicine_catalogue_item_id'])
            ->where('is_active', true)
            ->groupBy(['organisation_id', 'medicine_catalogue_item_id'])
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Multiple active Inventory SKU mappings exist for a Medicine. Review the data before applying the one-active-mapping constraint.',
            );
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON medicine_catalogue_inventory_skus (organisation_id, medicine_catalogue_item_id) WHERE is_active = true',
                self::INDEX,
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
