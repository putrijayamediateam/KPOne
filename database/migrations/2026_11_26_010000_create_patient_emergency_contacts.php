<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_emergency_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->unique();
            $table->text('encrypted_contact')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
        Schema::create('patient_emergency_contact_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id');
            $table->uuid('retry_key');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'patient_emergency_command_retry_unique');
        });
        DB::statement('ALTER TABLE patient_emergency_contacts ADD CONSTRAINT patient_emergency_version_check CHECK (lock_version >= 1)');
        DB::statement('ALTER TABLE patient_emergency_contacts ADD CONSTRAINT patient_emergency_organisation_fk FOREIGN KEY (patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patient_emergency_contact_commands ADD CONSTRAINT patient_emergency_command_org_fk FOREIGN KEY (patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION refuse_patient_emergency_command_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Emergency contact commands are append-only' USING ERRCODE = 'P0001';
END;
$$;
CREATE TRIGGER patient_emergency_commands_immutable BEFORE UPDATE OR DELETE OR TRUNCATE
ON patient_emergency_contact_commands FOR EACH STATEMENT
EXECUTE FUNCTION refuse_patient_emergency_command_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain contact and retry evidence; rollback never deletes patient contact data.
    }
};
