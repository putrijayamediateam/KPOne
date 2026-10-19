<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_document_footers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id')->unique();
            $table->text('text')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_document_footers');
    }
};
