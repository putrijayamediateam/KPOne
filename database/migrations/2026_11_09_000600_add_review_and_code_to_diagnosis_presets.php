<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SUG-1a (owner, 25 Nov 2026): a diagnosis preset may carry an optional ICD-10 code and now goes through a doctor's review.
 * Existing rows stay live: they become Approved with no named reviewer (owner decision: keep them as Approved). New rows
 * made by the settings service start as Draft. The column default is Approved so older code paths and fixtures that
 * write a preset directly keep working; the service always writes Draft explicitly. A draft never carries a review.
 * Additive only. The reviewer is never the person who created or last changed the row (PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnosis_presets', function (Blueprint $table): void {
            $table->string('icd10_code', 20)->nullable();
            $table->string('source', 20)->default('clinic');
            $table->string('status', 20)->default('approved');
            $table->unsignedBigInteger('edited_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreign(['edited_by_user_id', 'organisation_id'], 'diagnosis_presets_editor_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['approved_by_user_id', 'organisation_id'], 'diagnosis_presets_approver_owner_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->index(['organisation_id', 'status', 'icd10_code'], 'diagnosis_presets_review_index');
        });
        DB::table('diagnosis_presets')->update(['approved_at' => now()->utc()]);
        DB::statement("ALTER TABLE diagnosis_presets ADD CONSTRAINT diagnosis_presets_status_check CHECK (status IN ('draft', 'approved'))");
        DB::statement("ALTER TABLE diagnosis_presets ADD CONSTRAINT diagnosis_presets_source_check CHECK (source IN ('clinic', 'licensed_feed'))");
        DB::statement("ALTER TABLE diagnosis_presets ADD CONSTRAINT diagnosis_presets_approved_check CHECK (status = 'approved' OR (approved_at IS NULL AND approved_by_user_id IS NULL))");
        DB::statement('ALTER TABLE diagnosis_presets ADD CONSTRAINT diagnosis_presets_reviewer_check CHECK (approved_by_user_id IS NULL OR (status = \'approved\' AND approved_by_user_id <> created_by_user_id AND approved_by_user_id IS DISTINCT FROM edited_by_user_id))');
        DB::statement("ALTER TABLE diagnosis_presets ADD CONSTRAINT diagnosis_presets_code_check CHECK (icd10_code IS NULL OR icd10_code ~ '^[A-Z][0-9]{2}(\\.[0-9A-Z]{1,4})?$')");
    }

    public function down(): void
    {
        if (DB::table('diagnosis_presets')->where(fn ($q) => $q->whereNotNull('icd10_code')->orWhereNotNull('approved_by_user_id')->orWhere('status', 'draft'))->exists()) {
            throw new RuntimeException('Diagnosis codes, drafts and reviews must be retained.');
        }
        foreach (['code', 'reviewer', 'approved', 'source', 'status'] as $check) {
            DB::statement("ALTER TABLE diagnosis_presets DROP CONSTRAINT IF EXISTS diagnosis_presets_{$check}_check");
        }
        Schema::table('diagnosis_presets', function (Blueprint $table): void {
            $table->dropIndex('diagnosis_presets_review_index');
            $table->dropForeign('diagnosis_presets_editor_owner_fk');
            $table->dropForeign('diagnosis_presets_approver_owner_fk');
            $table->dropColumn(['icd10_code', 'source', 'status', 'edited_by_user_id', 'approved_by_user_id', 'approved_at']);
        });
    }
};
