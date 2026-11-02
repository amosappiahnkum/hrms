<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\PayRunStatus;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\PayslipLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Payroll figures over time: the dashboard (a run's cost by department and component, headcount
 * and what changed since the previous run, the 12-month trend) and the year's reports per
 * employee (year to date, PAYE and SSNIT by month). Counts approved and paid runs only.
 */
class PayrollAnalytics
{
    private const FINAL = [PayRunStatus::APPROVED, PayRunStatus::PAID];

    /** The dashboard for a regular run (the latest approved or paid one by default). */
    public function dashboard(?PayRun $run): array
    {
        $run ??= PayRun::where('type', 'regular')->whereIn('status', self::FINAL)->orderByDesc('year')->orderByDesc('month')->first();
        $trend = $this->trend();
        if (!$run) {
            return ['run' => null, 'trend' => $trend];
        }
        $previous = PayRun::where('type', 'regular')->whereIn('status', self::FINAL)
            ->where(fn ($q) => $q->where('year', '<', $run->year)->orWhere(fn ($w) => $w->where('year', $run->year)->where('month', '<', $run->month)))
            ->orderByDesc('year')->orderByDesc('month')->first();

        $slips = $run->payslips()->get();
        $before = $previous?->payslips()->get() ?? collect();

        return [
            'run'           => ['uuid' => $run->uuid, 'name' => $run->name, 'status' => $run->status->value],
            'previous'      => $previous ? ['uuid' => $previous->uuid, 'name' => $previous->name] : null,
            'totals'        => $run->totals,
            'change'        => $previous ? collect(['employees', 'gross_pay', 'paye', 'net_pay', 'employer_cost'])
                ->mapWithKeys(fn ($k) => [$k => round((float) ($run->totals[$k] ?? 0) - (float) ($previous->totals[$k] ?? 0), 2)]) : null,
            'by_department' => $slips->groupBy(fn ($p) => $p->employee_snapshot['department'] ?? 'No department')
                ->map(fn ($g, $name) => ['name' => $name, 'employees' => $g->count(), 'gross_pay' => round((float) $g->sum('gross_pay'), 2), 'employer_cost' => round((float) $g->sum('employer_cost'), 2)])
                ->sortByDesc('employer_cost')->values(),
            'by_component'  => PayslipLine::whereIn('payslip_id', $slips->pluck('id'))
                ->selectRaw('code, name, kind, sum(amount) as total')->groupBy('code', 'name', 'kind')->get()
                ->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'kind' => $l->kind, 'total' => round((float) $l->total, 2)])
                ->sortByDesc('total')->values(),
            'movements'     => $this->movements($slips, $before),
            'trend'         => $trend,
        ];
    }

    /** Joiners, leavers and the biggest changes in net pay since the previous run. */
    private function movements(Collection $now, Collection $before): array
    {
        $then = $before->keyBy('employee_id');
        $current = $now->keyBy('employee_id');
        $who = fn (Payslip $p) => ['name' => $p->employee_snapshot['name'] ?? '', 'staff_id' => $p->employee_snapshot['staff_id'] ?? null];

        return [
            'joined'  => $before->isEmpty() ? [] : $now->reject(fn ($p) => $then->has($p->employee_id))->map(fn ($p) => $who($p) + ['net_pay' => (float) $p->net_pay])->values(),
            'left'    => $before->reject(fn ($p) => $current->has($p->employee_id))->map(fn ($p) => $who($p) + ['net_pay' => (float) $p->net_pay])->values(),
            'changed' => $now->filter(fn ($p) => $then->has($p->employee_id))
                ->map(fn ($p) => $who($p) + ['from' => (float) $then[$p->employee_id]->net_pay, 'to' => (float) $p->net_pay, 'change' => round((float) $p->net_pay - (float) $then[$p->employee_id]->net_pay, 2)])
                ->filter(fn ($r) => abs($r['change']) >= 0.01)->sortByDesc(fn ($r) => abs($r['change']))->take(10)->values(),
        ];
    }

    /** The last 12 months: each month's runs (regular and off-cycle) together. */
    private function trend(): Collection
    {
        return PayRun::whereIn('status', self::FINAL)->orderByDesc('year')->orderByDesc('month')->get()
            ->groupBy(fn ($r) => sprintf('%04d-%02d', $r->year, $r->month))->take(12)
            ->map(fn ($runs, $period) => [
                'period'        => $period,
                'label'         => Carbon::parse("{$period}-01")->format('M Y'),
                'employees'     => (int) ($runs->firstWhere('type', 'regular')?->totals['employees'] ?? 0),
                'gross_pay'     => round($runs->sum(fn ($r) => (float) ($r->totals['gross_pay'] ?? 0)), 2),
                'net_pay'       => round($runs->sum(fn ($r) => (float) ($r->totals['net_pay'] ?? 0)), 2),
                'employer_cost' => round($runs->sum(fn ($r) => (float) ($r->totals['employer_cost'] ?? 0)), 2),
            ])->sortKeys()->values();
    }

    /** Each employee's year: gross, taxable, PAYE, SSNIT, net, from approved and paid runs. */
    public function yearToDate(int $year, ?int $employeeId = null): Collection
    {
        return $this->slipsOf($year, $employeeId)->groupBy('employee_id')->map(function ($g) {
            $last = $g->sortBy(fn ($p) => $p->run->month)->last();

            return [
                'employee_id'    => $g->first()->employee_id,
                'name'           => $last->employee_snapshot['name'] ?? '',
                'staff_id'       => $last->employee_snapshot['staff_id'] ?? null,
                'department'     => $last->employee_snapshot['department'] ?? null,
                'months'         => $g->pluck('run.month')->unique()->count(),
                'gross_pay'      => round((float) $g->sum('gross_pay'), 2),
                'taxable_income' => round((float) $g->sum('taxable_income'), 2),
                'paye'           => round((float) $g->sum('paye'), 2),
                'ssnit_employee' => round((float) $g->sum('ssnit_employee'), 2),
                'ssnit_employer' => round((float) $g->sum('ssnit_employer'), 2),
                'tier2'          => round((float) $g->sum('tier2'), 2),
                'net_pay'        => round((float) $g->sum('net_pay'), 2),
            ];
        })->sortBy('name')->values();
    }

    /**
     * A figure (PAYE, SSNIT…) per employee per month for the year, with totals: the annual return.
     *
     * @return array{0: array, 1: array, 2: string} headings, rows, title
     */
    public function annual(int $year, string $report): array
    {
        [$fields, $title, $extra] = match ($report) {
            'paye'  => [['taxable_income' => 'taxable', 'paye' => 'PAYE'], "PAYE {$year}", fn ($s) => [$s['tin'] ?? null]],
            'ssnit' => [['ssnit_base' => 'earnings', 'ssnit_employee' => 'employee', 'ssnit_employer' => 'employer'], "SSNIT {$year}", fn ($s) => [$s['ssnit_number'] ?? null]],
            'ytd'   => [['gross_pay' => 'gross', 'paye' => 'PAYE', 'ssnit_employee' => 'SSNIT', 'net_pay' => 'net'], "Year to date {$year}", fn ($s) => [$s['department'] ?? null]],
        };
        $idHeading = ['paye' => 'TIN / Ghana Card', 'ssnit' => 'SSNIT number', 'ytd' => 'Department'][$report];
        $months = range(1, 12);

        $headings = ['Staff ID', 'Name', $idHeading];
        foreach ($fields as $label) {
            foreach ($months as $m) {
                $headings[] = Carbon::create($year, $m)->format('M') . " {$label}";
            }
            $headings[] = "Total {$label}";
        }

        $rows = $this->slipsOf($year)->groupBy('employee_id')->map(function ($g) use ($fields, $months, $extra) {
            $last = $g->sortBy(fn ($p) => $p->run->month)->last();
            $row = [$last->employee_snapshot['staff_id'] ?? null, $last->employee_snapshot['name'] ?? '', ...$extra($last->employee_snapshot ?? [])];
            $byMonth = $g->groupBy(fn ($p) => $p->run->month);
            foreach (array_keys($fields) as $field) {
                foreach ($months as $m) {
                    $row[] = round((float) ($byMonth->get($m)?->sum($field) ?? 0), 2);
                }
                $row[] = round((float) $g->sum($field), 2);
            }

            return $row;
        })->sortBy(fn ($r) => $r[1])->values()->all();

        return [$headings, $rows, $title];
    }

    private function slipsOf(int $year, ?int $employeeId = null): Collection
    {
        return Payslip::whereHas('run', fn ($q) => $q->where('year', $year)->whereIn('status', self::FINAL))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->with('run:id,year,month,type')->get();
    }
}
