<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\PayRunStatus;
use App\Exceptions\UserFacingException;
use App\Models\Payroll\Approval;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\Loan;
use App\Models\Payroll\LoanInstalment;
use App\Models\Payroll\LoanRepayment;
use App\Models\Payroll\LoanType;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\Payroll\Payslip;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff loans under each loan type's limits: checked, approved through the loan workflow, paid out,
 * repaid by monthly instalments deducted in pay runs (or paid by hand), and settled.
 */
class LoanService
{
    private const OWING = ['pending', 'approved', 'active'];

    public function __construct(private readonly ApprovalWorkflowService $approvals)
    {
    }

    /**
     * Monthly instalments from the first repayment month.
     * none: equal principal. flat: interest on the full amount for the whole term, spread evenly.
     * reducing_balance: equal payments, interest on what is still owed each month.
     *
     * @return array<array{number: int, year: int, month: int, principal: float, interest: float, amount: float}>
     */
    public function schedule(float $amount, int $tenor, string $method, float $rate, Carbon $first): array
    {
        $tenor = max(1, $tenor);
        $rows = [];
        $balance = round($amount, 2);

        if ($method === 'reducing_balance' && $rate > 0) {
            $r = $rate / 1200;
            $payment = round($amount * $r / (1 - (1 + $r) ** -$tenor), 2);
            for ($n = 1; $n <= $tenor; $n++) {
                $interest = round($balance * $r, 2);
                $principal = $n === $tenor ? $balance : min($balance, round($payment - $interest, 2));
                $balance = round($balance - $principal, 2);
                $rows[] = [$principal, $interest];
            }
        } else {
            $totalInterest = $method === 'flat' ? round($amount * $rate / 100 * $tenor / 12, 2) : 0.0;
            $principalEach = floor($amount / $tenor * 100) / 100;
            $interestEach = floor($totalInterest / $tenor * 100) / 100;
            for ($n = 1; $n <= $tenor; $n++) {
                $last = $n === $tenor;
                $rows[] = [
                    $last ? round($amount - $principalEach * ($tenor - 1), 2) : $principalEach,
                    $last ? round($totalInterest - $interestEach * ($tenor - 1), 2) : $interestEach,
                ];
            }
        }

        return collect($rows)->values()->map(function ($row, $i) use ($first) {
            $period = $first->copy()->startOfMonth()->addMonthsNoOverflow($i);

            return ['number' => $i + 1, 'year' => $period->year, 'month' => $period->month, 'principal' => $row[0], 'interest' => $row[1], 'amount' => round($row[0] + $row[1], 2)];
        })->all();
    }

    /** The most this employee may borrow on this type now, and why not when they can't. */
    public function limits(Employee $employee, LoanType $type): array
    {
        $problems = [];
        $max = $type->max_amount !== null ? (float) $type->max_amount : null;

        if ($type->max_times_basic !== null) {
            $profile = EmployeePayProfile::inForceOn(now())->where('employee_id', $employee->id)->first();
            $rate = $profile ? ExchangeRate::for($profile->currency ?: setting('payroll.base_currency', 'GHS'), now()->year) : null;
            if (!$profile) {
                $problems[] = 'Your pay details aren\'t set up yet, so the limit can\'t be worked out. Speak to HR.';
            } elseif ($rate === null) {
                $problems[] = "There's no {$profile->currency} exchange rate for " . now()->year . ' yet. Speak to HR.';
            } else {
                $byBasic = round((float) $type->max_times_basic * (float) $profile->basic_salary * $rate, 2);
                $max = $max === null ? $byBasic : min($max, $byBasic);
            }
        }

        if ($type->min_service_months) {
            $joined = $employee->jobDetail?->joined_date;
            if (!$joined || Carbon::parse($joined)->addMonths($type->min_service_months)->isFuture()) {
                $problems[] = "{$type->name} is available after {$type->min_service_months} months of service.";
            }
        }
        if ($type->max_active_loans) {
            $open = Loan::where('employee_id', $employee->id)->where('loan_type_id', $type->id)->whereIn('status', self::OWING)->count();
            if ($open >= $type->max_active_loans) {
                $problems[] = "You already have {$open} {$type->name}" . ($open === 1 ? '' : 's') . ' being repaid or requested.';
            }
        }

        return ['max_amount' => $max, 'max_tenor_months' => $type->max_tenor_months, 'problems' => $problems];
    }

