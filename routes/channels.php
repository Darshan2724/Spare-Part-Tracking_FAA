<?php

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

// Authenticated users across any department can subscribe to manufacturing workflow events
Broadcast::channel('workflow', function ($user) {
    return !is_null($user);
});

// Department-specific private channels
Broadcast::channel('department.{code}', function ($user, $code) {
    return $user->hasRole('ADMIN') || $user->hasRole('MANAGER') || ($user->department && strtoupper($user->department->code) === strtoupper($code));
});
