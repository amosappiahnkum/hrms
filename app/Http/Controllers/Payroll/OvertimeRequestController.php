<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\ArrayExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\WorkOrder;
use App\Models\Payroll\WorkSite;
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
            'locations'          => app(OvertimeService::class)->locations(),
            // before / after / both: requested before the work (FM-07), after it, or before and confirmed after.
            'mode'               => $this->overtime->mode(),
            'advance_days'       => (int) setting('payroll.overtime_advance_days', 30),
            // Kinds of day that need a request before the work; and whether hours worked are confirmed on the timesheet.
            'advance_kinds'      => $this->overtime->inAdvance() ? \App\Services\Payroll\WorkingDay::advanceKinds() : [],
            'confirm_on_timesheet' => feature('payroll.timesheets') && setting('payroll.time_source', 'manual') === 'timesheet',
            'sites'              => WorkSite::where('active', true)->orderBy('name')->get()
                ->map(fn ($s) => ['uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'is_offshore' => $s->is_offshore])->values(),
            'work_orders'        => WorkOrder::with('client')->where('active', true)->orderBy('number')->get()
                ->map(fn ($w) => ['uuid' => $w->uuid, 'number' => $w->number, 'description' => $w->description, 'client' => $w->client?->name])->values(),
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
        $page = $query->with(['type', 'addonType', 'approval.decisions.decider', 'group.approval.decisions.decider', 'group.requests', 'payRun', 'workOrder.client'])
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
            // One date (work_date, hours), or several with the same details (dates).
            'work_date'          => ['required_without:dates', 'nullable', 'date'],
            'hours'              => ['required_without:dates', 'nullable', 'numeric', 'min:0.25', 'max:24'],
            'dates'              => ['nullable', 'array', 'min:2', 'max:31'],
            'dates.*.date'       => ['required', 'date'],
            'dates.*.hours'      => ['required', 'numeric', 'min:0.25', 'max:24'],
            'overtime_type_uuid' => [Rule::requiredIf(!setting('payroll.overtime_auto_type', true)), 'nullable', 'uuid'],
            'addon_type_uuid'    => ['nullable', 'uuid'],
            'location'             => ['nullable', 'string', 'max:100'],
            'work_site_uuid'       => ['nullable', 'uuid'],
            'work_order_uuid'      => ['nullable', 'uuid'],
            'activity_description' => ['nullable', 'string', 'max:1000'],
            'reason'               => ['nullable', 'string', 'max:1000'],
        ]);
        return $this->send($this->me($request), $data, $request);
    }

    /** HR records overtime for an employee; it still goes through the employee's workflow. */
    public function storeFor(Request $request): JsonResponse
    {
        $employee = Employee::where('uuid', $request->validate(['employee_uuid' => ['required', 'uuid']])['employee_uuid'])->firstOrFail();
        $data = $request->validate([
            // One date (work_date, hours), or several with the same details (dates).
            'work_date'          => ['required_without:dates', 'nullable', 'date'],
            'hours'              => ['required_without:dates', 'nullable', 'numeric', 'min:0.25', 'max:24'],
            'dates'              => ['nullable', 'array', 'min:2', 'max:31'],
            'dates.*.date'       => ['required', 'date'],
            'dates.*.hours'      => ['required', 'numeric', 'min:0.25', 'max:24'],
            'overtime_type_uuid' => [Rule::requiredIf(!setting('payroll.overtime_auto_type', true)), 'nullable', 'uuid'],
            'addon_type_uuid'    => ['nullable', 'uuid'],
            'location'             => ['nullable', 'string', 'max:100'],
            'work_site_uuid'       => ['nullable', 'uuid'],
            'work_order_uuid'      => ['nullable', 'uuid'],
            'activity_description' => ['nullable', 'string', 'max:1000'],
            'reason'               => ['nullable', 'string', 'max:1000'],
        ]);
        return $this->send($employee, $data, $request);
    }

    /** The employee confirms the hours they worked on overtime approved in advance. */
    public function confirmMine(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        abort_unless($overtimeRequest->employee_id === $this->me($request)->id, 404);
        // With pay from timesheets, the hours worked are confirmed on the timesheet.
        abort_if(feature('payroll.timesheets') && setting('payroll.time_source', 'manual') === 'timesheet', 422,
            'Confirm the hours worked on your timesheet.');

        return $this->confirm($request, $overtimeRequest);
    }

    /** HR or payroll confirm the hours worked (e.g. from the timesheet). */
    public function confirm(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $hours = (float) $request->validate(['actual_hours' => ['required', 'numeric', 'min:0', 'max:24']])['actual_hours'];
        $overtime = $this->overtime->confirmActual($overtimeRequest, $hours);

        return ApiResponse::success($this->row($overtime->load(['employee', 'type', 'addonType', 'approval.decisions.decider', 'payRun', 'workOrder.client']), true), 'Hours worked saved.');
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
        $page = $this->filtered($request)->with(['employee', 'type', 'addonType', 'approval.decisions.decider', 'group.approval.decisions.decider', 'group.requests', 'payRun', 'workOrder.client'])
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
        $rows = $this->filtered($request)->with(['employee', 'type.component', 'addonType.component', 'payRun', 'workOrder'])->orderBy('work_date')->get()
            ->map(fn (OvertimeRequest $r) => [
                $r->employee?->staff_id, trim(preg_replace('/\s+/', ' ', (string) $r->employee?->name)), $r->work_date->toDateString(),
                $r->typeLabel(), collect([$r->type?->component?->code, $r->addonType?->component?->code])->filter()->implode(' + '), (float) $r->hours_requested, (float) $r->approved_hours,
                $r->location, $r->workOrder?->number, $r->activity_description, $r->reason,
                $r->actual_hours !== null ? (float) $r->actual_hours : null, ucfirst($r->status), $r->payRun?->name,
            ])->all();

        return Excel::download(new ArrayExport(
            ['Staff ID', 'Name', 'Date', 'Type', 'Component', 'Hours requested', 'Hours approved', 'Location', 'PO / work order', 'Activities', 'Reason', 'Hours worked', 'Status', 'Paid in'],
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

    /** One date, or several (a group approved once): the request, or the group's first date, is returned. */
    private function send(Employee $employee, array $data, Request $request): JsonResponse
    {
        if (!empty($data['dates'])) {
            $group = $this->overtime->requestMany($employee, $data, $request->user());
            $first = $group->requests()->with(['employee', 'type', 'addonType', 'group.approval.decisions.decider', 'group.requests'])->first();

            return ApiResponse::success(self::row($first, true), count($data['dates']) . ' days of overtime sent for approval.', 201);
        }
        $overtime = $this->overtime->request($employee, $data, $request->user());

        return ApiResponse::success(self::row($overtime->load(['employee', 'type', 'addonType', 'approval.decisions.decider']), true), 'Overtime sent for approval.', 201);
    }

    public static function row(OvertimeRequest $r, bool $withApproval = false): array
    {
        // Requested with other dates: the group's approval is this date's.
        $approval = $r->overtime_request_group_id ? $r->group?->approval : $r->approval;

        return [
            'uuid'            => $r->uuid,
            'employee'        => $r->relationLoaded('employee') && $r->employee
                ? ['uuid' => $r->employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $r->employee->name)), 'staff_id' => $r->employee->staff_id]
                : null,
            'type'            => $r->type ? ['uuid' => $r->type->uuid, 'name' => $r->type->name] : null,
            // Paid on top for the same hours (e.g. offshore).
            'addon_type'      => $r->addonType ? ['uuid' => $r->addonType->uuid, 'name' => $r->addonType->name] : null,
            'work_date'       => $r->work_date->toDateString(),
            'hours_requested' => (float) $r->hours_requested,
            'approved_hours'  => (float) $r->approved_hours,
            'location'        => $r->location,
            'work_order'      => $r->work_order_id && $r->workOrder
                ? ['uuid' => $r->workOrder->uuid, 'number' => $r->workOrder->number, 'client' => $r->workOrder->client?->name]
                : null,
            'activity_description' => $r->activity_description,
            'reason'          => $r->reason,
            'requested_in_advance' => $r->requested_in_advance,
            // The supervisor's answers (FM-07); null until answered.
            'can_postpone'    => $r->can_postpone,
            'on_client_timesheet' => $r->on_client_timesheet,
            'actual_hours'    => $r->actual_hours !== null ? (float) $r->actual_hours : null,
            // Approved in advance and the work is done, but the hours worked aren't confirmed yet.
            'awaiting_actual' => $r->status === 'approved' && $r->requested_in_advance && $r->actual_hours === null && !$r->work_date->isFuture(),
            'status'          => $r->status,
            'paid_in'         => $r->payRun ? ['uuid' => $r->payRun->uuid, 'name' => $r->payRun->name] : null,
            'approval'        => $withApproval && $approval ? ApprovalController::summary($approval) : null,
            // Requested with other dates (approved and withdrawn together).
            'group'           => $r->overtime_request_group_id && $r->group ? [
                'uuid' => $r->group->uuid, 'days' => $r->group->requests->count(),
                'from' => $r->group->requests->min('work_date')?->toDateString(), 'to' => $r->group->requests->max('work_date')?->toDateString(),
            ] : null,
            'created_at'      => $r->created_at?->toIso8601String(),
        ];
    }
}
