<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Approval;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use App\Services\Payroll\ApprovalWorkflowService;
use App\Services\Payroll\PayRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pay runs: create, calculate, send for approval, mark paid; and their payslips. */
class PayRunController extends Controller
{
    public function __construct(
        private readonly PayRunService $runs,
        private readonly ApprovalWorkflowService $approvals,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $runs = PayRun::query()
            ->when($request->year, fn ($q, $v) => $q->where('year', $v))
            ->orderByDesc('year')->orderByDesc('month')->orderBy('type')
            ->paginate($request->integer('per_page', 24));

        return ApiResponse::success([
            'data' => collect($runs->items())->map(fn ($r) => $this->summary($r))->values(),
            'meta' => ['current_page' => $runs->currentPage(), 'per_page' => $runs->perPage(), 'total' => $runs->total(), 'last_page' => $runs->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year'     => ['required', 'integer', 'between:2000,2100'],
            'month'    => ['required', 'integer', 'between:1,12'],
            'type'     => ['sometimes', Rule::in(['regular', 'off_cycle'])],
            'pay_date' => ['nullable', 'date'],
        ]);
        $run = $this->runs->create($data['year'], $data['month'], $data['type'] ?? 'regular', $data['pay_date'] ?? null, $request->user());

        return ApiResponse::success($this->summary($run), 'Pay run created.', 201);
    }

    public function show(Request $request, PayRun $payRun): JsonResponse
    {
        return ApiResponse::success($this->detail($payRun, $request));
    }

    public function calculate(Request $request, PayRun $payRun): JsonResponse
    {
        $before = $payRun->totals ? $payRun->payslips()->pluck('employee_id') : null;
        $run = $this->runs->calculate($payRun, $request->user());

        // On a recalculation, say who came in and who dropped out.
        $message = "Calculated {$run->totals['employees']} payslip(s).";
        if ($before !== null) {
            $after = $run->payslips()->pluck('employee_id');
            $added = $after->diff($before)->count();
            $dropped = $before->diff($after)->count();
            if ($added || $dropped) {
                $message .= ' ' . implode(', ', array_filter([$added ? "{$added} added" : null, $dropped ? "{$dropped} no longer included" : null])) . ' since the last calculation.';
            }
        }

        return ApiResponse::success($this->detail($run, $request), $message);
    }

    /** Back to draft from approval (not once paid), to correct and recalculate. */
    public function reopen(Request $request, PayRun $payRun): JsonResponse
    {
        return ApiResponse::success($this->detail($this->runs->reopen($payRun, $request->user()), $request), 'Reopened. Recalculate it, then send it for approval again.');
    }

    public function submit(Request $request, PayRun $payRun): JsonResponse
    {
        $run = $this->runs->submit($payRun, $request->user());

        return ApiResponse::success($this->detail($run, $request), 'Sent for approval.');
    }

    public function markPaid(Request $request, PayRun $payRun): JsonResponse
    {
        $run = $this->runs->markPaid($payRun, $request->user());

        return ApiResponse::success($this->detail($run, $request), 'Marked as paid.');
    }

    public function destroy(PayRun $payRun): JsonResponse
    {
        if (!$payRun->status->isOpen()) {
            throw new UserFacingException('A pay run sent for approval can\'t be removed.');
        }
        app(\App\Services\Payroll\OvertimeService::class)->release($payRun);
        app(\App\Services\Payroll\TimeInputService::class)->release($payRun);
        app(\App\Services\Payroll\LoanService::class)->release($payRun);
        app(\App\Services\Payroll\ArrearsService::class)->release($payRun);
        $payRun->payslips()->delete();
        $payRun->delete();

        return ApiResponse::success(null, 'Pay run removed.');
    }

    /**
     * The run's payslips, filtered by what they were calculated with (department, payment, bank are as
     * at calculation), by a component they include, or by what needs a look; with totals for the filter.
     */
    public function payslips(Request $request, PayRun $payRun): JsonResponse
    {
        $request->validate([
            'department'     => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'bank'           => ['nullable', 'string', 'max:255'],
            'component'      => ['nullable', 'string', 'max:30'],
            'only'           => ['nullable', Rule::in(['warnings', 'part_month', 'negative'])],
            'sort'           => ['nullable', Rule::in(['name', 'net_desc', 'net_asc', 'gross_desc'])],
        ]);
        $none = '(none)';
        $query = $payRun->payslips()
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('employee_snapshot->name', 'like', "%{$v}%")->orWhere('employee_snapshot->staff_id', 'like', "%{$v}%")))
            ->when($request->department, fn ($q, $v) => $v === $none ? $q->whereNull('employee_snapshot->department') : $q->where('employee_snapshot->department', $v))
            ->when($request->payment_method, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($request->bank, fn ($q, $v) => $q->where('bank_name', $v))
            ->when($request->component, fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('code', $v)->where('amount', '!=', 0)))
            // "With warnings only" from before still works.
            ->when($request->only === 'warnings' || $request->boolean('warnings'), fn ($q) => $q->whereNotNull('warnings')->where('warnings', '!=', '[]'))
            ->when($request->only === 'part_month', fn ($q) => $q->where('proration', '<', 1))
            ->when($request->only === 'negative', fn ($q) => $q->where('net_pay', '<', 0));