    /** Problems with a loan of this amount and tenor, in words; empty when it can be requested. */
    public function problems(Employee $employee, LoanType $type, float $amount, int $tenor, ?Employee $guarantor, ?Loan $ignore = null): array
    {
        if (!$type->active) {
            return ['This loan type isn\'t offered now.'];
        }
        $limits = $this->limits($employee, $type);
        $problems = $ignore ? [] : $limits['problems'];

        if ($amount <= 0) {
            $problems[] = 'Enter the amount.';
        } elseif ($limits['max_amount'] !== null && $amount > $limits['max_amount']) {
            $problems[] = 'The most you can borrow is ' . number_format($limits['max_amount'], 2) . '.';
        }
        if ($tenor < 1 || $tenor > $type->max_tenor_months) {
            $problems[] = "Repay over 1 to {$type->max_tenor_months} months.";
        }
        if ($type->requires_guarantor && !$guarantor && !$ignore) {
            $problems[] = 'Name a guarantor.';
        } elseif ($guarantor && $guarantor->id === $employee->id) {
            $problems[] = 'You can\'t guarantee your own loan.';
        }

        if ($type->max_deduction_percent !== null && $amount > 0 && $tenor >= 1) {
            $monthly = $this->schedule($amount, $tenor, $type->interest_method, (float) $type->interest_rate, now()->addMonth())[0]['amount'];
            $others = $this->monthlyRepayments($employee, $ignore);
            $base = $this->takeHome($employee);
            $cap = round($base * (float) $type->max_deduction_percent / 100, 2);
            if ($base > 0 && $monthly + $others > $cap) {
                $problems[] = 'Repayments would be ' . number_format($monthly + $others, 2) . ' a month' . ($others ? ' with your other loans' : '')
                    . ', more than the ' . (float) $type->max_deduction_percent . '% of take-home pay allowed (' . number_format($cap, 2) . '). Borrow less or over longer.';
            }
        }

        return $problems;
    }

    public function request(Employee $employee, array $data, User $user): Loan
    {
        $type = LoanType::where('uuid', $data['loan_type_uuid'])->firstOrFail();
        $guarantor = filled($data['guarantor_uuid'] ?? null) ? Employee::where('uuid', $data['guarantor_uuid'])->firstOrFail() : null;
        $amount = round((float) $data['amount'], 2);
        $tenor = (int) $data['tenor_months'];

        if ($problems = $this->problems($employee, $type, $amount, $tenor, $guarantor)) {
            throw new UserFacingException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($employee, $type, $guarantor, $amount, $tenor, $data, $user) {
            $loan = Loan::create([
                'employee_id'      => $employee->id,
                'loan_type_id'     => $type->id,
                'amount_requested' => $amount,
                'amount'           => $amount,
                'tenor_months'     => $tenor,
                'purpose'          => $data['purpose'] ?? null,
                'guarantor_id'     => $guarantor?->id,
                'interest_method'  => $type->interest_method,
                'interest_rate'    => $type->interest_rate,
                'status'           => 'pending',
                'requested_by'     => $user->id,
            ]);
            $this->approvals->start($loan, ApprovalProcess::LOAN, $employee, $user, $type->workflow);

            return $loan;
        });
    }

    /** An approver lowers the amount or changes the tenor (within the type's limits). */
    public function adjust(Loan $loan, array $values): void
    {
        $amount = array_key_exists('amount', $values) ? round((float) $values['amount'], 2) : (float) $loan->amount;
        $tenor = array_key_exists('tenor_months', $values) ? (int) $values['tenor_months'] : $loan->tenor_months;
        if ($amount <= 0 || $amount > (float) $loan->amount_requested) {
            throw new UserFacingException('The approved amount must be more than 0 and no more than requested (' . number_format((float) $loan->amount_requested, 2) . ').');
        }
        if ($tenor < 1 || $tenor > $loan->type->max_tenor_months) {
            throw new UserFacingException("The repayment period must be 1 to {$loan->type->max_tenor_months} months.");
        }
        $loan->update(['amount' => $amount, 'tenor_months' => $tenor]);
    }

