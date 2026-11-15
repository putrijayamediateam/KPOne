<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-3: a "controlled medicine" flag on the Medicine Catalogue, set by catalogue editors
 * (medicines.manage.organisation) and printed as a marker on the drug label. Additive; every
 * existing medicine starts unflagged and nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_catalogue_items', function (Blueprint $table): void {
            $table->boolean('is_controlled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('medicine_catalogue_items', function (Blueprint $table): void {
            $table->dropColumn('is_controlled');
        });
    }
};
