<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DS-02a (owner decisions, 2026-10-09): an OTC patient waits in a list of their own, with a number in the
 * B series (consultation numbers are the A series). A number is never reused on the same clinic day.
 *
 * The OTC waiting list is a separate table, so the consultation queue, the doctor queue and the reports that
 * read queue_entries are untouched. A TV call record points at exactly one of the two kinds of entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_number_counters', function (Blueprint $table): void {
            $table->dropUnique('queue_counters_branch_day_unique');
        });
        Schema::table('queue_number_counters', function (Blueprint $table): void {
            $table->string('series', 1)->default('A');
            $table->unique(['organisation_id', 'branch_id', 'operational_date', 'series'], 'queue_counters_branch_day_series_unique');
        });

        Schema::create('otc_queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('visit_id');
            $table->date('operational_date');
            $table->unsignedBigInteger('queue_number');
            $table->string('status', 16);
            $table->timestamp('queued_at');
            $table->unsignedBigInteger('queued_by_user_id');
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 24)->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(['visit_id', 'organisation_id', 'branch_id'], 'otc_queue_visit_tenant_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(['queued_by_user_id', 'organisation_id'], 'otc_queue_queued_by_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'otc_queue_updated_by_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('visit_id');
            $table->unique(['organisation_id', 'branch_id', 'operational_date', 'queue_number'], 'otc_queue_branch_day_number_unique');
            $table->index(['branch_id', 'status', 'operational_date', 'queued_at'], 'otc_queue_live_branch_index');
        });

        Schema::table('queue_calls', function (Blueprint $table): void {
            $table->unsignedBigInteger('queue_entry_id')->nullable()->change();
            $table->unsignedBigInteger('otc_queue_entry_id')->nullable();
            $table->string('queue_series', 1)->default('A');
            $table->foreign('otc_queue_entry_id', 'queue_calls_otc_queue_entry_fk')->references('id')->on('otc_queue_entries')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE otc_queue_entries ADD CONSTRAINT otc_queue_number_positive CHECK (queue_number > 0 AND lock_version > 0)');
            DB::statement("ALTER TABLE otc_queue_entries ADD CONSTRAINT otc_queue_state_check CHECK ((status = 'waiting' AND removed_at IS NULL AND removal_reason IS NULL) OR (status = 'removed' AND removed_at IS NOT NULL AND removal_reason IN ('dispensing', 'cancelled')))");
            DB::statement("ALTER TABLE queue_number_counters ADD CONSTRAINT queue_counters_series_check CHECK (series IN ('A', 'B'))");
            DB::statement("ALTER TABLE queue_calls ADD CONSTRAINT queue_calls_one_entry_check CHECK ((queue_entry_id IS NOT NULL) <> (otc_queue_entry_id IS NOT NULL) AND (otc_queue_entry_id IS NULL OR (queue_series = 'B' AND service = 'dispensary')))");
        }
    }

    /**
     * Intentionally a no-op: reversing would drop OTC waiting-list history and TV call records.
     */
    public function down(): void {}
};
