<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DSP-1b: drug groups (for example "Penicillins") tagged on each medicine by catalogue editors, so the
 * Dispensary allergy warning can match an allergy to a group as well as to the medicine's names.
 * Additive; every existing medicine starts with no groups and nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE medicine_catalogue_items ADD COLUMN allergy_groups jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE medicine_catalogue_items ADD CONSTRAINT medicine_catalogue_allergy_groups_shape CHECK (jsonb_typeof(allergy_groups) = 'array' AND jsonb_array_length(allergy_groups) <= 10 AND NOT jsonb_path_exists(allergy_groups, '$[*] ? (@.type() != \"string\")'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE medicine_catalogue_items DROP CONSTRAINT IF EXISTS medicine_catalogue_allergy_groups_shape');
        DB::statement('ALTER TABLE medicine_catalogue_items DROP COLUMN IF EXISTS allergy_groups');
    }
};
