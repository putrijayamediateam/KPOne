<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('coverage_panel_note', 500)->nullable();
        });
        Schema::create('visit_panel_note_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('visit_id');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['organisation_id', 'idempotency_key'], 'visit_panel_note_commands_key_unique');
            $table->foreign(['visit_id', 'organisation_id', 'branch_id'], 'visit_panel_note_commands_visit_scope_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_panel_note_coverage_check CHECK (coverage_type = 'panel' OR coverage_panel_note IS NULL)");
        }
    }

    public function down(): void
    {
        if (DB::table('visits')->whereNotNull('coverage_panel_note')->exists() || DB::table('visit_panel_note_commands')->exists()) {
            throw new RuntimeException('Panel notes and retry evidence must be retained.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_panel_note_coverage_check');
        }
        Schema::dropIfExists('visit_panel_note_commands');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn('coverage_panel_note'));
    }
};
