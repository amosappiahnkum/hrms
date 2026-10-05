<?php

use App\Http\Controllers\Payroll\ApprovalWorkflowController;
use App\Http\Controllers\Payroll\EmployeePayController;
use App\Http\Controllers\Payroll\ExchangeRateController;
use App\Http\Controllers\Payroll\PayComponentController;
use App\Http\Controllers\Payroll\PayrollSettingsController;
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
        Route::put('pay-components/{payComponent}', [PayComponentController::class, 'update']);
        Route::delete('pay-components/{payComponent}', [PayComponentController::class, 'destroy']);
    });
});
