<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-7 (owner decisions 2026-11-05): a Panel's remittance statement (CSV) uploaded to help match a Panel payment
 * to claims. The file itself is encrypted on the private disk; only this record of it is kept in the database,
 * never its rows. The file is deleted after 30 days (delete_after), when deleted_at is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_statement_uploads', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_id');
            $t->string('stored_path', 200);
            $t->unsignedInteger('size_bytes');
            $t->string('content_hash', 64);
            $t->unsignedSmallInteger('row_count');
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->timestamp('uploaded_at');
            $t->timestamp('delete_after');
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->foreign(['branch_id', 'organisation_id'], 'panel_statement_branch_owner_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['panel_id', 'organisation_id'], 'panel_statement_panel_owner_fk')
                ->references(['id', 'organisation_id'])->on('panels')->restrictOnDelete();
            $t->foreign(['uploaded_by_user_id', 'organisation_id'], 'panel_statement_uploader_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'branch_id', 'panel_id', 'content_hash'], 'panel_statement_hash_idx');
            $t->index(['delete_after', 'deleted_at'], 'panel_statement_purge_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE panel_statement_uploads ADD CONSTRAINT panel_statement_uploads_shape CHECK (size_bytes BETWEEN 1 AND 1048576 AND row_count BETWEEN 1 AND 500 AND content_hash ~ '^[0-9a-f]{64}$' AND delete_after > uploaded_at AND (deleted_at IS NULL OR deleted_at >= uploaded_at))");
            // One live copy of the same file per Panel and branch: a re-upload reuses it.
            DB::statement('CREATE UNIQUE INDEX panel_statement_live_file ON panel_statement_uploads (organisation_id, branch_id, panel_id, content_hash) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void {}
};
