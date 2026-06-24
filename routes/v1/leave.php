<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaveAnalyticsController;
use App\Http\Controllers\LeaveManagementController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveResumptionController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:leave.enabled')->group(function () {
    Route::get('holidays', [LeaveRequestController::class, 'getHolidays']);
    Route::get('/who-is-out', [HomeController::class, 'getWhoIsOut']);
    Route::get('/approvals', [NotificationController::class, 'getApprovals']);
    Route::get('/supervisor/{employee}/pending-actions', [HomeController::class, 'getPendingApprovals']);
    Route::get('/my-team', [HomeController::class, 'getMyTeam']);

    Route::middleware('feature:leave.self_service')->group(function () {
        Route::prefix('leave-requests')->group(function () {
            Route::get('types', [LeaveRequestController::class, 'getLeaveTypes']);
            Route::post('status/change', [LeaveRequestController::class, 'changeLeaveStatus']);
            Route::post('{uuid}/cancel', [LeaveRequestController::class, 'cancelLeave']);
            Route::post('{uuid}/documents', [LeaveRequestController::class, 'addDocuments']);
            Route::delete('{uuid}/documents/{documentId}', [LeaveRequestController::class, 'removeDocument']);
            Route::post('{uuid}/resume', [LeaveResumptionController::class, 'confirm']);
        });
        Route::prefix('leave-resumptions')->group(function () {
            Route::get('pending', [LeaveResumptionController::class, 'checkPending']);
            Route::get('/', [LeaveResumptionController::class, 'pendingAcknowledgements']);
            Route::post('{uuid}/acknowledge', [LeaveResumptionController::class, 'acknowledge']);
        });
        Route::apiResource('/leave-requests', LeaveRequestController::class);
        Route::get('my-leave-requests', [LeaveRequestController::class, 'getMyLeaveRequest']);
        Route::post('change-leave-status', [LeaveRequestController::class, 'changeLeaveStatus']);
        Route::prefix('my-leave')->group(function () {
            Route::get('stats', [LeaveRequestController::class, 'getMyLeaveStats']);
            Route::get('balances', [LeaveRequestController::class, 'getMyLeaveBalance']);
            Route::get('upcoming', [LeaveRequestController::class, 'getUpcomingLeave']);
        });
    });

    Route::middleware('feature:leave.team_visibility')
        ->get('team-request', [LeaveRequestController::class, 'getTeamLeaveRequest']);

    Route::middleware('feature:leave.hr_approval')
        ->post('hr-change-leave-status', [LeaveRequestController::class, 'hrChangeLeaveStatus']);

    Route::middleware('feature:leave.types_management')->group(function () {
        Route::patch('/leave-types/config/{id}', [LeaveTypeController::class, 'updateLeaveTypeConfig']);
        Route::apiResource('/leave-types', LeaveTypeController::class);
    });

    Route::middleware('feature:leave.hr_approval')->prefix('leave-management')->group(function () {
        Route::get('/filter-params', [LeaveManagementController::class, 'getFilterParams']);
        Route::get('/leave-requests', [LeaveManagementController::class, 'getLeaveRequests']);
        Route::post('/leave-requests/status/hr/change', [LeaveRequestController::class, 'hrChangeLeaveStatus']);
        Route::get('/analytics', [LeaveAnalyticsController::class, 'index']);
        Route::get('/employee-balances', [LeaveManagementController::class, 'getEmployeeLeaveBalances']);
        Route::post('/employee-balances/adjust', [LeaveManagementController::class, 'adjustEmployeeBalance']);
    });
});
