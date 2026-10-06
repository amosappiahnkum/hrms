<?php

namespace App\Http\Controllers\Payroll;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Loan;
use App\Models\Payroll\LoanType;
use App\Models\SelfService\Employee;
use App\Services\Payroll\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Self-service: the employee's loans, what they can borrow, and requesting one. */
class MyLoanController extends Controller
{
    public function __construct(private readonly LoanService $loans)
    {
    }

    /** The loan types with this employee's limits on each. */
    public function options(Request $request): JsonResponse
    {
        $employee = $this->me($request);

        return ApiResponse::success([
            'currency' => setting('payroll.base_currency', 'GHS'),
            'types'    => LoanType::where('active', true)->orderBy('name')->get()->map(fn (LoanType $t) => LoanTypeController::row($t) + [
                'limits' => $this->loans->limits($employee, $t),
            ])->values(),
        ]);
    }

    /** Check an amount and period before requesting: problems, and the repayments it would mean. */
    public function check(Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        $employee = $this->me($request);
        $type = LoanType::where('uuid', $data['loan_type_uuid'])->firstOrFail();
        $amount = (float) ($data['amount'] ?? 0);
        $tenor = (int) ($data['tenor_months'] ?? 0);
        $schedule = $amount > 0 && $tenor >= 1 && $tenor <= 120
            ? $this->loans->schedule($amount, $tenor, $type->interest_method, (float) $type->interest_rate, now()->addMonth())
            : [];

        return ApiResponse::success([
            'problems'      => $this->loans->problems($employee, $type, $amount, $tenor, null) ?: [],
            'monthly'       => $schedule[0]['amount'] ?? null,
            'total_interest'=> $schedule ? round(array_sum(array_column($schedule, 'interest')), 2) : null,
            'total'         => $schedule ? round(array_sum(array_column($schedule, 'amount')), 2) : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $loans = Loan::where('employee_id', $this->me($request)->id)
            ->with(['type', 'approval.decisions.decider', 'instalments', 'repayments.payRun', 'guarantor'])
            ->latest('id')->get();

        return ApiResponse::success($loans->map(fn ($l) => LoanController::detail($l))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $loan = $this->loans->request($this->me($request), $this->validated($request, true), $request->user());

        return ApiResponse::success(LoanController::detail($loan->load(['type', 'approval.decisions.decider', 'instalments', 'guarantor'])), 'Loan request sent for approval.', 201);
    }

    public function cancel(Request $request, Loan $loan): JsonResponse
    {
        abort_unless($loan->employee_id === $this->me($request)->id, 404);
        if ($loan->status !== 'pending') {
            throw new \App\Exceptions\UserFacingException('Only a pending request can be withdrawn.');
        }
        $this->loans->cancel($loan);

        return ApiResponse::success(null, 'Request withdrawn.');
    }

    private function validated(Request $request, bool $sending): array
    {
        return $request->validate([
            'loan_type_uuid' => ['required', 'uuid'],
            'amount'         => [$sending ? 'required' : 'nullable', 'numeric', 'min:1'],
            'tenor_months'   => [$sending ? 'required' : 'nullable', 'integer', 'min:1', 'max:120'],
            'purpose'        => ['nullable', 'string', 'max:1000'],
            'guarantor_uuid' => ['nullable', 'uuid'],
        ]);
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::find($request->user()->employee_id);
        abort_unless($employee, 404, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