    /** Withdrawn by the employee (pending), or called off by HR before it's paid out. */
    public function cancel(Loan $loan): void
    {
        if (!in_array($loan->status, ['pending', 'approved'], true)) {
            throw new UserFacingException('Only a loan not yet paid out can be withdrawn.');
        }
        DB::transaction(function () use ($loan) {
            if ($loan->approval?->isPending()) {
                $this->approvals->cancel($loan->approval);
            }
            $loan->update(['status' => Approval::CANCELLED]);
        });
    }

    /** Pay out an approved loan: its repayment schedule starts from the month chosen. */
    public function disburse(Loan $loan, array $data, User $user): Loan
    {
        if ($loan->status !== 'approved') {
            throw new UserFacingException('Only an approved loan can be paid out.');
        }
        $on = Carbon::parse($data['disbursed_on'])->startOfDay();
        $first = Carbon::create((int) $data['first_year'], (int) $data['first_month'], 1);
        if ($first->lt($on->copy()->startOfMonth())) {
            throw new UserFacingException('Repayments can\'t start before the month the loan is paid out.');
        }
        if ($data['method'] === 'payroll' && !feature('payroll.enabled')) {
            throw new UserFacingException('Paying out on a payslip needs payroll to be on.');
        }

        return DB::transaction(function () use ($loan, $data, $on, $first, $user) {
            $rows = $this->schedule((float) $loan->amount, $loan->tenor_months, $loan->interest_method, (float) $loan->interest_rate, $first);
            $loan->instalments()->delete();
            $loan->instalments()->createMany(array_map(fn ($r) => $r + ['status' => 'due'], $rows));
            $loan->update([
                'status'                 => 'active',
                'disbursed_on'           => $on->toDateString(),
                'disbursement_method'    => $data['method'],
                'disbursement_reference' => $data['reference'] ?? null,
                'total_repayable'        => round(array_sum(array_column($rows, 'amount')), 2),
                'disbursed_by'           => $user->id,
            ]);

            return $loan->fresh('instalments');
        });
    }

