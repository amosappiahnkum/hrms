<?php

namespace App\Services\Payroll;

use App\Models\Payroll\PaymentFileLayout;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a pay run produces for others: the payroll register, statutory schedules (SSNIT, Tier 2,
 * GRA PAYE), a journal summary, and payment files. Each returns [headings, rows, title].
 * The statutory schedules hold the standard columns; check them against the current official templates.
 */
class PayRunReports
{
    public const REPORTS = [
        'register' => 'Payroll register',
        'ssnit'    => 'SSNIT contributions',
        'tier2'    => 'Tier 2 schedule',
        'paye'     => 'GRA PAYE return',
        'journal'  => 'Journal summary',
    ];

    public function build(PayRun $run, string $report): array
    {
        $slips = $run->payslips()->with('lines')->get()->sortBy(fn ($p) => $p->employee_snapshot['name'] ?? '')->values();
        $month = Carbon::create($run->year, $run->month)->format('F Y');

        return match ($report) {
            'register' => $this->register($slips, $month),
            'ssnit'    => $this->ssnit($slips, $month),
            'tier2'    => $this->tier2($slips, $month),
            'paye'     => $this->paye($slips, $month),
            'journal'  => $this->journal($slips, $month),
        };
    }

    /** One row per employee, payable in the layout's format. */
    public function paymentFile(PayRun $run, PaymentFileLayout $layout): array
    {
        $narration = 'Salary ' . Carbon::create($run->year, $run->month)->format('F Y');
        $currency = $run->settings['base_currency'] ?? 'GHS';

        $rows = $run->payslips()->get()
            ->filter(fn (Payslip $p) => ($p->payment_snapshot['payment_method'] ?? null) === $layout->payment_method)
            ->filter(fn (Payslip $p) => !$layout->bank_name || strcasecmp(trim($p->payment_snapshot['bank_name'] ?? ''), trim($layout->bank_name)) === 0)
            ->filter(fn (Payslip $p) => (float) $p->net_pay > 0)
            ->sortBy(fn ($p) => $p->employee_snapshot['name'] ?? '')
            ->map(function (Payslip $p) use ($layout, $narration, $currency, $run) {
                $values = ($p->payment_snapshot ?? []) + [
                    'staff_id'  => $p->employee_snapshot['staff_id'] ?? '',
                    'name'      => $p->employee_snapshot['name'] ?? '',
                    'amount'    => number_format((float) $p->net_pay, 2, '.', ''),
                    'currency'  => $currency,
                    'narration' => $narration,
                    'pay_date'  => $run->pay_date?->format('Y-m-d'),
                ];

                return array_map(fn ($col) => (string) ($values[$col['field']] ?? ''), $layout->columns);
            })->values()->all();

        return [$layout->include_header ? array_column($layout->columns, 'heading') : [], $rows, $layout->name];
    }

    private function register(Collection $slips, string $month): array
    {
        // A column per component that appears in the run, in payslip order.
        $codes = $slips->flatMap(fn ($p) => $p->lines)->sortBy('sort_order')->unique('code')->mapWithKeys(fn ($l) => [$l->code => $l->name]);
        $rows = $slips->map(function (Payslip $p) use ($codes) {
            $byCode = $p->lines->groupBy('code')->map(fn ($ls) => round($ls->sum('amount'), 2));

            return [
                $p->employee_snapshot['staff_id'] ?? '', $p->employee_snapshot['name'] ?? '', $p->employee_snapshot['department'] ?? '',
                ...$codes->keys()->map(fn ($c) => $byCode->get($c, 0))->all(),
                (float) $p->gross_pay, (float) $p->total_deductions, (float) $p->net_pay, (float) $p->employer_cost,
            ];
        })->all();

        return [['Staff ID', 'Name', 'Department', ...$codes->values()->all(), 'Gross pay', 'Total deductions', 'Net pay', 'Employer cost'], $rows, "Register {$month}"];
    }

    private function ssnit(Collection $slips, string $month): array
    {
        $rows = $slips->map(fn (Payslip $p) => [
            $p->employee_snapshot['ssnit_number'] ?? '', $p->employee_snapshot['name'] ?? '', $p->employee_snapshot['staff_id'] ?? '',
            (float) $p->basic_salary, (float) $p->ssnit_employee, (float) $p->ssnit_employer,
            round((float) $p->ssnit_employee + (float) $p->ssnit_employer, 2), (float) $p->tier1, (float) $p->tier2,
        ])->all();

        return [['SSNIT number', 'Name', 'Staff ID', 'Basic salary', 'Employee', 'Employer', 'Total', 'Tier 1', 'Tier 2'], $rows, "SSNIT {$month}"];
    }

    private function tier2(Collection $slips, string $month): array
    {
        // By trustee scheme (from the pay profile at the time, kept on the payslip snapshot when present).
        $rows = $slips->map(fn (Payslip $p) => [
            $p->employee_snapshot['tier2_scheme'] ?? '', $p->employee_snapshot['ssnit_number'] ?? '', $p->employee_snapshot['name'] ?? '',
            (float) $p->basic_salary, (float) $p->tier2,
        ])->sortBy(fn ($r) => $r[0] . $r[2])->values()->all();

        return [['Trustee / scheme', 'SSNIT number', 'Name', 'Basic salary', 'Tier 2'], $rows, "Tier 2 {$month}"];
    }

    private function paye(Collection $slips, string $month): array
    {
        $rows = $slips->map(function (Payslip $p) {
            $allowances = round((float) $p->gross_pay - (float) $p->basic_salary, 2);

            return [
                $p->employee_snapshot['tin'] ?? '', $p->employee_snapshot['name'] ?? '', $p->employee_snapshot['position'] ?? '',
                (float) $p->basic_salary, $allowances, (float) $p->gross_pay, (float) $p->ssnit_employee,
                (float) $p->taxable_income, (float) $p->paye,
            ];
        })->all();

        return [['TIN', 'Name', 'Position', 'Basic salary', 'Allowances', 'Gross pay', 'SSNIT (employee)', 'Chargeable income', 'PAYE'], $rows, "PAYE {$month}"];
    }

    private function journal(Collection $slips, string $month): array
    {
        $codes = \App\Models\Payroll\PayComponent::pluck('account_code', 'code');
        $lines = $slips->flatMap(fn ($p) => $p->lines);
        $rows = $lines->groupBy('code')->map(fn ($ls, $code) => [
            $codes->get($code) ?? '', $code, $ls->first()->name, ucfirst(str_replace('_', ' ', $ls->first()->kind)), round($ls->sum('amount'), 2),
        ])->values()->all();
        $rows[] = ['', 'NET', 'Net pay (to employees)', 'Payable', round($slips->sum(fn ($p) => (float) $p->net_pay), 2)];

        return [['Account code', 'Code', 'Item', 'Kind', 'Amount'], $rows, "Journal {$month}"];
    }
}
