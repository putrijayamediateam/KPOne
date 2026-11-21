<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-41 (owner, 18 Nov 2026, "Approve prepares real work", plan approved by B.E.R.N.I.S.S for the owner): a stock request or
 * purchase request prepared by approving an agent suggestion records which suggestion it came from (trail only; one request per
 * suggestion, never changed). An agent-prepared stock request is never approved by the person who requested it (owner decision 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_stock_requests' => 'stock_requests', 'inventory_purchase_requests' => 'purchase_requests'] as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($short): void {
                $t->foreignId('ai_proposal_id')->nullable();
                $t->foreign('ai_proposal_id', "{$short}_ai_proposal_fk")->references('id')->on('ai_proposals')->restrictOnDelete();
                $t->unique('ai_proposal_id', "{$short}_ai_proposal_unique");
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_inventory_request_ai_proposal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 proposal_org bigint;
BEGIN
 IF TG_OP = 'UPDATE' AND NEW.ai_proposal_id IS DISTINCT FROM OLD.ai_proposal_id THEN
  RAISE EXCEPTION 'The suggestion a request came from is kept as recorded' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' AND NEW.ai_proposal_id IS NOT NULL THEN
  SELECT organisation_id INTO proposal_org FROM ai_proposals WHERE id = NEW.ai_proposal_id;
  IF proposal_org IS DISTINCT FROM NEW.organisation_id THEN
   RAISE EXCEPTION 'A request and its suggestion belong to one organisation' USING ERRCODE = '23514';
  END IF;
 END IF;
 IF TG_TABLE_NAME = 'inventory_stock_requests' AND NEW.ai_proposal_id IS NOT NULL AND NEW.status = 'approved'
    AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM 'approved') AND NEW.decided_by_user_id = NEW.requested_by_user_id THEN
  RAISE EXCEPTION 'A stock request prepared from a suggestion is approved by someone other than its requester' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER stock_requests_ai_proposal_guard BEFORE INSERT OR UPDATE ON inventory_stock_requests FOR EACH ROW EXECUTE FUNCTION guard_inventory_request_ai_proposal();
CREATE TRIGGER purchase_requests_ai_proposal_guard BEFORE INSERT OR UPDATE ON inventory_purchase_requests FOR EACH ROW EXECUTE FUNCTION guard_inventory_request_ai_proposal();
SQL);
    }

    public function down(): void
    {
        // Rolling back would lose which suggestion a request came from: refuse instead.
        if (DB::table('inventory_stock_requests')->whereNotNull('ai_proposal_id')->exists()
            || DB::table('inventory_purchase_requests')->whereNotNull('ai_proposal_id')->exists()) {
            throw new RuntimeException('STK-41 requests prepared from suggestions are recorded; this migration cannot be rolled back.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_requests_ai_proposal_guard ON inventory_stock_requests; DROP TRIGGER IF EXISTS purchase_requests_ai_proposal_guard ON inventory_purchase_requests; DROP FUNCTION IF EXISTS guard_inventory_request_ai_proposal();');
        }
        foreach (['inventory_stock_requests' => 'stock_requests', 'inventory_purchase_requests' => 'purchase_requests'] as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($short): void {
                $t->dropUnique("{$short}_ai_proposal_unique");
                $t->dropForeign("{$short}_ai_proposal_fk");
                $t->dropColumn('ai_proposal_id');
            });
        }
    }
};
