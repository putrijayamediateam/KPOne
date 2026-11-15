<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test helper: copies one existing row $count times in a single INSERT ... SELECT, so a list test can have more rows than
 * a page's limit without building each one through the services. Each copy gets a new id and public id; $overrides maps
 * a column to an SQL expression in which g is the copy number (1 to $count). Insert triggers and CHECKs still run.
 */
final class RowCopies
{
    /** @param array<string, string> $overrides */
    public static function copy(string $table, int $id, int $count, array $overrides = []): void
    {
        $columns = array_values(array_diff(Schema::getColumnListing($table), ['id']));
        $select = array_map(function (string $column) use ($overrides): string {
            if (array_key_exists($column, $overrides)) {
                return $overrides[$column];
            }

            return $column === 'public_id' ? 'gen_random_uuid()' : 't.'.$column;
        }, $columns);
        DB::statement('INSERT INTO '.$table.' ('.implode(', ', $columns).') SELECT '.implode(', ', $select)
            .' FROM '.$table.' t CROSS JOIN generate_series(1, '.$count.') AS g WHERE t.id = ?', [$id]);
    }
}
