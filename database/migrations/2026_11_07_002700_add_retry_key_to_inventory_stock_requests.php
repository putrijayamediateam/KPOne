<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-39 (approved by B.E.R.N.I.S.S for the owner): an idempotency key on stock-request creation, so a double press or a
 * retry returns the request already made instead of making a second one. Additive and nullable: existing requests keep
 * no key, and a caller that sends none behaves as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_requests', function (Blueprint $table): void {
            $table->uuid('retry_key')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->unique(['organisation_id', 'retry_key'], 'stock_requests_retry_key_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_stock_requests ADD CONSTRAINT stock_requests_retry_shape CHECK ((retry_key IS NULL) = (payload_hash IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_stock_requests DROP CONSTRAINT IF EXISTS stock_requests_retry_shape');
        }

        Schema::table('inventory_stock_requests', function (Blueprint $table): void {
            $table->dropUnique('stock_requests_retry_key_unique');
            $table->dropColumn(['retry_key', 'payload_hash']);
        });
    }
};
