<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\Payroll\PayRunStatus;
use App\Exceptions\UserFacingException;
use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PaymentFileLayout;
use App\Models\Payroll\PayRun;
use App\Services\Payroll\PayRunReports;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A pay run's reports (register, SSNIT, Tier 2, PAYE, journal) and payment files. */
class PayRunOutputController extends Controller
{
    public function __construct(private readonly PayRunReports $reports)
    {
    }

    /** The register can be checked while calculated; the rest are for approved or paid runs. */
    public function report(PayRun $payRun, string $report): BinaryFileResponse
    {
        abort_unless(array_key_exists($report, PayRunReports::REPORTS), 404);
        if ($report !== 'register' && !$payRun->status->isFinal()) {
            throw new UserFacingException('Statutory reports are available once the pay run is approved.');
        }
        if (!$payRun->totals) {
            throw new UserFacingException('Calculate the pay run first.');
        }

        [$headings, $rows, $title] = $this->reports->build($payRun, $report);

        return Excel::download(new ArrayExport($headings, $rows, $title), str($title)->slug() . '.xlsx');
    }

    public function paymentFile(Request $request, PayRun $payRun, PaymentFileLayout $paymentFileLayout): BinaryFileResponse|StreamedResponse
    {
        if (!$payRun->status->isFinal()) {
            throw new UserFacingException('Payment files are available once the pay run is approved.');
        }

        [$headings, $rows, $title] = $this->reports->paymentFile($payRun, $paymentFileLayout);
        $name = str("{$title} {$payRun->year}-{$payRun->month}")->slug();

        if ($paymentFileLayout->format === 'xlsx') {
            return Excel::download(new ArrayExport($headings, $rows, $title), "{$name}.xlsx");
        }

        $delimiter = $paymentFileLayout->delimiter === '\t' ? "\t" : $paymentFileLayout->delimiter;

        return response()->streamDownload(function () use ($headings, $rows, $delimiter) {
            $out = fopen('php://output', 'w');
            if ($headings) {
                fputcsv($out, $headings, $delimiter);
            }
            foreach ($rows as $row) {
                fputcsv($out, $row, $delimiter);
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv']);
    }
}
