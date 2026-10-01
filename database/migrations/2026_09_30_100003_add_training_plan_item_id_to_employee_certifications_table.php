<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_certifications', function (Blueprint $table) {
            // Optional: the planned training this certificate came from.
            $table->foreignId('training_plan_item_id')->nullable()->after('employee_id')
                ->constrained('training_plan_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_certifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('training_plan_item_id');
        });
    }
};
