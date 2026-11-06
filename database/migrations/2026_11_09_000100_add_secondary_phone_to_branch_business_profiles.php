<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAT-9 (owner decision 2026-11-06, "go with CAT-9"): an optional second clinic phone per branch, carried on the
 * letterhead as details.phone_secondary for the documents that print it ("Tel: A / B"). Nullable and additive;
 * existing rows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_business_profiles', function (Blueprint $table): void {
            $table->string('phone_secondary', 40)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('branch_business_profiles', function (Blueprint $table): void {
            $table->dropColumn('phone_secondary');
        });
    }
};
