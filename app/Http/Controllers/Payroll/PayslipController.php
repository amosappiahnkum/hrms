<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\Payroll\PayRunStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\Payslip;
use App\Services\Payroll\PayslipDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Payslips as documents: HR for any run; employees their own once the run is paid. */
class PayslipController extends Controller
{
    public function __construct(private readonly PayslipDocument $document)
    {
    }

    public function pdf(PayRun $payRun, Payslip $payslip): Response
    {
        abort_unless($payslip->pay_run_id === $payRun->id, 404);

        return $this->document->download($payslip);
    }

    /** Self-service: own payslips from paid runs, newest first. */
    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()->employee_id, 404, 'Your account is not linked to an employee record.');

        $slips = Payslip::where('employee_id', $request->user()->employee_id)
            ->whereHas('run', fn ($r) => $r->where('status', PayRunStatus::PAID->value))
            ->with('run')
            ->get()
            ->sortByDesc(fn ($p) => sprintf('%04d%02d%s', $p->run->year, $p->run->month, $p->run->type))
            ->values();

        return ApiResponse::success($slips->map(fn (Payslip $p) => [
            'uuid'        => $p->uuid,
            'year'        => $p->run->year,
            'month'       => $p->run->month,
            'type'        => $p->run->type,
            'pay_date'    => $p->run->pay_date?->toDateString(),
            'gross_pay'   => (float) $p->gross_pay,
            'deductions'  => (float) $p->total_deductions,
            'net_pay'     => (float) $p->net_pay,
            'currency'    => $p->run->settings['base_currency'] ?? setting('payroll.base_currency', 'GHS'),
        ]));
    }

    public function myPdf(Request $request, Payslip $payslip): Response
    {
        abort_unless($payslip->employee_id === $request->user()->employee_id && $payslip->run?->status === PayRunStatus::PAID, 404);

        return $this->document->download($payslip);
    }
}
