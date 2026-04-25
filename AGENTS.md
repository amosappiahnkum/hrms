# AI Agent Guidelines for HRMS Project

## Architecture Overview
This is a Laravel 12-based HRMS (Human Resource Management System) with a React SPA frontend. Key components:
- **Backend**: Laravel API with Sanctum authentication, Fortify features, Spatie permissions/roles, activity logging, Excel exports/imports via Maatwebsite.
- **Frontend**: React with Ant Design UI, Redux state management, Tailwind CSS styling, CKEditor for rich text, ApexCharts for analytics.
- **Real-time**: Laravel Reverb websockets, Pusher integration.
- **Storage**: MinIO (S3-compatible) for file uploads, Intervention Image for processing.
- **Queues**: Redis-backed jobs for notifications (e.g., leave requests).

All models use UUID primary keys (see `app/Models/AppModel.php`), route model binding via `uuid` (not `id`). Relationships are defined in models like `app/Models/Employee.php`.

## Key Patterns
- **Model Structure**: Extend `AppModel` for UUIDs and auto user_id assignment. Use traits like `HasUuid`, scopes like `EmployeeScope` for filtering.
- **Enums**: Use PHP 8.1+ enums for statuses (e.g., `app/Enums/Statuses.php` for leave approvals: PENDING, APPROVED, etc.).
- **API Resources**: Routes in `routes/api.php` and `routes/v1/`, controllers in `app/Http/Controllers/`. Auth via Sanctum middleware.
- **File Handling**: Upload via `MinioUploadService` (`app/Services/MinioUploadService.php`), URL generation in `app/Helpers/Helper.php::getPhotoURL`.
- **Exports/Imports**: Use Maatwebsite Excel in `app/Exports/` (e.g., `EmployeeExport.php` maps employee data to columns).
- **Jobs**: Queue notifications like `SendLeaveRequestJob` (`app/Jobs/SendLeaveRequestJob.php`) using Laravel notifications.
- **Frontend Build**: `npm run dev` compiles React via Laravel Mix (`webpack.mix.js`), with Tailwind and Sass.

## Developer Workflows
- **Setup**: Use Docker (`docker-compose.yml`) for local dev: `docker-compose up -d` starts app, nginx, redis, reverb, queue worker, scheduler.
- **Build Assets**: `npm run dev` or `npm run watch` for frontend compilation.
- **Run Tests**: `php artisan test` (PHPUnit config in `phpunit.xml`).
- **Queues**: `php artisan queue:work` processes jobs like email notifications.
- **Migrations/Seeders**: Standard Laravel: `php artisan migrate`, seed data in `database/seed-data/`.
- **Debugging**: Use `php artisan tinker` for REPL, logs in `storage/logs/`.

## Conventions
- **Naming**: Controllers follow resource naming (e.g., `EmployeeController`), models singular (e.g., `Employee`).
- **Soft Deletes**: Used on employees for archiving (scope `archived` in `EmployeeScope`).
- **Scopes**: Chainable filters on models (e.g., `Employee::department('IT')->gender('male')->get()`).
- **Permissions**: Check via Spatie: `$user->hasPermissionTo('edit employee')`.
- **External Integrations**: Sync with SRMS via `Helper::updateSRMS()` (HTTP call to external API).

## Common Tasks
- Add new employee field: Update `Employee` model fillable, migration, and `EmployeeExport` map.
- New leave type: Create in `LeaveType` model, update config in `LeaveTypeLevelConfig`.
- Frontend component: Place in `resources/js/`, import in `app.js`, style with Tailwind classes.</content>
<parameter name="filePath">/Users/amosappiahnkum/Herd/hrms/AGENTS.md
