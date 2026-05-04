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
        Schema::table('education_levels', function (Blueprint $table) {
            $table->integer('rank')->nullable();
        });

        Schema::table('education', function (Blueprint $table) {
            $table->integer('education_level_rank')->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->integer('directory_order')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('education_levels', function (Blueprint $table) {
            $table->dropColumn('rank');
        });
        Schema::table('education', function (Blueprint $table) {
           $table->dropColumn('education_level_rank');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('directory_order');
        });
    }
};
