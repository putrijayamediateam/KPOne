<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-15 (owner decisions 2026-11-07): each branch may have a cash closing start date, from which the month view
 * counts uncounted days as missed. Set or changed by a Finance Officer or Director, on the cash closing month page.
 * Append-only revisions: the latest revision is the branch's current start date; every change is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closing_start_dates', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedInteger('revision');
            $t->date('starts_on');
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('set_at');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'branch_id', 'revision'], 'cash_closing_start_dates_revision_unique');
            $t->unique(['organisation_id', 'idempotency_key'], 'cash_closing_start_dates_idempotency_unique');
        });
        DB::statement("ALTER TABLE cash_closing_start_dates ADD CONSTRAINT cash_closing_start_dates_shape CHECK (revision > 0 AND starts_on >= DATE '2000-01-01')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_cash_closing_start_date() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Cash closing start dates are append-only' USING ERRCODE = 'P0001'; END IF;
 IF NEW.revision <> 1 + coalesce((SELECT max(revision) FROM cash_closing_start_dates s WHERE s.organisation_id = NEW.organisation_id AND s.branch_id = NEW.branch_id), 0) THEN
   RAISE EXCEPTION 'A cash closing start date must be the next revision for its branch' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cash_closing_start_dates_append BEFORE INSERT OR UPDATE OR DELETE ON cash_closing_start_dates FOR EACH ROW EXECUTE FUNCTION guard_cash_closing_start_date();
CREATE TRIGGER cash_closing_start_dates_truncate BEFORE TRUNCATE ON cash_closing_start_dates FOR EACH STATEMENT EXECUTE FUNCTION guard_cash_closing_start_date();
SQL);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(['name' => 'payments.cash_closing.start_date.manage.branch', 'guard_name' => 'web']);
        foreach (['finance_officer', 'director'] as $holder) {
            $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
            if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain start date evidence and the permission grant, following the evidence and additive permission conventions.
    }
};
