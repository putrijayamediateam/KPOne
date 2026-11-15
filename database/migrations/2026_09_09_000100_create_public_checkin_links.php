<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_checkin_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id');
            $table->string('token_hash', 64)->unique();
            $table->string('label', 120);
            $table->boolean('is_active')->default(true);
            $table->string('active_branch_guard', 64)->nullable()->unique();
            $table->foreignId('created_by_user_id');
            $table->foreignId('revoked_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('rotated_from_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'organisation_id'], 'public_checkin_links_id_org_unique');
            $table->foreign(['branch_id', 'organisation_id'], 'public_checkin_links_branch_org_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'public_checkin_links_creator_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['revoked_by_user_id', 'organisation_id'], 'public_checkin_links_revoker_org_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['rotated_from_id', 'organisation_id'], 'public_checkin_links_rotation_org_fk')
                ->references(['id', 'organisation_id'])->on('public_checkin_links')->restrictOnDelete();
            $table->index(['organisation_id', 'branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_checkin_links');
    }
};
