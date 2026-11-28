<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-39 (owner, 28 Nov 2026: migrations approved, option A): "Remove from family bill". An open exclusion takes one
 * member invoice out of its family bill; putting it back closes the row once (restored_by/at). It changes no money,
 * invoice or visit data. Append-oriented: no delete or truncate, at most one open exclusion per invoice, and a restored
 * row never changes again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_bill_exclusions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('excluded_by_user_id');
            $t->timestampTz('excluded_at');
            $t->unsignedBigInteger('restored_by_user_id')->nullable();
            $t->timestampTz('restored_at')->nullable();
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('excluded_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('restored_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'branch_id', 'invoice_id'], 'family_bill_exclusions_scope_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX family_bill_exclusions_open_unique ON family_bill_exclusions (invoice_id) WHERE restored_at IS NULL;
ALTER TABLE family_bill_exclusions ADD CONSTRAINT family_bill_exclusions_restore_pair
  CHECK ((restored_at IS NULL) = (restored_by_user_id IS NULL));

CREATE OR REPLACE FUNCTION kpone_family_bill_exclusion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
   RAISE EXCEPTION 'Family bill exclusions are kept' USING ERRCODE = 'P0001';
 END IF;
 IF TG_OP = 'INSERT' THEN
   IF NOT EXISTS (SELECT 1 FROM invoices i WHERE i.id = NEW.invoice_id AND i.organisation_id = NEW.organisation_id AND i.branch_id = NEW.branch_id) THEN
     RAISE EXCEPTION 'A family bill exclusion must belong to its invoice''s branch' USING ERRCODE = '23514';
   END IF;
   RETURN NEW;
 END IF;
 IF OLD.restored_at IS NOT NULL OR NEW.restored_at IS NULL
    OR (to_jsonb(NEW) - ARRAY['restored_at', 'restored_by_user_id']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['restored_at', 'restored_by_user_id']) THEN
   RAISE EXCEPTION 'A family bill exclusion is only put back once' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER family_bill_exclusions_guard BEFORE INSERT OR UPDATE OR DELETE ON family_bill_exclusions FOR EACH ROW EXECUTE FUNCTION kpone_family_bill_exclusion_guard();
CREATE TRIGGER family_bill_exclusions_truncate BEFORE TRUNCATE ON family_bill_exclusions FOR EACH STATEMENT EXECUTE FUNCTION kpone_family_bill_exclusion_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS family_bill_exclusions_truncate ON family_bill_exclusions; DROP TRIGGER IF EXISTS family_bill_exclusions_guard ON family_bill_exclusions;');
        }
        Schema::dropIfExists('family_bill_exclusions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS kpone_family_bill_exclusion_guard();');
        }
    }
};
