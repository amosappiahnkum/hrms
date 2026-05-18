<?php

namespace App\Http\Controllers;

use App\Models\Config\Department;
use App\Models\SelfService\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $base = Employee::query();

        if ($request->filled('department')) {
            $dept = Department::where('uuid', $request->department)->first();
            if ($dept) {
                $base->where('department_id', $dept->id);
            }
        }

        return response()->json([
            'data' => [
                'summary'         => $this->summary(clone $base, $year),
                'by_gender'       => $this->byGender(clone $base),
                'by_department'   => $this->byDepartment(clone $base),
                'by_rank'         => $this->byRank(clone $base),
                'by_job_category' => $this->byJobCategory(clone $base),
                'by_job_type'     => $this->byJobType(clone $base),
                'by_age_group'    => $this->byAgeGroup(clone $base),
                'by_tenure'       => $this->byTenure(clone $base),
                'staff_type'      => $this->staffType(clone $base),
                'headcount_trend' => $this->headcountTrend($year, $request),
            ],
        ]);
    }

    private function summary($query, int $year): array
    {
        $total = (clone $query)->count();

        $newHires = (clone $query)
            ->join('job_details', 'employees.id', '=', 'job_details.employee_id')
            ->whereYear('job_details.joined_date', $year)
            ->count();

        $terminatedThisYear = Employee::withTrashed()
            ->whereNotNull('termination_date')
            ->whereYear('termination_date', $year)
            ->count();

        $male   = (clone $query)->where('gender', 'Male')->count();
        $female = (clone $query)->where('gender', 'Female')->count();

        return [
            'total'                 => $total,
            'new_hires_this_year'   => $newHires,
            'terminated_this_year'  => $terminatedThisYear,
            'male'                  => $male,
            'female'                => $female,
            'gender_ratio'          => [
                'male'   => $total > 0 ? round($male / $total * 100, 1) : 0,
                'female' => $total > 0 ? round($female / $total * 100, 1) : 0,
            ],
        ];
    }

    private function byGender($query): array
    {
        $total = (clone $query)->count();

        $rows = (clone $query)
            ->selectRaw('COALESCE(gender, "Unspecified") as gender, COUNT(*) as count')
            ->groupBy('gender')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn($r) => [
            'gender'     => $r->gender,
            'count'      => (int) $r->count,
            'percentage' => $total > 0 ? round($r->count / $total * 100, 1) : 0,
        ])->all();
    }

    private function byDepartment($query): array
    {
        $total = (clone $query)->count();

        $rows = (clone $query)
            ->join('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('app_departments.uuid, app_departments.name, COUNT(app_employees.id) as count')
            ->groupBy('departments.id', 'departments.uuid', 'departments.name')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn($r) => [
            'id'         => $r->uuid,
            'name'       => $r->name,
            'count'      => (int) $r->count,
            'percentage' => $total > 0 ? round($r->count / $total * 100, 1) : 0,
        ])->all();
    }

    private function byRank($query): array
    {
        $rows = (clone $query)
            ->join('ranks', 'employees.rank_id', '=', 'ranks.id')
            ->selectRaw('app_ranks.uuid, app_ranks.name, COUNT(app_employees.id) as count')
            ->groupBy('ranks.id', 'ranks.uuid', 'ranks.name')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn($r) => [
            'id'    => $r->uuid,
            'name'  => $r->name,
            'count' => (int) $r->count,
        ])->all();
    }

    private function byJobCategory($query): array
    {
        $rows = (clone $query)
            ->join('job_details', 'employees.id', '=', 'job_details.employee_id')
            ->join('job_categories', 'job_details.job_category_id', '=', 'job_categories.id')
            ->selectRaw('app_job_categories.uuid, app_job_categories.name, COUNT(app_employees.id) as count')
            ->groupBy('job_categories.id', 'job_categories.uuid', 'job_categories.name')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn($r) => [
            'id'    => $r->uuid,
            'name'  => $r->name,
            'count' => (int) $r->count,
        ])->all();
    }

    private function byJobType($query): array
    {
        $rows = (clone $query)
            ->selectRaw('COALESCE(job_type, "Unspecified") as job_type, COUNT(*) as count')
            ->groupBy('job_type')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn($r) => [
            'job_type' => $r->job_type,
            'count'    => (int) $r->count,
        ])->all();
    }

    private function byAgeGroup($query): array
    {
        $groups = [
            'Under 30' => 0,
            '30 – 39'  => 0,
            '40 – 49'  => 0,
            '50+'      => 0,
        ];

        $rows = (clone $query)
            ->whereNotNull('dob')
            ->selectRaw(
                "CASE
                    WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 30 THEN 'Under 30'
                    WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 40 THEN '30 – 39'
                    WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 50 THEN '40 – 49'
                    ELSE '50+'
                END as age_group,
                COUNT(*) as count"
            )
            ->groupBy('age_group')
            ->pluck('count', 'age_group');

        foreach ($rows as $group => $count) {
            $groups[$group] = (int) $count;
        }

        return collect($groups)->map(fn($count, $group) => [
            'group' => $group,
            'count' => $count,
        ])->values()->all();
    }

    private function byTenure($query): array
    {
        $groups = [
            '< 1 year'   => 0,
            '1 – 4 years' => 0,
            '5 – 9 years' => 0,
            '10+ years'  => 0,
        ];

        $rows = (clone $query)
            ->join('job_details', 'employees.id', '=', 'job_details.employee_id')
            ->whereNotNull('job_details.joined_date')
            ->selectRaw(
                "CASE
                    WHEN TIMESTAMPDIFF(YEAR, app_job_details.joined_date, CURDATE()) < 1  THEN '< 1 year'
                    WHEN TIMESTAMPDIFF(YEAR, app_job_details.joined_date, CURDATE()) < 5  THEN '1 – 4 years'
                    WHEN TIMESTAMPDIFF(YEAR, app_job_details.joined_date, CURDATE()) < 10 THEN '5 – 9 years'
                    ELSE '10+ years'
                END as tenure_group,
                COUNT(*) as count"
            )
            ->groupBy('tenure_group')
            ->pluck('count', 'tenure_group');

        foreach ($rows as $group => $count) {
            $groups[$group] = (int) $count;
        }

        return collect($groups)->map(fn($count, $group) => [
            'group' => $group,
            'count' => $count,
        ])->values()->all();
    }

    private function staffType($query): array
    {
        $row = (clone $query)->selectRaw(
            'SUM(senior_member)    as senior_member,
             SUM(senior_staff)     as senior_staff,
             SUM(junior_staff)     as junior_staff,
             SUM(secondment_staff) as secondment'
        )->first();

        return [
            'senior_member' => (int) ($row->senior_member ?? 0),
            'senior_staff'  => (int) ($row->senior_staff ?? 0),
            'junior_staff'  => (int) ($row->junior_staff ?? 0),
            'secondment'    => (int) ($row->secondment ?? 0),
        ];
    }

    private function headcountTrend(int $year, Request $request): array
    {
        $deptId = null;
        if ($request->filled('department')) {
            $dept = Department::where('uuid', $request->department)->first();
            $deptId = $dept?->id;
        }

        // New hires per month via job_details.joined_date
        $hires = Employee::query()
            ->join('job_details', 'employees.id', '=', 'job_details.employee_id')
            ->when($deptId, fn($q) => $q->where('employees.department_id', $deptId))
            ->whereYear('job_details.joined_date', $year)
            ->selectRaw('MONTH(app_job_details.joined_date) as month_num, COUNT(*) as count')
            ->groupBy('month_num')
            ->pluck('count', 'month_num');

        // Terminations per month via employees.termination_date (including soft-deleted)
        $terminations = Employee::withTrashed()
            ->when($deptId, fn($q) => $q->where('department_id', $deptId))
            ->whereNotNull('termination_date')
            ->whereYear('termination_date', $year)
            ->selectRaw('MONTH(termination_date) as month_num, COUNT(*) as count')
            ->groupBy('month_num')
            ->pluck('count', 'month_num');

        $monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        return collect(range(1, 12))->map(fn($m) => [
            'month'        => $monthNames[$m - 1],
            'new_hires'    => (int) ($hires[$m] ?? 0),
            'terminations' => (int) ($terminations[$m] ?? 0),
        ])->all();
    }
}
