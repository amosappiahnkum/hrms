<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('document_categories')
                ->nullOnDelete();
            $table->timestamps();
        });

        $defaults = ['Policy', 'Manual', 'Handbook', 'Notice', 'Form', 'Other'];

        foreach ($defaults as $name) {
            DB::table('document_categories')->insert([
                'uuid'       => Str::uuid(),
                'name'       => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};
