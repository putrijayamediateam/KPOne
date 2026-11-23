<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STK-44: the medicine sales page reads invoices finalised in one month at one branch, or every branch. No index on
 * `finalized_at` existed, so this adds one. Additive; `down()` drops only this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index(['organisation_id', 'branch_id', 'status', 'finalized_at'], 'invoices_finalized_month_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_finalized_month_index');
        });
    }
};
