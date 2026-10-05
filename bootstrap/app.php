<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        // Audit trail: every data-changing API request, export and download.
        $middleware->api(append: [\App\Http\Middleware\RecordRequestActivity::class]);
        $middleware->alias([
            'feature'            => \App\Http\Middleware\RequireFeature::class,
            'owns.employee'      => \App\Http\Middleware\EnsureOwnsEmployeeData::class,
            'training-plan.access' => \App\Http\Middleware\TrainingPlanAccess::class,
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Answers written for users (e.g. "doesn't meet the requirements yet"), not faults: don't log them.
        $exceptions->dontReport(\App\Exceptions\UserFacingException::class);
        // API errors never carry exception messages, SQL, class names or traces, even with APP_DEBUG on;
        // details stay in the logs. Validation and auth errors keep Laravel's own (safe) responses.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            return \App\Helpers\ApiResponse::uncaught($e);
        });
    })->create();
