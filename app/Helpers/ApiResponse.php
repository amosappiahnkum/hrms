<?php

namespace App\Helpers;

use App\Exceptions\UserFacingException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiResponse
{
    public static function success($data = null, string $message = 'Success', int $code = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $code);
    }

    public static function error(string $message = 'Error', $errors = null, int $code = 400): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Turn an exception caught in a controller into a response without leaking internals.
     * Only messages meant for users (aborts, validation, UserFacingException) are passed through;
     * anything else is logged and replaced with $fallback.
     */
    public static function fromException(Throwable $e, string $fallback = 'Something went wrong. Please try again.'): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::error($e->getMessage(), $e->errors(), 422);
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::error(self::httpMessage($e), null, $e->getStatusCode());
        }

        if ($e instanceof ModelNotFoundException) {
            return self::error('Record not found.', null, 404);
        }

        if ($e instanceof UserFacingException) {
            return self::error($e->getMessage(), null, $e->status());
        }

        Log::error('Unhandled exception in ' . request()->method() . ' ' . request()->path(), ['exception' => $e]);

        return self::error($fallback, null, 500);
    }

    /**
     * Render any exception that escaped a controller. Unlike fromException() it doesn't log:
     * the framework already reports uncaught exceptions.
     */
    public static function uncaught(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof HttpExceptionInterface => self::error(self::httpMessage($e), null, $e->getStatusCode()),
            $e instanceof UserFacingException    => self::error($e->getMessage(), null, $e->status()),
            default                              => self::error('Something went wrong. Please try again.', null, 500),
        };
    }

    /**
     * Messages from abort() are written for users, but ones the framework generates
     * name model classes, ids, routes and enum classes, so those are replaced.
     */
    private static function httpMessage(HttpExceptionInterface $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException || $previous instanceof BackedEnumCaseNotFoundException) {
            return 'Record not found.';
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return 'This action is not allowed.';
        }

        if ($e instanceof NotFoundHttpException && str_starts_with($e->getMessage(), 'The route ')) {
            return 'Not found.';
        }

        return $e->getMessage() ?: (Response::$statusTexts[$e->getStatusCode()] ?? 'Request error.');
    }
}
