<?php

namespace App\Http\Controllers\Competency;

use App\Helpers\ApiResponse;
use App\Helpers\PdfLetterhead;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Http\Controllers\Controller;
use App\Models\SelfService\Employee;
use App\Services\Competency\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    /** The development record as a PDF: one document answering "competent, authorized, and what's being done". */
    public function record(Request $request, Employee $employee): Response
    {
        abort_unless($this->service->canSee($request->user(), $employee), 403);

        return $this->pdf($employee);
    }

    /** Self-service: the signed-in employee's own development record. */
    public function myRecord(Request $request): Response
    {
        $employee = Employee::find($request->user()->employee_id);
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return $this->pdf($employee);
    }

    private function pdf(Employee $employee): Response
    {
        $letterhead = PdfLetterhead::get();

        return Pdf::loadView('competency.record', [
            'record'  => $this->service->profile($employee),
            'company' => $letterhead['company'],
            'logo'    => $letterhead['logo'],
        ])->setPaper('a4', 'portrait')
            ->download('development-record-' . str($employee->name)->slug() . '-' . now()->format('Y-m-d') . '.pdf');
    }
}
