<?php

use App\Services\AdminSoloLeadsPolicy;
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
| Perfil «Sólo Leads» (misión embudo-whatsapp-precios, E5): cada callback empieza preguntando a
| AdminSoloLeadsPolicy::canal_permitido() con el patrón del canal. Para un admin sin el perfil siempre da
| true y el callback decide como siempre; con el perfil sólo pasan los canales de su lista. Un canal nuevo
| tiene que llevar la misma consulta (lo verifica CanalesDeBroadcastingDelPerfilTest).
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    if (! (new AdminSoloLeadsPolicy())->canal_permitido($user, 'App.Models.User.{id}')) {
        return false;
    }

    return (int) $user->id === (int) $id;
});

Broadcast::channel('deployment.{upgradeId}', function ($user, $upgradeId) {
    if (! (new AdminSoloLeadsPolicy())->canal_permitido($user, 'deployment.{upgradeId}')) {
        return false;
    }

    return $user !== null;
});

// Canal privado de alertas del closer: solo admins con is_closer = true pueden escuchar.
Broadcast::channel('closer-alerts', function ($user) {
    if (! (new AdminSoloLeadsPolicy())->canal_permitido($user, 'closer-alerts')) {
        return false;
    }

    return $user !== null && $user->is_closer === true;
});

// Canal privado de verificación por agendamiento: cualquier admin autenticado puede escuchar.
Broadcast::channel('verificacion-agendamiento-alerts', function ($user) {
    if (! (new AdminSoloLeadsPolicy())->canal_permitido($user, 'verificacion-agendamiento-alerts')) {
        return false;
    }

    return $user !== null;
});

// Canal privado por admin: por acá viajan los avisos in-app de tareas asignadas.
// Cada admin solo puede escuchar el suyo (compara su propio id contra el del canal).
Broadcast::channel('admin.{admin_id}', function ($user, $admin_id) {
    if (! (new AdminSoloLeadsPolicy())->canal_permitido($user, 'admin.{admin_id}')) {
        return false;
    }

    return $user !== null && (int) $user->id === (int) $admin_id;
});
