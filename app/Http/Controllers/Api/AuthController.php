<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\AuthResponseResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->generateToken($user, $request->device_name);
    }

    public function generateToken(User $user, string $device_name): JsonResponse
    {
        $token = $user->createToken($device_name)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new AuthResponseResource($user),
        ]);
    }

    public function logout()
    {
        auth()->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Token revoked successfully'
        ]);
    }

    public function me()
    {
        return response()->json(auth()->user());
    }

    public function tokens()
    {
        return response()->json([
            'tokens' => auth()->user()->tokens
        ]);
    }

    public function revokeToken(string $tokenId)
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json([
            'message' => 'Token revoked successfully'
        ]);
    }

    public function revokeAllTokens()
    {
        auth()->user()->tokens()->delete();

        return response()->json([
            'message' => 'All tokens revoked successfully'
        ]);
    }
}
