<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DS-01a-services (owner decision, 2026-10-09): at Dispensary the CA may edit, add and remove the services
 * of a consultation visit and confirms their performance. The doctor's treatment-plan service orders and
 * the doctor's `service_deliveries` evidence stay exactly as recorded; the CA's final list lives in
 * `dispensary_service_lines`, one set per handoff, started from the doctor's confirmation. When a visit goes
 * through Dispensary, billing reads these lines (invoice_lines.dispensary_service_line_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensary_service_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dispensary_handoff_id');
            $table->unsignedBigInteger('treatment_plan_service_order_id')->nullable();
            $table->unsignedBigInteger('clinical_service_catalogue_item_id');
            $table->string('service_code_snapshot', 64);
            $table->string('service_name_snapshot', 500);
            $table->string('unit_snapshot', 100);
            $table->decimal('quantity_ordered', 12, 3);
            $table->text('clinical_instruction')->nullable();
            $table->text('final_instruction')->nullable();
            $table->decimal('doctor_quantity_performed', 12, 3)->nullable();
            $table->string('disposition', 20);
            $table->decimal('quantity_performed', 12, 3);
            $table->timestamp('performed_at')->nullable();
            $table->string('source', 10)->default('doctor');
            $table->string('change_state', 10)->default('unchanged');
            $table->unsignedBigInteger('confirmed_by_user_id');
            $table->timestamp('confirmed_at');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->foreign(['dispensary_handoff_id', 'organisation_id', 'branch_id'], 'dsl_handoff_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_handoffs')->restrictOnDelete();
            $table->foreign(['treatment_plan_service_order_id', 'organisation_id', 'branch_id'], 'dsl_order_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plan_service_orders')->restrictOnDelete();
            $table->foreign(['clinical_service_catalogue_item_id', 'organisation_id'], 'dsl_catalogue_tenant_fk')->references(['id', 'organisation_id'])->on('clinical_service_catalogue_items')->restrictOnDelete();
            $table->foreign(['confirmed_by_user_id', 'organisation_id'], 'dsl_confirmed_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['dispensary_handoff_id', 'treatment_plan_service_order_id'], 'dsl_handoff_order_unique');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'dsl_id_tenant_unique');
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispensary_service_line_id')->nullable();
            $table->foreign(['dispensary_service_line_id', 'organisation_id', 'branch_id'], 'line_dispensary_service_line_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_service_lines')->restrictOnDelete();
        });

        $this->backfill();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dispensary_service_lines ADD CONSTRAINT dsl_state_check CHECK (
                lock_version > 0 AND quantity_ordered > 0 AND quantity_performed >= 0
                AND ((disposition = 'performed' AND quantity_performed > 0) OR (disposition = 'not_performed' AND quantity_performed = 0))
                AND source IN ('doctor', 'ca')
                AND change_state IN ('unchanged', 'edited', 'added', 'removed')
                AND ((source = 'doctor') = (treatment_plan_service_order_id IS NOT NULL))
                AND (source = 'ca' OR change_state <> 'added')
                AND (source = 'doctor' OR change_state <> 'unchanged')
                AND (change_state <> 'removed' OR disposition = 'not_performed')
            )");

            DB::statement('ALTER TABLE invoice_lines DROP CONSTRAINT IF EXISTS invoice_line_shape');
            DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT invoice_line_shape CHECK (quantity>0 AND unit_price_sen BETWEEN 0 AND 999999999999 AND line_total_sen BETWEEN 0 AND 999999999999 AND line_total_sen=round(quantity*unit_price_sen) AND price_version>0 AND (
                (line_type='medicine' AND dispensary_item_id IS NOT NULL AND service_delivery_id IS NULL AND dispensary_service_line_id IS NULL AND consultation_checkout_id IS NULL)
                OR (line_type='service' AND dispensary_item_id IS NULL AND consultation_checkout_id IS NULL AND ((service_delivery_id IS NOT NULL) <> (dispensary_service_line_id IS NOT NULL)))
                OR (line_type='consultation' AND dispensary_item_id IS NULL AND service_delivery_id IS NULL AND dispensary_service_line_id IS NULL AND consultation_checkout_id IS NOT NULL)))");

            $this->patchReconciliationFunction();
        }
    }

    /**
     * Intentionally a no-op: reversing would drop the CA's recorded service confirmations.
     */
    public function down(): void {}

    /** One line per service the doctor confirmed for each existing handoff, so billing reads one source. */
    private function backfill(): void
    {
        $rows = DB::table('service_deliveries as s')
            ->join('consultation_checkouts as c', 'c.id', '=', 's.consultation_checkout_id')
            ->join('treatment_plan_service_orders as o', 'o.id', '=', 's.treatment_plan_service_order_id')
            ->whereNotNull('c.dispensary_handoff_id')
            ->orderBy('s.id')
            ->get([
                's.organisation_id', 's.branch_id', 'c.dispensary_handoff_id', 's.treatment_plan_service_order_id', 's.disposition', 's.quantity_performed',
                's.performed_at', 's.confirmed_by_user_id', 's.created_at', 'o.clinical_service_catalogue_item_id', 'o.service_code_snapshot',
                'o.service_name_snapshot', 'o.unit_snapshot', 'o.quantity_ordered', 'o.clinical_instruction',
            ]);
        foreach ($rows as $row) {
            DB::table('dispensary_service_lines')->insert([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $row->organisation_id, 'branch_id' => $row->branch_id,
                'dispensary_handoff_id' => $row->dispensary_handoff_id, 'treatment_plan_service_order_id' => $row->treatment_plan_service_order_id,
                'clinical_service_catalogue_item_id' => $row->clinical_service_catalogue_item_id, 'service_code_snapshot' => $row->service_code_snapshot,
                'service_name_snapshot' => $row->service_name_snapshot, 'unit_snapshot' => $row->unit_snapshot, 'quantity_ordered' => $row->quantity_ordered,
                'clinical_instruction' => $row->clinical_instruction, 'doctor_quantity_performed' => $row->quantity_performed,
                'disposition' => $row->disposition, 'quantity_performed' => $row->quantity_performed, 'performed_at' => $row->performed_at,
                'source' => 'doctor', 'change_state' => 'unchanged', 'confirmed_by_user_id' => $row->confirmed_by_user_id, 'confirmed_at' => $row->created_at ?? now(),
                'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * The invoice reconciliation function proves every invoice line against its source. A service line
     * that came from a CA-confirmed Dispensary line is proved against that line; every other service line
     * is still proved against the doctor's delivery exactly as before.
     */
    private function patchReconciliationFunction(): void
    {
        $source = (string) DB::selectOne("SELECT prosrc FROM pg_proc WHERE proname = 'kpone_billing_check_invoice'")->prosrc;
        $old = "OR (l.line_type='service' AND NOT EXISTS (SELECT 1 FROM service_deliveries s";
        $new = "OR (l.line_type='service' AND l.dispensary_service_line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dispensary_service_lines sl JOIN dispensary_handoffs h ON h.id=sl.dispensary_handoff_id JOIN consultation_checkouts c ON c.dispensary_handoff_id=h.id WHERE sl.id=l.dispensary_service_line_id AND c.id=inv.consultation_checkout_id AND h.status='completed' AND sl.disposition='performed' AND sl.quantity_performed=l.quantity AND d.clinical_service_catalogue_item_id=sl.clinical_service_catalogue_item_id AND l.source_key='dservice:'||sl.id::text))\n        OR (l.line_type='service' AND l.dispensary_service_line_id IS NULL AND NOT EXISTS (SELECT 1 FROM service_deliveries s";
        if (substr_count($source, $old) !== 1) {
            throw new RuntimeException('The invoice reconciliation function has an unexpected shape; the service rule was not changed.');
        }
        $patched = str_replace($old, $new, $source);
        // the function body is the stored definition with one verified substitution, so it is not a literal
        // @phpstan-ignore argument.type
        DB::unprepared('CREATE OR REPLACE FUNCTION kpone_billing_check_invoice(invoice_key bigint) RETURNS void LANGUAGE plpgsql AS $kpone$'.$patched.'$kpone$');
    }
};
