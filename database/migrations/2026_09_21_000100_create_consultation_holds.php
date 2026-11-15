<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('queue_entry_id');
            $table->unsignedBigInteger('held_by_user_id');
            $table->unsignedBigInteger('resumed_by_user_id')->nullable();
            $table->timestamp('held_at');
            $table->timestamp('resumed_at')->nullable();
            $table->uuid('hold_idempotency_key');
            $table->char('hold_fingerprint', 64);
            $table->uuid('resume_idempotency_key')->nullable();
            $table->char('resume_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->foreign('organisation_id', 'consultation_holds_organisation_fk')
                ->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'consultation_holds_branch_org_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(
                ['clinical_encounter_id', 'organisation_id', 'branch_id'],
                'consultation_holds_encounter_org_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(
                ['visit_id', 'organisation_id', 'branch_id'],
                'consultation_holds_visit_org_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(
                ['queue_entry_id', 'organisation_id', 'branch_id'],
                'consultation_holds_queue_org_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('queue_entries')->restrictOnDelete();
            $table->foreign(['held_by_user_id', 'organisation_id'], 'consultation_holds_holder_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['resumed_by_user_id', 'organisation_id'], 'consultation_holds_resumer_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique(['organisation_id', 'hold_idempotency_key'], 'consultation_holds_hold_idempotency_unique');
            $table->unique(['organisation_id', 'resume_idempotency_key'], 'consultation_holds_resume_idempotency_unique');
            $table->index(['organisation_id', 'branch_id', 'held_at'], 'consultation_holds_branch_time_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX consultation_holds_one_active_unique ON consultation_holds (clinical_encounter_id) WHERE resumed_at IS NULL');
            DB::statement('ALTER TABLE consultation_holds ADD CONSTRAINT consultation_holds_resume_pair_check CHECK ((resumed_at IS NULL) = (resumed_by_user_id IS NULL))');
            DB::statement('ALTER TABLE consultation_holds ADD CONSTRAINT consultation_holds_resume_idempotency_pair_check CHECK ((resume_idempotency_key IS NULL) = (resume_fingerprint IS NULL))');
            DB::statement('ALTER TABLE consultation_holds ADD CONSTRAINT consultation_holds_time_order_check CHECK (resumed_at IS NULL OR resumed_at >= held_at)');
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX consultation_holds_one_active_unique ON consultation_holds (clinical_encounter_id) WHERE resumed_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_holds');
    }
};
