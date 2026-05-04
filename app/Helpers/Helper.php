<?php

namespace App\Helpers;

use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class Helper
{

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

    public static function updateSRMS($staffId, $phone): void
    {
        try {
            Http::withHeader('token', env('TTU_API_TOKEN'))
                ->post(env('TTU_API_URL') . '/staff/bio-data', [
                    'staff_id' => $staffId,
                    'phone' => $phone,
                ]);
        }catch (Exception $e) {
            Log::error("Failed to update SRMS: " . $e->getMessage());
        }
    }

    public static function getPhotoURL(?string $fileName): ?string
    {
        if (!$fileName) return null;

        return env("PHOTO_URL") . "/{$fileName}";
    }
}
