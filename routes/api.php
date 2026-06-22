<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\CommonController;
use App\Http\Controllers\UserSettingsController;
use App\Http\Controllers\CommunityServiceController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\DirectReportController;
use App\Http\Controllers\EmployeeAnalyticsController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InformationUpdateController;
use App\Http\Controllers\LeaveAnalyticsController;
use App\Http\Controllers\LeaveManagementController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveResumptionController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QuickEmailController;
use App\Http\Controllers\Recruitment\CandidatePortalController;
use App\Http\Controllers\Recruitment\PublicJobController;
use App\Http\Controllers\SelfService\DependantController;
use App\Http\Controllers\SelfService\EmergencyContactController;
use App\Http\Controllers\SelfService\ExperienceController;
use App\Http\Controllers\SelfService\PreviousPositionController;
use App\Http\Controllers\SelfService\PreviousRankController;
use App\Http\Controllers\SelfService\QualificationController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('scan/{token}', [AuthController::class, 'qrCodeScan']);

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::get('settings/public', [SettingController::class, 'public']);
    foreach (glob(__DIR__ . '/staff-directory/*.php') as $file) {
        require $file;
    }

    // Public recruitment routes (no auth required)
    Route::prefix('public')->group(function () {
        Route::get('jobs', [PublicJobController::class, 'index']);
        Route::get('jobs/{jobOpening}', [PublicJobController::class, 'show']);
        Route::post('candidate/register', [CandidatePortalController::class, 'register']);
        Route::post('candidate/login', [CandidatePortalController::class, 'login']);
    });
});

// Candidate portal — separate session guard, never touches the users table
Route::prefix('v1')
    ->middleware(['auth:candidate', 'feature:recruitment.enabled'])
    ->group(function () {
        require __DIR__ . '/v1/recruitment-portal.php';
    });