    /**
     * Money paid back by hand. It pays off the last instalments first, so the loan ends sooner
     * and the monthly deduction stays the same.
     */
    public function repay(Loan $loan, float $amount, string $paidOn, ?string $reference, ?string $notes, User $user): LoanRepayment
    {
        $this->ensureActive($loan);

        return DB::transaction(function () use ($loan, $amount, $paidOn, $reference, $notes, $user) {
            $this->unclaim($loan);
            $balance = $loan->balance();
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > $balance) {
                throw new UserFacingException('Enter an amount up to the balance (' . number_format($balance, 2) . ').');
            }
            $repayment = $loan->repayments()->create(['amount' => $amount, 'paid_on' => $paidOn, 'source' => 'manual', 'reference' => $reference, 'notes' => $notes, 'recorded_by' => $user->id]);

            $left = $amount;
            foreach ($loan->instalments()->where('status', 'due')->reorder('number', 'desc')->get() as $i) {
                if ($left <= 0) {
                    break;
                }
                if ($left >= (float) $i->amount) {
                    $left = round($left - (float) $i->amount, 2);
                    $i->update(['status' => 'paid']);
                } else {
                    $principal = max(0, round((float) $i->principal - $left, 2));
                    $interest = round((float) $i->interest - max(0, $left - (float) $i->principal), 2);
                    $i->update(['principal' => $principal, 'interest' => $interest, 'amount' => round($principal + $interest, 2)]);
                    $left = 0;
                }
            }
            $this->settleIfPaid($loan, Carbon::parse($paidOn));

            return $repayment;
        });
    }

    /** What settling in full costs now: the principal still owed (interest not yet due is waived). */
    public function settlementAmount(Loan $loan): float
    {
        return round((float) $loan->instalments()->where('status', 'due')->sum('principal'), 2);
    }

    public function settle(Loan $loan, string $paidOn, ?string $reference, User $user): LoanRepayment
    {
        $this->ensureActive($loan);

        return DB::transaction(function () use ($loan, $paidOn, $reference, $user) {
            $this->unclaim($loan);
            $amount = $this->settlementAmount($loan);
            $repayment = $loan->repayments()->create(['amount' => $amount, 'paid_on' => $paidOn, 'source' => 'manual', 'reference' => $reference, 'notes' => 'Settled in full', 'recorded_by' => $user->id]);
            foreach ($loan->instalments()->where('status', 'due')->get() as $i) {
                $i->update(['interest' => 0, 'amount' => $i->principal, 'status' => 'paid']);
            }
            $loan->update(['status' => 'settled', 'settled_on' => $paidOn]);

            return $repayment;
        });
    }

    /** Skip a month's instalment: it moves to the end of the schedule. */
    public function pause(LoanInstalment $instalment): LoanInstalment
    {
        $loan = $instalment->loan;
        $this->ensureActive($loan);
        if ($instalment->status !== 'due') {
            throw new UserFacingException('Only a due instalment can be paused.');
        }

        return DB::transaction(function () use ($loan, $instalment) {
            $this->unclaim($loan, $instalment);
            $last = $loan->instalments()->reorder('number', 'desc')->first();
            $next = $last->period()->addMonthNoOverflow();
            $instalment->update(['status' => 'paused']);

            return $loan->instalments()->create([
                'number' => $last->number + 1, 'year' => $next->year, 'month' => $next->month,
                'principal' => $instalment->principal, 'interest' => $instalment->interest, 'amount' => $instalment->amount, 'status' => 'due',
            ]);
        });
    }

    /**
     * Bring due instalments (and loans paid out on the payslip) into a regular run. A leaver's
     * whole balance comes off their final pay when the organization asks for it.
     */
    public function claimFor(PayRun $run): void
    {
        DB::transaction(function () use ($run) {
            $this->release($run);
            if ($run->type !== 'regular' || !feature('payroll.loans')) {
                return;
            }
            $paid = EmployeePayProfile::inForceOn($run->period_end)->pluck('employee_id');
            $leavers = setting('payroll.loans_deduct_from_final_pay', true)
                ? Employee::withTrashed()->whereIn('id', $paid)->whereBetween('termination_date', [$run->period_start->toDateString(), $run->period_end->toDateString()])->pluck('id')
                : collect();

            $instalments = LoanInstalment::where('status', 'due')->whereNull('pay_run_id')
                ->whereHas('loan', fn ($q) => $q->where('status', 'active')->whereIn('employee_id', $paid))
                ->with('loan.type')
                ->get()
                ->filter(fn ($i) => $leavers->contains($i->loan->employee_id) || $i->period()->lte($run->period_start));

            $repayment = PayComponent::where('code', PayComponent::LOAN_REPAYMENT)->firstOrFail();
            foreach ($instalments->groupBy(fn ($i) => $i->loan->employee_id) as $employeeId => $items) {
                PayRunInput::create([
                    'pay_run_id' => $run->id, 'employee_id' => $employeeId, 'pay_component_id' => $repayment->id,
                    'amount' => round($items->sum(fn ($i) => (float) $i->amount), 2), 'source' => 'loan',
                    'notes' => $this->describe($items, $leavers->contains($employeeId)),
                ]);
            }
            LoanInstalment::whereIn('id', $instalments->pluck('id'))->update(['pay_run_id' => $run->id]);

            // Loans paid out on the payslip, in the run for the month they're paid out (or the next one).
            $payouts = Loan::where('status', 'active')->where('disbursement_method', 'payroll')->whereNull('disbursement_run_id')
                ->whereDate('disbursed_on', '<=', $run->period_end)->whereIn('employee_id', $paid)->get();
            $payout = PayComponent::where('code', PayComponent::LOAN_DISBURSEMENT)->firstOrFail();
            foreach ($payouts as $loan) {
                PayRunInput::create([
                    'pay_run_id' => $run->id, 'employee_id' => $loan->employee_id, 'pay_component_id' => $payout->id,
                    'amount' => $loan->amount, 'source' => 'loan', 'notes' => "{$loan->type->name} paid out",
                ]);
            }
            Loan::whereIn('id', $payouts->pluck('id'))->update(['disbursement_run_id' => $run->id]);
        });
    }

    public function release(PayRun $run): void
    {
        PayRunInput::where('pay_run_id', $run->id)->where('source', 'loan')->delete();
        LoanInstalment::where('pay_run_id', $run->id)->where('status', 'due')->update(['pay_run_id' => null]);
        Loan::where('disbursement_run_id', $run->id)->update(['disbursement_run_id' => null]);
    }

    /** The run is paid: its instalments are repaid. */
    public function paid(PayRun $run): void
    {
        DB::transaction(function () use ($run) {
            $instalments = LoanInstalment::where('pay_run_id', $run->id)->where('status', 'due')->with('loan')->get();
            foreach ($instalments as $i) {
                $i->update(['status' => 'paid']);
                $i->loan->repayments()->create([
                    'loan_instalment_id' => $i->id, 'amount' => $i->amount, 'paid_on' => $run->pay_date ?? now(),
                    'source' => 'payroll', 'pay_run_id' => $run->id, 'notes' => "Instalment {$i->number}",
                ]);
            }
            foreach ($instalments->pluck('loan')->unique('id') as $loan) {
                $this->settleIfPaid($loan, Carbon::parse($run->pay_date ?? now()));
            }
        });
    }

    /** Monthly repayments on the employee's other loans (the next instalment, or the first for ones not paid out yet). */
    public function monthlyRepayments(Employee $employee, ?Loan $ignore = null): float
    {
        return round(Loan::where('employee_id', $employee->id)->whereIn('status', self::OWING)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->with(['instalments' => fn ($q) => $q->where('status', 'due')])->get()
            ->sum(fn (Loan $l) => $l->status === 'active'
                ? (float) ($l->instalments->first()?->amount ?? 0)
                : $this->schedule((float) $l->amount, $l->tenor_months, $l->interest_method, (float) $l->interest_rate, now())[0]['amount']), 2);
    }

    /** Take-home pay before loan repayments: the last paid payslip, else basic salary. */
    public function takeHome(Employee $employee): float
    {
        $slip = Payslip::where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->where('status', PayRunStatus::PAID))
            ->latest('id')->with('lines')->first();
        if ($slip) {
            return round((float) $slip->net_pay + (float) $slip->lines->where('code', PayComponent::LOAN_REPAYMENT)->sum('amount'), 2);
        }
        $profile = EmployeePayProfile::inForceOn(now())->where('employee_id', $employee->id)->first();
        $rate = $profile ? ExchangeRate::for($profile->currency ?: setting('payroll.base_currency', 'GHS'), now()->year) : null;

        return $profile && $rate ? round((float) $profile->basic_salary * $rate, 2) : 0.0;
    }

    private function describe(Collection $items, bool $leaver): string
    {
        return ($leaver ? 'Final pay: ' : '') . $items->groupBy('loan_id')->map(function ($group) {
            $loan = $group->first()->loan;
            $numbers = $group->pluck('number')->sort()->values();

            return "{$loan->type->name} instalment" . ($numbers->count() > 1 ? 's ' . $numbers->first() . '–' . $numbers->last() : " {$numbers->first()}") . " of {$loan->instalments()->count()}";
        })->implode('; ');
    }

    private function ensureActive(Loan $loan): void
    {
        if ($loan->status !== 'active') {
            throw new UserFacingException('Only a loan being repaid can change.');
        }
    }

    /**
     * Take instalments back from runs still being prepared (they're recalculated), and refuse when
     * a run already sent for approval holds them.
     */
    private function unclaim(Loan $loan, ?LoanInstalment $only = null): void
    {
        $claimed = $loan->instalments()->where('status', 'due')->whereNotNull('pay_run_id')
            ->when($only, fn ($q) => $q->whereKey($only->id))->with('payRun')->get();
        foreach ($claimed as $i) {
            if (!$i->payRun?->status->isOpen()) {
                throw new UserFacingException("Instalment {$i->number} is in {$i->payRun?->name}, already sent for approval. Wait until that run is paid.");
            }
            if ($i->payRun->status === PayRunStatus::CALCULATED) {
                $i->payRun->update(['status' => PayRunStatus::DRAFT]);
            }
            $i->update(['pay_run_id' => null]);
        }
    }

    private function settleIfPaid(Loan $loan, Carbon $on): void
    {
        if (!$loan->instalments()->where('status', 'due')->exists()) {
            $loan->update(['status' => 'settled', 'settled_on' => $on->toDateString()]);
        }
    }
}
