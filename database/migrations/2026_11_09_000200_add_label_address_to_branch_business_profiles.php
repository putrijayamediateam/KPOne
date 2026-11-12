<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drug label short address (owner decision 2026-11-12, option A): an optional single-line address per branch that the
 * drug label uses when it is set. It is at most 96 characters, the label's own address budget. Invoices, receipts and
 * letters keep the full address. Nullable and additive; existing rows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_business_profiles', function (Blueprint $table): void {
            $table->string('label_address', 96)->nullable()->after('address_line_2');
        });
        DB::statement("ALTER TABLE branch_business_profiles ADD CONSTRAINT branch_business_profiles_label_address_shape CHECK (label_address IS NULL OR (char_length(label_address) <= 96 AND btrim(label_address) <> '' AND label_address !~ '[\\n\\r]'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE branch_business_profiles DROP CONSTRAINT IF EXISTS branch_business_profiles_label_address_shape');
        Schema::table('branch_business_profiles', function (Blueprint $table): void {
            $table->dropColumn('label_address');
        });
    }
};
