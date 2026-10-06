<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\PayRunStatus;
use App\Exceptions\UserFacingException;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A pay run's life: created, calculated (as often as needed), sent for approval, approved, paid. */
class PayRunService
{
    public function __construct(private readonly ApprovalWorkflowService $approvals)
    {
    }

    public function create(int $year, int $month, string $type, ?string $payDate, User $user): PayRun
    {
        if ($type === 'regular' && PayRun::where('year', $year)->where('month', $month)->where('type', 'regular')->exists()) {
            throw new UserFacingException('There is already a pay run for this month. Use an off-cycle run for extra payments.');
        }
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return PayRun::create([
            'year'         => $year,
            'month'        => $month,
            'type'         => $type,
            'name'         => ($type === 'off_cycle' ? 'Off-cycle payroll · ' : 'Payroll · ') . $start->format('F Y'),
            'period_start' => $start->toDateString(),
            'period_end'   => $start->copy()->endOfMonth()->toDateString(),
            'pay_date'     => $payDate ?? $start->copy()->endOfMonth()->toDateString(),
            'status'       => PayRunStatus::DRAFT,
            'prepared_by'  => $user->id,
        ]);
    }

    /** (Re)calculate every payslip. Refuses without confirmed statutory rates, or with a currency missing its rate. */
    public function calculate(PayRun $run, User $user): PayRun
    {
        if (!$run->status->isOpen()) {
            throw new UserFacingException('Only a draft or calculated pay run can be calculated.');
        }

        $start = $run->period_start->copy()->startOfDay();
        $end = $run->period_end->copy()->startOfDay();
        $statutory = StatutoryRateSet::inForceOn($end);
        if (!$statutory) {
            throw new UserFacingException('There are no statutory rates in force for this period. Add them in Payroll → Settings.');
        }
        if (!$statutory->isConfirmed()) {
            throw new UserFacingException("The statutory rates \"{$statutory->name}\" haven't been confirmed. Check and confirm them in Payroll → Settings first.");
        }

        $settings = [
            'base_currency'          => strtoupper((string) setting('payroll.base_currency', 'GHS')),
            'working_days_per_month' => (int) setting('payroll.working_days_per_month', 22),
            'hours_per_day'          => (float) setting('payroll.hours_per_day', 8),
            'overtime_tax_method'    => (string) setting('payroll.overtime_tax_method', 'income'),
            'bonus_tax_method'       => (string) setting('payroll.bonus_tax_method', 'gra_bonus'),
        ];
        $offCycle = $run->type === 'off_cycle';
        $regular = $offCycle ? PayRun::where(['year' => $run->year, 'month' => $run->month, 'type' => 'regular'])->first() : null;
        if ($offCycle && (!$regular || $regular->status === PayRunStatus::DRAFT)) {
            throw new UserFacingException('Calculate this month\'s regular pay run first: an off-cycle run is taxed on top of it.');
        }
        $rates = ExchangeRate::where('year', $run->year)->pluck('rate', 'currency')->map(fn ($r) => (float) $r)->all();
        $basic = PayComponent::where('code', PayComponent::BASIC)->firstOrFail();
        $calculator = new PayrollCalculator(['start' => $start, 'end' => $end], $statutory, $rates, $settings, $basic);

        // Approved overtime and time inputs, and due loan instalments, join the run (again on each calculation, so they are current).
        app(OvertimeService::class)->claimFor($run);
        app(TimeInputService::class)->claimFor($run);
        app(LoanService::class)->claimFor($run);
        $inputs = app(PayRunInputs::class)->forRun($run);

        // Who is paid: a pay profile in force, joined by the end of the period, not gone before it started.
        // An off-cycle run pays only the employees given something in it.
        $profiles = EmployeePayProfile::inForceOn($end)->get()->keyBy('employee_id');
        if ($offCycle) {
            $profiles = $profiles->filter(fn ($p, $employeeId) => isset($inputs[$employeeId]));
        }
        $employees = Employee::withTrashed()
            ->whereIn('id', $profiles->keys())
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhereDate('termination_date', '>=', $start))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $start))
            ->with(['jobDetail.position', 'department', 'contactDetail'])
            ->get()
            ->filter(fn (Employee $e) => !$e->jobDetail?->joined_date || Carbon::parse($e->jobDetail->joined_date)->lte($end));

        $components = EmployeePayComponent::whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->whereHas('component', fn ($c) => $c->where('active', true))
            ->with('component')->get()->groupBy('employee_id');
        $prior = $this->prior($run, $employees->pluck('id')->all());
        // Back pay for raises entered with an earlier date, then the inputs again with it.
        app(ArrearsService::class)->claimFor($run, $employees->pluck('id')->all());
        $inputs = app(PayRunInputs::class)->forRun($run);

        // Work everything out first: a missing rate stops the run before anything is saved.
        $results = $employees->mapWithKeys(fn (Employee $e) => [$e->id => $calculator->calculate(
            $profiles[$e->id],
            $components->get($e->id, collect()),
            $inputs[$e->id] ?? [],
            $e->jobDetail?->joined_date ? Carbon::parse($e->jobDetail->joined_date) : null,
            $e->termination_date ? Carbon::parse($e->termination_date) : null,
            (bool) $e->ssnit_number,
            ($prior[$e->id] ?? []) + ['off_cycle' => $offCycle],
        )]);

        DB::transaction(function () use ($run, $employees, $profiles, $results, $statutory, $rates, $settings, $user) {
            $run->payslips()->delete();

            foreach ($employees as $e) {
                $result = $results[$e->id];
                $profile = $profiles[$e->id];
                $gaps = $profile->gaps($e->ssnit_number, null);
                $payslip = $run->payslips()->create(collect($result)->except(['lines', 'warnings'])->all() + [
                    'employee_id'       => $e->id,
                    'employee_snapshot' => [
                        'name' => trim(preg_replace('/\s+/', ' ', $e->name)), 'staff_id' => $e->staff_id,
                        'position' => $e->jobDetail?->position?->name, 'department' => $e->department?->name,
                        'ssnit_number' => $e->ssnit_number, 'tin' => $profile->tin ?? $e->contactDetail?->ghana_card_number,
                        'tier2_scheme' => $profile->tier2_scheme,
                    ],
                    'payment_snapshot'  => collect($profile->only(['payment_method', 'bank_name', 'bank_branch', 'account_name', 'account_number', 'mobile_money_provider', 'mobile_money_number']))->all(),
                    'warnings'          => array_values(array_unique(array_merge($result['warnings'], array_diff($gaps, ['SSNIT number', 'TIN / Ghana Card'])))),
                ]);
                $payslip->lines()->createMany($result['lines']);
            }

            $slips = $run->payslips()->get();
            $sum = fn (string $f) => round((float) $slips->sum($f), 2);
            $run->update([
                'status'                => PayRunStatus::CALCULATED,
                'statutory_rate_set_id' => $statutory->id,
                'exchange_rates'        => $rates,
                'settings'              => $settings,
                'calculated_at'         => now(),
                'prepared_by'           => $user->id,
                'totals'                => [
                    'employees'        => $slips->count(),
                    'gross_pay'        => $sum('gross_pay'),
                    'paye'             => $sum('paye'),
                    'ssnit_employee'   => $sum('ssnit_employee'),
                    'ssnit_employer'   => $sum('ssnit_employer'),
                    'tier2'            => $sum('tier2'),
                    'total_deductions' => $sum('total_deductions'),
                    'net_pay'          => $sum('net_pay'),
                    'employer_cost'    => $sum('employer_cost'),
                    'with_warnings'    => $slips->filter(fn ($s) => !empty($s->warnings))->count(),
                ],
            ]);
        });

        return $run->fresh();
    }

    /**
     * What earlier runs already taxed, per employee: for an off-cycle run, the month's other runs
     * (taxable income and SSNIT base); for any run, bonus taxed at the bonus rate earlier in the year.
     *
     * @return array<int, array{taxable: float, ssnit_base: float, bonus_concession_ytd: float}>
     */
    private function prior(PayRun $run, array $employeeIds): array
    {
        $counted = [PayRunStatus::CALCULATED, PayRunStatus::PENDING_APPROVAL, PayRunStatus::APPROVED, PayRunStatus::PAID];
        $slips = Payslip::whereIn('employee_id', $employeeIds)
            ->whereHas('run', fn ($q) => $q->where('year', $run->year)->whereKeyNot($run->id)->whereIn('status', $counted)
                // The month's off-cycle runs count once sent for approval; the regular run once calculated.
                ->where(fn ($w) => $w->where('type', 'regular')->orWhereIn('status', [PayRunStatus::PENDING_APPROVAL, PayRunStatus::APPROVED, PayRunStatus::PAID])))
            ->with('run:id,month,type')->get(['id', 'pay_run_id', 'employee_id', 'taxable_income', 'ssnit_base', 'bonus_concession']);

        return $slips->groupBy('employee_id')->map(function ($items) use ($run) {
            $month = $run->type === 'off_cycle' ? $items->filter(fn ($p) => $p->run->month === $run->month) : collect();

            return [
                'taxable'              => round((float) $month->sum('taxable_income'), 2),
                'ssnit_base'           => round((float) $month->sum('ssnit_base'), 2),
                'bonus_concession_ytd' => round((float) $items->sum('bonus_concession'), 2),
            ];
        })->all();
    }

    public function submit(PayRun $run, User $user): PayRun
    {
        if ($run->status !== PayRunStatus::CALCULATED) {
            throw new UserFacingException('Calculate the pay run before sending it for approval.');
        }
        if (!($run->totals['employees'] ?? 0)) {
            throw new UserFacingException('This pay run has no payslips.');
        }

        $run->update(['status' => PayRunStatus::PENDING_APPROVAL]);
        $this->approvals->start($run, ApprovalProcess::PAY_RUN, null, $user);

        return $run->fresh();
    }

    public function markPaid(PayRun $run, User $user): PayRun
    {
        if ($run->status !== PayRunStatus::APPROVED) {
            throw new UserFacingException('Only an approved pay run can be marked as paid.');
        }
        DB::transaction(function () use ($run, $user) {
            $run->update(['status' => PayRunStatus::PAID, 'paid_at' => now(), 'paid_by' => $user->id]);
            // The loan instalments it deducted are now repaid.
            app(LoanService::class)->paid($run);
        });

        // Payslips are now visible to employees; tell them when the organization wants it.
        if (setting('payroll.email_payslips', false)) {
            $run->payslips()->with(['employee.userAccount', 'run'])->get()
                ->each(fn ($p) => $p->employee?->userAccount?->notify(new \App\Notifications\PayslipAvailableNotification($p)));
        }

        return $run->fresh();
    }
}
