<?php

namespace App\Http\Controllers\Appraisal;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeKpiResource;
use App\Http\Resources\MiniEmployeeResource;
use App\Models\Appraisal\EmployeeKpi;
use App\Models\Appraisal\JobKpi;
use App\Models\SelfService\Employee;
use App\Services\Org\ReportingLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KPI plans: the targets agreed with an employee for a period, set ahead of the appraisal by their supervisor or HR.
 * The employee sees theirs all year; the appraisal for the period starts from it.
 */
class KpiPlanController extends Controller
{
    public function __construct(private readonly ReportingLine $line)
    {
    }

    /** The signed-in employee's own KPI plans, latest period first. */
    public function mine(Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee_id;

        return ApiResponse::success($employeeId ? $this->plans($employeeId) : []);
    }

    /** The signed-in supervisor's reports, each with their plan for the period (default: the one covering today). */
    public function team(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $me = $request->user()->employee;
        $date = CarbonImmutable::parse($request->input('date', now()));
        $reports = $me ? $this->line->reportsOf($me) : collect();

        $kpis = EmployeeKpi::whereIn('employee_id', $reports->pluck('id'))->overlapping($date, $date)
            ->orderBy('order')->get()->groupBy('employee_id');

        return ApiResponse::success($reports->values()->map(fn (Employee $e) => [
            'employee' => MiniEmployeeResource::make($e),
            'kpis'     => EmployeeKpiResource::collection($kpis->get($e->id, collect())),
        ]));
    }

    /** An employee's plans, and their department's KPI library to pick descriptions from. */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizePlanner($request, $employee);

        return ApiResponse::success([
            'employee' => MiniEmployeeResource::make($employee),
            'plans'    => $this->plans($employee->id),
            'library'  => JobKpi::libraryFor($employee->department_id),
        ]);
    }

    /** Replace an employee's plan for a period. */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizePlanner($request, $employee);

        $data = $request->validate([
            'period_start'       => ['required', 'date'],
            'period_end'         => ['required', 'date', 'after:period_start'],
            'kpis'               => ['present', 'array', 'max:50'],
            'kpis.*.description' => ['required', 'string', 'max:1000'],
            'kpis.*.target'      => ['required', 'numeric', 'min:0'],
        ]);

        $descriptions = array_map(fn ($k) => strtolower(trim($k['description'])), $data['kpis']);
        if (count($descriptions) !== count(array_unique($descriptions))) {
            return response()->json(['message' => 'Duplicate KPI descriptions are not allowed in the same plan.'], 422);
        }

        $from = CarbonImmutable::parse($data['period_start']);
        $to = CarbonImmutable::parse($data['period_end']);
        DB::transaction(fn () => EmployeeKpi::replacePlan($employee, $from, $to, $data['kpis'], $request->user()->id));

        activity('appraisals')->causedBy($request->user())->performedOn($employee)
            ->withProperties(['period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'kpis' => count($data['kpis'])])
            ->log("KPI plan set for {$employee->name}");

        return ApiResponse::success($this->plans($employee->id), 'KPI plan saved.');
    }

    /** HR, or the employee's supervisor. */
    private function authorizePlanner(Request $request, Employee $employee): void
    {
        $user = $request->user();
        if ($user->can('finalize-appraisals')) {
            return;
        }

        if (!$user->employee_id || $this->line->supervisorOf($employee)?->id !== $user->employee_id) {
            abort(403, 'Only their supervisor or HR can set this employee\'s KPIs.');
        }
    }

    /** Plans grouped by period, latest first. */
    private function plans(int $employeeId): Collection
    {
        return EmployeeKpi::with('setter')->where('employee_id', $employeeId)
            ->orderByDesc('period_start')->orderBy('order')->get()
            ->groupBy(fn (EmployeeKpi $k) => $k->period_start->toDateString() . '|' . $k->period_end->toDateString())
            ->map(fn (Collection $kpis) => [
                'period_start' => $kpis->first()->period_start->toDateString(),
                'period_end'   => $kpis->first()->period_end->toDateString(),
                'kpis'         => EmployeeKpiResource::collection($kpis),
            ])
            ->values();
    }
}
