<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('description', 500)->nullable()->after('name');
            $table->unsignedSmallInteger('sort_order')->default(100)->after('requires_reference');
            $table->index(['organisation_id', 'is_active', 'sort_order'], 'payment_methods_operational_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropIndex('payment_methods_operational_order_idx');
            $table->dropColumn(['description', 'sort_order']);
        });
    }
};
