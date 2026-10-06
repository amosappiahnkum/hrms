<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\ArrayExport;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Loan;
use App\Models\Payroll\LoanInstalment;
use App\Services\Payroll\LoanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** HR's loans register: paying out, repayments, settlement, pausing an instalment. */
class LoanController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'active', 'settled'];

    public function __construct(private readonly LoanService $loans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered($request)->with(['employee', 'type', 'instalments'])->latest('id')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return ApiResponse::success([
            'data'    => collect($page->items())->map(fn ($l) => self::row($l))->values(),
            'meta'    => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'summary' => [
                'to_pay_out'  => Loan::where('status', 'approved')->count(),
                'active'      => Loan::where('status', 'active')->count(),
                'outstanding' => round((float) \App\Models\Payroll\LoanInstalment::where('status', 'due')->whereHas('loan', fn ($q) => $q->where('status', 'active'))->sum('amount'), 2),
            ],
        ]);
    }

    public function show(Loan $loan): JsonResponse
    {
        return ApiResponse::success(self::detail($loan->load(['employee', 'type', 'approval.decisions.decider', 'instalments.payRun', 'repayments.payRun', 'guarantor'])) + [
            'settlement_amount' => $loan->status === 'active' ? $this->loans->settlementAmount($loan) : null,
        ]);
    }

    public function disburse(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'disbursed_on' => ['required', 'date'],
            'method'       => ['required', Rule::in(['bank', 'cash', 'mobile_money', 'payroll'])],
            'reference'    => ['nullable', 'string', 'max:100'],
            'first_year'   => ['required', 'integer', 'between:2000,2100'],
            'first_month'  => ['required', 'integer', 'between:1,12'],
        ]);
        $this->loans->disburse($loan, $data, $request->user());

        return $this->show($loan->fresh())->setStatusCode(200);
    }

    public function repay(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'paid_on'   => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);
        $this->loans->repay($loan, (float) $data['amount'], $data['paid_on'], $data['reference'] ?? null, $data['notes'] ?? null, $request->user());

        return $this->show($loan->fresh());
    }

    public function settle(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate(['paid_on' => ['required', 'date', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:100']]);
        $this->loans->settle($loan, $data['paid_on'], $data['reference'] ?? null, $request->user());

        return $this->show($loan->fresh());
    }

    public function pause(Loan $loan, LoanInstalment $loanInstalment): JsonResponse
    {
        abort_unless($loanInstalment->loan_id === $loan->id, 404);
        $this->loans->pause($loanInstalment);

        return $this->show($loan->fresh());
    }

    public function cancel(Loan $loan): JsonResponse
    {
        $this->loans->cancel($loan);

        return ApiResponse::success(null, 'Loan called off.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $rows = $this->filtered($request)->with(['employee', 'type', 'instalments'])->orderBy('id')->get()->map(function (Loan $l) {
            $r = self::row($l);

            return [$l->employee?->staff_id, $r['employee']['name'] ?? null, $l->type?->name, ucfirst($l->status), (float) $l->amount, $l->tenor_months,
                $l->disbursed_on?->toDateString(), $r['monthly'], $r['balance'], $l->settled_on?->toDateString()];
        })->all();

        return Excel::download(new ArrayExport(['Staff ID', 'Name', 'Type', 'Status', 'Amount', 'Months', 'Paid out', 'Monthly', 'Balance', 'Settled'], $rows, 'Loans'), 'loans-' . now()->format('Y-m-d') . '.xlsx');
    }

    private function filtered(Request $request): Builder
    {
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'type' => ['nullable', 'uuid']]);

        return Loan::query()
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->type, fn ($q, $v) => $q->whereHas('type', fn ($t) => $t->where('uuid', $v)))
            ->when($request->search, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$v}%")->orWhere('last_name', 'like', "%{$v}%")->orWhere('staff_id', 'like', "%{$v}%"))));
    }

    /** A loan in a list. */
    public static function row(Loan $l): array
    {
        $due = $l->relationLoaded('instalments') ? $l->instalments->where('status', 'due') : collect();

        return [
            'uuid'             => $l->uuid,
            'employee'         => $l->relationLoaded('employee') && $l->employee
                ? ['uuid' => $l->employee->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $l->employee->name)), 'staff_id' => $l->employee->staff_id]
                : null,
            'type'             => $l->type ? ['uuid' => $l->type->uuid, 'name' => $l->type->name] : null,
            'amount_requested' => (float) $l->amount_requested,
            'amount'           => (float) $l->amount,
            'tenor_months'     => $l->tenor_months,
            'status'           => $l->status,
            'interest'         => ['method' => $l->interest_method, 'rate' => (float) $l->interest_rate],
            'monthly'          => $due->isNotEmpty() ? (float) $due->first()->amount : null,
            'balance'          => in_array($l->status, ['active', 'settled'], true) ? round((float) $due->sum('amount'), 2) : null,
            'next_due'         => $due->isNotEmpty() ? $due->first()->period()->format('Y-m') : null,
            'disbursed_on'     => $l->disbursed_on?->toDateString(),
            'settled_on'       => $l->settled_on?->toDateString(),
            'created_at'       => $l->created_at?->toIso8601String(),
        ];
    }

    /** A loan with its approval, schedule and repayments (the statement). */
    public static function detail(Loan $l): array
    {
        return self::row($l) + [
            'purpose'             => $l->purpose,
            'guarantor'           => $l->guarantor ? ['uuid' => $l->guarantor->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $l->guarantor->name))] : null,
            'disbursement_method' => $l->disbursement_method,
            'total_repayable'     => $l->total_repayable !== null ? (float) $l->total_repayable : null,
            'approval'            => $l->approval ? ApprovalController::summary($l->approval) : null,
            'instalments'         => $l->instalments->map(fn (LoanInstalment $i) => [
                'uuid' => $i->uuid, 'number' => $i->number, 'period' => $i->period()->format('Y-m'),
                'principal' => (float) $i->principal, 'interest' => (float) $i->interest, 'amount' => (float) $i->amount,
                'status' => $i->status, 'in_run' => $i->relationLoaded('payRun') && $i->payRun && $i->status === 'due' ? $i->payRun->name : null,
            ])->values(),
            'repayments'          => $l->relationLoaded('repayments') ? $l->repayments->map(fn ($r) => [
                'uuid' => $r->uuid, 'amount' => (float) $r->amount, 'paid_on' => $r->paid_on->toDateString(), 'source' => $r->source,
                'reference' => $r->reference, 'notes' => $r->notes, 'run' => $r->payRun?->name,
            ])->values() : [],
        ];
    }
}
