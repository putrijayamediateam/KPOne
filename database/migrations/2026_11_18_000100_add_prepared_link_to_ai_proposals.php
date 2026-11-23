<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-30: an approved agent proposal may carry a link to the work its approval prepared (docs/agents/CONTRACT.md section 15):
 * the kind of record, its public id and its printed number. The proposal stays `approved` (`executed` stays reserved).
 * The link is all or nothing and only on an approved proposal; the existing guard trigger already freezes a decided
 * proposal, so it is written in the same update that decides it and never changes afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_proposals', function ($t): void {
            $t->string('prepared_record_type', 40)->nullable();
            $t->uuid('prepared_record_public_id')->nullable();
            $t->string('prepared_record_label', 40)->nullable();
            $t->timestampTz('prepared_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_prepared_link_check CHECK (
    (prepared_record_type IS NULL AND prepared_record_public_id IS NULL AND prepared_record_label IS NULL AND prepared_at IS NULL)
    OR (status = 'approved' AND prepared_record_type IN ('inventory.stock_request', 'inventory.purchase_request')
        AND prepared_record_public_id IS NOT NULL AND length(btrim(coalesce(prepared_record_label, ''))) > 0 AND prepared_at IS NOT NULL)
);
SQL);
    }

    public function down(): void
    {
        if (DB::table('ai_proposals')->whereNotNull('prepared_record_type')->exists()) {
            throw new RuntimeException('Proposals already link to prepared work; rolling this back would lose that link.');
        }
        DB::unprepared('ALTER TABLE ai_proposals DROP CONSTRAINT IF EXISTS ai_proposals_prepared_link_check');
        Schema::table('ai_proposals', function ($t): void {
            $t->dropColumn(['prepared_record_type', 'prepared_record_public_id', 'prepared_record_label', 'prepared_at']);
        });
    }
};
