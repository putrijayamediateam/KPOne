<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REL-PR (owner decisions 25 Nov 2026, relayed by B.E.R.N.I.S.S.; the REL-PR release note, section 5, answers 1 to 6):
 * the clinic's doctor approved the wording of the Medical Certificate, the Referral letter and the Attendance confirmation
 * (English and Bahasa Melayu), exactly as it stands in KPOne. The approval was verbal: "the clinic's doctor (owner-confirmed,
 * verbal, 25 Nov 2026)". No name is recorded in the repository.
 *
 * Nothing is flipped and nothing is rewritten. Template rows are append-only, so this ADDS approved revisions with identical
 * wording: MC revision 3 (renderer mc-a4-v2, the layout of revision 2) and referral and attendance revision 2. New documents
 * use the newest revision; every document issued earlier keeps its frozen snapshot, which still says DRAFT.
 *
 * Each approved row must carry its approval record (approved_by_label and approved_on); an unapproved row must carry none.
 * down() refuses once any document was issued from an approved revision.
 */
return new class extends Migration
{
    private const APPROVER = "the clinic's doctor (owner-confirmed, verbal, 25 Nov 2026)";

    private const APPROVED_ON = '2026-11-25';

    private const TABLES = ['mc_template_revisions', 'referral_template_revisions', 'attendance_template_revisions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN approved_by_label varchar(160) NULL, ADD COLUMN approved_on date NULL");
        }

        // MC: revisions 1 and 2 stay unapproved; revision 3 is the approved one (same layout renderer as revision 2).
        DB::statement('ALTER TABLE mc_template_revisions DROP CONSTRAINT mc_template_shape');
        DB::statement("ALTER TABLE mc_template_revisions ADD CONSTRAINT mc_template_shape CHECK (language IN ('en','bm') AND revision >= 1 AND char_length(wording) BETWEEN 1 AND 2000 AND wording !~ '[<>]' AND ((revision = 1 AND renderer_version = 'mc-a4-v1' AND NOT production_approved) OR (revision = 2 AND renderer_version = 'mc-a4-v2' AND NOT production_approved) OR (revision = 3 AND renderer_version = 'mc-a4-v2' AND production_approved AND approved_by_label IS NOT NULL AND approved_on IS NOT NULL)) AND (production_approved OR (approved_by_label IS NULL AND approved_on IS NULL)))");

        foreach (['mc_template_revisions' => 2, 'referral_template_revisions' => 1, 'attendance_template_revisions' => 1] as $table => $from) {
            $this->appendApprovedRevision($table, $from);
        }

        foreach (['referral' => 'referral-a4-v1', 'attendance' => 'attendance-a4-v1'] as $prefix => $renderer) {
            $table = "{$prefix}_template_revisions";
            // The original column checks (revision = 1, one renderer, NOT production_approved) are anonymous; replace them all with one named shape.
            foreach (DB::select("SELECT conname FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND contype = 'c'") as $row) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$row->conname}\"");
            }
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$prefix}_template_shape CHECK (language IN ('en','bm') AND renderer_version = '{$renderer}' AND ((revision = 1 AND NOT production_approved) OR (revision = 2 AND production_approved AND approved_by_label IS NOT NULL AND approved_on IS NOT NULL)) AND (production_approved OR (approved_by_label IS NULL AND approved_on IS NULL)))");
        }
    }

    public function down(): void
    {
        $used = DB::table('medical_certificates')->join('mc_template_revisions', 'mc_template_revisions.id', '=', 'medical_certificates.template_revision_id')->where('mc_template_revisions.production_approved', true)->exists()
            || DB::table('referral_documents')->join('referral_template_revisions', 'referral_template_revisions.id', '=', 'referral_documents.template_revision_id')->where('referral_template_revisions.production_approved', true)->exists()
            || DB::table('attendance_documents')->join('attendance_template_revisions', 'attendance_template_revisions.id', '=', 'attendance_documents.template_revision_id')->where('attendance_template_revisions.production_approved', true)->exists();
        if ($used) {
            throw new RuntimeException('Documents were issued from an approved template revision; it cannot be removed.');
        }
        // Template rows are append-only: remove the unused approved rows with the guard switched off for these statements only.
        foreach (self::TABLES as $table) {
            $guard = $table === 'mc_template_revisions' ? 'mc_template_immutable' : "{$table}_immutable";
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$guard}");
            DB::table($table)->where('production_approved', true)->delete();
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$guard}");
        }
        DB::statement('ALTER TABLE mc_template_revisions DROP CONSTRAINT mc_template_shape');
        DB::statement("ALTER TABLE mc_template_revisions ADD CONSTRAINT mc_template_shape CHECK (language IN ('en','bm') AND revision >= 1 AND char_length(wording) BETWEEN 1 AND 2000 AND wording !~ '[<>]' AND ((revision = 1 AND renderer_version = 'mc-a4-v1') OR (revision = 2 AND renderer_version = 'mc-a4-v2')) AND NOT production_approved)");
        foreach (['referral' => 'referral-a4-v1', 'attendance' => 'attendance-a4-v1'] as $prefix => $renderer) {
            $table = "{$prefix}_template_revisions";
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$prefix}_template_shape");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$prefix}_template_shape CHECK (language IN ('en','bm') AND revision = 1 AND renderer_version = '{$renderer}' AND NOT production_approved)");
        }
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN approved_by_label, DROP COLUMN approved_on");
        }
    }

    /** Copy each language's wording unchanged from the revision it was approved at into the next revision, approved. */
    private function appendApprovedRevision(string $table, int $from): void
    {
        $constraintOpen = $table !== 'mc_template_revisions'; // referral and attendance forbid an approved row until their shape check is replaced below
        if ($constraintOpen) {
            foreach (DB::select("SELECT conname FROM pg_constraint WHERE conrelid = '{$table}'::regclass AND contype = 'c'") as $row) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$row->conname}\"");
            }
        }
        foreach (DB::table($table)->where('revision', $from)->orderBy('language')->get() as $row) {
            $copy = (array) $row;
            unset($copy['id']);
            $copy['revision'] = $from + 1;
            $copy['production_approved'] = true;
            $copy['approved_by_label'] = self::APPROVER;
            $copy['approved_on'] = self::APPROVED_ON;
            if (array_key_exists('created_at', $copy)) {
                $copy['created_at'] = now()->utc()->format('Y-m-d H:i:s.uP');
            }
            DB::table($table)->insert($copy);
        }
    }
};
