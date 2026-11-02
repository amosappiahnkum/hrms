<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\ComponentCalculation;
use App\Enums\Payroll\ComponentKind;
use App\Exceptions\UserFacingException;
use App\Models\Payroll\EmployeePayComponent;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\StatutoryRateSet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out one employee's pay for a period, Ghana-style:
 * earnings (pro-rated, converted to the base currency) → SSNIT on SSNIT-able earnings (capped) →
 * Tier 3 → taxable income (minus employee SSNIT, Tier 3 relief, pre-tax deductions, reliefs) →
 * PAYE by bands (overtime and non-residents per the rules) → post-tax deductions → net pay.
 * Returns the lines and totals; it saves nothing.
 */
class PayrollCalculator
{
    /**
     * @param array{start: Carbon, end: Carbon} $period
     * @param array<string, array{standard: ?array{rate: float, date: string}, basic: ?array{rate: float, date: string}}> $rates
     *        exchange rates to the base currency in force for the run, by currency (as `PayRunService::ratesOn()` gives them)
     * @param array{base_currency: string, working_days_per_month: int, hours_per_day: float, overtime_tax_method: string, bonus_tax_method?: string,
     *              working_days?: int, overtime_hourly_basis?: string, overtime_monthly_hours?: float, overtime_rate_type?: string,
     *              rate_date?: string} $settings
     *        `working_days`: the period's working days (for components that follow days worked).
     */
    public function __construct(
        private readonly array $period,
        private readonly StatutoryRateSet $statutory,
        private readonly array $rates,
        private readonly array $settings,
        private readonly PayComponent $basic,
    ) {
    }

