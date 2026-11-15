<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DSP-1c: other names (brand names and common misspellings, for example "Augmentin") added to each medicine by
 * catalogue editors, so the Dispensary allergy warning can match an allergy to them as well as to the medicine's
 * names and drug groups. Used for matching only; never printed. Additive; every medicine starts with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE medicine_catalogue_items ADD COLUMN allergy_aliases jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE medicine_catalogue_items ADD CONSTRAINT medicine_catalogue_allergy_aliases_shape CHECK (jsonb_typeof(allergy_aliases) = 'array' AND jsonb_array_length(allergy_aliases) <= 10 AND NOT jsonb_path_exists(allergy_aliases, '$[*] ? (@.type() != \"string\")'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE medicine_catalogue_items DROP CONSTRAINT IF EXISTS medicine_catalogue_allergy_aliases_shape');
        DB::statement('ALTER TABLE medicine_catalogue_items DROP COLUMN IF EXISTS allergy_aliases');
    }
};
