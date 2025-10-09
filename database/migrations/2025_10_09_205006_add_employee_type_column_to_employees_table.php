<?php

use App\Models\TerminationReason;
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
            $table->string('job_type')->nullable()->after('user_id');
            $table->foreignIdFor(TerminationReason::class)->nullable()->after('job_type')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('job_type');
            $table->dropForeign(['termination_reason_id']);
            $table->dropColumn('termination_reason_id');
        });
    }
};
