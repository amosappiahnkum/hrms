<?php

use App\Http\Controllers\CommonController;
use App\Http\Controllers\CommunityServiceController;
use App\Http\Controllers\ContactDetailController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DependantController;
use App\Http\Controllers\DirectReportController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QualificationController;
use App\Http\Controllers\EmergencyContactController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InformationUpdateController;
use App\Http\Controllers\JobDetailController;
use App\Http\Controllers\LeaveManagementController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NextOfKinController;
use App\Http\Controllers\PreviousPositionController;
use App\Http\Controllers\PreviousRankController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\GrantAndFundController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
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

// Public routes
Route::post('login', [AuthController::class, 'login']);
Route::group(['middleware' => ['auth:sanctum']], static function () {
    Route::get('commons', [HomeController::class, 'getCommonData']);
    Route::get('educational-levels', [CommonController::class, 'getEducationalLevels']);
    Route::prefix('user')->group(function () {
        Route::get('/{id}/roles/active', [UserController::class, 'getActiveRoles']);
        Route::get('/{id}/roles', [UserController::class, 'getUserRoles']);
        Route::post('/{id}/delete', [UserController::class, 'deleteUser']);
        Route::post('/roles/add', [UserController::class, 'addUserRoles']);
        Route::post('/roles/actions', [UserController::class, 'enableOrDisableRole']);
    });

    Route::apiResource('/users', UserController::class);

    Route::get('employees/search', [EmployeeController::class, 'searchEmployees']);

    Route::get('/stats/employee-management', [CommonController::class, 'getEmployeeManagementStats']);
    Route::get('/employees/directory', [EmployeeController::class, 'getEmployeeDirectory']);
    Route::resource('/employees', EmployeeController::class);
    Route::get('/people', [EmployeeController::class, 'getPeople']);

    Route::apiResource('/contact-details', ContactDetailController::class);
    Route::apiResource('/job-details', JobDetailController::class);
    Route::apiResource('/next-of-kin', NextOfKinController::class);
    Route::apiResource('/educations', QualificationController::class);
    Route::apiResource('/experiences', ExperienceController::class);
    Route::apiResource('/emergency-contacts', EmergencyContactController::class);
    Route::apiResource('/dependants', DependantController::class);
    Route::apiResource('/direct-reports', DirectReportController::class);
    Route::apiResource('/community-services', CommunityServiceController::class);
    Route::apiResource('/previous-ranks', PreviousRankController::class);
    Route::apiResource('/previous-positions', PreviousPositionController::class);
    Route::prefix('leave-requests')->group(function () {
        Route::get('holidays', [LeaveRequestController::class, 'getHolidays']);
        Route::get('types', [LeaveRequestController::class, 'getLeaveTypes']);
        Route::post('status/change', [LeaveRequestController::class, 'changeLeaveStatus']);
    });
    Route::apiResource('/leave-requests', LeaveRequestController::class);
    Route::get('my-leave-requests', [LeaveRequestController::class, 'getMyLeaveRequest']);
    Route::prefix('leave-management')->group(function () {
        Route::get('/filter-params', [LeaveManagementController::class, 'getFilterParams']);
        Route::get('/leave-requests', [LeaveManagementController::class, 'getLeaveRequests']);
        Route::post('/leave-requests/status/hr/change', [LeaveRequestController::class, 'hrChangeLeaveStatus']);
    });

    Route::apiResource('/leave-types', LeaveTypeController::class);

    Route::post('/approvals', [NotificationController::class, 'getApprovals']);
    Route::get('/supervisor/{employee}/pending-actions', [HomeController::class, 'getPendingApprovals']);
    Route::get('/my-team', [HomeController::class, 'getMyTeam']);
    Route::get('/who-is-out', [HomeController::class, 'getWhoIsOut']);
    Route::prefix('common')->group(function () {
        Route::get('permissions/{id}', [CommonController::class, 'getAllPermissions']);
        Route::post('permissions/assign', [CommonController::class, 'assignPermissions']);
    });

    Route::get('notifications/navs', [CommonController::class, 'getNotificationNavs']);
    Route::apiResource('information-updates', InformationUpdateController::class);


    Route::get('search-staff-id', [EmployeeController::class, 'getStaff']);
    Route::post('update-mail', [EmployeeController::class, 'updateStaffMail']);

    Route::get('/user-projects', [ProjectController::class, 'userProjects']);

    Route::apiResource('projects', ProjectController::class);

    Route::get('employees/{employee}/publications', [PublicationController::class, 'getMyPublications']);
    Route::apiResource('publications', PublicationController::class);
    Route::apiResource('grants', GrantAndFundController::class);

    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/validate-auth', [AuthController::class, 'validateAuth'])->name('api.validate-auth');

    // Token management
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);
    Route::delete('/tokens', [AuthController::class, 'revokeAllTokens']);
    Route::apiResource('departments', DepartmentController::class);
});


Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
