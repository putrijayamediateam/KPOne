<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_guardian_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->unique()->constrained()->restrictOnDelete();
            $table->string('relationship', 32);
            $table->text('encrypted_contact');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('patient_guardian_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->uuid('retry_key');
            $table->string('payload_hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'patient_guardian_command_retry_unique');
        });
        DB::statement("ALTER TABLE patient_guardian_contacts ADD CONSTRAINT patient_guardian_relationship_check CHECK (relationship IN ('mother','father','legal_guardian','other_family_member'))");
        DB::statement('ALTER TABLE patient_guardian_contacts ADD CONSTRAINT patient_guardian_organisation_fk FOREIGN KEY (patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::unprepared("CREATE OR REPLACE FUNCTION refuse_patient_guardian_command_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Guardian commands are append-only' USING ERRCODE = 'P0001'; END; $$;
        CREATE TRIGGER patient_guardian_commands_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON patient_guardian_commands FOR EACH STATEMENT EXECUTE FUNCTION refuse_patient_guardian_command_mutation();");
    }

    public function down(): void
    {
        // Retain contact and retry evidence; rollback never deletes patient contact data.
    }
};
