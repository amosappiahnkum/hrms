<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_responses', function (Blueprint $table) {
            $table->text('supervisor_answer')->nullable()->after('score');
            $table->integer('supervisor_score')->nullable()->after('supervisor_answer');
            $table->foreignId('supervisor_id')->nullable()->after('supervisor_score')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('supervisor_updated_at')->nullable()->after('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::table('question_responses', function (Blueprint $table) {
            $table->dropForeign(['supervisor_id']);
            $table->dropColumn(['supervisor_answer', 'supervisor_score', 'supervisor_id', 'supervisor_updated_at']);
        });
    }
};
