<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-4 (owner decisions 2026-11-05): daily cash closing. A counter records the day's counted cash takings (float
 * excluded) against the posted Cash receipts; a different person approves or returns it. Both tables are append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closings', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->unsignedInteger('revision');
            $t->unsignedInteger('expected_receipt_count');
            $t->bigInteger('expected_cash_sen');
            $t->bigInteger('counted_cash_sen');
            $t->bigInteger('variance_sen');
            $t->string('variance_reason', 1000)->nullable();
            $t->unsignedBigInteger('counted_by_user_id');
            $t->timestampTz('counted_at');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['counted_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'branch_id', 'business_date', 'revision'], 'cash_closings_revision_unique');
            $t->unique(['organisation_id', 'idempotency_key'], 'cash_closings_idempotency_unique');
        });
        Schema::create('cash_closing_decisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('cash_closing_id')->unique();
            $t->string('decision', 16);
            $t->string('reason', 1000)->nullable();
            $t->unsignedBigInteger('decided_by_user_id');
            $t->timestampTz('decided_at');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->foreign('cash_closing_id')->references('id')->on('cash_closings')->restrictOnDelete();
            $t->foreign(['decided_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'cash_closing_decisions_idempotency_unique');
        });
        DB::statement('ALTER TABLE cash_closings ADD CONSTRAINT cash_closings_shape CHECK (revision > 0 AND expected_cash_sen >= 0 AND counted_cash_sen >= 0 AND variance_sen = counted_cash_sen - expected_cash_sen AND (variance_sen = 0 OR (variance_reason IS NOT NULL AND length(trim(variance_reason)) > 0)))');
        DB::statement("ALTER TABLE cash_closing_decisions ADD CONSTRAINT cash_closing_decisions_shape CHECK ((decision = 'approved' AND reason IS NULL) OR (decision = 'returned' AND reason IS NOT NULL AND length(trim(reason)) > 0))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_cash_closing() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Cash closing evidence is append-only' USING ERRCODE = 'P0001'; END IF;
 IF EXISTS (SELECT 1 FROM cash_closings c JOIN cash_closing_decisions d ON d.cash_closing_id = c.id
   WHERE c.organisation_id = NEW.organisation_id AND c.branch_id = NEW.branch_id AND c.business_date = NEW.business_date AND d.decision = 'approved') THEN
   RAISE EXCEPTION 'This business date already has an approved cash closing' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cash_closings_append BEFORE INSERT OR UPDATE OR DELETE ON cash_closings FOR EACH ROW EXECUTE FUNCTION guard_cash_closing();
CREATE TRIGGER cash_closings_truncate BEFORE TRUNCATE ON cash_closings FOR EACH STATEMENT EXECUTE FUNCTION guard_cash_closing();
CREATE OR REPLACE FUNCTION guard_cash_closing_decision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c cash_closings%ROWTYPE;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Cash closing decisions are append-only' USING ERRCODE = 'P0001'; END IF;
 SELECT * INTO c FROM cash_closings WHERE id = NEW.cash_closing_id;
 IF c.id IS NULL OR c.organisation_id <> NEW.organisation_id THEN RAISE EXCEPTION 'Invalid cash closing decision scope' USING ERRCODE = '23514'; END IF;
 IF NEW.decided_by_user_id = c.counted_by_user_id THEN RAISE EXCEPTION 'The person who counted cannot decide the cash closing' USING ERRCODE = '23514'; END IF;
 IF EXISTS (SELECT 1 FROM cash_closings n WHERE n.organisation_id = c.organisation_id AND n.branch_id = c.branch_id AND n.business_date = c.business_date AND n.revision > c.revision) THEN
   RAISE EXCEPTION 'Only the latest cash count can be decided' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cash_closing_decisions_append BEFORE INSERT OR UPDATE OR DELETE ON cash_closing_decisions FOR EACH ROW EXECUTE FUNCTION guard_cash_closing_decision();
CREATE TRIGGER cash_closing_decisions_truncate BEFORE TRUNCATE ON cash_closing_decisions FOR EACH STATEMENT EXECUTE FUNCTION guard_cash_closing_decision();
SQL);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(['name' => 'payments.cash_closing.approve.branch', 'guard_name' => 'web']);
        foreach (['ca_supervisor', 'finance_officer', 'director'] as $holder) {
            $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
            if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain cash closing evidence and the permission grant, following the evidence and additive permission conventions.
    }
};