    /**
     * @param Collection<EmployeePayComponent> $components recurring components active in the period
     * @param array<array{component: PayComponent, quantity: ?float, amount: ?float, source: string}> $inputs per-run items (overtime, one-offs…)
     * @param array{off_cycle?: bool, taxable?: float, ssnit_base?: float, bonus_concession_ytd?: float, freelance?: bool} $prior
     *        Off-cycle: only the inputs are paid, taxed on top of what the month's earlier runs taxed (`taxable`,
     *        `ssnit_base`). Bonus taxed at the bonus rate earlier in the year (`bonus_concession_ytd`).
     *        `freelance`: no SSNIT, and taxed by withholding when the organization chooses (setting `freelance_tax`).
     */
    public function calculate(EmployeePayProfile $profile, Collection $components, array $inputs, ?Carbon $joined, ?Carbon $left, bool $hasSsnitNumber, array $prior = []): array
    {
        $warnings = [];
        $offCycle = (bool) ($prior['off_cycle'] ?? false);
        $freelance = (bool) ($prior['freelance'] ?? false);
        // A per-unit component given to the employee with their own amount: that amount is their rate (e.g. a
        // freelancer's day rate), paid on the month's quantity.
        $ownRates = $components
            ->filter(fn ($given) => $given->amount !== null && $given->component->calculation === ComponentCalculation::RATE_PER_UNIT
                && $given->component->quantity_source === PayComponent::QUANTITY_INPUT)
            ->mapWithKeys(fn ($given) => [$given->pay_component_id => (float) $given->amount])->all();
        $factor = $offCycle ? 1.0 : $this->proration($joined, $left);
        // Basic converts at the employee's fixed rate, else the basic rate (or the standard one).
        $basicRate = $this->basicRate($profile);
        $basicMonthly = (float) $profile->basic_salary * $basicRate;
        // Overtime's hourly rate may convert basic at the standard rate instead (setting).
        $overtimeBasic = ($this->settings['overtime_rate_type'] ?? ExchangeRate::BASIC) === ExchangeRate::STANDARD
            ? $this->convert((float) $profile->basic_salary, $profile->currency)
            : $basicMonthly;
        $hourly = $overtimeBasic / $this->overtimeHours();
        $lines = [];

        // Days worked aren't paid: they set the quantity or share of the components that follow them.
        $daysWorked = null;
        $inputs = array_values(array_filter($inputs, function ($input) use (&$daysWorked) {
            if ($input['component']->code !== PayComponent::DAYS_WORKED) {
                return true;
            }
            $daysWorked = ($daysWorked ?? 0) + (float) $input['quantity'];

            return false;
        }));
        $workingDays = $this->settings['working_days'] ?? null;
        $usedDaysWorked = false;

        // Basic salary (not in an off-cycle run, which pays only its inputs).
        if (!$offCycle) {
            $lines[] = $this->line($this->basic, 'profile', round($basicMonthly * ($this->basic->prorate ? $factor : 1), 2), (float) $profile->basic_salary, $profile->currency, $basicRate);
        }

        // Recurring components.
        foreach ($offCycle ? [] : $components as $given) {
            $c = $given->component;
            $override = $given->amount !== null ? (float) $given->amount : null;

            // Per day worked (e.g. lunch), or shared by days worked ÷ working days (e.g. transport).
            $perDayWorked = $c->calculation === ComponentCalculation::RATE_PER_UNIT && $c->quantity_source === PayComponent::FROM_DAYS_WORKED;
            $byDaysWorked = !$perDayWorked && $c->prorate && $c->prorate_basis === PayComponent::FROM_DAYS_WORKED
                && in_array($c->calculation, [ComponentCalculation::FIXED, ComponentCalculation::MANUAL, ComponentCalculation::PERCENT_OF_BASIC], true);
            if ($perDayWorked || $byDaysWorked) {
                if ($daysWorked === null) {
                    $warnings[] = "{$c->name}: no days worked recorded, so it was left out.";
                    continue;
                }
                $usedDaysWorked = true;
                if ($perDayWorked) {
                    $rate = $override ?? (float) $c->rate;
                    $line = $this->line($c, 'component', round($this->convert($rate, $c->currency) * $daysWorked, 2), $rate * $daysWorked, $c->currency);
                    $line['quantity'] = $daysWorked;
                    $line['rate'] = $rate;
                } else {
                    $share = $workingDays ? min(1, $daysWorked / $workingDays) : 0.0;
                    $full = $c->calculation === ComponentCalculation::PERCENT_OF_BASIC
                        ? $basicMonthly * ($override ?? (float) $c->rate) / 100
                        : $this->convert($override ?? (float) $c->rate, $c->currency);
                    $original = $c->calculation === ComponentCalculation::PERCENT_OF_BASIC ? null : ($override ?? (float) $c->rate);
                    $line = $this->line($c, 'component', round($full * $share, 2), $original, $c->currency);
                    $line['quantity'] = $daysWorked;
                }
                $lines[] = $line;
                continue;
            }

            // Per-unit items with an entered quantity are paid from the month's inputs (at the employee's rate).
            if ($c->calculation === ComponentCalculation::RATE_PER_UNIT) {
                continue;
            }

            $amount = match ($c->calculation) {
                ComponentCalculation::PERCENT_OF_BASIC => $basicMonthly * ($override ?? (float) $c->rate) / 100,
                ComponentCalculation::FIXED, ComponentCalculation::MANUAL => $this->convert($override ?? (float) $c->rate, $c->currency),
                // Per-hour items need a quantity: a standing override amount is used as is.
                default => $override !== null ? $this->convert($override, $c->currency) : null,
            };
            if ($amount === null) {
                $warnings[] = "{$c->name}: needs a quantity each pay run, so it was left out.";
                continue;
            }
            $original = $c->calculation === ComponentCalculation::PERCENT_OF_BASIC ? null : ($override ?? (float) $c->rate);
            $lines[] = $this->line($c, 'component', round($amount * ($c->prorate ? $factor : 1), 2), $original, $c->currency);
        }

        // Per-run inputs: overtime hours, unit inputs, one-offs.
        foreach ($inputs as $input) {
            $c = $input['component'];
            $quantity = $input['quantity'];
            $rate = $c->calculation === ComponentCalculation::RATE_PER_UNIT ? ($ownRates[$c->id] ?? (float) $c->rate) : (float) $c->rate;
            $amount = match ($c->calculation) {
                ComponentCalculation::HOURLY_MULTIPLIER => $hourly * $rate * (float) $quantity,
                ComponentCalculation::RATE_PER_UNIT     => $this->convert($rate, $c->currency) * (float) $quantity,
                ComponentCalculation::PERCENT_OF_BASIC  => $basicMonthly * (float) ($input['amount'] ?? $c->rate) / 100,
                default                                 => $this->convert((float) ($input['amount'] ?? $c->rate), $c->currency),
            };
            $line = $this->line($c, $input['source'], round($amount, 2), $input['amount'], $c->currency);
            $line['quantity'] = $quantity;
            $line['rate'] = $rate;
            $lines[] = $line;
        }
        // Freelancers on day rates have no basic salary: it isn't printed.
        if (!$offCycle && (float) $profile->basic_salary == 0.0) {
            $lines[0]['show_on_payslip'] = false;
        }

        $earnings = collect($lines)->where('kind', ComponentKind::EARNING->value);
        $deductions = collect($lines)->where('kind', ComponentKind::DEDUCTION->value);
        $gross = round($earnings->sum('amount'), 2);

        // SSNIT on SSNIT-able earnings, up to the maximum insurable earnings (less what the month's earlier runs used).
        $s = $this->statutory->ssnit;
        // Freelancers aren't covered by SSNIT.
        $ssnitBase = $freelance ? 0.0 : $earnings->where('ssnit_applicable', true)->sum('amount');
        if (!empty($s['max_insurable_earnings'])) {
            $ssnitBase = min($ssnitBase, max(0, (float) $s['max_insurable_earnings'] - (float) ($prior['ssnit_base'] ?? 0)));
        }
        $ssnitBase = round($ssnitBase, 2);
        $ssnitEmployee = round($ssnitBase * $s['employee_rate'] / 100, 2);
        $ssnitEmployer = round($ssnitBase * $s['employer_rate'] / 100, 2);
        $tier1 = round($ssnitBase * $s['tier1_rate'] / 100, 2);
        $tier2 = round($ssnitBase * $s['tier2_rate'] / 100, 2);
        if (!$hasSsnitNumber && !$freelance) {
            $warnings[] = 'No SSNIT number.';
        }

        // Tier 3 (voluntary) on basic; relieved from tax up to the limit (% of gross).
        $basicPaid = $offCycle ? 0.0 : (float) $lines[0]['amount'];
        $tier3 = $profile->tier3_percent ? round($basicPaid * (float) $profile->tier3_percent / 100, 2) : 0.0;
        $tier3Relief = $this->statutory->tier3_relief_limit_percent !== null
            ? min($tier3, $gross * (float) $this->statutory->tier3_relief_limit_percent / 100)
            : 0.0;

        $isOvertime = fn ($l) => $l['source'] === 'overtime' || $l['calculation'] === ComponentCalculation::HOURLY_MULTIPLIER->value;
        $overtime = $earnings->filter($isOvertime)->sum('amount');
        $bonus = $earnings->where('is_bonus', true)->where('taxable', true)->sum('amount');

        // Overtime taxed apart (GRA junior staff: residents whose taxable employment income for the year is up to
        // the threshold) or as income. The year's income: the month's taxable earnings without overtime and bonuses,
        // times 12 (an off-cycle run, which pays only its inputs, goes by basic salary).
        $ot = $this->statutory->overtime_tax;
        $regularIncome = $offCycle ? $basicMonthly : $earnings->where('taxable', true)->reject($isOvertime)->where('is_bonus', '!=', true)->sum('amount');
        $overtimeApart = $overtime > 0 && $profile->tax_resident && $this->settings['overtime_tax_method'] === 'gra_junior' && $ot
            && $regularIncome * 12 <= (float) ($ot['annual_income_threshold'] ?? $ot['annual_basic_threshold'] ?? 0);

        // Non-residents: overtime and bonuses at their own flat rate (GRA: 20%), apart from their income.
        $nonResidentSpecial = !$profile->tax_resident
            ? $earnings->filter($isOvertime)->where('taxable', true)->sum('amount') + $bonus
            : 0.0;

        // Bonus at the bonus rate, up to a share of annual basic in the year; the rest as income (residents).
        $bt = $this->statutory->bonus_tax;
        $bonusConcession = 0.0;
        if ($bonus > 0 && ($this->settings['bonus_tax_method'] ?? 'gra_bonus') === 'gra_bonus' && $bt && $profile->tax_resident) {
            $allowance = $basicMonthly * 12 * (float) $bt['percent_of_annual_basic'] / 100;
            $bonusConcession = round(min($bonus, max(0, $allowance - (float) ($prior['bonus_concession_ytd'] ?? 0))), 2);
        }
        $bonusTax = round($bonusConcession * (float) ($bt['rate'] ?? 0) / 100, 2);

        $taxableEarnings = $earnings->where('taxable', true)->sum('amount') - ($overtimeApart ? $overtime : 0) - $bonusConcession - $nonResidentSpecial;
        $preTax = $deductions->where('taxable', true)->sum('amount');
        $beforeReliefs = max(0, $taxableEarnings - $ssnitEmployee - $tier3Relief - $preTax);
        // Reliefs are given once a month, in the regular run.
        $taxable = round(max(0, $beforeReliefs - ($offCycle ? 0 : $this->reliefs($profile, $beforeReliefs))), 2);

        // Off-cycle: the tax on the month's total less the tax the earlier runs' income already bore.
        $earlier = (float) ($prior['taxable'] ?? 0);
        $paye = $profile->tax_resident
            ? $this->bands($earlier + $taxable) - $this->bands($earlier)
            : $taxable * (float) $this->statutory->non_resident_rate / 100
                + $nonResidentSpecial * (float) ($this->statutory->non_resident_special_rate ?? 20) / 100;
        $paye += $bonusTax;
        if ($overtimeApart) {
            $lowerCap = $basicMonthly * (float) $ot['percent_of_basic'] / 100;
            $paye += min($overtime, $lowerCap) * (float) $ot['lower_rate'] / 100 + max(0, $overtime - $lowerCap) * (float) $ot['higher_rate'] / 100;
        }
        $paye = round($paye, 2);

        // Freelancers taxed by withholding: a flat rate on what they're paid, instead of PAYE.
        $withholding = 0.0;
        if ($freelance && ($this->settings['freelance_tax'] ?? 'paye') === 'withholding') {
            $rate = $this->statutory->freelance_withholding_rate;
            if ($rate === null) {
                throw new UserFacingException("The statutory rates \"{$this->statutory->name}\" have no withholding rate for freelancers. Set it in Payroll → Settings → Statutory rates.");
            }
            $taxable = round(max(0, $earnings->where('taxable', true)->sum('amount') - $preTax), 2);
            $withholding = round($taxable * (float) $rate / 100, 2);
            $paye = 0.0;
            $bonusConcession = 0.0;
            $bonusTax = 0.0;
        }

        // Statutory lines, so the payslip reads uniformly.
        $lines[] = $this->statutoryLine('SSNIT', 'SSNIT (employee)', ComponentKind::DEDUCTION, $ssnitEmployee, 900);
        if ($tier3 > 0) {
            $lines[] = $this->statutoryLine('TIER3', 'Tier 3 (voluntary)', ComponentKind::DEDUCTION, $tier3, 901);
        }
        if ($withholding > 0) {
            $lines[] = $this->statutoryLine('WHT', 'Withholding tax', ComponentKind::DEDUCTION, $withholding, 902);
        } else {
            $lines[] = $this->statutoryLine('PAYE', 'Income tax (PAYE)', ComponentKind::DEDUCTION, $paye, 902);
        }
        $lines[] = $this->statutoryLine('SSNIT_ER', 'SSNIT (employer)', ComponentKind::EMPLOYER_CONTRIBUTION, $ssnitEmployer, 950);

        $totalDeductions = round($ssnitEmployee + $tier3 + $paye + $withholding + $deductions->sum('amount'), 2);
        $net = round($gross - $totalDeductions, 2);
        if ($net < 0) {
            $warnings[] = 'Deductions exceed pay: net pay is negative.';
        }
        $employerExtras = collect($lines)->where('kind', ComponentKind::EMPLOYER_CONTRIBUTION->value)->where('source', '!=', 'statutory')->sum('amount');

        return [
            'proration'        => round($factor, 4),
            'working_days'     => $usedDaysWorked ? $workingDays : null,
            'days_worked'      => $usedDaysWorked ? $daysWorked : null,
            'basic_salary'     => $basicPaid,
            'gross_pay'        => $gross,
            'taxable_income'   => $taxable,
            'ssnit_base'       => $ssnitBase,
            'ssnit_employee'   => $ssnitEmployee,
            'ssnit_employer'   => $ssnitEmployer,
            'tier1'            => $tier1,
            'tier2'            => $tier2,
            'tier3'            => $tier3,
            'paye'             => $paye,
            'withholding_tax'  => $withholding,
            'bonus_concession' => $bonusConcession,
            'bonus_tax'        => $bonusTax,
            'total_deductions' => $totalDeductions,
            'net_pay'          => $net,
            'employer_cost'    => round($gross + $ssnitEmployer + $employerExtras, 2),
            'warnings'         => $warnings,
            'lines'            => array_map(fn ($l) => collect($l)->except(['calculation', 'is_bonus'])->all(), $lines),
        ];
    }

