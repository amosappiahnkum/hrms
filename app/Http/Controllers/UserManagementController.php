<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserManagementResource;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    private function authorizeAdmin(): void
    {
        if (!$this->hasRole('super-admin')) {
            abort(403, 'Only super admins can access user management.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $users = User::withTrashed()
            ->with(['employee:id,uuid,first_name,middle_name,last_name,title,staff_id,photo', 'roles'])
            ->when($request->filled('search'), fn($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('username', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->filled('role'), fn($q) => $q->role($request->role))
            ->when($request->filled('status'), function ($q) use ($request) {
                if ($request->status === 'suspended') {
                    $q->onlyTrashed();
                } elseif ($request->status === 'active') {
                    $q->whereNull('deleted_at');
                }
            })
            ->latest()
            ->paginate($request->per_page ?? 20);

        return UserManagementResource::collection($users);
    }

    public function showByEmployee(string $employeeUuid): JsonResponse
    {
        $this->authorizeAdmin();

        $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();

        $user = User::withTrashed()
            ->with(['roles'])
            ->where('employee_id', $employee->id)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'No user account found for this employee.'], 404);
        }

        return response()->json(new UserManagementResource($user));
    }

    public function syncRoles(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdmin();
        $request->validate(['roles' => 'array', 'roles.*' => 'string|exists:roles,name']);

        $user = User::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $user->syncRoles($request->roles);

        return response()->json(new UserManagementResource($user->load('roles')));
    }

    public function syncPermissions(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAdmin();
        $request->validate(['permissions' => 'array', 'permissions.*' => 'string|exists:permissions,name']);

        $user = User::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $user->syncPermissions($request->permissions);

        return response()->json(new UserManagementResource($user->load('roles')));
    }

    public function suspend(string $uuid): JsonResponse
    {
        $this->authorizeAdmin();

        $user = User::where('uuid', $uuid)->firstOrFail();

        if ($user->hasRole('super-admin')) {
            return response()->json(['message' => 'Super admin accounts cannot be suspended.'], 422);
        }

        $user->delete();

        return response()->json(new UserManagementResource($user->load('roles')));
    }

    public function restore(string $uuid): JsonResponse
    {
        $this->authorizeAdmin();

        $user = User::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $user->restore();

        return response()->json(new UserManagementResource($user->load('roles')));
    }

    public function roles(): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json(Role::orderBy('name')->get(['id', 'name']));
    }

    public function permissions(): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json(Permission::orderBy('name')->get(['id', 'name']));
    }
}
