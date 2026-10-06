<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\ArrayExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PayRun;
use App\Services\Payroll\PayrollAnalytics;
use App\Services\Payroll\PayrollAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The payroll dashboard and the year's reports. */
class PayrollReportController extends Controller
{
    public const ANNUAL = ['ytd' => 'Year to date per employee', 'paye' => 'PAYE by month (annual return)', 'ssnit' => 'SSNIT by month'];

    public function __construct(private readonly PayrollAnalytics $analytics)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $run = $request->filled('run') ? PayRun::where('uuid', $request->run)->where('type', 'regular')->firstOrFail() : null;

        return ApiResponse::success($this->analytics->dashboard($run) + [
            'runs' => PayRun::where('type', 'regular')->whereIn('status', ['approved', 'paid'])->orderByDesc('year')->orderByDesc('month')->limit(24)
                ->get(['uuid', 'name'])->map(fn ($r) => ['uuid' => $r->uuid, 'name' => $r->name]),
        ]);
    }

    /** Each employee's year so far. */
    public function yearToDate(Request $request): JsonResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];

        return ApiResponse::success([
            'rows'    => $this->analytics->yearToDate($year)->map(fn ($r) => collect($r)->except('employee_id')->all()),
            'reports' => collect(self::ANNUAL)->map(fn ($label, $key) => compact('key', 'label'))->values(),
        ]);
    }

    public function annual(Request $request, string $report): BinaryFileResponse
    {
        abort_unless(array_key_exists($report, self::ANNUAL), 404);
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        [$headings, $rows, $title] = $this->analytics->annual($year, $report);

        return Excel::download(new ArrayExport($headings, $rows, $title), Str::slug($title) . '.xlsx');
    }

    /** Every change to payroll data, newest first. */
    public function audit(Request $request, PayrollAudit $audit): JsonResponse
    {
        $page = $audit->page($this->auditFilters($request), min((int) $request->input('per_page', 25), 100));

        return ApiResponse::success([
            'data'     => $page->items(),
            'meta'     => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'subjects' => collect(PayrollAudit::SUBJECTS)->map(fn ($label, $class) => ['value' => class_basename($class), 'label' => $label])->values(),
        ]);
    }

    public function auditExport(Request $request, PayrollAudit $audit): BinaryFileResponse
    {
        $rows = $audit->rows($this->auditFilters($request));

        return Excel::download(new ArrayExport(['When', 'By', 'What', 'Change', 'Record', 'Employee', 'Details'], $rows, 'Payroll audit'), 'payroll-audit-' . now()->format('Y-m-d') . '.xlsx');
    }

    private function auditFilters(Request $request): array
    {
        return $request->validate([
            'subject' => ['nullable', 'string', \Illuminate\Validation\Rule::in(collect(PayrollAudit::SUBJECTS)->keys()->map(fn ($c) => class_basename($c)))],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'user'    => ['nullable', 'uuid'],
        ]);
    }
}
