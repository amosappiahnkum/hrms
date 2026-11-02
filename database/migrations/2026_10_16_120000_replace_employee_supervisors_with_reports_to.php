<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One supervisor per employee: "Reports to" becomes a column on the employee instead of the employee_supervisors
 * table (which allowed several, and kept In-Direct lines nothing used). Each employee's current Direct line is
 * copied across; changes are kept in the activity log from now on. Empty means the head of their unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('reports_to_id')->nullable()->after('department_id')
                ->constrained('employees', indexName: 'employees_reports_to_fk')->nullOnDelete();
        });

        if (Schema::hasTable('employee_supervisors')) {
            // The latest Direct line in force for each employee (not to themselves, nor to someone removed).
            $lines = DB::table('employee_supervisors as s')
                ->join('employees as boss', 'boss.id', '=', 's.supervisor_id')
                ->whereNull('s.deleted_at')->whereNull('boss.deleted_at')
                ->where('s.report_method', 'Direct')->whereColumn('s.supervisor_id', '!=', 's.employee_id')
                ->orderBy('s.id')->get(['s.employee_id', 's.supervisor_id'])
                ->keyBy('employee_id'); // later rows win
            foreach ($lines as $line) {
                DB::table('employees')->where('id', $line->employee_id)->update(['reports_to_id' => $line->supervisor_id]);
            }
            Schema::drop('employee_supervisors');
        }
    }

    public function down(): void
    {
        Schema::create('employee_supervisors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->foreignId('supervisor_id')->constrained('employees');
            $table->foreignId('employee_id')->constrained('employees');
            $table->enum('report_method', ['Direct', 'In-Direct'])->default('Direct');
            $table->softDeletes();
            $table->timestamps();
        });
        DB::table('employees')->whereNotNull('reports_to_id')->orderBy('id')->get(['id', 'reports_to_id'])
            ->each(fn ($e) => DB::table('employee_supervisors')->insert([
                'uuid' => (string) Str::uuid(), 'supervisor_id' => $e->reports_to_id, 'employee_id' => $e->id,
                'report_method' => 'Direct', 'created_at' => now(), 'updated_at' => now(),
            ]));

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('employees_reports_to_fk');
            $table->dropColumn('reports_to_id');
        });
    }
};
