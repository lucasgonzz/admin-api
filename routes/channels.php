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

Broadcast::channel('deployment.{upgradeId}', function ($user, $upgradeId) {
    return $user !== null;
});

// Canal privado de alertas del closer: solo admins con is_closer = true pueden escuchar.
Broadcast::channel('closer-alerts', function ($user) {
    return $user !== null && $user->is_closer === true;
});

// Canal privado de verificación por agendamiento: cualquier admin autenticado puede escuchar.
Broadcast::channel('verificacion-agendamiento-alerts', function ($user) {
    return $user !== null;
});
