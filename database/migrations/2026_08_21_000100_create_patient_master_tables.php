<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_number_counters', function (Blueprint $table) {
            $table->foreignId('organisation_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('patient_number', 20);
            $table->string('full_name');
            $table->string('search_name');
            $table->date('date_of_birth')->nullable();
            $table->enum('sex', ['female', 'male', 'indeterminate', 'unknown']);
            $table->char('nationality_code', 2)->nullable();
            $table->string('mobile_phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['organisation_id', 'patient_number']);
            $table->unique(['id', 'organisation_id'], 'patients_id_organisation_unique');
            $table->index(['organisation_id', 'search_name']);
            $table->index(['organisation_id', 'mobile_phone']);
            $table->index(['organisation_id', 'date_of_birth']);
        });

        Schema::create('patient_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->enum('identifier_type', ['nric', 'passport']);
            $table->char('issuing_country_code', 2);
            $table->string('normalized_value', 100);
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['patient_id', 'organisation_id'], 'patient_identifiers_patient_organisation_fk')
                ->references(['id', 'organisation_id'])
                ->on('patients')
                ->restrictOnDelete();
            $table->unique(
                ['organisation_id', 'identifier_type', 'issuing_country_code', 'normalized_value'],
                'patient_identifiers_reserved_unique',
            );
            $table->index(['patient_id', 'retired_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX patient_one_current_nric
            ON patient_identifiers (patient_id)
            WHERE identifier_type = 'nric' AND retired_at IS NULL
        SQL);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE patient_number_counters ADD CONSTRAINT patient_number_counters_next_value_positive CHECK (next_value > 0)');
            DB::statement("ALTER TABLE patient_identifiers ADD CONSTRAINT patient_identifiers_nric_issuer_check CHECK (identifier_type <> 'nric' OR issuing_country_code = 'MY')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_identifiers');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('patient_number_counters');
    }
};
