<?php

namespace App\Services;

/**
 * Política del perfil «Sólo Leads» (misión embudo-whatsapp-precios, etapa E5).
 *
 * Es la ÚNICA fuente de lo que ese perfil puede hacer: la lista de rutas permitidas, la restricción por
 * parámetro de cada una, los campos que puede escribir y los canales de broadcasting que puede escuchar.
 * El middleware `admin.solo_leads` (SoloLeadsAllowlist) y `routes/channels.php` sólo la consultan: ninguna
 * otra parte del código mira el flag `admins.solo_leads` ni repite la lista.
 *
 * Es una lista BLANCA a propósito: una ruta nueva queda cerrada para el perfil hasta que alguien la agregue
 * acá, de forma explícita. Una lista negra dejaría abierta cada ruta que se escriba mañana.
 *
 * Decisión de producto (P4 de la misión): el perfil sólo LEE las conversaciones; cambia el estado del lead
 * y edita su nota. No le escribe al lead (envíos, aprobaciones, reacciones: rutas «WA», P5) ni cambia el
 * estado compartido del chat de los setters (marcar leído, fijar, respondido).
 *
 * Es pura: no toca la base ni la red, y no depende del request. Los tests la invocan directo.
 */
class AdminSoloLeadsPolicy
{
    /**
     * Código del cuerpo del 403 del perfil. El SPA lo usa para no mostrar toast en los pedidos de fondo.
     */
    const CODIGO_DE_RESPUESTA = 'solo_leads';

    /**
     * Mensaje del cuerpo del 403 del perfil (contrato del plan, §2.6).
     */
    const MENSAJE_DE_RESPUESTA = 'Tu perfil sólo tiene acceso a Leads.';

    /**
     * Rutas permitidas: cadenas "<MÉTODO> <uri>" exactamente como las devuelve `Route::uri()` (sin barra
     * inicial y con los marcadores de Laravel). Cada entrada tiene que corresponder a una ruta real que
     * lleve el middleware `admin.solo_leads` (lo verifica TodaRutaAutenticadaPasaPorElPerfilTest).
     *
     * @var array<int, string>
     */
    const RUTAS_PERMITIDAS = [
        // Sesión y arranque de la SPA.
        'GET api/admin/me',
        'POST api/admin/logout',
        'GET api/admin/version',
        // Campos del modal del lead (sólo para el modelo lead: ver RESTRICCIONES_DE_PARAMETROS).
        'GET api/admin/meta/{model}',
        // Lista de leads, tarjetas por estado, chats y contadores.
        'GET api/admin/lead',
        'GET api/admin/lead/status-cards',
        'GET api/admin/lead/chats',
        'GET api/admin/lead/chats/{id}',
        'GET api/admin/lead/unread-badges',
        // Ficha e hilo de la conversación; cambiar estado y nota (campos en CAMPOS_EDITABLES).
        'GET api/admin/lead/{id}',
        'PUT api/admin/lead/{id}',
        // Su propia campanita del lead.
        'POST api/admin/lead/{id}/toggle-notify-messages',
        // Lo que lee la conversación y el panel de demos al abrir.
        'GET api/admin/settings/lead-whatsapp-onboarding',
        'GET api/admin/settings/lead-demo',
        // Pila de avisos de tareas.
        'GET api/admin/task-notification/pending',
        'POST api/admin/task-notification/{id}/seen',
        'POST api/admin/task-notification/seen-all',
        // Notificaciones push.
        'GET api/admin/push/vapid-public-key',
        'POST api/admin/push/subscribe',
        'POST api/admin/push/unsubscribe',
        'POST api/admin/push/subscription-status',
        // Autorización de sus canales privados (fuera de api/admin) y salida del panel Blade.
        'POST broadcasting/auth',
        'POST logout',
    ];

    /**
     * Restricciones por parámetro de ruta: "<MÉTODO> <uri>" => [nombre del parámetro => valores permitidos].
     * Una ruta con restricción sólo se permite si TODOS sus parámetros restringidos traen un valor de la lista.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    const RESTRICCIONES_DE_PARAMETROS = [
        // `meta/{model}` sirve el catálogo de campos de cualquier modelo: el perfil sólo ve el de lead.
        'GET api/admin/meta/{model}' => ['model' => ['lead']],
    ];

    /**
     * Campos del cuerpo que el perfil puede escribir en una ruta: "<MÉTODO> <uri>" => [campos]. En las rutas
     * de esta lista el resto del cuerpo se descarta (no se rechaza). Las demás rutas pasan sin tocar.
     *
     * @var array<string, array<int, string>>
     */
    const CAMPOS_EDITABLES = [
        // Cambiar el estado y la nota; nada más del lead (nombre, teléfono, empresa, etc.).
        'PUT api/admin/lead/{id}' => ['status', 'notes'],
    ];

