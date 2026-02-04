<?php

namespace App\Helpers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class Helper
{
    public static function saveImage($model, $file, $directory): void
    {
        $image_name = uniqid() . '.' . $file->getClientOriginalExtension();
        $file->storeAs(env('APP_PHOTO_PATH') . '/' . $directory . '/', $image_name);
        $model->photo()->updateOrCreate(['photoable_id' => $model->id], [
            'file_name' => $image_name
        ]);
    }

    public static function createUserAccount($model, $data, $userName = null): void
    {
        $password = strtoupper(Str::random(10));
        $user = $model::find($data['id'])->user()->updateOrCreate(
            ['username' => $data['email']],
            [
                'username' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($password),
                'default_password' => $password,
            ]
        );

        $role = Role::where('name', 'Staff')->first();

        $user->roles()->attach($role->id);
    }

    public static function formatDate($request)
    {
        if ($request->date !== 'null') {
            $explode = explode(',', $request->date);
            $request['start_date'] = Carbon::parse($explode[1])->format('Y-m-d');
            $request['end_date'] = Carbon::parse($explode[3])->format('Y-m-d');
        }

        return $request->all();
    }

    public static function getUserAuthInfo(User $loggedInUser): array
    {
        return [
            'user' => $loggedInUser->only(['id', 'name', 'username']),
            'roles' => $loggedInUser->getRoleNames(),
            'permissions' => $loggedInUser->getPermissionsViaRoles()->pluck('name')->merge
            ($loggedInUser->getDirectPermissions()->pluck('name')),
            'employee_id' => $loggedInUser->employee ? $loggedInUser->employee->id : null
        ];
    }

    public static function updateSRMS($staffId): void
    {
        Http::withHeader('token', env('TTU_API_TOKEN'))
            ->post(env('TTU_API_URL') . '/staff/bio-data', [
                'staff_id' => $staffId,
            ]);
    }
}
