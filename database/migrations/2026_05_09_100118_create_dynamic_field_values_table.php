<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dynamic_field_values', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('dynamic_field_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->morphs('valuable');

            $table->jsonb('value')->nullable();

            $table->timestamps();

            $table->unique([
                'dynamic_field_id',
                'valuable_type',
                'valuable_id',
            ], 'dynamic_field_unique_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_field_values');
    }
};
