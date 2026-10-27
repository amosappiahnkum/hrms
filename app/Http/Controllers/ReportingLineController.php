<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\SelfService\Employee;
use App\Services\Org\ReportingLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An employee's reporting line: who they report to (set, or by default the head of their unit), and who reports to
 * them. HR sets "Reports to" to override the default.
 */
class ReportingLineController extends Controller
{
    public function __construct(private readonly ReportingLine $line)
    {
    }

    /** HR, or the employee their own (in self-service: who they report to, and who reports to them). */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view-employee') || $user->employee_id === $employee->id, 403);

        return ApiResponse::success($this->present($employee));
    }

    /** Set who the employee reports to; empty returns them to the default (the head of their unit). */
    public function update(Request $request, Employee $employee): JsonResponse
    {
        $uuid = $request->validate(['supervisor_uuid' => ['nullable', 'uuid']])['supervisor_uuid'] ?? null;
        $supervisor = $uuid ? Employee::where('uuid', $uuid)->firstOrFail() : null;
        $this->line->setReportsTo($employee, $supervisor);
        activity('employees')->causedBy($request->user())->performedOn($employee)->event('reports_to_changed')
            ->withProperties(['reports_to' => $supervisor?->name])
            ->log($supervisor ? "{$employee->name} now reports to {$supervisor->name}" : "{$employee->name} reports to the head of their unit");

        return ApiResponse::success($this->present($employee->fresh()), $supervisor ? 'Reports to saved.' : 'Back to the head of their unit.');
    }

    private function present(Employee $employee): array
    {
        $set = $this->line->reportsTo($employee);
        $default = $this->line->unitHeadOf($employee);

        return [
            'reports_to'         => $set ? self::person($set) : null,
            'unit_head'          => $default ? self::person($default) : null,
            // Who approves "supervisor" steps, leads their appraisal and sees them in "my team".
            'supervisor'         => ($s = $set ?? $default) ? self::person($s) + ['by_default' => !$set] : null,
            'direct_reports'     => $this->line->reportsOf($employee)->map(fn (Employee $e) => self::person($e) + ['by_default' => (bool) $e->reports_by_default])->values(),
        ];
    }

    private static function person(Employee $e): array
    {
        return [
            'uuid' => $e->uuid, 'name' => trim(preg_replace('/\s+/', ' ', (string) $e->name)), 'staff_id' => $e->staff_id,
            'department' => $e->department?->name,
        ];
    }
}
