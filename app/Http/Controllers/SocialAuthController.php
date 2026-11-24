<?php

namespace App\Http\Controllers;

use App\Models\SelfService\ContactDetail;
use App\Models\User;
use App\Services\Employee\WorkEmail;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->with(["prompt" => "select_account"])->stateless()->redirect();
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        $frontendURL = env('FRONTEND_URL');

        try {
            $socialUser = Socialite::driver('google')->stateless()->user();

            if (!$socialUser->token) {
                return redirect($frontendURL . '/login?error=invalid_token');
            }

            $googleEmail = WorkEmail::normalize($socialUser->getEmail());

            // The login email is the work email; the contact copy covers employees without an account yet.
            $account = User::query()->whereRaw('LOWER(email) = ?', [$googleEmail])->first();
            $contactDetail = ($account ? WorkEmail::employeeOf($account)?->contactDetail : null)
                ?? ContactDetail::query()->whereRaw('LOWER(work_email) = ?', [$googleEmail])->first();

            if (!$contactDetail) {
                return redirect($frontendURL . '/account-not-found?error=account_not_found');
            }

            if (!$contactDetail->employee->userAccount) {
                $user = User::updateOrCreate([
                    'email' => $googleEmail
                ], [
                    'name' => $socialUser->getName(),
                    'username' => $googleEmail,
                    'email' => $googleEmail,
                    'password' => Hash::make(Str::random()),
                    'provider' => 'google',
                    'provider_id' => $socialUser->getId(),
                    'employee_id' => $contactDetail->employee_id
                ]);

                $user->assignRole('staff');
            } else {
                $contactDetail->employee->userAccount->assignRole('staff');
                $user = $contactDetail->employee->userAccount;
            }

            $contactDetail->employee()->update(['user_id' => $user->id]);


            Auth::login($user);

            request()->session()->regenerate();

            return redirect($frontendURL . '/google-auth-success');

        } catch (Exception $e) {

            Log::error('Login failed: ', [$e]);
            return redirect($frontendURL . '/login?error=google_login_failed');
        }
    }
}
