<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "New look (preview)": a per-person pilot switch beside the Version 2 switch. Default off; changes nothing else. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_space_preferences', function (Blueprint $table): void {
            $table->boolean('new_look')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_space_preferences', function (Blueprint $table): void {
            $table->dropColumn('new_look');
        });
    }
};
