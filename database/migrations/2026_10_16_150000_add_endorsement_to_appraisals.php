<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appraisals endorsed by the heads above the appraiser before HR: a new status between the employee's
 * acknowledgment and HR (pending_endorsement), and the endorsement's events.
 */
return new class extends Migration
{
    private const STATUSES = ['draft', 'pending_supervisor', 'pending_employee_acknowledgment', 'supervisor_confirmed', 'completed', 'returned', 'submitted', 'pending_signatures'];
    private const EVENTS = ['submitted', 'returned', 'supervisor_confirmed', 'hr_completed', 'answers_edited', 'employee_disagreed', 'employee_acknowledged', 'pending_signatures', 'supervisor_signed', 'employee_signed'];
    /** The endorsement's history: sent (with the heads), each head's endorsement, and a return to the appraiser. */
    private const ENDORSEMENT_EVENTS = ['sent_for_endorsement', 'endorsed', 'endorsement_returned'];

    public function up(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->enum('status', [...self::STATUSES, 'pending_endorsement'])->default('draft')->change();
        });
        Schema::table('assessment_attempt_events', function (Blueprint $table) {
            $table->enum('event_type', [...self::EVENTS, ...self::ENDORSEMENT_EVENTS])->change();
        });
    }

    public function down(): void
    {
        // Back to the appraiser's confirmation for any still being endorsed.
        DB::table('assessment_attempts')->where('status', 'pending_endorsement')->update(['status' => 'pending_supervisor']);
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->enum('status', self::STATUSES)->default('draft')->change();
        });
        DB::table('assessment_attempt_events')->whereIn('event_type', self::ENDORSEMENT_EVENTS)->delete();
        Schema::table('assessment_attempt_events', function (Blueprint $table) {
            $table->enum('event_type', self::EVENTS)->change();
        });
    }
};
