<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BIL-11 (owner decision 2026-11-07): each invoice line keeps its clinical service's category as it was when the line
 * was priced, so a later rename never moves money between the printed category rows. Additive and nullable:
 * lines finalised before BIL-11 keep NULL and the print keeps reading the live category for them. An empty string
 * means "frozen with no category" (Service). Finalised lines stay immutable through the existing guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_lines ADD COLUMN service_category_snapshot varchar(120)');
    }

    public function down(): void
    {
        // Retain the frozen categories with the financial evidence.
    }
};
