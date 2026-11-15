<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-30b: optimistic locking and an idempotency key for saving a reorder level (B.E.R.N.I.S.S for the owner).
 * Existing rows start at version 1; retry records are append-only evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reorder_levels', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(1);
        });

        Schema::create('inventory_reorder_level_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_reorder_level_id');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'reorder_level_commands_key_unique');
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'reorder_level_commands_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign('inventory_reorder_level_id')->references('id')->on('inventory_reorder_levels')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reorder_levels ADD CONSTRAINT reorder_levels_lock_version_check CHECK (lock_version >= 1)');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_reorder_level_commands() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'Reorder level retry records are append-only' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER reorder_level_commands_append_only BEFORE UPDATE OR DELETE ON inventory_reorder_level_commands FOR EACH ROW EXECUTE FUNCTION guard_reorder_level_commands();
CREATE TRIGGER reorder_level_commands_no_truncate BEFORE TRUNCATE ON inventory_reorder_level_commands FOR EACH STATEMENT EXECUTE FUNCTION guard_reorder_level_commands();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('inventory_reorder_level_commands')->exists()) {
            throw new RuntimeException('Reorder level retry evidence must be retained.');
        }
        Schema::dropIfExists('inventory_reorder_level_commands');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_reorder_level_commands()');
            DB::statement('ALTER TABLE inventory_reorder_levels DROP CONSTRAINT IF EXISTS reorder_levels_lock_version_check');
        }
        Schema::table('inventory_reorder_levels', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
