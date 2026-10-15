<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_detail_assets')) {
            Schema::create('business_detail_assets', function (Blueprint $t): void {
                $t->id();
                $t->uuid('public_id')->unique();
                $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
                $t->string('path');
                $t->string('mime', 20);
                $t->char('sha256', 64);
                $t->unsignedInteger('bytes');
                $t->unsignedInteger('width');
                $t->unsignedInteger('height');
                $t->timestamp('retired_at')->nullable();
                $t->timestamps();
                $t->unique(['id', 'organisation_id']);
            });
        }
        if (! Schema::hasTable('organisation_business_profiles')) {
            Schema::create('organisation_business_profiles', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
                $t->string('legal_name', 200);
                $t->string('registration_type', 10)->nullable();
                $t->string('registration_number', 80)->nullable();
                $t->unsignedBigInteger('logo_asset_id')->nullable();
                $t->foreign(['logo_asset_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('business_detail_assets')->restrictOnDelete();
                $t->unsignedInteger('lock_version')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('branch_business_profiles')) {
            Schema::create('branch_business_profiles', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('branch_id')->unique();
                $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
                $t->string('clinic_name', 200);
                $t->string('address_line_1', 255);
                $t->string('address_line_2', 255)->nullable();
                $t->string('postcode', 20);
                $t->string('city', 100);
                $t->string('state', 100);
                $t->string('country', 100);
                $t->string('phone', 40)->nullable();
                $t->string('email', 254)->nullable();
                $t->unsignedInteger('lock_version')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('business_detail_commands')) {
            Schema::create('business_detail_commands', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('branch_id');
                $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
                $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $t->uuid('idempotency_key');
                $t->char('payload_hash', 64);
                $t->unsignedInteger('organisation_version');
                $t->unsignedInteger('branch_version');
                $t->timestamp('created_at');
                $t->unique(['organisation_id', 'idempotency_key']);
            });
        }
    }

    public function down(): void
    {
        // Retained business identity and asset history must not be destroyed by rollback.
    }
};
