<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')
                ->constrained('course_enrollments')
                ->cascadeOnDelete();

            $table->foreignId('material_id')
                ->constrained('course_materials')
                ->cascadeOnDelete();

            $table->timestamp('viewed_at')->useCurrent();

            // One view record per employee per material
            $table->unique(['enrollment_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_views');
    }
};
