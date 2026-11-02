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
use Illuminate\Support\Collection;
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
        $period = PayPeriod::forMonth($year, $month);

        return PayRun::create([
            'year'         => $year,
            'month'        => $month,
            'type'         => $type,
            'name'         => ($type === 'off_cycle' ? 'Off-cycle payroll · ' : 'Payroll · ') . Carbon::create($year, $month, 1)->format('F Y'),
            'period_start' => $period->start->toDateString(),
            'period_end'   => $period->end->toDateString(),
            'pay_date'     => $payDate ?? $this->defaultPayDate($year, $month)->toDateString(),
            'status'       => PayRunStatus::DRAFT,
            'prepared_by'  => $user->id,
        ]);
    }

    /** The pay day in the run's month (setting `payroll.pay_day`; 0: the last day). */
    public function defaultPayDate(int $year, int $month): Carbon
    {
        $month = Carbon::create($year, $month, 1)->startOfDay();
        $day = (int) setting('payroll.pay_day', 0);

        return $day > 0 ? $month->day(min($day, $month->daysInMonth)) : $month->endOfMonth()->startOfDay();
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

        $rateDate = $this->rateDate($run);
        $settings = [
            'base_currency'          => strtoupper((string) setting('payroll.base_currency', 'GHS')),
            'working_days_per_month' => (int) setting('payroll.working_days_per_month', 22),
            'hours_per_day'          => (float) setting('payroll.hours_per_day', 8),
            'overtime_tax_method'    => (string) setting('payroll.overtime_tax_method', 'income'),
            'bonus_tax_method'       => (string) setting('payroll.bonus_tax_method', 'gra_bonus'),
            'working_days'           => PayPeriod::ofRun($run)->workingDays(),
            'overtime_hourly_basis'  => (string) setting('payroll.overtime_hourly_basis', 'fixed_days'),
            'overtime_monthly_hours' => (float) setting('payroll.overtime_monthly_hours', 173.33),
            // Which rate converts a foreign-currency basic for overtime's hourly rate: basic's own, or the standard rate.
            'overtime_rate_type'     => (string) setting('payroll.overtime_rate_type', ExchangeRate::BASIC),
            'rate_date'              => $rateDate->toDateString(),
            // Freelancers: taxed as usual (paye) or by a flat withholding rate.
            'freelance_tax'          => (string) setting('payroll.freelance_tax', 'paye'),
        ];
        $offCycle = $run->type === 'off_cycle';
        $regular = $offCycle ? PayRun::where(['year' => $run->year, 'month' => $run->month, 'type' => 'regular'])->first() : null;
        if ($offCycle && (!$regular || $regular->status === PayRunStatus::DRAFT)) {
            throw new UserFacingException('Calculate this month\'s regular pay run first: an off-cycle run is taxed on top of it.');
        }
        $rates = $this->ratesOn($rateDate);
        $basic = PayComponent::where('code', PayComponent::BASIC)->firstOrFail();
        $calculator = new PayrollCalculator(['start' => $start, 'end' => $end], $statutory, $rates, $settings, $basic);

        // Approved overtime and time inputs, and due loan instalments, join the run (again on each calculation, so they are current).
        // Overtime approved in advance and still waiting for its hours worked is noted on the payslip.
        $overtimeWaiting = app(OvertimeService::class)->claimFor($run);
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
            ->with(['jobDetail.position', 'jobDetail.costCentre', 'department', 'contactDetail'])
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

        $paidIds = $employees->pluck('id')->flip();
        // With pay from timesheets, note who has no approved timesheet for the month.
        $noTimesheet = array_flip(app(TimesheetService::class)->missingFor($run, $employees->pluck('id')));
        $sharedAccount = $this->sharedAccounts($employees, $profiles);
        $this->ensureRates($profiles->filter(fn ($p, $employeeId) => $paidIds->has($employeeId)), $components->flatten(), $inputs, $rates, $settings['base_currency'], $rateDate);

        // Work everything out first: a missing rate stops the run before anything is saved.
        $results = $employees->mapWithKeys(fn (Employee $e) => [$e->id => $calculator->calculate(
            $profiles[$e->id],
            $components->get($e->id, collect()),
            $inputs[$e->id] ?? [],
            $e->jobDetail?->joined_date ? Carbon::parse($e->jobDetail->joined_date) : null,
            $e->termination_date ? Carbon::parse($e->termination_date) : null,
            (bool) $e->ssnit_number,
            ($prior[$e->id] ?? []) + ['off_cycle' => $offCycle, 'freelance' => $e->job_type === 'freelance'],
        )]);

        DB::transaction(function () use ($run, $employees, $profiles, $results, $statutory, $rates, $settings, $user, $overtimeWaiting, $noTimesheet, $sharedAccount) {
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
                        'engagement_date' => $e->jobDetail?->joined_date ? Carbon::parse($e->jobDetail->joined_date)->toDateString() : null,
                        // Their cost centre, else their department.
                        'cost_centre' => $e->jobDetail?->costCentre?->name ?? $e->department?->name,
                        'ssnit_number' => $e->ssnit_number, 'tin' => $profile->tin ?? $e->contactDetail?->ghana_card_number,
                        'tier2_scheme' => $profile->tier2_scheme,
                    ],
                    // With the bank's code and the branch's sort code as they are now (for payment files).
                    'payment_snapshot'  => collect($profile->only(['payment_method', 'bank_name', 'bank_branch', 'account_name', 'account_number', 'mobile_money_provider', 'mobile_money_number']))
                        ->merge($profile->payment_method === 'bank' ? ['bank_code' => $profile->bank?->code, 'bank_branch_sort_code' => $profile->branch?->sort_code] : [])->all(),
                    // Plain copies for filtering (the snapshot is encrypted).
                    'payment_method'    => $profile->payment_method,
                    'bank_id'           => $profile->payment_method === 'bank' ? $profile->bank_id : null,
                    'bank_name'         => $profile->payment_method === 'bank' ? $profile->bank_name : null,
                    'warnings'          => array_values(array_unique(array_merge($result['warnings'], $overtimeWaiting[$e->id] ?? [],
                        isset($noTimesheet[$e->id]) ? ['No approved timesheet for this month.'] : [], $sharedAccount[$e->id] ?? [], array_diff($gaps, ['SSNIT number', 'TIN / Ghana Card'])))),
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
                    'withholding_tax'  => $sum('withholding_tax'),
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

    /** The date a run takes its exchange rates on (setting `payroll.exchange_rate_day` in the run's month; 0: the period's last day). */
    public function rateDate(PayRun $run): Carbon
    {
        $day = (int) setting('payroll.exchange_rate_day', 0);
        if ($day <= 0) {
            return $run->period_end->copy()->startOfDay();
        }
        $month = Carbon::create($run->year, $run->month, 1)->startOfDay();

        return $month->day(min($day, $month->daysInMonth));
    }

    /**
     * Each currency's rates in force on a date, as the run records them:
     * {"USD": {"standard": {"rate": 11.3933, "date": "2026-09-22"}, "basic": {"rate": 13, "date": "2026-09-01"}}}.
     * The basic rate only when the organization uses one (setting `payroll.separate_basic_rate`).
     */
    public function ratesOn(Carbon $on): array
    {
        $separate = (bool) setting('payroll.separate_basic_rate', false);
        $pick = fn (string $currency, string $type) => ($r = ExchangeRate::inForceOn($currency, $on, $type))
            ? ['rate' => (float) $r->rate, 'date' => $r->effective_date->toDateString()] : null;

        return ExchangeRate::query()->distinct()->orderBy('currency')->pluck('currency')
            ->mapWithKeys(fn ($c) => [$c => ['standard' => $pick($c, ExchangeRate::STANDARD), 'basic' => $separate ? $pick($c, ExchangeRate::BASIC) : null]])
            ->filter(fn ($r) => $r['standard'] || $r['basic'])
            ->all();
    }

    /**
     * Stop with a clear message when pay is set in a currency that has no rate in force: which currency, and
     * who or what uses it, so it can be fixed (add the rate, or correct the currency). Basic salary needs a
     * basic or standard rate (or the employee's fixed rate); everything else the standard rate.
     */
    private function ensureRates($profiles, $components, array $inputs, array $rates, string $base, Carbon $on): void
    {
        $missing = fn (?string $c) => $c && strtoupper($c) !== $base && empty($rates[strtoupper($c)]['standard']);
        $byCurrency = [];
        foreach ($profiles as $p) {
            $foreign = $p->currency && strtoupper($p->currency) !== $base;
            if ($foreign && $p->fixed_exchange_rate === null && empty($rates[strtoupper($p->currency)]['basic']) && empty($rates[strtoupper($p->currency)]['standard'])) {
                $byCurrency[strtoupper($p->currency)]['employees'][$p->employee_id] = true;
            }
        }
        foreach ($components as $given) {
            if ($missing($given->component?->currency)) {
                $byCurrency[strtoupper($given->component->currency)]['components'][$given->component->name] = true;
            }
        }
        foreach ($inputs as $items) {
            foreach ($items as $i) {
                if ($missing($i['component']->currency)) {
                    $byCurrency[strtoupper($i['component']->currency)]['components'][$i['component']->name] = true;
                }
            }
        }
        if (!$byCurrency) {
            return;
        }

        $parts = collect($byCurrency)->map(function ($uses, $currency) use ($on) {
            $who = array_filter([
                isset($uses['employees']) ? count($uses['employees']) . ' employee' . (count($uses['employees']) === 1 ? "'s pay details" : "s' pay details") : null,
                isset($uses['components']) ? 'the component' . (count($uses['components']) > 1 ? 's ' : ' ') . implode(', ', array_keys($uses['components'])) : null,
            ]);

            return "no {$currency} exchange rate on or before {$on->format('j M Y')} (used by " . implode(' and ', $who) . ')';
        })->implode('; ');

        throw new UserFacingException('There is ' . $parts . '. Add the rate in Payroll → Settings → Exchange rates, or correct the currency on the pay details or component.');
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

    /**
     * Take a run sent for approval (or approved, not yet paid) back to draft so it can be corrected and
     * recalculated, e.g. after pay details were filled in. Its approval is withdrawn; sending it again
     * starts a new one.
     */
    public function reopen(PayRun $run, User $user): PayRun
    {
        if (!in_array($run->status, [PayRunStatus::PENDING_APPROVAL, PayRunStatus::APPROVED], true)) {
            throw new UserFacingException($run->status === PayRunStatus::PAID
                ? 'A paid pay run can\'t be reopened. Use an off-cycle run for corrections.'
                : 'This pay run is already open: recalculate it.');
        }
        DB::transaction(function () use ($run) {
            if ($run->approval?->isPending()) {
                $this->approvals->cancel($run->approval);
            }
            $run->update(['status' => PayRunStatus::DRAFT, 'approved_at' => null]);
        });

        return $run->fresh();
    }

    /**
     * Employees in the run paid into the same account (bank and number, or mobile money number) as someone else in it:
     * a warning each, naming the others (details saved before duplicates were refused, or a mistake to fix first).
     *
     * @return array<int, array<string>>
     */
    private function sharedAccounts(Collection $employees, Collection $profiles): array
    {
        $names = $employees->mapWithKeys(fn (Employee $e) => [$e->id => trim(preg_replace('/\s+/', ' ', $e->name)) . ($e->staff_id ? " ({$e->staff_id})" : '')]);
        $warnings = [];
        $employees->groupBy(fn (Employee $e) => EmployeePayProfile::paymentKey($profiles[$e->id]->only(['payment_method', 'bank_id', 'account_number', 'mobile_money_number'])) ?? "none:{$e->id}")
            ->filter(fn ($group) => $group->count() > 1)
            ->each(function ($group) use ($names, &$warnings) {
                foreach ($group as $e) {
                    $others = $group->where('id', '!=', $e->id)->map(fn ($o) => $names[$o->id])->join(', ');
                    $warnings[$e->id] = ["Paid into the same account as {$others}."];
                }
            });

        return $warnings;
    }

    /**
     * Who isn't paid by a regular run and why: no pay details, details that start after the month,
     * or joining after it. Leavers who left before the month aren't listed.
     *
     * @return array{count: int, people: array<array{uuid: string, name: string, staff_id: ?string, reason: string}>}
     */
    public function leftOut(PayRun $run, int $limit = 50): array
    {
        if ($run->type !== 'regular') {
            return ['count' => 0, 'people' => []];
        }
        $start = $run->period_start->copy()->startOfDay();
        $end = $run->period_end->copy()->startOfDay();
        $inForce = EmployeePayProfile::inForceOn($end)->pluck('employee_id')->flip();
        $firstDetails = EmployeePayProfile::query()->selectRaw('employee_id, min(effective_from) as starts')->groupBy('employee_id')->pluck('starts', 'employee_id');

        $people = Employee::withTrashed()
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhereDate('termination_date', '>=', $start))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $start))
            ->with('jobDetail')->orderBy('first_name')->orderBy('last_name')->get()
            ->map(function (Employee $e) use ($inForce, $firstDetails, $end) {
                $joined = $e->jobDetail?->joined_date ? Carbon::parse($e->jobDetail->joined_date) : null;
                $reason = match (true) {
                    $joined && $joined->gt($end)                  => 'Joins ' . $joined->format('j M Y') . ', after this month',
                    $inForce->has($e->id)                         => null,
                    isset($firstDetails[$e->id])                  => 'Pay details start ' . Carbon::parse($firstDetails[$e->id])->format('j M Y') . ', after this month',
                    default                                       => 'No pay details',
                };

                return $reason ? ['uuid' => $e->uuid, 'name' => trim(preg_replace('/\s+/', ' ', $e->name)), 'staff_id' => $e->staff_id, 'reason' => $reason] : null;
            })->filter()->values();

        return ['count' => $people->count(), 'people' => $people->take($limit)->all()];
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
