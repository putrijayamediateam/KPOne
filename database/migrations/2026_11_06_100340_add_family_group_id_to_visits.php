<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FAM-1 (owner decisions 27 Nov 2026): one shared key on the visits of family members registered at the same branch on
 * the same day. Patients & Registration writes it (at registration and when a family is linked); Billing only reads it
 * for the family bill (BIL-39). Additive and nullable: existing visits keep NULL. No ai_read view selects visits.*, so no
 * view needs recreating (BASELINE section 6, item 23 applies only to dropped or retyped columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->uuid('family_group_id')->nullable();
        });
        DB::statement('CREATE INDEX visits_branch_family_group_idx ON visits (branch_id, family_group_id) WHERE family_group_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS visits_branch_family_group_idx');
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn('family_group_id');
        });
    }
};