    /** Share of the month worked: calendar days from joining to leaving within the period. */
    public function proration(?Carbon $joined, ?Carbon $left): float
    {
        $start = $this->period['start'];
        $end = $this->period['end'];
        $from = $joined && $joined->gt($start) ? $joined : $start;
        $to = $left && $left->lt($end) ? $left : $end;

        if ($to->lt($from)) {
            return 0.0;
        }

        return ($from->diffInDays($to) + 1) / ($start->diffInDays($end) + 1);
    }

    /**
     * Hours in a month, for the hourly rate overtime is paid at (setting `payroll.overtime_hourly_basis`):
     * fixed working days × hours a day (default), the period's working days × hours a day, or a fixed number of hours.
     */
    public function overtimeHours(): float
    {
        $hoursPerDay = max(0.5, (float) $this->settings['hours_per_day']);

        return match ($this->settings['overtime_hourly_basis'] ?? 'fixed_days') {
            'period_working_days' => max(1, (int) ($this->settings['working_days'] ?? $this->settings['working_days_per_month'])) * $hoursPerDay,
            'fixed_hours'         => max(1, (float) ($this->settings['overtime_monthly_hours'] ?? 173.33)),
            default               => max(1, (int) $this->settings['working_days_per_month']) * $hoursPerDay,
        };
    }

