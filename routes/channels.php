<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


Broadcast::channel('chat', function ($user) {
    return $user;
},['middleware' => ['auth:sanctum']]);


Broadcast::channel('notify-user.{role}', function (User $user, string $role) {
    return $user->hasRole($role);
});

Broadcast::channel('read-notification', function (User $user) {
    return true;
});

Broadcast::channel('system-maintenance', function () {
    return true;
});
