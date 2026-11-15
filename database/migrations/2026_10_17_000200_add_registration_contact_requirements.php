<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_settings', function (Blueprint $table) {
            $table->boolean('require_email')->default(false);
            $table->boolean('require_address')->default(false);
        });
        Schema::table('public_intake_sessions', function (Blueprint $table) {
            // Null preserves the pre-SET-04b notice and collection scope.
            $table->string('privacy_notice_version', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('public_intake_sessions', fn (Blueprint $table) => $table->dropColumn('privacy_notice_version'));
        Schema::table('registration_settings', fn (Blueprint $table) => $table->dropColumn(['require_email', 'require_address']));
    }
};
