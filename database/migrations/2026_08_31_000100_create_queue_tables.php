<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->unique(
                ['id', 'organisation_id', 'branch_id'],
                'visits_id_organisation_branch_unique',
            );
        });

        Schema::create('queue_number_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('operational_date');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->foreign(['branch_id', 'organisation_id'], 'queue_counters_branch_organisation_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->unique(
                ['organisation_id', 'branch_id', 'operational_date'],
                'queue_counters_branch_day_unique',
            );
        });

        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('visit_id');
            $table->date('operational_date');
            $table->unsignedBigInteger('queue_number');
            $table->string('status', 32);
            $table->timestamp('queued_at');
            $table->unsignedBigInteger('queued_by_user_id');
            $table->timestamp('called_at')->nullable();
            $table->unsignedBigInteger('called_by_user_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(
                ['visit_id', 'organisation_id', 'branch_id'],
                'queue_entries_visit_organisation_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(['queued_by_user_id', 'organisation_id'], 'queue_entries_queued_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['called_by_user_id', 'organisation_id'], 'queue_entries_called_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'queue_entries_updated_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('visit_id');
            $table->unique(
                ['organisation_id', 'branch_id', 'operational_date', 'queue_number'],
                'queue_entries_branch_day_number_unique',
            );
            $table->index(
                ['branch_id', 'status', 'operational_date', 'queued_at'],
                'queue_entries_live_branch_index',
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE queue_number_counters ADD CONSTRAINT queue_counters_next_value_positive CHECK (next_value > 0)');
            DB::statement('ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_number_positive CHECK (queue_number > 0)');
            DB::statement('ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_lock_version_positive CHECK (lock_version > 0)');
            DB::statement("ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_status_check CHECK (status IN ('waiting', 'serving', 'removed'))");
            DB::statement('ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_called_pair_check CHECK ((called_at IS NULL) = (called_by_user_id IS NULL))');
            DB::statement("ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_state_fields_check CHECK ((status = 'waiting' AND called_at IS NULL AND removed_at IS NULL) OR (status = 'serving' AND called_at IS NOT NULL AND removed_at IS NULL) OR (status = 'removed' AND removed_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
        Schema::dropIfExists('queue_number_counters');
        Schema::table('visits', fn (Blueprint $table) => $table
            ->dropUnique('visits_id_organisation_branch_unique'));
    }
};
