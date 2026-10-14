<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_claim_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16)->default('single');
            $table->text('body');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'kind']);
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_claim_message_templates');
    }
};
