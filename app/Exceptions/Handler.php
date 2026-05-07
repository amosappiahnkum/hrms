<?php

namespace App\Exceptions;

use App\Helpers\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e): JsonResponse|Response
    {
        // Model not found (404)
        if ($e instanceof ModelNotFoundException) {
            return ApiResponse::error(
                'Resource not found',
                null,
                404
            );
        }

        // Validation errors
        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                'Validation failed',
                $e->errors(),
                422
            );
        }

        // HTTP exceptions (403, 401, etc.)
        if ($e instanceof HttpExceptionInterface) {
            return ApiResponse::error(
                $e->getMessage() ?: 'Request error',
                null,
                $e->getStatusCode()
            );
        }

        // Fallback (500)
//        return ApiResponse::error(
//            config('app.debug') ? $e->getMessage() : 'Server error',
//            null,
//            500
//        );

        return parent::render($request, $e);
    }
}
