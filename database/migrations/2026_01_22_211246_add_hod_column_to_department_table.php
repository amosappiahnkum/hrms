<?php

use App\Models\Employee;
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
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('hod')->nullable()->references('id')->on('employees')->nullOnDelete();
            $table->integer('allowed_for_leave')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign('app_departments_hod_foreign');
            $table->dropColumn('hod');
            $table->dropColumn('allowed_for_leave');
        });
    }
};
