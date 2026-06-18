<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sections');

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('parent_department_id')
                ->nullable()
                ->references('id')
                ->on('departments')
                ->nullOnDelete()
                ->after('hod');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['parent_department_id']);
            $table->dropColumn('parent_department_id');
        });
    }
};
