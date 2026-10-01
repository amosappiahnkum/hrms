<?php

namespace App\Http\Controllers\Competency;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SelfService\Employee;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** An employee's competency profile: requirements vs current levels, gaps, actions and history. */
class EmployeeCompetencyController extends Controller
{
    public function __construct(private readonly CompetencyService $service)
    {
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->service->canSee($request->user(), $employee), 403);

        return ApiResponse::success($this->service->profile($employee, $request->user()));
    }

    /** Self-service: the signed-in employee's own profile. */
    public function mine(Request $request): JsonResponse
    {
        $employee = Employee::find($request->user()->employee_id);
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return ApiResponse::success($this->service->profile($employee));
    }
}
