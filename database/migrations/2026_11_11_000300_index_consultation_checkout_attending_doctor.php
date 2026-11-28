<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INS-BM-4 (follow-up to INS-BM-3, PR #1297): the Insights doctor filter for a branch-only person asks whether a doctor
 * attended a consultation at the branch in the last year, and several Insights reports filter checkouts by attending
 * doctor. `attending_doctor_user_id` had only a composite foreign key, which PostgreSQL does not index, so each lookup
 * scanned the table. Additive and reversible: no column, constraint or data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_checkouts', function (Blueprint $table): void {
            $table->index(['attending_doctor_user_id', 'branch_id', 'created_at'], 'consultation_checkouts_attending_branch_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_checkouts', function (Blueprint $table): void {
            $table->dropIndex('consultation_checkouts_attending_branch_created_index');
        });
    }
};