    /** PAYE by the monthly bands. */
    public function bands(float $taxable): float
    {
        $remaining = $taxable;
        $tax = 0.0;
        foreach ($this->statutory->paye_bands as $band) {
            $chunk = $band['limit'] === null ? $remaining : min($remaining, (float) $band['limit']);
            $tax += $chunk * (float) $band['rate'] / 100;
            $remaining -= $chunk;
            if ($remaining <= 0) {
                break;
            }
        }

        return $tax;
    }

    /** Monthly value of the reliefs claimed (annual amounts ÷ 12; percentages of income). */
    private function reliefs(EmployeePayProfile $profile, float $income): float
    {
        $defined = collect($this->statutory->reliefs ?? [])->keyBy('code');

        return collect($profile->reliefs ?? [])->sum(function ($claim) use ($defined, $income) {
            $def = $defined->get($claim['code']);
            if (!$def) {
                return 0;
            }
            if ($def['percent_of_income'] !== null) {
                return $income * (float) $def['percent_of_income'] / 100;
            }
            $units = min($claim['units'] ?? 1, $def['max_units'] ?? PHP_INT_MAX);

            return (float) $def['annual_amount'] * $units / 12;
        });
    }

    /** An amount in the base currency, at the run's standard rate. */
    private function convert(float $amount, ?string $currency): float
    {
        return $amount * $this->rate($currency);
    }

