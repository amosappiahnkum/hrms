<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\ArrayExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\OvertimeType;
use App\Models\SelfService\Employee;
use App\Services\Payroll\OvertimeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Overtime requests: employees' own (self-service), and everyone's for HR and payroll. */
class OvertimeRequestController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function __construct(private readonly OvertimeService $overtime)
    {
    }

    /** What the request form needs: the policy, the types, and whether the employee may claim. */
    public function options(Request $request): JsonResponse
    {
        // For an employee chosen by HR, or the signed-in employee; HR without an employee record sees the policy only.
        abort_if($request->filled('employee_uuid') && !$request->user()->hasAnyPermission(['view-overtime', 'configure-payroll', 'prepare-payroll']), 403);
        $employee = $request->filled('employee_uuid')
            ? Employee::where('uuid', $request->employee_uuid)->firstOrFail()
            : Employee::find($request->user()->employee_id);
        $reason = $employee ? $this->overtime->ineligibility($employee) : null;

        return ApiResponse::success([
            'eligible'           => !$reason,
            'ineligible_reason'  => $reason,
            'auto_type'          => (bool) setting('payroll.overtime_auto_type', true),
            'types'              => OvertimeType::where('active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($t) => ['uuid' => $t->uuid, 'name' => $t->name, 'applies_on' => $t->applies_on])->values(),
            'max_hours_per_day'  => (float) setting('payroll.overtime_max_hours_per_day', 12),
            'backdate_days'      => (int) setting('payroll.overtime_backdate_days', 31),
            'require_reason'     => (bool) setting('payroll.overtime_require_reason', true),
            'require_location'   => (bool) setting('payroll.overtime_require_location', false),
            'locations'          => array_values((array) setting('payroll.overtime_locations', [])),
        ]);
    }

    /** The type a date would get (shown as the employee picks the date). */
    public function typeFor(Request $request): JsonResponse
    {
        $date = Carbon::parse($request->validate(['date' => ['required', 'date']])['date']);
        $type = $this->overtime->typeFor($date);

        return ApiResponse::success([
            'day'  => $this->overtime->dayKind($date),
            'type' => $type ? ['uuid' => $type->uuid, 'name' => $type->name] : null,
        ]);
    }

    /** The employee's own requests, by status, dates worked, type, and whether they've been paid; with the hours shown. */
    public function mine(Request $request): JsonResponse
    {
        $request->validate([
            'status'  => ['nullable', Rule::in(self::STATUSES)],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date', 'after_or_equal:from'],
            'type'    => ['nullable', 'uuid'],
            'payment' => ['nullable', Rule::in(['paid', 'unpaid'])],
        ]);
        $query = OvertimeRequest::where('employee_id', $this->me($request)->id)
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->when($request->type, fn ($q, $v) => $q->whereHas('type', fn ($t) => $t->withTrashed()->where('uuid', $v)))
            ->when($request->payment === 'paid', fn ($q) => $q->whereNotNull('pay_run_id'))
            ->when($request->payment === 'unpaid', fn ($q) => $q->where('status', 'approved')->whereNull('pay_run_id'));

        $summary = [
            'requests'        => (clone $query)->count(),
            'hours_requested' => (float) (clone $query)->whereIn('status', ['pending', 'approved'])->sum('hours_requested'),
            'hours_approved'  => (float) (clone $query)->where('status', 'approved')->sum('approved_hours'),
            'pending'         => (clone $query)->where('status', 'pending')->count(),
        ];
        $page = $query->with(['type', 'approval.decisions.decider', 'payRun'])
            ->latest('work_date')->latest('id')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return ApiResponse::success([
            'data'    => collect($page->items())->map(fn ($r) => $this->row($r, true))->values(),
            'meta'    => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'summary' => $summary,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'work_date'          => ['required', 'date'],
            'hours'              => ['required', 'numeric', 'min:0.25', 'max:24'],
            'overtime_type_uuid' => [Rule::requiredIf(!setting('payroll.overtime_auto_type', true)), 'nullable', 'uuid'],
            'location'           => ['nullable', 'string', 'max:100'],
            'reason'             => ['nullable', 'string', 'max:1000'],
        ]);
        $overtime = $this->overtime->request($this->me($request), $data, $request->user());

        return ApiResponse::success($this->row($overtime->load(['type', 'approval.decisions.decider']), true), 'Overtime sent for approval.', 201);
    }

    /** HR records overtime for an employee; it still goes through the employee's workflow. */
    public function storeFor(Request $request): JsonResponse
    {
        $employee = Employee::where('uuid', $request->validate(['employee_uuid' => ['required', 'uuid']])['employee_uuid'])->firstOrFail();
        $data = $request->validate([
            'work_date'          => ['required', 'date'],
            'hours'              => ['required', 'numeric', 'min:0.25', 'max:24'],
            'overtime_type_uuid' => [Rule::requiredIf(!setting('payroll.overtime_auto_type', true)), 'nullable', 'uuid'],
            'location'           => ['nullable', 'string', 'max:100'],
            'reason'             => ['nullable', 'string', 'max:1000'],
        ]);
        $overtime = $this->overtime->request($employee, $data, $request->user());

        return ApiResponse::success($this->row($overtime->load(['employee', 'type', 'approval.decisions.decider']), true), 'Overtime sent for approval.', 201);
    }

    public function cancel(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        abort_unless($overtimeRequest->employee_id === $this->me($request)->id, 404);
        $this->overtime->cancel($overtimeRequest);

        return ApiResponse::success(null, 'Request withdrawn.');
    }

    /** Everyone's requests, for HR and payroll. */
    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered($request)->with(['employee', 'type', 'approval.decisions.decider', 'payRun'])
            ->latest('work_date')->latest('id')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return ApiResponse::success([
            'data'    => collect($page->items())->map(fn ($r) => $this->row($r, true))->values(),
            'meta'    => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'summary' => [
                'pending_count'  => OvertimeRequest::where('status', 'pending')->count(),
                'approved_hours' => (float) $this->filtered($request)->where('status', 'approved')->sum('approved_hours'),
            ],
        ]);
    }

    /** The filtered requests as a spreadsheet: how approved hours reach payroll when payroll is run elsewhere. */
    public function export(Request $request): BinaryFileResponse
    {
        $rows = $this->filtered($request)->with(['employee', 'type.component', 'payRun'])->orderBy('work_date')->get()
            ->map(fn (OvertimeRequest $r) => [
                $r->employee?->staff_id, trim(preg_replace('/\s+/', ' ', (string) $r->employee?->name)), $r->work_date->toDateString(),
                $r->type?->name, $r->type?->component?->code, (float) $r->hours_requested, (float) $r->approved_hours,
                $r->location, $r->reason, ucfirst($r->status), $r->payRun?->name,
            ])->all();

        return Excel::download(new ArrayExport(
            ['Staff ID', 'Name', 'Date', 'Type', 'Component', 'Hours requested', 'Hours approved', 'Location', 'Reason', 'Status', 'Paid in'],
            $rows,
        ), 'overtime-' . now()->format('Y-m-d') . '.xlsx');
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'from'   => ['nullable', 'date'],
            'to'     => ['nullable', 'date'],
            'unpaid' => ['nullable', 'boolean'],
        ]);

        return OvertimeRequest::query()
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->when($request->boolean('unpaid'), fn ($q) => $q->whereNull('pay_run_id'))
            ->when($request->search, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%"))));
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::find($request->user()->employee_id);
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return $employee;
    }

    public static function row(OvertimeRequest $r, bool $withApproval = false): array
    {
        return [
            'uuid'            => $r->uuid,
            'employee'        => $r->relationLoaded('employee') && $r->employee
                ? ['uuid' => $r->employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $r->employee->name)), 'staff_id' => $r->employee->staff_id]
                : null,
            'type'            => $r->type ? ['uuid' => $r->type->uuid, 'name' => $r->type->name] : null,
            'work_date'       => $r->work_date->toDateString(),
            'hours_requested' => (float) $r->hours_requested,
            'approved_hours'  => (float) $r->approved_hours,
            'location'        => $r->location,
            'reason'          => $r->reason,
            'status'          => $r->status,
            'paid_in'         => $r->payRun ? ['uuid' => $r->payRun->uuid, 'name' => $r->payRun->name] : null,
            'approval'        => $withApproval && $r->approval ? ApprovalController::summary($r->approval) : null,
            'created_at'      => $r->created_at?->toIso8601String(),
        ];
    }
}
