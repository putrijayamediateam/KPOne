<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_claims', function (Blueprint $table): void {
            $table->uuid('creation_idempotency_key')->nullable();
            $table->string('creation_payload_hash', 64)->nullable();
            $table->unique(['organisation_id', 'creation_idempotency_key'], 'panel_claim_creation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('panel_claims', function (Blueprint $table): void {
            $table->dropUnique('panel_claim_creation_key_unique');
            $table->dropColumn(['creation_idempotency_key', 'creation_payload_hash']);
        });
    }
};
