<?php

namespace App\Services\Payroll;

use App\Enums\Payroll\PayRunStatus;
use App\Helpers\PdfLetterhead;
use App\Models\Payroll\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/** A payslip as a PDF: lines as calculated, optionally employer contributions and year-to-date totals. */
class PayslipDocument
{
    public function download(Payslip $payslip): Response
    {
        $payslip->loadMissing(['run', 'lines']);
        $run = $payslip->run;
        $letterhead = PdfLetterhead::get();

        // Year to date: this employee's approved or paid runs this year, up to this one.
        $ytd = setting('payroll.payslip_show_ytd', true)
            ? Payslip::where('employee_id', $payslip->employee_id)
                ->whereHas('run', fn ($r) => $r->where('year', $run->year)->where('month', '<=', $run->month)
                    ->where(fn ($q) => $q->whereIn('status', [PayRunStatus::APPROVED->value, PayRunStatus::PAID->value])->orWhere('id', $run->id)))
                ->selectRaw('SUM(gross_pay) gross, SUM(paye) paye, SUM(ssnit_employee) ssnit, SUM(net_pay) net')
                ->first()
            : null;

        return Pdf::loadView('payroll.payslip', [
            'payslip'      => $payslip,
            'run'          => $run,
            'currency'     => $run->settings['base_currency'] ?? setting('payroll.base_currency', 'GHS'),
            'showEmployer' => (bool) setting('payroll.payslip_show_employer', true),
            'ytd'          => $ytd,
            'company'      => $letterhead['company'],
            'logo'         => $letterhead['logo'],
        ])->setPaper('a4')->download('payslip-' . str($payslip->employee_snapshot['name'] ?? 'employee')->slug() . "-{$run->year}-" . str_pad($run->month, 2, '0', STR_PAD_LEFT) . '.pdf');
    }
}
