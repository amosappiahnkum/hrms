<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\AuthResponseResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in successfully',
            'user' => new AuthResponseResource(Auth::user()),
        ]);
    }

    public function setCookie(User $user, string $device_name): string
    {
        $token = $user->createToken($device_name)->plainTextToken;
        Cookie::queue(
            Cookie::make(
                'auth_token',
                $token,
                60 * 24, // 1 day
                null,
                null,
                true, // Secure
                true, // HttpOnly
                false,
                'Strict'
            )
        );

        return '';
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Cookie::queue(Cookie::forget('auth_token'));
        Cookie::queue(Cookie::forget('XSRF-TOKEN'));
        return response()->json([
            'message' => 'Token revoked successfully'
        ]);
    }

    public function me(): JsonResponse
    {
        try {
            $user = Auth::user();

            return response()->json([
                "id" => $user->id,
                "uuid" => $user->uuid,
                "name" => $user->name,
                "username" => $user->username,
                "email" => $user->email,
                "phone_number" => $user->phone_number,
                "password_changed" => $user->password_changed,
                "employee_id" => $user?->employee?->uuid ?? null,
                "department_id" => $user?->employee?->department_id ?? null,
                "department" => new DepartmentResource($user?->employee?->department)
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return response()->json([
                'message' => "Something went wrong"
            ]);
        }
    }

    public function validateAuth(): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Logged in successfully',
                'user' => new AuthResponseResource(Auth::user()),
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                'message' => "Unauthorized",
            ], 400);
        }
    }

    public function tokens(): JsonResponse
    {
        return response()->json([
            'tokens' => auth()->user()->tokens
        ]);
    }

    public function revokeToken(string $tokenId): JsonResponse
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json([
            'message' => 'Token revoked successfully'
        ]);
    }

    public function revokeAllTokens(): JsonResponse
    {
        auth()->user()->tokens()->delete();

        return response()->json([
            'message' => 'All tokens revoked successfully'
        ]);
    }

    public function qrCodeScan($token)
    {
        try {
            $student = Employee::where('uuid', $token)->firstOrFail();

            Log::info('os', $student->toArray());
            return response()->make("
            <!DOCTYPE html>
            <html>
            <head>
                <title>Student Verification</title>
                <meta name='viewport' content='width=device-width, initial-scale=1'>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        background-color: #f4f6f9;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .card {
                        background: white;
                        padding: 40px;
                        border-radius: 8px;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                        text-align: center;
                    }
                    .success {
                        color: #2e7d32;
                        font-size: 24px;
                        font-weight: bold;
                    }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='success'>
                        Welcome {$student->first_name} {$student->middle_name} {$student->last_name}
                    </div>
                </div>
            </body>
            </html>
        ", 200);

        } catch (\Throwable $e) {

            return response()->make("
            <!DOCTYPE html>
            <html>
            <head>
                <title>Verification Failed</title>
                <meta name='viewport' content='width=device-width, initial-scale=1'>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        background-color: #f4f6f9;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .card {
                        background: white;
                        padding: 40px;
                        border-radius: 8px;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                        text-align: center;
                    }
                    .error {
                        color: #c62828;
                        font-size: 22px;
                        font-weight: bold;
                    }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='error'>
                        Verification Failed
                    </div>
                </div>
            </body>
            </html>
        ", 404);
        }
    }
}
