<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('require_date_of_birth')->default(false);
            $table->boolean('require_nationality')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        Schema::create('registration_setting_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'registration_setting_commands_retry_unique');
            $table->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
        Schema::table('public_intake_sessions', function (Blueprint $table) {
            // Null means a pre-SET-04 session: preserve its original optional policy.
            $table->jsonb('registration_policy')->nullable();
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registration_settings ADD CONSTRAINT registration_settings_version_positive CHECK (lock_version > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('public_intake_sessions', fn (Blueprint $table) => $table->dropColumn('registration_policy'));
        Schema::dropIfExists('registration_setting_commands');
        Schema::dropIfExists('registration_settings');
    }
};
