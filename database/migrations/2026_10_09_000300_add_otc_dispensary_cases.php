<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DS-01b-1 (owner decision, 2026-10-09): an OTC visit gets a Dispensary case of its own.
 *
 * An OTC case has no clinical encounter, treatment plan or queue entry. It reuses the
 * consultation case's items, batch allocations, labels and the stock debit at Complete.
 * The CA records, per case, what the patient said about allergies (an enum, never free text)
 * and confirms it before Complete; the doctor's allergy review does not exist for OTC.
 */
return new class extends Migration
{
    private const PERMISSION = 'dispensary.otc.create.branch';

    /** @var list<string> */
    private const ROLES = ['ca', 'ca_supervisor'];

    public function up(): void
    {
        Schema::table('dispensary_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinical_encounter_id')->nullable()->change();
            $table->unsignedBigInteger('treatment_plan_id')->nullable()->change();
            $table->string('case_type', 16)->default('consultation');
        });
        Schema::table('dispensary_handoffs', function (Blueprint $table): void {
            $table->unsignedInteger('treatment_plan_lock_version_received')->nullable()->change();
            $table->string('otc_allergy_statement', 16)->nullable();
            $table->timestamp('otc_allergy_confirmed_at')->nullable();
        });
        // One OTC case per visit. (A consultation visit is already one-to-one through its plan.)
        DB::statement("CREATE UNIQUE INDEX dispensary_cases_one_otc_per_visit ON dispensary_cases (visit_id) WHERE case_type = 'otc'");

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dispensary_cases ADD CONSTRAINT dispensary_cases_type_check CHECK (
                (case_type = 'consultation' AND clinical_encounter_id IS NOT NULL AND treatment_plan_id IS NOT NULL)
                OR (case_type = 'otc' AND clinical_encounter_id IS NULL AND treatment_plan_id IS NULL)
            )");
            DB::statement("ALTER TABLE dispensary_handoffs ADD CONSTRAINT dispensary_handoffs_otc_allergy_check CHECK (
                (otc_allergy_statement IS NULL AND otc_allergy_confirmed_at IS NULL)
                OR (otc_allergy_statement IN ('none', 'has_allergy') AND otc_allergy_confirmed_at IS NOT NULL)
            )");
            // A CA-added line may carry 0 when the patient has no Allergy Profile yet (OTC); a doctor's line never may.
            DB::statement('ALTER TABLE dispensary_items DROP CONSTRAINT IF EXISTS dispensary_items_versions_positive');
            DB::statement("ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_versions_positive CHECK (lock_version > 0 AND quantity_ordered > 0 AND (allergy_profile_version_validated > 0 OR (source = 'ca' AND treatment_plan_medicine_order_id IS NULL AND allergy_profile_version_validated = 0)))");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);
        foreach (self::ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('DS-01b-1 migration: role not present in database, skipped (never auto-created).', ['role' => $name]);
            } elseif (! $role->hasPermissionTo(self::PERMISSION)) {
                $role->givePermissionTo(self::PERMISSION);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Intentionally a no-op: reversing would orphan OTC cases and could remove a grant this migration did not create.
     */
    public function down(): void {}
};
