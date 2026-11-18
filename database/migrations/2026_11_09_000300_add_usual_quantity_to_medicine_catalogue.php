<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional "usual quantity" per medicine: the quantity the doctor's treatment plan can prefill, counted in the medicine's
 * order unit (the same unit as `quantity_ordered`). It is a default only; the saved order is whatever the doctor sends.
 * Nullable and additive; existing rows are unchanged. When set it must be above zero (three decimals, like the dosage default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_catalogue_items', function (Blueprint $table): void {
            $table->decimal('usual_quantity', 12, 3)->nullable()->after('default_indication');
        });
        DB::statement('ALTER TABLE medicine_catalogue_items ADD CONSTRAINT medicine_catalogue_usual_quantity_positive CHECK (usual_quantity IS NULL OR usual_quantity > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE medicine_catalogue_items DROP CONSTRAINT IF EXISTS medicine_catalogue_usual_quantity_positive');
        Schema::table('medicine_catalogue_items', function (Blueprint $table): void {
            $table->dropColumn('usual_quantity');
        });
    }
};
