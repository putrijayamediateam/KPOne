<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-17 multi-lot supplier returns (approved by B.E.R.N.I.S.S for the owner, 2026-11-11). One group returns
 * several lots from ONE verified delivery. Each line stays an ordinary STK-7 return row with its own
 * append-only actions; the group only ties them together. One supplier credit note may now close every
 * line of one group, and is still refused anywhere else for that supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplier_return_groups', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('group_number', 40);
            $t->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $t->foreignId('goods_receipt_id')->constrained('inventory_goods_receipts')->restrictOnDelete();
            $t->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'group_number'], 'supplier_return_groups_number_unique');
            $t->unique(['organisation_id', 'requester_user_id', 'retry_key'], 'supplier_return_groups_retry_unique');
            $t->index(['organisation_id', 'branch_id'], 'supplier_return_groups_branch_index');
        });
        Schema::table('inventory_supplier_returns', function (Blueprint $t): void {
            $t->foreignId('group_id')->nullable()->constrained('inventory_supplier_return_groups')->restrictOnDelete();
            $t->index('group_id', 'supplier_returns_group_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_supplier_return_group_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.group_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM inventory_supplier_return_groups g WHERE g.id=NEW.group_id AND g.organisation_id=NEW.organisation_id AND g.branch_id=NEW.branch_id
   AND g.supplier_id=NEW.supplier_id AND g.goods_receipt_id=NEW.goods_receipt_id AND g.requester_user_id=NEW.requester_user_id)
 THEN RAISE EXCEPTION 'A grouped supplier return line must match its group delivery, supplier, branch and requester' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER supplier_returns_group_insert BEFORE INSERT ON inventory_supplier_returns FOR EACH ROW EXECUTE FUNCTION guard_supplier_return_group_line();
CREATE TRIGGER inventory_supplier_return_groups_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_supplier_return_groups FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_return_immutable();
SQL);
        // The one rule that changes: a credit-note reference already recorded for this supplier is refused,
        // unless every earlier use is on a line of the same group as the new line.
        $function = (string) DB::selectOne("SELECT pg_get_functiondef('guard_supplier_return_action()'::regprocedure) AS def")->def;
        $old = "WHERE a.action='credit_recorded' AND o.organisation_id=r.organisation_id AND o.supplier_id=r.supplier_id AND upper(trim(a.reference))=upper(trim(NEW.reference))) THEN";
        $new = "WHERE a.action='credit_recorded' AND o.organisation_id=r.organisation_id AND o.supplier_id=r.supplier_id AND upper(trim(a.reference))=upper(trim(NEW.reference)) AND (r.group_id IS NULL OR o.group_id IS DISTINCT FROM r.group_id)) THEN";
        if (substr_count($function, $old) !== 1) {
            throw new RuntimeException('guard_supplier_return_action() does not have the expected credit-note rule; refusing to patch it.');
        }
        // The body is the live function definition, patched by an exact one-time match checked above.
        // @phpstan-ignore argument.type
        DB::unprepared(str_replace($old, $new, $function));
    }

    public function down(): void
    {
        // Retain grouped supplier return evidence.
    }
};
