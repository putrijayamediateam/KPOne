<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DS-01b-2 (owner decision, 2026-10-09): an OTC visit is billed from its completed OTC Dispensary case.
 *
 * An invoice is anchored to exactly one source: a consultation checkout (unchanged) or an OTC
 * Dispensary case. An OTC invoice has medicine lines only, no consultation line, and every line is
 * proved against a dispensed item of that case. The PostgreSQL reconciliation function and the
 * completed-visit trigger are patched in place (each replacement must match exactly once, or the
 * migration refuses to run) so every consultation rule stays as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('consultation_checkout_id')->nullable()->change();
            $table->unsignedBigInteger('dispensary_case_id')->nullable();
            $table->foreign(['dispensary_case_id', 'organisation_id', 'branch_id'], 'invoice_otc_case_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_cases')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoice_anchor_check CHECK ((consultation_checkout_id IS NOT NULL) <> (dispensary_case_id IS NOT NULL))');
        $this->patchFunction('kpone_billing_check_invoice', [
            'IF NOT EXISTS (SELECT 1 FROM consultation_checkouts c WHERE c.id=inv.consultation_checkout_id AND c.visit_id=inv.visit_id AND c.patient_id=inv.patient_id AND c.organisation_id=inv.organisation_id AND c.branch_id=inv.branch_id) THEN' => "IF NOT ((inv.dispensary_case_id IS NULL AND EXISTS (SELECT 1 FROM consultation_checkouts c WHERE c.id=inv.consultation_checkout_id AND c.visit_id=inv.visit_id AND c.patient_id=inv.patient_id AND c.organisation_id=inv.organisation_id AND c.branch_id=inv.branch_id))\n        OR (inv.dispensary_case_id IS NOT NULL AND EXISTS (SELECT 1 FROM dispensary_cases dc JOIN visits v ON v.id=dc.visit_id WHERE dc.id=inv.dispensary_case_id AND dc.case_type='otc' AND dc.visit_id=inv.visit_id AND dc.organisation_id=inv.organisation_id AND dc.branch_id=inv.branch_id AND v.visit_type='otc' AND v.patient_id=inv.patient_id))) THEN",
            "OR NOT EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id=invoice_key AND line_type='consultation') THEN" => "OR (inv.dispensary_case_id IS NULL AND NOT EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id=invoice_key AND line_type='consultation'))\n        OR (inv.dispensary_case_id IS NOT NULL AND (NOT EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id=invoice_key) OR EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id=invoice_key AND line_type<>'medicine'))) THEN",
            "OR (l.line_type='medicine' AND NOT EXISTS (SELECT 1 FROM dispensary_items i JOIN dispensary_handoffs h ON h.id=i.dispensary_handoff_id JOIN consultation_checkouts c" => "OR (l.line_type='medicine' AND inv.dispensary_case_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dispensary_items i JOIN dispensary_handoffs h ON h.id=i.dispensary_handoff_id WHERE i.id=l.dispensary_item_id AND h.dispensary_case_id=inv.dispensary_case_id AND h.status='completed' AND i.status IN ('dispensed','partial') AND i.quantity_dispensed=l.quantity AND d.medicine_catalogue_item_id=i.medicine_catalogue_item_id AND l.source_key='medicine:'||i.id::text))\n        OR (l.line_type='medicine' AND inv.dispensary_case_id IS NULL AND NOT EXISTS (SELECT 1 FROM dispensary_items i JOIN dispensary_handoffs h ON h.id=i.dispensary_handoff_id JOIN consultation_checkouts c",
        ], 'RETURNS void');
        $this->patchFunction('kpone_completed_visit_evidence', [
            "OR NEW.visit_type<>'consultation' OR inv.status<>'finalized'" => "OR NEW.visit_type NOT IN ('consultation','otc') OR inv.status<>'finalized'",
            "OR NOT EXISTS (SELECT 1 FROM queue_entries WHERE visit_id=NEW.id AND status='removed')" => "OR (NEW.visit_type='consultation' AND NOT EXISTS (SELECT 1 FROM queue_entries WHERE visit_id=NEW.id AND status='removed'))",
            'OR NOT EXISTS (SELECT 1 FROM consultation_checkouts c JOIN clinical_encounters e' => "OR (NEW.visit_type='consultation' AND NOT EXISTS (SELECT 1 FROM consultation_checkouts c JOIN clinical_encounters e",
            "d.status='completed'))) THEN" => "d.status='completed'))))\n            OR (NEW.visit_type='otc' AND (inv.dispensary_case_id IS NULL OR NOT EXISTS (SELECT 1 FROM dispensary_cases dc JOIN dispensary_handoffs h ON h.dispensary_case_id=dc.id WHERE dc.id=inv.dispensary_case_id AND dc.visit_id=NEW.id AND dc.case_type='otc' AND dc.status='completed' AND h.status='completed'))) THEN",
        ], 'RETURNS trigger');
    }

    /**
     * @param  array<string,string>  $replacements
     */
    private function patchFunction(string $name, array $replacements, string $returns): void
    {
        $definition = DB::selectOne('SELECT prosrc, pg_get_function_arguments(oid) AS arguments FROM pg_proc WHERE proname = ?', [$name]);
        $source = (string) $definition->prosrc;
        foreach ($replacements as $old => $new) {
            if (substr_count($source, $old) !== 1) {
                throw new RuntimeException("The {$name} function has an unexpected shape; the OTC rule was not applied.");
            }
            $source = str_replace($old, $new, $source);
        }
        // The stored body is re-created verbatim apart from the verified replacements.
        // @phpstan-ignore argument.type
        DB::unprepared("CREATE OR REPLACE FUNCTION {$name}({$definition->arguments}) {$returns} LANGUAGE plpgsql AS \$kpone\$".$source.'$kpone$');
    }

    /**
     * Intentionally a no-op: reversing would orphan OTC invoices and weaken the reconciliation proof.
     */
    public function down(): void {}
};
