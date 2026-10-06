<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\PayRunStatus;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayArrear;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Models\Payroll\Payslip;
use Illuminate\Support\Facades\DB;

/**
 * Back pay. A regular run checks the employees' paid payslips of the last months (the setting
 * `payroll.arrears_months`, 0 = off): when the basic salary now in force for one of those months
 * is more than was paid (a raise entered with an earlier date), the difference is paid as back pay.
 * Pay cuts aren't recovered. Only basic salary is considered.
 */
class ArrearsService
{
    public function claimFor(PayRun $run, array $employeeIds): void
    {
        DB::transaction(function () use ($run, $employeeIds) {
            $this->release($run);
            $months = (int) setting('payroll.arrears_months', 12);
            if ($run->type !== 'regular' || $months <= 0 || !$employeeIds) {
                return;
            }

            $from = $run->period_start->copy()->subMonthsNoOverflow($months);
            $slips = Payslip::whereIn('employee_id', $employeeIds)
                ->whereHas('run', fn ($q) => $q->where('type', 'regular')->where('status', PayRunStatus::PAID)
                    ->whereDate('period_start', '<', $run->period_start)->whereDate('period_start', '>=', $from))
                ->with('run')->get();
            if ($slips->isEmpty()) {
                return;
            }
            $already = PayArrear::whereIn('payslip_id', $slips->pluck('id'))
                ->where('pay_run_id', '!=', $run->id)->groupBy('payslip_id')->selectRaw('payslip_id, sum(amount) as total')->pluck('total', 'payslip_id');

            $owed = [];
            foreach ($slips as $slip) {
                $profile = EmployeePayProfile::inForceOn($slip->run->period_end)->where('employee_id', $slip->employee_id)->first();
                if (!$profile) {
                    continue;
                }
                $currency = strtoupper($profile->currency ?: (string) ($slip->run->settings['base_currency'] ?? setting('payroll.base_currency', 'GHS')));
                $base = strtoupper((string) ($slip->run->settings['base_currency'] ?? setting('payroll.base_currency', 'GHS')));
                $rate = $currency === $base ? 1.0 : ($slip->run->exchange_rates[$currency] ?? null);
                if ($rate === null) {
                    continue;
                }
                $due = round((float) $profile->basic_salary * (float) $rate * (float) ($slip->proration ?? 1), 2);
                $short = round($due - (float) $slip->basic_salary - (float) ($already[$slip->id] ?? 0), 2);
                if ($short >= 0.01) {
                    $owed[$slip->employee_id][] = [$slip, $short];
                }
            }

            $component = PayComponent::where('code', PayComponent::ARREARS)->firstOrFail();
            foreach ($owed as $employeeId => $items) {
                foreach ($items as [$slip, $short]) {
                    PayArrear::create(['employee_id' => $employeeId, 'payslip_id' => $slip->id, 'amount' => $short, 'pay_run_id' => $run->id]);
                }
                $months = collect($items)->map(fn ($i) => $i[0]->run->period_start->format('M Y'))->implode(', ');
                PayRunInput::create([
                    'pay_run_id' => $run->id, 'employee_id' => $employeeId, 'pay_component_id' => $component->id,
                    'amount' => round(collect($items)->sum(fn ($i) => $i[1]), 2), 'source' => 'arrears', 'notes' => "Back pay for {$months}",
                ]);
            }
        });
    }

    public function release(PayRun $run): void
    {
        PayRunInput::where('pay_run_id', $run->id)->where('source', 'arrears')->delete();
        PayArrear::where('pay_run_id', $run->id)->delete();
    }
}
