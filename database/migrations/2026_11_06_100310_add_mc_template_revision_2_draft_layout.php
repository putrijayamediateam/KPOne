<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MC slice 1 (owner decision 2026-11-06): template revision 2 in English and Bahasa Melayu, drawn by renderer
 * "mc-a4-v2" to match the clinic's printed certificate. Still DRAFT: production_approved stays false for every row,
 * and releasing approved wording remains a separate, doctor-gated migration. Revision 1 and every issued snapshot
 * are untouched; an issued MC keeps the HTML frozen in its own encrypted snapshot.
 */
return new class extends Migration
{
    private const FIELDS = ['patient_name', 'identification', 'issue_date', 'start_date', 'end_date', 'days_number', 'doctor_name', 'mmc_number', 'mc_number'];

    public function up(): void
    {
        DB::statement('ALTER TABLE mc_template_revisions DROP CONSTRAINT mc_template_shape');
        DB::statement("ALTER TABLE mc_template_revisions ADD CONSTRAINT mc_template_shape CHECK (language IN ('en','bm') AND revision >= 1 AND char_length(wording) BETWEEN 1 AND 2000 AND wording !~ '[<>]' AND ((revision = 1 AND renderer_version = 'mc-a4-v1') OR (revision = 2 AND renderer_version = 'mc-a4-v2')) AND NOT production_approved)");

        foreach ([
            'en' => ['MEDICAL CERTIFICATE', "This is to certify that {{patient_name}} , {{identification}}\nwill be unfit on duty {{days_number}} day(s) (from {{start_date}} until {{end_date}} )\nHe/She is to be re-examined on ________ He/She is able to resume duties from ________\nDiagnosis: ________"],
            'bm' => ['SIJIL CUTI SAKIT', "Dengan ini disahkan bahawa {{patient_name}} , {{identification}}\ntidak sihat untuk bertugas selama {{days_number}} hari (dari {{start_date}} hingga {{end_date}} )\nBeliau perlu diperiksa semula pada ________ Beliau boleh kembali bertugas mulai ________\nDiagnosis: ________"],
        ] as $language => [$title, $wording]) {
            DB::table('mc_template_revisions')->insert([
                'language' => $language, 'revision' => 2, 'title' => $title, 'wording' => $wording,
                'merge_fields' => json_encode(self::FIELDS, JSON_THROW_ON_ERROR), 'renderer_version' => 'mc-a4-v2',
                'production_approved' => false, 'created_at' => now()->utc()->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }

    public function down(): void
    {
        $used = DB::table('medical_certificates')->join('mc_template_revisions', 'mc_template_revisions.id', '=', 'medical_certificates.template_revision_id')
            ->where('mc_template_revisions.revision', 2)->exists();
        if ($used) {
            throw new RuntimeException('Medical certificates were issued with template revision 2; it cannot be removed.');
        }
        // Template rows are append-only; remove the unused revision 2 rows with the guard switched off for this statement only.
        DB::statement('ALTER TABLE mc_template_revisions DISABLE TRIGGER mc_template_immutable');
        DB::table('mc_template_revisions')->where('revision', 2)->delete();
        DB::statement('ALTER TABLE mc_template_revisions ENABLE TRIGGER mc_template_immutable');
        DB::statement('ALTER TABLE mc_template_revisions DROP CONSTRAINT mc_template_shape');
        DB::statement("ALTER TABLE mc_template_revisions ADD CONSTRAINT mc_template_shape CHECK (language IN ('en','bm') AND revision >= 1 AND char_length(wording) BETWEEN 1 AND 2000 AND wording !~ '[<>]' AND renderer_version = 'mc-a4-v1' AND NOT production_approved)");
    }
};
