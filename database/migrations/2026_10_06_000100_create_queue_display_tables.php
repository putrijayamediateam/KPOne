<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('kind', 32);
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign(['branch_id', 'organisation_id'], 'branch_rooms_branch_organisation_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'branch_rooms_updated_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['id', 'organisation_id', 'branch_id'], 'branch_rooms_id_organisation_branch_unique');
            $table->unique(['branch_id', 'name'], 'branch_rooms_branch_name_unique');
        });

        Schema::create('doctor_room_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('doctor_user_id');
            $table->unsignedBigInteger('branch_room_id');
            $table->date('operational_date');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(['branch_room_id', 'organisation_id', 'branch_id'], 'doctor_rooms_room_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('branch_rooms')->restrictOnDelete();
            $table->foreign(['doctor_user_id', 'organisation_id'], 'doctor_rooms_doctor_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['branch_id', 'doctor_user_id', 'operational_date'], 'doctor_rooms_doctor_day_unique');
        });

        Schema::create('queue_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('queue_entry_id');
            $table->string('service', 32);
            $table->boolean('is_recall')->default(false);
            $table->unsignedBigInteger('branch_room_id')->nullable();
            $table->string('room_name', 60)->nullable();
            $table->unsignedBigInteger('queue_number');
            $table->date('operational_date');
            $table->unsignedBigInteger('called_by_user_id');
            $table->timestamp('called_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign(['branch_id', 'organisation_id'], 'queue_calls_branch_organisation_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign('queue_entry_id', 'queue_calls_queue_entry_fk')
                ->references('id')->on('queue_entries')->restrictOnDelete();
            $table->foreign(['branch_room_id', 'organisation_id', 'branch_id'], 'queue_calls_room_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('branch_rooms')->restrictOnDelete();
            $table->foreign(['called_by_user_id', 'organisation_id'], 'queue_calls_called_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->index(['branch_id', 'operational_date', 'called_at'], 'queue_calls_branch_day_index');
        });

        Schema::create('branch_display_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('ticker_text', 500)->nullable();
            $table->string('youtube_video_id', 11)->nullable();
            $table->unsignedSmallInteger('poster_seconds')->default(10);
            $table->json('posters');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign(['branch_id', 'organisation_id'], 'display_settings_branch_organisation_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'display_settings_updated_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('branch_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE branch_rooms ADD CONSTRAINT branch_rooms_kind_check CHECK (kind IN ('consultation', 'dispensary', 'treatment'))");
            DB::statement('ALTER TABLE branch_rooms ADD CONSTRAINT branch_rooms_lock_version_positive CHECK (lock_version > 0)');
            DB::statement("ALTER TABLE queue_calls ADD CONSTRAINT queue_calls_service_check CHECK (service IN ('consultation'))");
            DB::statement('ALTER TABLE queue_calls ADD CONSTRAINT queue_calls_number_positive CHECK (queue_number > 0)');
            DB::statement('ALTER TABLE branch_display_settings ADD CONSTRAINT display_settings_poster_seconds_check CHECK (poster_seconds BETWEEN 5 AND 120)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_display_settings');
        Schema::dropIfExists('queue_calls');
        Schema::dropIfExists('doctor_room_assignments');
        Schema::dropIfExists('branch_rooms');
    }
};
