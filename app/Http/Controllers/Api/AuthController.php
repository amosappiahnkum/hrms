<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\GetMiniProfileRequest;
use App\Http\Resources\AuthResponseResource;
use App\Models\SelfService\ContactDetail;
use App\Models\SelfService\Employee;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Throwable;
use function response;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        return app(Pipeline::class)
            ->send($request)
            ->through([
                EnsureLoginIsNotThrottled::class,
                AttemptToAuthenticate::class,
                PrepareAuthenticatedSession::class,
            ])
            ->then(fn() => response()->json([
                'message' => 'Logged in successfully',
                'user' => new AuthResponseResource(Auth::user()),
            ]));
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => new AuthResponseResource(Auth::user()),
        ]);
    }

    public function validateAuth(): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Logged in successfully',
                'user' => new AuthResponseResource(Auth::user()),
            ]);
        } catch (Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => 'Unauthorized'], 401);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Cookie::queue(Cookie::forget('auth_token'));
        Cookie::queue(Cookie::forget('XSRF-TOKEN'));

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function setCookie(User $user, string $device_name): string
    {
        $token = $user->createToken($device_name)->plainTextToken;
        Cookie::queue(
            Cookie::make('auth_token', $token, 60 * 24, null, null, true, true, false, 'Strict')
        );

        return '';
    }

    public function tokens(): JsonResponse
    {
        return response()->json([
            'tokens' => auth()->user()->tokens,
        ]);
    }

    public function revokeToken(string $tokenId): JsonResponse
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'Token revoked successfully']);
    }

    public function revokeAllTokens(): JsonResponse
    {
        auth()->user()->tokens()->delete();

        return response()->json(['message' => 'All tokens revoked successfully']);
    }

    public function qrCodeScan($token)
    {
        try {
            $student = Employee::where('uuid', $token)->firstOrFail();

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
                        Welcome {$student->title} {$student->first_name} {$student->middle_name} {$student->last_name}
                    </div>
                </div>
            </body>
            </html>
        ", 200);

        } catch (Throwable $e) {

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

    public function changePassword(ChangePasswordRequest $request): Application|Response|JsonResponse|\Illuminate\Contracts\Foundation\Application|ResponseFactory
    {
        DB::beginTransaction();
        $user = Auth::User();

        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }

        try {
            if (!Hash::check($request['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Current Password is incorrect'
                ], 400);
            }

            if (Hash::check($request['password'], $user->password)) {
                return response()->json([
                    'message' => 'New Password is the same as current'
                ], 400);
            }

            $user->update([
                'password' => Hash::make($request->password),
                'password_changed' => true,
            ]);

            DB::commit();
            return response()->json([
                'data' => new AuthResponseResource($user)
            ]);
        } catch (Exception $exception) {
            DB::rollBack();
            return response('Something went wrong!', 400);
        }
    }

    public function getMiniProfile(GetMiniProfileRequest $request): JsonResponse
    {
        try {
            $contact = ContactDetail::where('work_email', $request->email)->firstOrFail();

            if (!$contact) {
                return response()->json([
                    'data' => null,
                    'message' => 'User not found'
                ], 404);
            }

            $employee = $contact->employee;

            $department = $employee?->department;
            return response()->json([
                'data' => [
                    "title" => $employee?->title,
                    "name" => $employee?->name,
                    "staff_id" => $employee?->staff_id,
                    "phone_number" => $employee?->phone_number,
                    "photo" => Helper::getTempPhoto($employee?->photo),
                    "department_id" => $department?->uuid,
                    "department_name" => $department?->name,
                ],
                'message' => 'User Info'
            ]);


        } catch (\Exception $exception) {
            Log::error($exception);

            return response()->json([
                'data' => null,
                'message' => 'Something went wrong!'
            ], 400);
        }
    }
}
