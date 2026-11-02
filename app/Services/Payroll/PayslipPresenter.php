<?php

namespace App\Services\Payroll;

use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A payslip as people read it, for the PDF and the screens alike: who and when (header), the earnings and
 * deductions (with any lines the organization always prints, at "–" when there's nothing), and the totals.
 */
class PayslipPresenter
{
    /** Statutory lines the calculator adds, so they can be printed at "–" too. */
    private const STATUTORY = [
        'SSNIT'    => ['name' => 'SSNIT (employee)', 'kind' => 'deduction', 'sort_order' => 900],
        'TIER3'    => ['name' => 'Tier 3 (voluntary)', 'kind' => 'deduction', 'sort_order' => 901],
        'PAYE'     => ['name' => 'Income tax (PAYE)', 'kind' => 'deduction', 'sort_order' => 902],
        'WHT'      => ['name' => 'Withholding tax', 'kind' => 'deduction', 'sort_order' => 902],
        'SSNIT_ER' => ['name' => 'SSNIT (employer)', 'kind' => 'employer_contribution', 'sort_order' => 950],
    ];

    /**
     * @return array{header: array, earnings: array, deductions: array, employer: array, totals: array}
     */
    public function present(Payslip $payslip): array
    {
        $payslip->loadMissing(['run', 'lines']);
        $rows = $this->rows($payslip);

        return [
            'header'     => $this->header($payslip),
            'earnings'   => $rows->where('kind', 'earning')->values()->all(),
            'deductions' => $rows->where('kind', 'deduction')->values()->all(),
            'employer'   => $rows->where('kind', 'employer_contribution')->values()->all(),
            'totals'     => [
                'gross_pay'              => (float) $payslip->gross_pay,
                'total_deductions'       => (float) $payslip->total_deductions,
                'employer_contributions' => round((float) $payslip->lines->where('kind', 'employer_contribution')->sum('amount'), 2),
                // Gross plus what the employer pays on top.
                'staff_cost'             => (float) $payslip->employer_cost,
                'net_pay'                => (float) $payslip->net_pay,
            ],
        ];
    }

    public function header(Payslip $payslip): array
    {
        $run = $payslip->run;
        $e = $payslip->employee_snapshot ?? [];

        return [
            'name'            => $e['name'] ?? null,
            'staff_id'        => $e['staff_id'] ?? null,
            'position'        => $e['position'] ?? null,
            'department'      => $e['department'] ?? null,
            'cost_centre'     => $e['cost_centre'] ?? $e['department'] ?? null,
            'engagement_date' => $e['engagement_date'] ?? null,
            'ssnit_number'    => $e['ssnit_number'] ?? null,
            'month'           => Carbon::create($run->year, $run->month, 1)->format('F Y'),
            'off_cycle'       => $run->type === 'off_cycle',
            'period_start'    => $run->period_start->toDateString(),
            'period_end'      => $run->period_end->toDateString(),
            'pay_date'        => $run->pay_date?->toDateString(),
            'days'            => $payslip->days_worked !== null ? ['working' => (int) $payslip->working_days, 'worked' => (float) $payslip->days_worked] : null,
            'currency'        => $run->settings['base_currency'] ?? setting('payroll.base_currency', 'GHS'),
            'exchange_rates'  => $this->rates($payslip),
            'payment'         => $this->payment($payslip),
        ];
    }

    /**
     * The rates this payslip was converted at: for each foreign currency on it, the rate basic salary used
     * and the one everything else used (one entry when they're the same).
     */
    private function rates(Payslip $payslip): array
    {
        $out = [];
        foreach ($payslip->lines->whereNotNull('original_currency')->groupBy('original_currency') as $currency => $lines) {
            $snapshot = $payslip->run->exchange_rates[$currency] ?? [];
            $dateFor = fn (float $rate, array $types) => collect($types)->map(fn ($t) => $snapshot[$t] ?? null)
                ->first(fn ($r) => $r && (float) $r['rate'] === $rate)['date'] ?? null; // null: an employee's fixed rate
            $basic = $lines->firstWhere('code', PayComponent::BASIC);
            $other = $lines->firstWhere('code', '!=', PayComponent::BASIC);

            if ($basic && $other && (float) $basic->exchange_rate !== (float) $other->exchange_rate) {
                $out[] = ['currency' => $currency, 'applies_to' => 'basic', 'rate' => (float) $basic->exchange_rate,
                    'date' => $dateFor((float) $basic->exchange_rate, [ExchangeRate::BASIC, ExchangeRate::STANDARD])];
                $out[] = ['currency' => $currency, 'applies_to' => 'allowances', 'rate' => (float) $other->exchange_rate,
                    'date' => $dateFor((float) $other->exchange_rate, [ExchangeRate::STANDARD])];
            } else {
                $rate = (float) ($basic ?? $other)->exchange_rate;
                $out[] = ['currency' => $currency, 'applies_to' => 'all', 'rate' => $rate, 'date' => $dateFor($rate, [ExchangeRate::BASIC, ExchangeRate::STANDARD])];
            }
        }

        return $out;
    }

    /** How it was paid; the account number masked unless the organization prints it in full. */
    private function payment(Payslip $payslip): array
    {
        $pay = $payslip->payment_snapshot ?? [];
        $full = setting('payroll.payslip_account_number', 'masked') === 'full';
        $mask = fn (?string $n) => $n === null || $n === '' ? null : ($full ? $n : '•••• ' . substr($n, -4));
        $method = $pay['payment_method'] ?? null;

        return [
            'method'       => $method,
            'method_label' => EmployeePayProfile::PAYMENT_METHODS[$method] ?? ($method ? ucfirst($method) : null),
            'bank_name'    => $method === 'bank' ? ($pay['bank_name'] ?? null) : null,
            'bank_branch'  => $method === 'bank' ? ($pay['bank_branch'] ?? null) : null,
            'account_name' => $pay['account_name'] ?? null,
            'account'      => $method === 'bank' ? $mask($pay['account_number'] ?? null)
                : ($method === 'mobile_money' ? trim(($pay['mobile_money_provider'] ?? '') . ' ' . ($mask($pay['mobile_money_number'] ?? null) ?? '')) ?: null : null),
        ];
    }

    /** Lines shown on payslips, plus the always-printed ones it doesn't have (amount null = "–"), in payslip order. */
    private function rows(Payslip $payslip): Collection
    {
        $rows = $payslip->lines->where('show_on_payslip', true)->map(fn ($l) => [
            'code'     => $l->code,
            'name'     => $l->name,
            'kind'     => $l->kind,
            'quantity' => $l->quantity !== null ? (float) $l->quantity : null,
            'amount'   => (float) $l->amount,
            'order'    => (int) $l->sort_order,
        ]);

        $have = $payslip->lines->pluck('code')->flip();
        $fixed = collect((array) setting('payroll.payslip_fixed_lines', []))->map(fn ($c) => strtoupper((string) $c))->reject(fn ($c) => $have->has($c));
        $components = PayComponent::whereIn('code', $fixed)->where('show_on_payslip', true)->get()->keyBy('code');
        foreach ($fixed as $code) {
            $def = $components->has($code)
                ? ['name' => $components[$code]->name, 'kind' => $components[$code]->kind->value, 'sort_order' => $components[$code]->sort_order]
                : (self::STATUTORY[$code] ?? null);
            if ($def) {
                $rows->push(['code' => $code, 'name' => $def['name'], 'kind' => $def['kind'], 'quantity' => null, 'amount' => null, 'order' => (int) $def['sort_order']]);
            }
        }

        return $rows->sortBy([['order', 'asc'], ['name', 'asc']])->values()->map(fn ($r) => collect($r)->except('order')->all());
    }
}
