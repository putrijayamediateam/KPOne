<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FAM-1 (owner decisions 27 Nov 2026, docs/teams/patients-registration/FAMILY-LINK-DESIGN.md): staff link relatives by
 * hand into one family. A patient is in at most one family at a time; a family has no head and no payer. Members are
 * never deleted: leaving stamps left_at. Every link, role change and removal is one retry-safe command, kept append-only.
 * Linking uses the existing patients.update.organisation permission; no permission is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestampsTz();
            $table->unique(['id', 'organisation_id'], 'patient_families_id_owner_unique');
            $table->foreign(['created_by_user_id', 'organisation_id'], 'patient_families_creator_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('patient_family_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('patient_family_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('role', 20);
            $table->timestampTz('joined_at');
            $table->unsignedBigInteger('added_by_user_id');
            $table->timestampTz('left_at')->nullable();
            $table->unsignedBigInteger('removed_by_user_id')->nullable();
            $table->timestampsTz();
            $table->foreign(['patient_family_id', 'organisation_id'], 'patient_family_members_family_owner_fk')
                ->references(['id', 'organisation_id'])->on('patient_families')->restrictOnDelete();
            $table->foreign(['patient_id', 'organisation_id'], 'patient_family_members_patient_owner_fk')
                ->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $table->foreign(['added_by_user_id', 'organisation_id'], 'patient_family_members_adder_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['removed_by_user_id', 'organisation_id'], 'patient_family_members_remover_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->index(['patient_family_id', 'left_at'], 'patient_family_members_family_active_idx');
        });

        Schema::create('patient_family_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->string('action', 12);
            $table->unsignedBigInteger('patient_family_id');
            $table->unsignedInteger('result_version');
            $table->timestampTz('created_at');
            $table->unique(['organisation_id', 'actor_user_id', 'idempotency_key'], 'patient_family_commands_retry_unique');
            $table->foreign(['actor_user_id', 'organisation_id'], 'patient_family_commands_actor_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['patient_family_id', 'organisation_id'], 'patient_family_commands_family_owner_fk')
                ->references(['id', 'organisation_id'])->on('patient_families')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX patient_family_members_one_active ON patient_family_members (patient_id) WHERE left_at IS NULL');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX patient_family_members_one_active ON patient_family_members (patient_id) WHERE left_at IS NULL');
        DB::statement('ALTER TABLE patient_families ADD CONSTRAINT patient_families_shape CHECK (lock_version > 0)');
        DB::statement(<<<'SQL'
ALTER TABLE patient_family_members ADD CONSTRAINT patient_family_members_shape CHECK (
    role IN ('parent', 'child', 'grandparent', 'other_relative')
    AND ((left_at IS NULL) = (removed_by_user_id IS NULL))
    AND (left_at IS NULL OR left_at >= joined_at)
)
SQL);
        DB::statement("ALTER TABLE patient_family_commands ADD CONSTRAINT patient_family_commands_shape CHECK (action IN ('add', 'set_role', 'remove') AND result_version > 0 AND payload_hash ~ '^[0-9a-f]{64}$')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_patient_family_member_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'Patient family members are retained' USING ERRCODE = 'P0001';
    END IF;
    IF OLD.left_at IS NOT NULL THEN
        RAISE EXCEPTION 'A family member who left is final' USING ERRCODE = '23514';
    END IF;
    IF (to_jsonb(NEW) - ARRAY['role', 'left_at', 'removed_by_user_id', 'updated_at'])
        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['role', 'left_at', 'removed_by_user_id', 'updated_at']) THEN
        RAISE EXCEPTION 'Patient family member facts are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER patient_family_members_guard BEFORE UPDATE OR DELETE ON patient_family_members FOR EACH ROW EXECUTE FUNCTION kpone_patient_family_member_guard();
CREATE TRIGGER patient_family_members_no_truncate BEFORE TRUNCATE ON patient_family_members FOR EACH STATEMENT EXECUTE FUNCTION kpone_patient_family_member_guard();

CREATE OR REPLACE FUNCTION kpone_patient_family_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Patient families and their commands are retained' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER patient_families_retained BEFORE DELETE OR TRUNCATE ON patient_families FOR EACH STATEMENT EXECUTE FUNCTION kpone_patient_family_retained();
CREATE TRIGGER patient_family_commands_append_only BEFORE UPDATE OR DELETE OR TRUNCATE ON patient_family_commands FOR EACH STATEMENT EXECUTE FUNCTION kpone_patient_family_retained();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS patient_family_commands_append_only ON patient_family_commands;
DROP TRIGGER IF EXISTS patient_families_retained ON patient_families;
DROP TRIGGER IF EXISTS patient_family_members_no_truncate ON patient_family_members;
DROP TRIGGER IF EXISTS patient_family_members_guard ON patient_family_members;
DROP FUNCTION IF EXISTS kpone_patient_family_retained();
DROP FUNCTION IF EXISTS kpone_patient_family_member_guard();
SQL);
        }
        Schema::dropIfExists('patient_family_commands');
        Schema::dropIfExists('patient_family_members');
        Schema::dropIfExists('patient_families');
    }
};
