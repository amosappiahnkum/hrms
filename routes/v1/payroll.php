<?php

use App\Http\Controllers\Payroll\ApprovalController;
use App\Http\Controllers\Payroll\ApprovalWorkflowController;
use App\Http\Controllers\Payroll\OvertimeRequestController;
use App\Http\Controllers\Payroll\OvertimeTypeController;
use App\Http\Controllers\Payroll\TimeInputController;
use App\Http\Controllers\Payroll\LoanController;
use App\Http\Controllers\Payroll\LoanTypeController;
use App\Http\Controllers\Payroll\MyLoanController;
use App\Http\Controllers\Payroll\PayRunController;
use App\Http\Controllers\Payroll\PayRunInputController;
use App\Http\Controllers\Payroll\PayslipController;
use App\Http\Controllers\Payroll\PaymentFileLayoutController;
use App\Http\Controllers\Payroll\PayRunOutputController;
use App\Http\Controllers\Payroll\EmployeePayController;
use App\Http\Controllers\Payroll\ExchangeRateController;
use App\Http\Controllers\Payroll\PayComponentController;
use App\Http\Controllers\Payroll\PayrollSettingsController;
use App\Http\Controllers\Payroll\PayrollReportController;
use App\Http\Controllers\Payroll\StatutoryRateSetController;
use Illuminate\Support\Facades\Route;

