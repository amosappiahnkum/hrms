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
        Schema::table('questions', function (Blueprint $table) {
            // Self-referential: this question is only shown when depends_on
            // has an answer matching show_when_value (score or answer text).
            $table->foreignId('depends_on_question_id')
                ->nullable()
                ->after('order')
                ->constrained('questions')
                ->onDelete('set null');
            $table->string('show_when_value')->nullable()->after('depends_on_question_id');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['depends_on_question_id']);
            $table->dropColumn(['depends_on_question_id', 'show_when_value']);
        });
    }
};
