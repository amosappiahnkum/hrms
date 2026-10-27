<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Departments get a level (an index into the setting `org.department_levels`, e.g. Directorate, Department,
 * Section, Unit), used to label the tree and to stop approval chains.
 *
 * Sub-units become sub-departments: for every (department, sub-unit) pair in use on job details, a child department
 * named after the sub-unit is made under that department (or an existing child with that name is used) and those
 * employees move into it. Each move is logged in `sub_unit_moves`, so it can be checked and undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->nullable()->after('parent_department_id');
        });

        Schema::create('sub_unit_moves', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sub_unit_id');
            $table->unsignedBigInteger('from_department_id');
            $table->unsignedBigInteger('to_department_id');
            // Made by this migration (removed again on rollback), or an existing child that was reused.
            $table->boolean('created_department')->default(false);
            $table->json('employee_ids');
            $table->timestamps();
            $table->softDeletes();
        });

        $rows = DB::table('job_details')->join('employees', 'employees.id', '=', 'job_details.employee_id')
            ->whereNotNull('job_details.sub_unit_id')->whereNotNull('employees.department_id')->whereNull('employees.deleted_at')
            ->get(['employees.id as employee_id', 'employees.department_id', 'job_details.sub_unit_id']);

        foreach ($rows->groupBy(fn ($r) => "{$r->department_id}:{$r->sub_unit_id}") as $group) {
            $first = $group->first();
            $unit = DB::table('sub_units')->where('id', $first->sub_unit_id)->value('name');
            $parent = DB::table('departments')->where('id', $first->department_id)->first(['id', 'name', 'level']);
            if (!$unit || !$parent) {
                continue;
            }

            $child = DB::table('departments')->where('parent_department_id', $parent->id)->whereNull('deleted_at')->where('name', $unit)->value('id');
            $created = !$child;
            if ($created) {
                // Names are unique across departments: "ICT Unit (Finance)" when another department has the name.
                $name = DB::table('departments')->whereNull('deleted_at')->where('name', $unit)->exists() ? "{$unit} ({$parent->name})" : $unit;
                $child = DB::table('departments')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'name' => $name, 'parent_department_id' => $parent->id,
                    'level' => $parent->level !== null ? $parent->level + 1 : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $ids = $group->pluck('employee_id')->unique()->values()->all();
            DB::table('employees')->whereIn('id', $ids)->update(['department_id' => $child]);
            DB::table('sub_unit_moves')->insert([
                'uuid' => (string) Str::uuid(), 'sub_unit_id' => $first->sub_unit_id, 'from_department_id' => $parent->id,
                'to_department_id' => $child, 'created_department' => $created, 'employee_ids' => json_encode($ids),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('sub_unit_moves')->orderByDesc('id')->get() as $move) {
            DB::table('employees')->whereIn('id', json_decode($move->employee_ids, true) ?: [])->where('department_id', $move->to_department_id)
                ->update(['department_id' => $move->from_department_id]);
            if ($move->created_department && !DB::table('employees')->where('department_id', $move->to_department_id)->exists()) {
                DB::table('departments')->where('id', $move->to_department_id)->delete();
            }
        }
        Schema::dropIfExists('sub_unit_moves');
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('level'));
    }
};
