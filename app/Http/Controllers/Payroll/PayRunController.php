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
        $run = $this->runs->calculate($payRun, $request->user());

        return ApiResponse::success($this->detail($run, $request), "Calculated {$run->totals['employees']} payslip(s).");
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

    public function payslips(Request $request, PayRun $payRun): JsonResponse
    {
        $page = $payRun->payslips()
            ->when($request->search, fn ($q, $v) => $q->where(fn ($w) => $w->where('employee_snapshot->name', 'like', "%{$v}%")->orWhere('employee_snapshot->staff_id', 'like', "%{$v}%")))
            ->when($request->boolean('warnings'), fn ($q) => $q->whereNotNull('warnings')->where('warnings', '!=', '[]'))
            ->orderBy('employee_snapshot->name')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success([
            'data' => collect($page->items())->map(fn (Payslip $p) => [
                'uuid'             => $p->uuid,
                'employee'         => $p->employee_snapshot,
                'gross_pay'        => (float) $p->gross_pay,
                'total_deductions' => (float) $p->total_deductions,
                'net_pay'          => (float) $p->net_pay,
                'proration'        => (float) $p->proration,
                'warnings'         => $p->warnings ?? [],
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
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
            'can'            => [
                'prepare' => $user->can('prepare-payroll'),
                'decide'  => $approval && $this->approvals->canDecide($approval, $user),
            ],
        ];
    }
}