    /** The run's rate for a currency (1 for the base currency); a basic rate falls back to the standard one. */
    private function rate(?string $currency, string $type = ExchangeRate::STANDARD): float
    {
        $currency = strtoupper($currency ?: $this->settings['base_currency']);
        if ($currency === strtoupper($this->settings['base_currency'])) {
            return 1.0;
        }
        $rates = $this->rates[$currency] ?? [];
        $rate = ($type === ExchangeRate::BASIC ? ($rates[ExchangeRate::BASIC]['rate'] ?? null) : null) ?? ($rates[ExchangeRate::STANDARD]['rate'] ?? null);
        if ($rate === null) {
            $on = isset($this->settings['rate_date']) ? Carbon::parse($this->settings['rate_date'])->format('j M Y') : $this->period['end']->format('j M Y');
            throw new UserFacingException("There's no {$currency} exchange rate on or before {$on}. Add it in Payroll → Settings → Exchange rates.");
        }

        return (float) $rate;
    }

    /** The rate basic salary converts at: the employee's fixed rate, else the run's basic rate (or standard). */
    private function basicRate(EmployeePayProfile $profile): float
    {
        $foreign = $profile->currency && strtoupper($profile->currency) !== strtoupper($this->settings['base_currency']);
        if ($foreign && $profile->fixed_exchange_rate !== null) {
            return (float) $profile->fixed_exchange_rate;
        }

        return $this->rate($profile->currency, ExchangeRate::BASIC);
    }

