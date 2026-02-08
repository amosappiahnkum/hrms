<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\AuthResponseResource;
use App\Http\Resources\DepartmentResource;
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
}
