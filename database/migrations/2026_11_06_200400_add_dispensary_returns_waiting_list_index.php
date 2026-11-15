<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-13 (approved by B.E.R.N.I.S.S for the owner, 2026-11-07): the DSP-7b "Returns to check" list reads one
 * branch's returns oldest first. This index serves that read. No data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensary_returns', function (Blueprint $table): void {
            $table->index(['organisation_id', 'branch_id', 'recorded_at', 'id'], 'dispensary_return_branch_waiting_index');
        });
    }

    public function down(): void
    {
        Schema::table('dispensary_returns', function (Blueprint $table): void {
            $table->dropIndex('dispensary_return_branch_waiting_index');
        });
    }
};