        $totals = (clone $query)->selectRaw('count(*) as payslips, sum(gross_pay) as gross_pay, sum(total_deductions) as total_deductions, sum(net_pay) as net_pay')->first();
        $page = (match ($request->input('sort', 'name')) {
            'net_desc'   => $query->orderByDesc('net_pay'),
            'net_asc'    => $query->orderBy('net_pay'),
            'gross_desc' => $query->orderByDesc('gross_pay'),
            default      => $query->orderBy('employee_snapshot->name'),
        })->paginate($request->integer('per_page', 25));

        return ApiResponse::success([
            'data'    => collect($page->items())->map(fn (Payslip $p) => [
                'uuid'             => $p->uuid,
                'employee'         => $p->employee_snapshot,
                'payment_method'   => $p->payment_method,
                'gross_pay'        => (float) $p->gross_pay,
                'total_deductions' => (float) $p->total_deductions,
                'net_pay'          => (float) $p->net_pay,
                'proration'        => (float) $p->proration,
                'warnings'         => $p->warnings ?? [],
            ])->values(),
            'meta'    => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'totals'  => [
                'payslips'         => (int) $totals->payslips,
                'gross_pay'        => round((float) $totals->gross_pay, 2),
                'total_deductions' => round((float) $totals->total_deductions, 2),
                'net_pay'          => round((float) $totals->net_pay, 2),
            ],
            'filters' => $this->payslipFilters($payRun, $none),
        ]);
    }

    /** The choices worth offering for this run: only what its payslips actually have. */
    private function payslipFilters(PayRun $run, string $none): array
    {
        $slips = $run->payslips()->get(['id', 'employee_snapshot', 'payment_method', 'bank_name']);
        $methods = \App\Models\Payroll\EmployeePayProfile::PAYMENT_METHODS;

        return [
            'departments'     => $slips->map(fn ($p) => $p->employee_snapshot['department'] ?? null)->unique()->sort()->values()
                ->map(fn ($d) => ['value' => $d ?? $none, 'label' => $d ?? 'No department']),
            'payment_methods' => $slips->pluck('payment_method')->filter()->unique()->sort()->values()
                ->map(fn ($m) => ['value' => $m, 'label' => $methods[$m] ?? $m]),
            'banks'           => $slips->pluck('bank_name')->filter()->unique()->sort()->values(),
            'components'      => \App\Models\Payroll\PayslipLine::whereIn('payslip_id', $slips->pluck('id'))
                ->where('source', '!=', 'statutory')->where('code', '!=', \App\Models\Payroll\PayComponent::BASIC)->where('amount', '!=', 0)
                ->select('code', 'name')->distinct()->orderBy('name')->get()->map(fn ($l) => ['value' => $l->code, 'label' => $l->name]),
        ];
    }

    public function payslip(PayRun $payRun, Payslip $payslip): JsonResponse
    {
        abort_unless($payslip->pay_run_id === $payRun->id, 404);

        return ApiResponse::success(self::payslipDetail($payslip));
    }

    public static function payslipDetail(Payslip $p): array
    {
        $num = fn ($v) => $v === null ? null : (float) $v;

        return [
            'uuid'     => $p->uuid,
            'employee' => $p->employee_snapshot,
            'figures'  => collect(['proration', 'basic_salary', 'gross_pay', 'taxable_income', 'ssnit_employee', 'ssnit_employer', 'tier1', 'tier2', 'tier3', 'paye', 'total_deductions', 'net_pay', 'employer_cost'])
                ->mapWithKeys(fn ($f) => [$f => (float) $p->{$f}])->all(),
            'warnings' => $p->warnings ?? [],
            'lines'    => $p->lines->map(fn ($l) => [
                'code' => $l->code, 'name' => $l->name, 'kind' => $l->kind, 'source' => $l->source,
                'quantity' => $num($l->quantity), 'rate' => $num($l->rate), 'amount' => (float) $l->amount,
                'original_amount' => $num($l->original_amount), 'original_currency' => $l->original_currency, 'exchange_rate' => $num($l->exchange_rate),
                'taxable' => $l->taxable, 'show_on_payslip' => $l->show_on_payslip,
            ])->values(),
        ];
    }

    private function summary(PayRun $r): array
    {
        return [
            'uuid'          => $r->uuid,
            'name'          => $r->name,
            'year'          => $r->year,
            'month'         => $r->month,
            'type'          => $r->type,
            'period_start'  => $r->period_start->toDateString(),
            'period_end'    => $r->period_end->toDateString(),
            'pay_date'      => $r->pay_date?->toDateString(),
            'status'        => ['value' => $r->status->value, 'label' => $r->status->label()],
            'totals'        => $r->totals,
            'calculated_at' => $r->calculated_at?->toIso8601String(),
        ];
    }

    private function detail(PayRun $run, Request $request): array
    {
        $run->loadMissing(['rateSet', 'preparer', 'approval.decisions.decider']);
        $approval = $run->approval;
        $user = $request->user();

        return $this->summary($run) + [
            'rate_set'       => $run->rateSet ? ['uuid' => $run->rateSet->uuid, 'name' => $run->rateSet->name] : null,
            'exchange_rates' => $run->exchange_rates ?? [],
            'prepared_by'    => $run->preparer?->name,
            'approved_at'    => $run->approved_at?->toIso8601String(),
            'paid_at'        => $run->paid_at?->toIso8601String(),
            'approval'       => $approval ? ApprovalController::summary($approval) : null,
            // Who this month's run doesn't pay and why (regular runs).
            'left_out'       => $this->runs->leftOut($run),
            'can'            => [
                'prepare' => $user->can('prepare-payroll'),
                'decide'  => $approval && $this->approvals->canDecide($approval, $user),
            ],
        ];
    }
}
