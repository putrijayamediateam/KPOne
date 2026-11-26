<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_family_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_low_id');
            $table->foreignId('patient_high_id');
            $table->string('relationship_code', 24);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['id', 'organisation_id'], 'patient_family_id_org_unique');
            $table->unique(['organisation_id', 'patient_low_id', 'patient_high_id'], 'patient_family_pair_unique');
            $table->index(['organisation_id', 'patient_low_id', 'is_active'], 'patient_family_low_active_idx');
            $table->index(['organisation_id', 'patient_high_id', 'is_active'], 'patient_family_high_active_idx');
        });
        Schema::create('patient_family_link_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('link_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('retry_key');
            $table->char('payload_hash', 64);
            $table->string('action', 16);
            $table->string('result_relationship_code', 24);
            $table->boolean('result_is_active');
            $table->unsignedInteger('result_version');
            $table->timestampTz('created_at');
            $table->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'patient_family_commands_retry_unique');
            $table->unique(['link_id', 'result_version'], 'patient_family_command_version_unique');
        });
        DB::statement('ALTER TABLE patient_family_links ADD CONSTRAINT patient_family_pair_order_check CHECK (patient_low_id < patient_high_id)');
        DB::statement('ALTER TABLE patient_family_links ADD CONSTRAINT patient_family_version_check CHECK (lock_version >= 1)');
        DB::statement("ALTER TABLE patient_family_links ADD CONSTRAINT patient_family_relationship_check CHECK (relationship_code IN ('parent_of','child_of','spouse','sibling','other_relative'))");
        DB::statement('ALTER TABLE patient_family_links ADD CONSTRAINT patient_family_low_org_fk FOREIGN KEY (patient_low_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patient_family_links ADD CONSTRAINT patient_family_high_org_fk FOREIGN KEY (patient_high_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patient_family_link_commands ADD CONSTRAINT patient_family_command_org_fk FOREIGN KEY (link_id, organisation_id) REFERENCES patient_family_links(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patient_family_link_commands ADD CONSTRAINT patient_family_command_version_check CHECK (result_version >= 1)');
        DB::statement("ALTER TABLE patient_family_link_commands ADD CONSTRAINT patient_family_command_relation_check CHECK (result_relationship_code IN ('parent_of','child_of','spouse','sibling','other_relative'))");
        DB::statement("ALTER TABLE patient_family_link_commands ADD CONSTRAINT patient_family_command_action_check CHECK ((action IN ('link','correct') AND result_is_active) OR (action = 'unlink' AND NOT result_is_active))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION refuse_patient_family_command_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Family link commands are append-only' USING ERRCODE = 'P0001';
END;
$$;
CREATE TRIGGER patient_family_commands_immutable BEFORE UPDATE OR DELETE OR TRUNCATE
ON patient_family_link_commands FOR EACH STATEMENT
EXECUTE FUNCTION refuse_patient_family_command_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain family link and command history; rollback never deletes patient relationship evidence.
    }
};
