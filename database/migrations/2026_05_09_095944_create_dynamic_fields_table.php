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
        Schema::create('dynamic_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('dynamic_form_id')->constrained()->onDelete('cascade');
            // FE key
            $table->string('name');
            // UI label
            $table->string('label');

            // text, select, date
            $table->string('type');

            $table->integer('col_span')
                ->default(24);

            $table->integer('sort_order')
                ->default(0);

            $table->boolean('is_required')
                ->default(false);

            $table->boolean('is_active')
                ->default(true);

            /*
            UI metadata:
            placeholder
            allowClear
            showSearch
            */
            $table->json('props')->nullable();

            /*
            Ant rules array
            */
            $table->json('rules')->nullable();

            /*
            Conditional logic
            */
            $table->json('behavior')->nullable();

            /*
            API source config
            */
            $table->json('data_source')->nullable();

            /*
            Formatting config
            */
            $table->json('transformers')->nullable();

            $table->timestamps();

            $table->index([
                'dynamic_form_id',
                'sort_order'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_fields');
    }
};
