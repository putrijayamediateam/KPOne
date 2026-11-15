<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STK-31 (approved by B.E.R.N.I.S.S for the owner): a stock request may now send stock to another branch of the same
 * organisation. PostgreSQL repeats the service's rules for every new request: the source is at the requesting branch
 * (or the organisation warehouse), and neither end is a patient returns location. The organisation is already enforced
 * by the location foreign keys. KPOne never re-points a request after it is made (dispatch and receive re-check their
 * own side), so the guard runs on insert only: an UPDATE of the location or branch columns is NOT guarded here (two
 * existing regression tests repoint a request by SQL on purpose). Existing rows are not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_stock_request_locations() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE src inventory_locations%ROWTYPE; dst inventory_locations%ROWTYPE;
BEGIN
  SELECT * INTO src FROM inventory_locations WHERE id = NEW.source_location_id AND organisation_id = NEW.organisation_id;
  SELECT * INTO dst FROM inventory_locations WHERE id = NEW.destination_location_id AND organisation_id = NEW.organisation_id;
  IF src.id IS NULL OR dst.id IS NULL THEN
    RAISE EXCEPTION 'Stock request locations must belong to its organisation' USING ERRCODE = '23514';
  END IF;
  IF src.branch_id IS NOT NULL AND src.branch_id <> NEW.requesting_branch_id THEN
    RAISE EXCEPTION 'A stock request is sent from the requesting branch' USING ERRCODE = '23514';
  END IF;
  IF src.type = 'dispensary_returns' OR dst.type = 'dispensary_returns' THEN
    RAISE EXCEPTION 'A stock request never touches a patient returns location' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER stock_request_locations_guard BEFORE INSERT ON inventory_stock_requests FOR EACH ROW EXECUTE FUNCTION guard_stock_request_locations();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS stock_request_locations_guard ON inventory_stock_requests; DROP FUNCTION IF EXISTS guard_stock_request_locations();');
    }
};
