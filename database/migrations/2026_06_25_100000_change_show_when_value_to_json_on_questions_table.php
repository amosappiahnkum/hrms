<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add a temporary JSON column
        Schema::table('questions', function (Blueprint $table) {
            $table->json('show_when_value_json')->nullable()->after('show_when_value');
        });

        // Drop old string column and rename JSON column to the original name
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('show_when_value');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->renameColumn('show_when_value_json', 'show_when_value');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('show_when_value_str')->nullable()->after('show_when_value');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('show_when_value');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->renameColumn('show_when_value_str', 'show_when_value');
        });
    }
};