    /**
     * Patrones de canales de broadcasting que el perfil puede escuchar, tal como están registrados en
     * `routes/channels.php`. El resto de los canales le da acceso denegado.
     *
     * @var array<int, string>
     */
    const CANALES_PERMITIDOS = [
        // Su canal propio: por acá viajan los avisos in-app de tareas (el callback ya exige que sea el suyo).
        'admin.{admin_id}',
        // Alertas de verificación por agendamiento: el plan lo lista para el perfil.
        'verificacion-agendamiento-alerts',
    ];

    /**
     * Indica si el usuario tiene el perfil restringido. Un usuario nulo, o un admin sin la columna (base
     * todavía sin migrar) o con el flag en false, no lo tiene.
     *
     * @param mixed $usuario Usuario autenticado (Admin) o null.
     *
     * @return bool
     */
    public function tiene_perfil($usuario): bool
    {
        return $usuario !== null && (bool) ($usuario->solo_leads ?? false);
    }

    /**
     * Arma la clave "<MÉTODO> <uri>" con la que se busca en las listas. HEAD se trata como GET (Laravel lo
     * agrega solo a toda ruta GET) y la barra inicial de la uri se ignora.
     *
     * @param string $metodo Verbo HTTP.
     * @param string $uri    Uri de la ruta, como la devuelve `Route::uri()`.
     *
     * @return string
     */
    private function clave_de_ruta(string $metodo, string $uri): string
    {
        $metodo = strtoupper($metodo);
        if ($metodo === 'HEAD') {
            $metodo = 'GET';
        }

        return $metodo . ' ' . ltrim($uri, '/');
    }

    /**
     * Dice si el perfil puede usar una ruta: tiene que estar en la lista y, si tiene restricción por
     * parámetro, cumplirla.
     *
     * @param string               $metodo     Verbo HTTP.
     * @param string               $uri        Uri de la ruta con sus marcadores (`Route::uri()`).
     * @param array<string, mixed> $parametros Parámetros resueltos de la ruta (`Route::parameters()`).
     *
     * @return bool
     */
    public function permite(string $metodo, string $uri, array $parametros): bool
    {
        $clave = $this->clave_de_ruta($metodo, $uri);

        if (! in_array($clave, self::RUTAS_PERMITIDAS, true)) {
            return false;
        }

        $restricciones = self::RESTRICCIONES_DE_PARAMETROS[$clave] ?? [];
        foreach ($restricciones as $nombre => $valores_permitidos) {
            // Un parámetro restringido que no llega, o que no es un valor de la lista, cierra la ruta.
            if (! isset($parametros[$nombre]) || ! is_scalar($parametros[$nombre])
                || ! in_array((string) $parametros[$nombre], $valores_permitidos, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deja del cuerpo sólo los campos que el perfil puede escribir en esa ruta. Si la ruta no tiene
     * campos editables definidos, devuelve el cuerpo igual. Se descarta, no se rechaza: el endpoint no pisa
     * lo que no viene.
     *
     * @param string               $metodo Verbo HTTP.
     * @param string               $uri    Uri de la ruta con sus marcadores.
     * @param array<string, mixed> $input  Cuerpo (o query) del pedido.
     *
     * @return array<string, mixed>
     */
    public function filtrar_cuerpo(string $metodo, string $uri, array $input): array
    {
        $clave = $this->clave_de_ruta($metodo, $uri);

        if (! isset(self::CAMPOS_EDITABLES[$clave])) {
            return $input;
        }

        return array_intersect_key($input, array_flip(self::CAMPOS_EDITABLES[$clave]));
    }

    /**
     * Indica si hay campos editables definidos para la ruta (el middleware sólo filtra en ese caso).
     *
     * @param string $metodo Verbo HTTP.
     * @param string $uri    Uri de la ruta con sus marcadores.
     *
     * @return bool
     */
    public function tiene_campos_editables(string $metodo, string $uri): bool
    {
        return isset(self::CAMPOS_EDITABLES[$this->clave_de_ruta($metodo, $uri)]);
    }

    /**
     * Dice si el usuario puede escuchar un canal de broadcasting. Lo consultan los callbacks de
     * `routes/channels.php`: un usuario sin el perfil no se ve afectado (devuelve true y el callback decide
     * como siempre); con el perfil sólo pasan los canales de CANALES_PERMITIDOS.
     *
     * @param mixed  $usuario Usuario del callback del canal.
     * @param string $patron  Patrón del canal tal como se registró (ej. `admin.{admin_id}`).
     *
     * @return bool
     */
    public function canal_permitido($usuario, string $patron): bool
    {
        if (! $this->tiene_perfil($usuario)) {
            return true;
        }

        return in_array($patron, self::CANALES_PERMITIDOS, true);
    }
}
