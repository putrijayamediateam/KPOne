<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DS-01a (owner decision, 2026-10-09): a CA may edit, add and remove medicine lines at Dispensary.
 *
 * The doctor's order stays exactly as sent: every snapshot column on dispensary_items remains
 * immutable (model guard) and the treatment-plan order is untouched. The CA's version lives in the
 * new nullable final_* columns (null = the doctor's value stands), quantity_dispensed, and the
 * source / change_state markers. A line the CA adds has no treatment-plan order (source = 'ca').
 * The handoff records the CA's verification at completion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensary_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('treatment_plan_medicine_order_id')->nullable()->change();
            $table->string('medicine_order_public_id', 36)->nullable()->change();
            $table->string('source', 10)->default('doctor');
            $table->string('change_state', 10)->default('unchanged');
            $table->string('final_dosage', 500)->nullable();
            $table->string('final_frequency', 500)->nullable();
            $table->string('final_duration', 500)->nullable();
            $table->string('final_route', 500)->nullable();
            $table->text('final_administration_instruction')->nullable();
            $table->text('final_precaution')->nullable();
            $table->unsignedBigInteger('edited_by_user_id')->nullable();
            $table->timestamp('edited_at')->nullable();
        });
        Schema::table('dispensary_handoffs', function (Blueprint $table): void {
            $table->timestamp('ca_verified_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dispensary_items DROP CONSTRAINT IF EXISTS dispensary_items_state_check');
            DB::statement("ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_state_check CHECK (
                (status = 'pending' AND quantity_dispensed IS NULL AND reason IS NULL)
                OR (status = 'dispensed' AND quantity_dispensed > 0 AND reason IS NULL AND (change_state IN ('edited', 'added') OR quantity_dispensed = quantity_ordered))
                OR (status = 'partial' AND quantity_dispensed > 0 AND quantity_dispensed < quantity_ordered AND reason IS NOT NULL)
                OR (status = 'not_dispensed' AND quantity_dispensed = 0 AND reason IS NOT NULL)
            )");
            DB::statement('ALTER TABLE dispensary_items DROP CONSTRAINT IF EXISTS dispensary_items_reason_check');
            DB::statement("ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_reason_check CHECK (reason IS NULL OR reason IN ('patient_declined', 'out_of_stock', 'clarification_required', 'other', 'ca_removed'))");
            DB::statement("ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_source_check CHECK (
                source IN ('doctor', 'ca')
                AND change_state IN ('unchanged', 'edited', 'added', 'removed')
                AND ((source = 'doctor') = (treatment_plan_medicine_order_id IS NOT NULL))
                AND (source = 'ca' OR change_state <> 'added')
                AND (source = 'doctor' OR change_state <> 'unchanged')
                AND ((change_state = 'removed') = (COALESCE(reason, '') = 'ca_removed'))
            )");
            DB::statement('ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_editor_tenant_fk FOREIGN KEY (edited_by_user_id, organisation_id) REFERENCES users (id, organisation_id) ON DELETE RESTRICT');
        }
    }

    /**
     * Intentionally a no-op: reversing would drop the CA's recorded edits and verification.
     */
    public function down(): void {}
};
