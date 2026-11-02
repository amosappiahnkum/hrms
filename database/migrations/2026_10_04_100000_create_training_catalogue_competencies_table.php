<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which competencies a catalogue training develops, and the level it brings a trainee to. Lets a
 * competency gap suggest courses, and training needs be grouped by course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_catalogue_competencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('training_catalogue_item_id')->constrained(indexName: 'tcc_catalogue_item_fk')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained(indexName: 'tcc_competency_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('target_level');
            $table->timestamps();
            $table->softDeletes();

            // Soft-deleted links are revived on re-save, so one row per pair.
            $table->unique(['training_catalogue_item_id', 'competency_id'], 'tcc_item_competency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_catalogue_competencies');
    }
};
