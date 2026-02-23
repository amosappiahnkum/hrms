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
        Schema::table('employees', function (Blueprint $table) {
            $table->string("photo")->nullable();
            $table->date('termination_date')->nullable();
            $table->integer('terminated_by')->nullable();
            $table->boolean('onboarding')->default(false)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('photo');
            $table->dropColumn('termination_date');
            $table->dropColumn('terminated_by');
            $table->dropColumn('onboarding');
        });
    }
};
