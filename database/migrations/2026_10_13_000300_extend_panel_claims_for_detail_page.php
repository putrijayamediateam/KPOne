<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table): void {
            $table->string('verification_url', 500)->nullable();
        });
        Schema::table('panel_claims', function (Blueprint $table): void {
            $table->string('remarks', 500)->nullable();
        });
        Schema::create('panel_claim_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_claim_id');
            $t->string('original_name', 255);
            $t->string('mime_type', 32);
            $t->unsignedInteger('size_bytes');
            $t->char('sha256', 64);
            $t->string('storage_path', 255);
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->timestamps();
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_claim_document_claim_owner_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->foreign(['uploaded_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id', 'branch_id']);
            $t->index(['panel_claim_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE panel_claim_documents ADD CONSTRAINT panel_claim_documents_shape CHECK (mime_type IN ('image/jpeg','image/png') AND size_bytes BETWEEN 1 AND 5242880)");
            DB::statement("ALTER TABLE panels ADD CONSTRAINT panels_verification_url_check CHECK (verification_url IS NULL OR verification_url LIKE 'https://%')");
            DB::unprepared('CREATE TRIGGER panel_claim_documents_immutable BEFORE UPDATE OR DELETE ON panel_claim_documents FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_child_immutable();');
            DB::statement('ALTER TABLE panel_claim_events DROP CONSTRAINT panel_claim_events_shape');
            DB::statement("ALTER TABLE panel_claim_events ADD CONSTRAINT panel_claim_events_shape CHECK (event_type IN ('prepared','amount_adjusted','submitted','queried','decision_accepted','decision_partially_accepted','decision_rejected','note') AND (previous_amount_sen IS NULL OR previous_amount_sen BETWEEN 0 AND 999999999999) AND (amount_sen IS NULL OR amount_sen BETWEEN 0 AND 999999999999) AND ((event_type='prepared' AND amount_sen IS NOT NULL) OR (event_type='amount_adjusted' AND previous_amount_sen IS NOT NULL AND amount_sen IS NOT NULL AND note IS NOT NULL) OR (event_type='submitted' AND amount_sen IS NOT NULL) OR (event_type='queried' AND note IS NOT NULL) OR (event_type LIKE 'decision_%' AND amount_sen IS NOT NULL AND note IS NOT NULL) OR (event_type='note' AND note IS NOT NULL)))");
        }
    }

    public function down(): void {}
};
