<?php

namespace Tests\Support;

use App\Domain\Organisation\Inventory\Services\LegacyStockPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

trait AssertsLegacyStockRefusal
{
    /** @var array<string,bool>|null */
    private ?array $legacyStockTableOrder = null;

    /** @return array<string,string> */
    private function legacyStockSnapshot(): array
    {
        if ($this->legacyStockTableOrder === null) {
            $this->legacyStockTableOrder = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $row) {
                if (str_starts_with($row->tablename, 'inventory_') || in_array($row->tablename, ['stock_movements', 'medicine_catalogue_inventory_skus', 'dispensary_item_batch_allocations', 'audit_logs'], true)) {
                    $this->legacyStockTableOrder[$row->tablename] = Schema::hasColumn($row->tablename, 'id');
                }
            }
        }
        $snapshot = [];
        foreach ($this->legacyStockTableOrder as $table => $hasId) {
            $query = DB::table($table);
            $snapshot[$table] = $hasId ? $query->orderBy('id')->get()->toJson() : $query->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->toJson();
        }

        return $snapshot;
    }

    private function expectLegacyRefusal(callable $operation): void
    {
        $before = $this->legacyStockSnapshot();
        try {
            $operation();
            $this->fail('A retired stock path unexpectedly succeeded.');
        } catch (ValidationException $e) {
            $this->assertSame(['legacy_stock_path' => [LegacyStockPath::MESSAGE]], $e->errors());
        }
        $this->assertSame($before, $this->legacyStockSnapshot());
    }
}