// Payroll, overtime and loans: each part has its own flag; the shared configuration is available
// when any of them is on.
Route::middleware('feature:payroll.enabled|payroll.overtime|payroll.loans|payroll.time_inputs')->prefix('payroll')->group(function () {
    Route::middleware('permission:configure-payroll|prepare-payroll|view-payroll')->group(function () {
        Route::get('settings', [PayrollSettingsController::class, 'show']);
        Route::get('approval-workflows', [ApprovalWorkflowController::class, 'index']);
        Route::get('approval-workflows/{approvalWorkflow}/preview', [ApprovalWorkflowController::class, 'preview']);
        Route::get('statutory-rates', [StatutoryRateSetController::class, 'index']);
        Route::get('exchange-rates', [ExchangeRateController::class, 'index']);
        Route::get('pay-components', [PayComponentController::class, 'index']);
    });

    // The approvals inbox, and deciding a step: who may is checked against the workflow.
    Route::get('approvals', [ApprovalController::class, 'index']);
    Route::post('approvals/{approval}/decide', [ApprovalController::class, 'decide']);

    // Overtime.
    Route::middleware('feature:payroll.overtime')->group(function () {
        // Self-service: request own overtime.
        Route::get('my-overtime', [OvertimeRequestController::class, 'mine']);
        Route::get('my-overtime/options', [OvertimeRequestController::class, 'options']);
        Route::get('my-overtime/type-for', [OvertimeRequestController::class, 'typeFor']);
        Route::post('my-overtime', [OvertimeRequestController::class, 'store']);
        Route::post('my-overtime/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel']);

        Route::middleware('permission:view-overtime|configure-payroll|prepare-payroll')->group(function () {
            Route::get('overtime', [OvertimeRequestController::class, 'index']);
            Route::get('overtime/export', [OvertimeRequestController::class, 'export']);
            Route::post('overtime', [OvertimeRequestController::class, 'storeFor']);
        });
        Route::get('overtime-types', [OvertimeTypeController::class, 'index'])->middleware('permission:configure-payroll|prepare-payroll|view-payroll');
        Route::middleware('permission:configure-payroll')->group(function () {
            Route::post('overtime-types', [OvertimeTypeController::class, 'store']);
            Route::put('overtime-types/{overtimeType}', [OvertimeTypeController::class, 'update']);
            Route::delete('overtime-types/{overtimeType}', [OvertimeTypeController::class, 'destroy']);
        });
    });

    // Staff loans.
    Route::middleware('feature:payroll.loans')->group(function () {
        // Self-service: what can be borrowed, requesting, own loans and statements.
        Route::get('my-loans', [MyLoanController::class, 'index']);
        Route::get('my-loans/options', [MyLoanController::class, 'options']);
        Route::post('my-loans/check', [MyLoanController::class, 'check']);
        Route::post('my-loans', [MyLoanController::class, 'store']);
        Route::post('my-loans/{loan}/cancel', [MyLoanController::class, 'cancel']);

        Route::get('loan-types', [LoanTypeController::class, 'index'])->middleware('permission:configure-payroll|manage-loans|view-payroll');
        Route::middleware('permission:configure-payroll')->group(function () {
            Route::post('loan-types', [LoanTypeController::class, 'store']);
            Route::put('loan-types/{loanType}', [LoanTypeController::class, 'update']);
            Route::delete('loan-types/{loanType}', [LoanTypeController::class, 'destroy']);
        });
        Route::middleware('permission:manage-loans')->group(function () {
            Route::get('loans', [LoanController::class, 'index']);
            Route::get('loans/export', [LoanController::class, 'export']);
            Route::get('loans/{loan}', [LoanController::class, 'show']);
            Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse']);
            Route::post('loans/{loan}/repayments', [LoanController::class, 'repay']);
            Route::post('loans/{loan}/settle', [LoanController::class, 'settle']);
            Route::post('loans/{loan}/instalments/{loanInstalment}/pause', [LoanController::class, 'pause']);
            Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel']);
        });
    });

    // Time inputs: units per employee per month.
    Route::middleware(['feature:payroll.time_inputs', 'permission:enter-time-inputs|prepare-payroll'])->group(function () {
        Route::get('time-inputs', [TimeInputController::class, 'index']);
        Route::put('time-inputs', [TimeInputController::class, 'save']);
        Route::get('time-inputs/template', [TimeInputController::class, 'template']);
        Route::post('time-inputs/import', [TimeInputController::class, 'import']);
    });

    // Pay runs.
    Route::middleware('feature:payroll.enabled')->group(function () {
        // Self-service: own payslips from paid runs.
        Route::get('my-payslips', [PayslipController::class, 'mine']);
        Route::get('my-payslips/{payslip}/pdf', [PayslipController::class, 'myPdf']);

        Route::middleware('permission:prepare-payroll|approve-payroll|view-payroll')->group(function () {
            Route::get('runs', [PayRunController::class, 'index']);
            Route::get('runs/{payRun}', [PayRunController::class, 'show']);
            Route::get('runs/{payRun}/payslips', [PayRunController::class, 'payslips']);
            Route::get('runs/{payRun}/payslips/{payslip}', [PayRunController::class, 'payslip']);
            Route::get('runs/{payRun}/inputs', [PayRunInputController::class, 'index']);
            Route::get('runs/{payRun}/payslips/{payslip}/pdf', [PayslipController::class, 'pdf']);
            Route::get('runs/{payRun}/reports/{report}', [PayRunOutputController::class, 'report']);
            Route::get('runs/{payRun}/payment-files/{paymentFileLayout}', [PayRunOutputController::class, 'paymentFile']);
            Route::get('payment-file-layouts', [PaymentFileLayoutController::class, 'index']);
            // The dashboard and the year's reports.
            Route::get('dashboard', [PayrollReportController::class, 'dashboard']);
            Route::get('year-to-date', [PayrollReportController::class, 'yearToDate']);
            Route::get('annual/{report}', [PayrollReportController::class, 'annual']);
        });
        Route::middleware('permission:prepare-payroll')->group(function () {
            Route::post('runs', [PayRunController::class, 'store']);
            Route::post('runs/{payRun}/calculate', [PayRunController::class, 'calculate']);
            Route::post('runs/{payRun}/submit', [PayRunController::class, 'submit']);
            Route::post('runs/{payRun}/paid', [PayRunController::class, 'markPaid']);
            Route::delete('runs/{payRun}', [PayRunController::class, 'destroy']);
            Route::get('inputs-template', [PayRunInputController::class, 'template']);
            Route::post('runs/{payRun}/inputs', [PayRunInputController::class, 'store']);
            Route::post('runs/{payRun}/inputs/import', [PayRunInputController::class, 'import']);
            Route::put('runs/{payRun}/inputs/{payRunInput}', [PayRunInputController::class, 'update']);
            Route::delete('runs/{payRun}/inputs/{payRunInput}', [PayRunInputController::class, 'destroy']);
        });
    });

    // Employees' pay details: needed by payroll and by loans (limits as a multiple of basic).
    Route::middleware('feature:payroll.enabled|payroll.loans')->group(function () {
        // Self-service: own pay details, read-only.
        Route::get('my-pay-details', [EmployeePayController::class, 'mine']);

        Route::middleware('permission:prepare-payroll|view-payroll')->group(function () {
            Route::get('employees', [EmployeePayController::class, 'index']);
            Route::get('employees/{employee}', [EmployeePayController::class, 'show']);
        });
        Route::middleware('permission:prepare-payroll')->group(function () {
            Route::put('employees/{employee}/profile', [EmployeePayController::class, 'saveProfile']);
            Route::post('employees/{employee}/components', [EmployeePayController::class, 'addComponent']);
            Route::put('employees/{employee}/components/{employeePayComponent}', [EmployeePayController::class, 'updateComponent']);
            Route::delete('employees/{employee}/components/{employeePayComponent}', [EmployeePayController::class, 'removeComponent']);
        });
    });

    Route::middleware('permission:configure-payroll')->group(function () {
        Route::put('settings', [PayrollSettingsController::class, 'update']);
        // The audit trail: every change to payroll data.
        Route::get('audit', [PayrollReportController::class, 'audit']);
        Route::get('audit/export', [PayrollReportController::class, 'auditExport']);
        Route::get('approval-workflows/people', [ApprovalWorkflowController::class, 'people']);
        Route::post('approval-workflows', [ApprovalWorkflowController::class, 'store']);
        Route::put('approval-workflows/{approvalWorkflow}', [ApprovalWorkflowController::class, 'update']);
        Route::delete('approval-workflows/{approvalWorkflow}', [ApprovalWorkflowController::class, 'destroy']);
        Route::post('statutory-rates', [StatutoryRateSetController::class, 'store']);
        Route::put('statutory-rates/{statutoryRateSet}', [StatutoryRateSetController::class, 'update']);
        Route::post('statutory-rates/{statutoryRateSet}/confirm', [StatutoryRateSetController::class, 'confirm']);
        Route::delete('statutory-rates/{statutoryRateSet}', [StatutoryRateSetController::class, 'destroy']);
        Route::post('exchange-rates', [ExchangeRateController::class, 'store']);
        Route::put('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update']);
        Route::delete('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy']);
        Route::post('pay-components', [PayComponentController::class, 'store']);
        Route::post('payment-file-layouts', [PaymentFileLayoutController::class, 'store']);
        Route::put('payment-file-layouts/{paymentFileLayout}', [PaymentFileLayoutController::class, 'update']);
        Route::delete('payment-file-layouts/{paymentFileLayout}', [PaymentFileLayoutController::class, 'destroy']);
        Route::put('pay-components/{payComponent}', [PayComponentController::class, 'update']);
        Route::delete('pay-components/{payComponent}', [PayComponentController::class, 'destroy']);
    });
});
