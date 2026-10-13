<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table): void {
            $table->string('panel_type', 24)->nullable();
            $table->string('company_registration_number', 12)->nullable();
            $table->string('company_name')->nullable();
            $table->string('person_in_charge')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postcode', 16)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('verification_mode', 16)->default('url');
            $table->string('verification_remark', 500)->nullable();
            $table->string('income_tax_number', 20)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE panels ADD CONSTRAINT panels_panel_type_check CHECK (panel_type IS NULL OR panel_type IN ('tpa','direct_company'))");
            DB::statement("ALTER TABLE panels ADD CONSTRAINT panels_verification_mode_check CHECK (verification_mode IN ('url','remark'))");
            DB::statement("ALTER TABLE panels ADD CONSTRAINT panels_company_registration_number_check CHECK (company_registration_number IS NULL OR company_registration_number ~ '^[0-9]{12}$')");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels DROP CONSTRAINT IF EXISTS panels_company_registration_number_check');
            DB::statement('ALTER TABLE panels DROP CONSTRAINT IF EXISTS panels_verification_mode_check');
            DB::statement('ALTER TABLE panels DROP CONSTRAINT IF EXISTS panels_panel_type_check');
        }

        Schema::table('panels', function (Blueprint $table): void {
            $table->dropColumn([
                'panel_type', 'company_registration_number', 'company_name', 'person_in_charge', 'phone', 'email',
                'address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'country',
                'verification_mode', 'verification_remark', 'income_tax_number', 'lock_version',
            ]);
        });
    }
};
