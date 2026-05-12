<?php

namespace App\Http\Responses;

use App\Http\Resources\AuthResponseResource;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        return response()->json([
            'message' => 'Logged in successfully',
            'user'    => new AuthResponseResource(Auth::user()),
        ]);
    }
}
