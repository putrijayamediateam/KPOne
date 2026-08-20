<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('staff_number', 50)->nullable()->unique();
            $table->string('job_title')->nullable();
            $table->timestamps();

            $table->index('department_id');
        });

        Schema::create('staff_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false)->index();
            $table->string('assignment_type', 50);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->unique(
                ['staff_profile_id', 'branch_id', 'assignment_type', 'valid_from'],
                'staff_branch_assignment_period_unique',
            );
            $table->index(
                ['staff_profile_id', 'valid_from', 'valid_until'],
                'staff_assignment_effective_period_index',
            );
            $table->index(
                ['branch_id', 'valid_from', 'valid_until'],
                'branch_assignment_effective_period_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_branch_assignments');
        Schema::dropIfExists('staff_profiles');
    }
};
