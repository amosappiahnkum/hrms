<?php

namespace App\Http\Controllers;


use App\Http\Resources\UserResource;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return
     */
    public function index()
    {
        return UserResource::collection(User::withTrashed()->whereHas('activeRoles', function ($q) {
            $q->where('name', 'Admin')->orWhere('name', 'EC')->orWhere('name', 'Agent');
        })->get());
    }

    public function getActiveRoles()
    {
        $loggedInUser = Auth::user();

        if (!$loggedInUser) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 422);
        }

        return [
            'user' => $loggedInUser->only(['id', 'name', 'username']),
            'roles' => $loggedInUser->getRoleNames(),
            'permissions' => $loggedInUser->getPermissionsViaRoles()->pluck('name')->merge
            ($loggedInUser->getDirectPermissions()->pluck('name')),
            'employee_id' => $loggedInUser->employee ? $loggedInUser->employee->id : null
        ];
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     */
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'first_name'   => ['required', 'string', 'max:255'],
            'last_name'    => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['nullable', 'string', 'max:30'],
        ]);

        $username = $validated['first_name'] . '.' . $validated['last_name'];
        $checkUsername = User::where('username', $username)->count();

        if ($checkUsername >= 1) {
            $username = $username . '_' . random_int(10, 150);
        }
        DB::beginTransaction();
        try {
            $user = User::create([
                'name'         => $validated['first_name'] . ' ' . $validated['last_name'],
                'username'     => strtolower($username),
                'email'        => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? null,
                'password'     => Hash::make(Str::random(32)),
            ]);
            DB::commit();

            return \response(new UserResource($user));
        } catch (Exception $exception) {
            DB::rollBack();

            return \response('Something went wrong', 422);
        }
    }


    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param  $id
     *
     * @return JsonResponse|Response
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:255'],
            'email'        => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $id],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        DB::beginTransaction();
        try {
            User::findOrFail($id)->update($validated);
            DB::commit();

            $user = User::find($id);

            return \response(new UserResource($user));
        } catch (Exception $exception) {
            DB::rollBack();

            return response('Something went wrong', 422);
        }
    }

    public function getUserRoles($id): array
    {
        $userRoles = User::find($id)->roles;
        $otherRoles = Role::whereNotIn('id', $userRoles->pluck('pivot.roleId'))->get();

        return [$userRoles, $otherRoles];
    }
}
