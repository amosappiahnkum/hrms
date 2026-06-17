<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_types', 'requires_document')) {
                $table->boolean('requires_document')->default(false)->after('description');
            }
            if (!Schema::hasColumn('leave_types', 'max_documents')) {
                $table->unsignedTinyInteger('max_documents')->default(1)->after('requires_document');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['requires_document', 'max_documents']);
        });
    }
};
