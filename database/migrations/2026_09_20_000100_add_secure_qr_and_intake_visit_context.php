<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_checkin_links', function (Blueprint $table) {
            $table->text('encrypted_token')->nullable()->after('token_hash');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->string('intake_purpose', 40)->nullable()->after('visit_reason');
            $table->text('encrypted_presenting_information')->nullable()->after('intake_purpose');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['intake_purpose', 'encrypted_presenting_information']);
        });

        Schema::table('public_checkin_links', function (Blueprint $table) {
            $table->dropColumn('encrypted_token');
        });
    }
};