    /** @param ?float $rate the exchange rate used, when not the standard one (basic salary) */
    private function line(PayComponent $c, string $source, float $amount, ?float $original, ?string $currency, ?float $rate = null): array
    {
        $currency = $currency ? strtoupper($currency) : null;
        $foreign = $currency && $currency !== strtoupper($this->settings['base_currency']);

        return [
            'pay_component_id' => $c->id,
            'code'             => $c->code,
            'name'             => $c->name,
            'kind'             => $c->kind->value,
            'calculation'      => $c->calculation->value,
            'source'           => $source,
            'quantity'         => null,
            'rate'             => null,
            'original_amount'  => $foreign ? $original : null,
            'original_currency'=> $foreign ? $currency : null,
            'exchange_rate'    => $foreign ? ($rate ?? $this->rate($currency)) : null,
            'amount'           => $amount,
            'taxable'          => $c->taxable,
            'is_bonus'         => (bool) $c->is_bonus,
            'ssnit_applicable' => $c->ssnit_applicable,
            'show_on_payslip'  => $c->show_on_payslip,
            'sort_order'       => $c->sort_order,
        ];
    }

    private function statutoryLine(string $code, string $name, ComponentKind $kind, float $amount, int $sort): array
    {
        return [
            'pay_component_id' => null, 'code' => $code, 'name' => $name, 'kind' => $kind->value, 'calculation' => null,
            'source' => 'statutory', 'quantity' => null, 'rate' => null, 'original_amount' => null, 'original_currency' => null,
            'exchange_rate' => null, 'amount' => $amount, 'taxable' => false, 'ssnit_applicable' => false, 'show_on_payslip' => true, 'sort_order' => $sort,
        ];
    }
}
