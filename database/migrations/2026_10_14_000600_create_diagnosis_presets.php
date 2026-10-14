<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_presets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->string('name', 500);
            $table->string('normalized_name', 500);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'diagnosis_presets_creator_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'normalized_name'], 'diagnosis_presets_name_unique');
            $table->index(['organisation_id', 'is_active', 'id']);
        });
        Schema::create('diagnosis_preset_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'diagnosis_preset_commands_key_unique');
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'diagnosis_preset_commands_branch_owner_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('diagnosis_presets')->exists() || DB::table('diagnosis_preset_commands')->exists()) {
            throw new RuntimeException('Diagnosis presets and retry evidence must be retained.');
        }
        Schema::dropIfExists('diagnosis_preset_commands');
        Schema::dropIfExists('diagnosis_presets');
    }
};
