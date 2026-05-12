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
        Schema::create('dynamic_field_options', function (Blueprint $table) {
            $table->id();

            $table->uuid();

            $table->foreignId('dynamic_field_id')->constrained()->onDelete('cascade');

            $table->string('label');

            $table->string('value');

            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_field_options');
    }
};
