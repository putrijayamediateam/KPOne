<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_options', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(1);
        });
        Schema::create('medicine_option_settings_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'medicine_option_settings_key_unique');
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'medicine_option_settings_branch_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('medicine_option_settings_commands')->exists()) {
            throw new RuntimeException('Medicine option retry evidence must be retained.');
        }
        Schema::dropIfExists('medicine_option_settings_commands');
        Schema::table('catalogue_options', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
