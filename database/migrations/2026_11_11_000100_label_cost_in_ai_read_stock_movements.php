<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AG-7b (owner decision 2026-11-06, Option A): Ask KPOne keeps stock cost in ai_read.stock_movements but labels it.
 * After STK-10B a movement's unit_cost_sen can be the purchase order's estimate, so the view adds cost_total_sen and
 * cost_source and the column comments say that only cost_source = actual is an actual cost. Ask KPOne is the owner's
 * (Director's) own tool; stock cost is shown nowhere else.
 */
return new class extends Migration
{
    private const READER = 'kpone_ai_reader';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // CREATE OR REPLACE may only append columns, so the existing columns and grants stay as they are. The two
        // STK-10B columns are read through the whole row (to_jsonb, computed once per row), so the view does not pin
        // them: STK-10B's own migration can still be rolled back on its own (its tests do this), and the view then
        // shows the two columns empty instead of blocking the rollback.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW ai_read.stock_movements AS
            SELECT m.id, m.occurred_at, m.movement_type, s.sku_code, b.batch_number,
                m.source_location_id, m.destination_location_id, m.quantity, m.unit_cost_sen,
                (r.j->>'cost_total_sen')::bigint AS cost_total_sen, (r.j->>'cost_source')::varchar(10) AS cost_source
            FROM public.stock_movements m
            CROSS JOIN LATERAL (SELECT to_jsonb(m) AS j) r
            JOIN public.inventory_skus s ON s.id = m.inventory_sku_id
            LEFT JOIN public.inventory_batches b ON b.id = m.inventory_batch_id
            SQL);
        DB::unprepared("COMMENT ON VIEW ai_read.stock_movements IS 'Stock ledger movements. Check cost_source before treating any cost as actual.'");
        DB::unprepared("COMMENT ON COLUMN ai_read.stock_movements.unit_cost_sen IS 'Cost per stock unit in sen. Actual only when cost_source is actual; the purchase order estimate when cost_source is estimate. When cost_source is empty: a purchase receipt recorded before STK-10B (origin unknown), or not a purchase receipt (for example an opening balance, whose unit cost staff entered).'");
        DB::unprepared("COMMENT ON COLUMN ai_read.stock_movements.cost_total_sen IS 'Total paid for the whole received lot in sen, when entered at receiving (cost_source is then actual and unit_cost_sen is empty). Do not multiply it by quantity.'");
        DB::unprepared("COMMENT ON COLUMN ai_read.stock_movements.cost_source IS 'actual or estimate on purchase receipts from STK-10B on; empty on older purchase receipts and on every other movement type.'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Removing columns needs a drop and recreate; the reader's grant is restored when the login exists.
        DB::unprepared('DROP VIEW IF EXISTS ai_read.stock_movements');
        DB::unprepared(<<<'SQL'
            CREATE VIEW ai_read.stock_movements AS
            SELECT m.id, m.occurred_at, m.movement_type, s.sku_code, b.batch_number,
                m.source_location_id, m.destination_location_id, m.quantity, m.unit_cost_sen
            FROM public.stock_movements m
            JOIN public.inventory_skus s ON s.id = m.inventory_sku_id
            LEFT JOIN public.inventory_batches b ON b.id = m.inventory_batch_id
            SQL);
        DB::unprepared("COMMENT ON VIEW ai_read.stock_movements IS 'Stock ledger movements.'");
        DB::unprepared("COMMENT ON COLUMN ai_read.stock_movements.unit_cost_sen IS 'Unit cost in sen when recorded (receiving).'");
        if (DB::table('pg_roles')->where('rolname', self::READER)->exists()) {
            DB::unprepared('GRANT SELECT ON ai_read.stock_movements TO '.self::READER);
        }
    }
};