Route::group(['middleware' => ['auth:sanctum']], static function () {
    Route::prefix('v1')->group(function () {
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        // User settings (all authenticated users)
        Route::prefix('user/settings')->group(function () {
            Route::get('notifications', [UserSettingsController::class, 'getNotificationPreferences']);
            Route::patch('notifications', [UserSettingsController::class, 'updateNotificationPreferences']);
        });
        // Loaded sub-route files (employees, question-bank, dynamic-forms)
        foreach (glob(__DIR__ . '/v1/*.php') as $file) {
            if (basename($file) === 'recruitment-portal.php') continue;
            require $file;
        }

        // Auth & session
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/validate-auth', [AuthController::class, 'validateAuth'])->name('api.validate-auth');

        // Impersonation (super-admin only)
        Route::post('/auth/impersonate', [ImpersonationController::class, 'impersonate']);
        Route::post('/auth/stop-impersonating', [ImpersonationController::class, 'stopImpersonating']);

        // Features management
        Route::get('features', [SettingController::class, 'indexFeatures']);
        Route::patch('features/{key}', [SettingController::class, 'updateFeature'])->where('key', '.+');

        // App configuration (super-admin only for writes)
        Route::get('settings/app', [SettingController::class, 'indexApp']);
        Route::patch('settings/app', [SettingController::class, 'updateApp']);

        // Company overview
        Route::get('company/overview', [CompanyController::class, 'overview']);

        // Commons & shared lookups (always available)
        Route::get('commons', [HomeController::class, 'getCommonData']);
        Route::get('educational-levels', [CommonController::class, 'getEducationalLevels']);
        Route::get('notifications/navs', [CommonController::class, 'getNotificationNavs']);
        Route::get('/stats/time-attendance', [CommonController::class, 'getTimeAndAttendanceDashboardData']);
        Route::prefix('common')->group(function () {
            Route::get('permissions/{id}', [CommonController::class, 'getAllPermissions']);
            Route::post('permissions/assign', [CommonController::class, 'assignPermissions']);
        });
        Route::prefix('user')->group(function () {
            Route::get('/{id}/roles/active', [UserController::class, 'getActiveRoles']);
            Route::get('/{id}/roles', [UserController::class, 'getUserRoles']);
        });
        Route::apiResource('/users', UserController::class);

        // User management — super-admin only (auth check enforced in controller)
        Route::prefix('user-management')->group(function () {
            Route::get('/', [UserManagementController::class, 'index']);
            Route::get('roles', [UserManagementController::class, 'roles']);
            Route::get('permissions', [UserManagementController::class, 'permissions']);
            Route::get('employee/{employeeUuid}', [UserManagementController::class, 'showByEmployee']);
            Route::post('{uuid}/roles', [UserManagementController::class, 'syncRoles']);
            Route::post('{uuid}/permissions', [UserManagementController::class, 'syncPermissions']);
            Route::post('{uuid}/suspend', [UserManagementController::class, 'suspend']);
            Route::post('{uuid}/restore', [UserManagementController::class, 'restore']);
        });
        Route::get('departments/search', [DepartmentController::class, 'searchDepartments']);
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('positions', PositionController::class)->except(['create', 'edit', 'show']);

        // Employees
        Route::middleware('feature:employees.enabled')->group(function () {
            Route::get('employee-analytics', [EmployeeAnalyticsController::class, 'index']);
            Route::get('employees/{employee}/stats', [EmployeeController::class, 'employeeStats']);
            Route::get('/stats/employee-management', [CommonController::class, 'getEmployeeManagementStats']);
            Route::get('/people', [EmployeeController::class, 'getPeople']);
            Route::get('search-staff-id', [EmployeeController::class, 'getStaff']);
            Route::post('update-mail', [EmployeeController::class, 'updateStaffMail']);

            Route::middleware('feature:employees.termination')
                ->post('/terminate-employee', [EmployeeController::class, 'terminateEmployee']);

            Route::middleware('feature:employees.photo_upload')
                ->post('upload-photo', [EmployeeController::class, 'uploadPhoto']);
        });

        // Leave
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

        // Self-service
        Route::middleware('feature:self_service.enabled')->group(function () {
            Route::middleware('feature:self_service.qualifications')
                ->apiResource('/qualifications', QualificationController::class);

            Route::middleware('feature:self_service.experience')
                ->apiResource('/experiences', ExperienceController::class);

            Route::middleware('feature:self_service.emergency_contacts')
                ->apiResource('/emergency-contacts', EmergencyContactController::class);

            Route::middleware('feature:self_service.dependants')
                ->apiResource('/dependants', DependantController::class);

            Route::middleware('feature:self_service.community_services')
                ->apiResource('/community-services', CommunityServiceController::class);
        });

        // Training history
        Route::middleware('feature:training.enabled')->group(function () {
            Route::middleware('feature:training.previous_ranks')
                ->apiResource('/previous-ranks', PreviousRankController::class);

            Route::middleware('feature:training.previous_positions')
                ->apiResource('/previous-positions', PreviousPositionController::class);
        });

        // Direct reports
        Route::middleware('feature:direct_reports.enabled')
            ->apiResource('/direct-reports', DirectReportController::class);

        // Quick email
        Route::middleware('feature:quick_email.enabled')
            ->post('mail/send', [QuickEmailController::class, 'send']);

        // Information updates & approvals
        Route::middleware('feature:information_updates.enabled')->group(function () {
            Route::apiResource('information-updates', InformationUpdateController::class);
            Route::prefix('approvals')->group(function () {
                Route::get('/', [InformationUpdateController::class, 'index']);
                Route::get('mine', [InformationUpdateController::class, 'myRequest']);
                Route::get('/{information_update}', [InformationUpdateController::class, 'show']);
                Route::post('/{information_update}/approve', [InformationUpdateController::class, 'approve']);
                Route::post('/{information_update}/reject', [InformationUpdateController::class, 'reject']);
            });
        });
    });
});
