<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The effectiveness check that finishes a development action (SOP 5.3.6): its result, who checked it
 * and when, and the rating that re-verified the competency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competency_development_actions', function (Blueprint $table) {
            $table->string('effectiveness_result')->nullable()->after('outcome');
            $table->unsignedTinyInteger('verified_level')->nullable()->after('effectiveness_result');
            $table->foreignId('competency_rating_id')->nullable()->after('verified_level')
                ->constrained('competency_ratings', indexName: 'comp_actions_rating_fk')->nullOnDelete();
            $table->foreignId('evaluated_by')->nullable()->after('competency_rating_id')
                ->constrained('users', indexName: 'comp_actions_evaluator_fk')->nullOnDelete();
            $table->date('evaluated_on')->nullable()->after('evaluated_by');
        });
    }

    public function down(): void
    {
        Schema::table('competency_development_actions', function (Blueprint $table) {
            // Dropped by the names they were created with.
            $table->dropForeign('comp_actions_rating_fk');
            $table->dropForeign('comp_actions_evaluator_fk');
            $table->dropColumn(['competency_rating_id', 'evaluated_by']);
            $table->dropColumn(['effectiveness_result', 'verified_level', 'evaluated_on']);
        });
    }
};
