<?php

namespace App\Actions\Fortify;

use App\Services\Employee\WorkEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  mixed  $user
     * @param  array  $input
     * @return void
     */
    public function update($user, array $input)
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validateWithBag('updateProfileInformation');

        $emailChanged = WorkEmail::normalize($input['email']) !== WorkEmail::normalize($user->email);

        // An employee's email is their work email, which only HR changes (employee profile › Contact).
        if ($emailChanged && $user->employee && !$user->can('edit-employee')) {
            throw ValidationException::withMessages(['email' => 'Only HR can change your work email.'])
                ->errorBag('updateProfileInformation');
        }

        if ($emailChanged) {
            app(WorkEmail::class)->changeForUser($user, $input['email']);
        }

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $user->forceFill(['name' => $input['name'], 'email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        } else {
            $user->forceFill(['name' => $input['name']])->save();
        }
    }
}
