<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_catalogue_items', function (Blueprint $table): void {
            $table->string('generic_name', 300)->nullable();
            $table->string('category', 120)->nullable();
            $table->string('group_name', 120)->nullable();
            $table->decimal('default_dosage_amount', 12, 3)->nullable();
            $table->string('default_dosage_unit', 100)->nullable();
            $table->string('default_instruction', 200)->nullable();
            $table->string('default_precaution', 500)->nullable();
            $table->string('default_frequency', 200)->nullable();
            $table->string('default_duration', 100)->nullable();
            $table->string('default_indication', 300)->nullable();
        });
        Schema::table('clinical_service_catalogue_items', function (Blueprint $table): void {
            $table->string('category', 120)->nullable();
        });

        Schema::create('catalogue_options', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->string('option_type', 50);
            $table->string('label', 200);
            $table->string('normalized_label', 200);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'catalogue_options_creator_tenant_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'option_type', 'normalized_label'], 'catalogue_options_identity_unique');
            $table->unique(['id', 'organisation_id'], 'catalogue_options_id_tenant_unique');
            $table->index(['organisation_id', 'option_type', 'is_active', 'label'], 'catalogue_options_search_index');
        });

        Schema::table('price_books', function (Blueprint $table): void {
            $table->string('price_tier', 20)->default('self_pay');
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->foreign(['panel_id', 'organisation_id'], 'price_books_panel_tenant_fk')
                ->references(['id', 'organisation_id'])->on('panels')->restrictOnDelete();
            $table->index(['organisation_id', 'price_tier', 'panel_id', 'branch_id'], 'price_books_tier_scope_index');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE price_books DROP CONSTRAINT price_book_shape');
            DB::statement("ALTER TABLE price_books ADD CONSTRAINT price_book_shape CHECK (currency='MYR' AND ((price_tier='self_pay' AND panel_id IS NULL AND ((branch_id IS NULL AND scope_key='organisation') OR (branch_id IS NOT NULL AND scope_key='branch:'||branch_id::text))) OR (price_tier='panel' AND branch_id IS NULL AND ((panel_id IS NULL AND scope_key='panel:default') OR (panel_id IS NOT NULL AND scope_key='panel:'||panel_id::text)))))");
        }

        Schema::table('inventory_purchase_order_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('estimated_unit_cost_sen')->nullable();
        });

        Schema::table('inventory_goods_receipt_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_cost_sen')->nullable();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_cost_sen')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreign(['supplier_id', 'organisation_id'], 'stock_movements_supplier_tenant_fk')
                ->references(['id', 'organisation_id'])->on('inventory_suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign('stock_movements_supplier_tenant_fk');
            $table->dropColumn(['unit_cost_sen', 'supplier_id']);
        });
        Schema::table('inventory_goods_receipt_lines', fn (Blueprint $table) => $table->dropColumn('unit_cost_sen'));
        Schema::table('inventory_purchase_order_lines', fn (Blueprint $table) => $table->dropColumn('estimated_unit_cost_sen'));
        Schema::table('price_books', function (Blueprint $table): void {
            $table->dropIndex('price_books_tier_scope_index');
            $table->dropForeign('price_books_panel_tenant_fk');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE price_books DROP CONSTRAINT price_book_shape');
        }
        Schema::table('price_books', function (Blueprint $table): void {
            $table->dropColumn(['price_tier', 'panel_id']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE price_books ADD CONSTRAINT price_book_shape CHECK (currency='MYR' AND ((branch_id IS NULL AND scope_key='organisation') OR (branch_id IS NOT NULL AND scope_key='branch:'||branch_id::text)))");
        }
        Schema::dropIfExists('catalogue_options');
        Schema::table('medicine_catalogue_items', function (Blueprint $table): void {
            $table->dropColumn([
                'generic_name',
                'category',
                'group_name',
                'default_dosage_amount',
                'default_dosage_unit',
                'default_instruction',
                'default_precaution',
                'default_frequency',
                'default_duration',
                'default_indication',
            ]);
        });
        Schema::table('clinical_service_catalogue_items', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
