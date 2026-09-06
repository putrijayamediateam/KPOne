<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_reason_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();

            $table->foreign(['created_by_user_id', 'organisation_id'], 'visit_reason_creator_tenant_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'normalized_name'], 'visit_reason_catalogue_org_name_unique');
            $table->unique(['id', 'organisation_id'], 'visit_reason_catalogue_id_org_unique');
            $table->index(['organisation_id', 'is_active', 'name'], 'visit_reason_catalogue_search_index');
        });

        Schema::create('visit_reason_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('visit_reason_catalogue_item_id');
            $table->string('label_snapshot', 120);
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->foreign(['visit_id', 'organisation_id', 'branch_id'], 'visit_reason_assignment_visit_tenant_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(['visit_reason_catalogue_item_id', 'organisation_id'], 'visit_reason_assignment_catalogue_tenant_fk')
                ->references(['id', 'organisation_id'])->on('visit_reason_catalogue_items')->restrictOnDelete();
            $table->unique(['visit_id', 'visit_reason_catalogue_item_id'], 'visit_reason_assignment_reason_unique');
            $table->unique(['visit_id', 'position'], 'visit_reason_assignment_position_unique');
            $table->index(['organisation_id', 'visit_id', 'position'], 'visit_reason_assignment_visit_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visit_reason_assignments ADD CONSTRAINT visit_reason_assignment_position_check CHECK (position BETWEEN 1 AND 5)');
            DB::statement("ALTER TABLE visit_reason_catalogue_items ADD CONSTRAINT visit_reason_catalogue_name_check CHECK (btrim(name) <> '' AND btrim(normalized_name) <> '')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_reason_assignments');
        Schema::dropIfExists('visit_reason_catalogue_items');
    }
};
