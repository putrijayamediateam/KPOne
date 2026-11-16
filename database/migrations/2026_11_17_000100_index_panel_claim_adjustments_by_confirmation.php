<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PCA-3: day-story reports read confirmed Panel claim adjustments by branch and confirmation time. Additive index only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_claim_adjustments', function (Blueprint $t): void {
            $t->index(['branch_id', 'confirmed_at'], 'panel_claim_adjustments_branch_confirmed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('panel_claim_adjustments', function (Blueprint $t): void {
            $t->dropIndex('panel_claim_adjustments_branch_confirmed_idx');
        });
    }
};
