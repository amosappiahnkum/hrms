<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResponseResource;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function impersonate(Request $request): JsonResponse
    {
        $request->validate([
            'employee_uuid' => ['required', 'string'],
        ]);

        if (!Auth::user()->hasRole('super-admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $employee = Employee::where('uuid', $request->employee_uuid)->first();

        if (!$employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $target = User::where('employee_id', $employee->id)->first();

        if (!$target) {
            return response()->json(['message' => 'No user account linked to this employee'], 404);
        }

        // Store the super-admin's ID so we can restore later
        $request->session()->put('impersonating_original_id', Auth::id());

        Auth::guard('web')->login($target);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Now impersonating ' . $employee->first_name . ' ' . $employee->last_name,
            'user'    => new AuthResponseResource($target),
        ]);
    }

    public function stopImpersonating(Request $request): JsonResponse
    {
        $originalId = $request->session()->get('impersonating_original_id');

        if (!$originalId) {
            return response()->json(['message' => 'Not in an impersonation session'], 400);
        }

        $original = User::find($originalId);

        if (!$original) {
            return response()->json(['message' => 'Original user not found'], 404);
        }

        Auth::guard('web')->login($original);
        $request->session()->forget('impersonating_original_id');
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Impersonation ended',
            'user'    => new AuthResponseResource($original),
        ]);
    }
}
