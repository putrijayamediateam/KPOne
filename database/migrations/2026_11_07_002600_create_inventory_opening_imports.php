<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-35 (approved by B.E.R.N.I.S.S for the owner): one row per opening-stock import, for its idempotency key. The file
 * itself is never stored, only its SHA-256. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_opening_imports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('idempotency_key');
            $table->string('file_hash', 64);
            $table->unsignedInteger('row_count');
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'opening_imports_key_unique');
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'opening_imports_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['actor_user_id', 'organisation_id'], 'opening_imports_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_opening_imports ADD CONSTRAINT opening_imports_shape_check CHECK (row_count BETWEEN 1 AND 500 AND file_hash ~ \'^[0-9a-f]{64}$\')');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_opening_imports() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'Opening import records are append-only' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER opening_imports_append_only BEFORE UPDATE OR DELETE ON inventory_opening_imports FOR EACH ROW EXECUTE FUNCTION guard_opening_imports();
CREATE TRIGGER opening_imports_no_truncate BEFORE TRUNCATE ON inventory_opening_imports FOR EACH STATEMENT EXECUTE FUNCTION guard_opening_imports();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('inventory_opening_imports')->exists()) {
            throw new RuntimeException('Opening import records must be retained.');
        }
        Schema::dropIfExists('inventory_opening_imports');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_opening_imports()');
        }
    }
};
