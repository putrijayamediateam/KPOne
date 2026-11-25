<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SUG-1b (owner, 25 Nov 2026, "Lulus semua cadangan"): standard dosing and instruction phrases in English and Bahasa Melayu
 * for the consultation recommendation popup. Entered by the clinic (KPOne ships none), a Draft until a doctor other than the
 * person who entered or last changed it approves it (same rule as diagnosis presets, SUG-1a). Never deleted: archived.
 * Each language is at most 60 characters so a frequency plus an instruction fits the 144-character drug label budget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescribing_phrases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->string('kind', 20);
            $table->string('label_en', 60);
            $table->string('label_ms', 60);
            $table->string('normalized_en', 60);
            $table->boolean('is_active')->default(true);
            $table->string('source', 20)->default('clinic');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('edited_by_user_id')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'prescribing_phrases_creator_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['edited_by_user_id', 'organisation_id'], 'prescribing_phrases_editor_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['approved_by_user_id', 'organisation_id'], 'prescribing_phrases_approver_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'kind', 'normalized_en'], 'prescribing_phrases_identity_unique');
            $table->index(['organisation_id', 'kind', 'status', 'is_active', 'label_en'], 'prescribing_phrases_search_index');
        });
        DB::statement("ALTER TABLE prescribing_phrases ADD CONSTRAINT prescribing_phrases_kind_check CHECK (kind IN ('frequency', 'instruction', 'precaution', 'duration'))");
        DB::statement("ALTER TABLE prescribing_phrases ADD CONSTRAINT prescribing_phrases_status_check CHECK (status IN ('draft', 'approved'))");
        DB::statement("ALTER TABLE prescribing_phrases ADD CONSTRAINT prescribing_phrases_source_check CHECK (source IN ('clinic', 'licensed_feed'))");
        DB::statement("ALTER TABLE prescribing_phrases ADD CONSTRAINT prescribing_phrases_text_check CHECK (btrim(label_en) <> '' AND btrim(label_ms) <> '')");
        DB::statement("ALTER TABLE prescribing_phrases ADD CONSTRAINT prescribing_phrases_draft_check CHECK (status = 'approved' OR (approved_at IS NULL AND approved_by_user_id IS NULL))");
        DB::statement("ALTER TABLE prescribing_phrases ADD CONSTRAINT prescribing_phrases_reviewer_check CHECK (approved_by_user_id IS NULL OR (status = 'approved' AND approved_by_user_id <> created_by_user_id AND approved_by_user_id IS DISTINCT FROM edited_by_user_id))");

        Schema::create('prescribing_phrase_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'prescribing_phrase_commands_key_unique');
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'prescribing_phrase_commands_branch_owner_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('prescribing_phrases')->exists() || DB::table('prescribing_phrase_commands')->exists()) {
            throw new RuntimeException('Prescribing phrases and retry evidence must be retained.');
        }
        Schema::dropIfExists('prescribing_phrase_commands');
        Schema::dropIfExists('prescribing_phrases');
    }
};
