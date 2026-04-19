<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CommonController;
use App\Http\Controllers\CommunityServiceController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DependantController;
use App\Http\Controllers\DirectReportController;
use App\Http\Controllers\EmergencyContactController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InformationUpdateController;
use App\Http\Controllers\JobDetailController;
use App\Http\Controllers\LeaveManagementController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreviousPositionController;
use App\Http\Controllers\PreviousRankController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\QuickEmailController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('')->group(function () {
    foreach (glob(__DIR__ . '/v1/*.php') as $file) {
        require $file;
    }
});
// Public routes
Route::post('login', [AuthController::class, 'login']);
Route::get('scan/{token}', [AuthController::class, 'qrCodeScan']);
Route::group(['middleware' => ['auth:sanctum']], static function () {
    Route::post('mail/send', [QuickEmailController::class, 'send']);
    Route::get('commons', [HomeController::class, 'getCommonData']);
    Route::get('educational-levels', [CommonController::class, 'getEducationalLevels']);
    Route::prefix('user')->group(function () {
        Route::get('/{id}/roles/active', [UserController::class, 'getActiveRoles']);
        Route::get('/{id}/roles', [UserController::class, 'getUserRoles']);
   });

    Route::apiResource('/users', UserController::class);

    Route::get('/stats/employee-management', [CommonController::class, 'getEmployeeManagementStats']);
    Route::post('/terminate-employee', [EmployeeController::class, 'terminateEmployee']);
    Route::get('/people', [EmployeeController::class, 'getPeople']);

    Route::apiResource('/qualifications', QualificationController::class);
    Route::apiResource('/experiences', ExperienceController::class);
    Route::apiResource('/emergency-contacts', EmergencyContactController::class);
    Route::apiResource('/dependants', DependantController::class);
    Route::apiResource('/direct-reports', DirectReportController::class);
    Route::apiResource('/community-services', CommunityServiceController::class);
    Route::apiResource('/previous-ranks', PreviousRankController::class);
    Route::apiResource('/previous-positions', PreviousPositionController::class);
    Route::get('holidays', [LeaveRequestController::class, 'getHolidays']);
    Route::prefix('leave-requests')->group(function () {
        Route::get('types', [LeaveRequestController::class, 'getLeaveTypes']);
        Route::post('status/change', [LeaveRequestController::class, 'changeLeaveStatus']);
    });
    Route::apiResource('/leave-requests', LeaveRequestController::class);
    Route::get('my-leave-requests', [LeaveRequestController::class, 'getMyLeaveRequest']);
    Route::get('team-request', [LeaveRequestController::class, 'getTeamLeaveRequest']);
    Route::post('change-leave-status', [LeaveRequestController::class, 'changeLeaveStatus']);
    Route::post('hr-change-leave-status', [LeaveRequestController::class, 'hrChangeLeaveStatus']);
    Route::prefix('my-leave')->group(function () {
        Route::get('stats', [LeaveRequestController::class, 'getMyLeaveStats']);
        Route::get('balances', [LeaveRequestController::class, 'getMyLeaveBalance']);
        Route::get('upcoming', [LeaveRequestController::class, 'getUpcomingLeave']);
    });
    Route::prefix('leave-management')->group(function () {
        Route::get('/filter-params', [LeaveManagementController::class, 'getFilterParams']);
        Route::get('/leave-requests', [LeaveManagementController::class, 'getLeaveRequests']);
        Route::post('/leave-requests/status/hr/change', [LeaveRequestController::class, 'hrChangeLeaveStatus']);
    });

    Route::patch('/leave-types/config/{id}', [LeaveTypeController::class, 'updateLeaveTypeConfig']);
    Route::apiResource('/leave-types', LeaveTypeController::class);

    Route::post('/approvals', [NotificationController::class, 'getApprovals']);
    Route::get('/supervisor/{employee}/pending-actions', [HomeController::class, 'getPendingApprovals']);
    Route::get('/my-team', [HomeController::class, 'getMyTeam']);
    Route::get('/who-is-out', [HomeController::class, 'getWhoIsOut']);

    Route::get('/stats/time-attendance', [CommonController::class, 'getTimeAndAttendanceDashboardData']);
    Route::prefix('common')->group(function () {
        Route::get('permissions/{id}', [CommonController::class, 'getAllPermissions']);
        Route::post('permissions/assign', [CommonController::class, 'assignPermissions']);
    });

    Route::get('notifications/navs', [CommonController::class, 'getNotificationNavs']);
    Route::apiResource('information-updates', InformationUpdateController::class);


    Route::get('search-staff-id', [EmployeeController::class, 'getStaff']);
    Route::post('update-mail', [EmployeeController::class, 'updateStaffMail']);

    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/validate-auth', [AuthController::class, 'validateAuth'])->name('api.validate-auth');

    // Token management
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);
    Route::delete('/tokens', [AuthController::class, 'revokeAllTokens']);
    Route::apiResource('departments', DepartmentController::class);

    Route::post("upload-photo", [EmployeeController::class, 'uploadPhoto']);
    Route::get("get-photo/{fileName}", [EmployeeController::class, 'getPhoto']);
});
