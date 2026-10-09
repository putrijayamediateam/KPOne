<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A QR intake for "Buy medicine only" is accepted into the OTC waiting list instead of the consultation queue. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_patient_intakes', function (Blueprint $table) {
            $table->unsignedBigInteger('otc_queue_entry_id')->nullable()->unique();
            $table->foreign('otc_queue_entry_id', 'public_patient_intakes_otc_queue_fk')
                ->references('id')->on('otc_queue_entries')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_patient_intakes DROP CONSTRAINT public_patient_intakes_accepted_links_check');
            DB::statement("ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_accepted_links_check CHECK (status <> 'accepted' OR (patient_id IS NOT NULL AND visit_id IS NOT NULL AND ((queue_entry_id IS NOT NULL) <> (otc_queue_entry_id IS NOT NULL)) AND accepted_at IS NOT NULL AND accepted_by_user_id IS NOT NULL))");
            DB::statement('ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_one_queue_check CHECK (queue_entry_id IS NULL OR otc_queue_entry_id IS NULL)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_patient_intakes DROP CONSTRAINT IF EXISTS public_patient_intakes_one_queue_check');
            DB::statement('ALTER TABLE public_patient_intakes DROP CONSTRAINT IF EXISTS public_patient_intakes_accepted_links_check');
            DB::statement("ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_accepted_links_check CHECK (status <> 'accepted' OR (patient_id IS NOT NULL AND visit_id IS NOT NULL AND queue_entry_id IS NOT NULL AND accepted_at IS NOT NULL AND accepted_by_user_id IS NOT NULL))");
        }

        Schema::table('public_patient_intakes', function (Blueprint $table) {
            $table->dropForeign('public_patient_intakes_otc_queue_fk');
            $table->dropColumn('otc_queue_entry_id');
        });
    }
};
