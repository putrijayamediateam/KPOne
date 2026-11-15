<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_checkin_links', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('revoked_at');
            $table->unique(
                ['id', 'organisation_id', 'branch_id'],
                'public_checkin_links_id_org_branch_unique',
            );
            $table->index(['is_active', 'expires_at'], 'public_checkin_links_active_expiry_index');
        });

        Schema::table('queue_entries', function (Blueprint $table) {
            $table->unique(['id', 'organisation_id', 'branch_id'], 'queue_entries_id_org_branch_unique');
        });

        Schema::create('public_intake_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('public_checkin_link_id');
            $table->char('nonce_digest', 64)->unique();
            $table->char('status_receipt_digest', 64)->unique();
            $table->uuid('submission_idempotency_key');
            $table->char('payload_fingerprint', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('organisation_id', 'public_intake_sessions_organisation_fk')
                ->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'public_intake_sessions_branch_org_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(
                ['public_checkin_link_id', 'organisation_id', 'branch_id'],
                'public_intake_sessions_link_org_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])
                ->on('public_checkin_links')->restrictOnDelete();
            $table->unique(
                ['id', 'organisation_id', 'branch_id'],
                'public_intake_sessions_id_org_branch_unique',
            );
            $table->unique(
                ['public_checkin_link_id', 'submission_idempotency_key'],
                'public_intake_sessions_link_idempotency_unique',
            );
            $table->index(['branch_id', 'expires_at', 'consumed_at'], 'public_intake_sessions_expiry_index');
        });

        Schema::create('public_patient_intakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('public_checkin_link_id');
            $table->unsignedBigInteger('public_intake_session_id')->unique();
            $table->string('status', 32);
            $table->string('submission_type', 16);
            $table->text('encrypted_payload')->nullable();
            $table->char('payload_fingerprint', 64);
            $table->string('privacy_notice_version', 80);
            $table->timestamp('consented_at');
            $table->timestamp('submitted_at');
            $table->timestamp('expires_at');
            $table->timestamp('payload_purge_at');
            $table->timestamp('payload_purged_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->unsignedBigInteger('reviewing_user_id')->nullable();
            $table->timestamp('correction_required_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by_user_id')->nullable();
            $table->string('rejection_category', 40)->nullable();
            $table->uuid('acceptance_idempotency_key')->nullable();
            $table->char('acceptance_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->unsignedBigInteger('queue_entry_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['id', 'organisation_id', 'branch_id'], 'public_patient_intakes_id_org_branch_unique');
            $table->foreign('organisation_id', 'public_patient_intakes_organisation_fk')
                ->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'public_patient_intakes_branch_org_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(
                ['public_checkin_link_id', 'organisation_id', 'branch_id'],
                'public_patient_intakes_link_org_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])
                ->on('public_checkin_links')->restrictOnDelete();
            $table->foreign(
                ['public_intake_session_id', 'organisation_id', 'branch_id'],
                'public_patient_intakes_session_org_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])
                ->on('public_intake_sessions')->restrictOnDelete();
            $table->foreign(['reviewing_user_id', 'organisation_id'], 'public_patient_intakes_reviewer_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['accepted_by_user_id', 'organisation_id'], 'public_patient_intakes_acceptor_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['rejected_by_user_id', 'organisation_id'], 'public_patient_intakes_rejector_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['patient_id', 'organisation_id'], 'public_patient_intakes_patient_org_fk')
                ->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $table->foreign(['visit_id', 'organisation_id', 'branch_id'], 'public_patient_intakes_visit_org_branch_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(['queue_entry_id', 'organisation_id', 'branch_id'], 'public_patient_intakes_queue_org_branch_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('queue_entries')->restrictOnDelete();
            $table->unique(['organisation_id', 'acceptance_idempotency_key'], 'public_patient_intakes_acceptance_unique');
            $table->index(['branch_id', 'status', 'submitted_at'], 'public_patient_intakes_review_index');
            $table->index(['status', 'expires_at'], 'public_patient_intakes_expiry_index');
            $table->index(['payload_purge_at', 'payload_purged_at'], 'public_patient_intakes_purge_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_status_check CHECK (status IN ('pending', 'under_review', 'correction_required', 'accepted', 'rejected', 'expired'))");
            DB::statement("ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_submission_type_check CHECK (submission_type IN ('patient', 'guardian'))");
            DB::statement('ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_lock_version_positive CHECK (lock_version > 0)');
            DB::statement('ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_payload_purge_check CHECK ((encrypted_payload IS NULL) = (payload_purged_at IS NOT NULL))');
            DB::statement('ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_acceptance_pair_check CHECK ((acceptance_idempotency_key IS NULL) = (acceptance_fingerprint IS NULL))');
            DB::statement("ALTER TABLE public_patient_intakes ADD CONSTRAINT public_patient_intakes_accepted_links_check CHECK (status <> 'accepted' OR (patient_id IS NOT NULL AND visit_id IS NOT NULL AND queue_entry_id IS NOT NULL AND accepted_at IS NOT NULL AND accepted_by_user_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_patient_intakes');
        Schema::dropIfExists('public_intake_sessions');

        Schema::table('queue_entries', fn (Blueprint $table) => $table
            ->dropUnique('queue_entries_id_org_branch_unique'));
        Schema::table('public_checkin_links', function (Blueprint $table) {
            $table->dropIndex('public_checkin_links_active_expiry_index');
            $table->dropUnique('public_checkin_links_id_org_branch_unique');
            $table->dropColumn('expires_at');
        });
    }
};
