<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(1);
        });
        // The retained supplier fields remain the primary contact; these are additional contacts.
        Schema::create('inventory_supplier_contacts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_supplier_id');
            $table->unsignedSmallInteger('position');
            $table->string('name', 200)->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamps();
            $table->foreign(['inventory_supplier_id', 'organisation_id'], 'supplier_contact_owner_fk')
                ->references(['id', 'organisation_id'])->on('inventory_suppliers')->restrictOnDelete();
            $table->unique(['inventory_supplier_id', 'position']);
        });
        Schema::create('inventory_supplier_settings_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('inventory_supplier_id');
            $table->uuid('idempotency_key');
            $table->string('operation', 20);
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'supplier_settings_key_unique');
            $table->foreign(['inventory_supplier_id', 'organisation_id'], 'supplier_command_owner_fk')
                ->references(['id', 'organisation_id'])->on('inventory_suppliers')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'supplier_command_branch_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['inventory_supplier_contacts', 'inventory_supplier_settings_commands'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Supplier settings evidence must be retained.');
            }
        }
        Schema::dropIfExists('inventory_supplier_settings_commands');
        Schema::dropIfExists('inventory_supplier_contacts');
        Schema::table('inventory_suppliers', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
