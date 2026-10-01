<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('nature');
            $table->string('domain')->nullable();
            $table->decimal('default_days', 5, 1)->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->string('trainer')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_catalogue_items');
    }
};
