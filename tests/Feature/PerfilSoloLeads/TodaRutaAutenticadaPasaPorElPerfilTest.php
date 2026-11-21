<?php

namespace Tests\Feature\PerfilSoloLeads;

use App\Services\AdminSoloLeadsPolicy;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CA37 de E5 (misión embudo-whatsapp-precios): la regla mecánica que impide que una ruta autenticada
 * nueva quede abierta para el perfil «Sólo Leads».
 *
 * Es el test estructural: recorre `Route::getRoutes()` (las rutas reales, no una lista a mano) y exige que
 * toda ruta cuyo middleware incluya `auth:sanctum` o `auth` incluya también `admin.solo_leads`. Una ruta
 * autenticada nueva que se olvide del middleware rompe acá, no en producción.
 *
 * También fija la lista permitida (`AdminSoloLeadsPolicy::RUTAS_PERMITIDAS`, la única fuente) contra tres
 * cosas: que cada entrada exista como ruta real, que no contenga ninguna de las rutas que le escriben al
 * lead (las «WA» de §2.7), y que sea exactamente la del plan (una entrada de más es una puerta abierta).
 *
 * No necesita base de datos: sólo lee el registro de rutas y la política pura.
 *
 * Hoy está EN ROJO: el middleware `admin.solo_leads` y `AdminSoloLeadsPolicy` no existen todavía.
 */
class TodaRutaAutenticadaPasaPorElPerfilTest extends TestCase
{
    /** Nombre del alias del middleware del perfil (Kernel `$routeMiddleware`). */
    private const MIDDLEWARE_DEL_PERFIL = 'admin.solo_leads';

    /**
     * Las 23 entradas de la lista permitida de §2.7, en el formato `"<MÉTODO> <uri de Laravel>"` tal como
     * las devuelve `Route::uri()`. Si el plan cambia, se cambia acá a propósito.
     *
     * @var array<int, string>
     */
    private const LISTA_DEL_PLAN = [
        'GET api/admin/me',
        'POST api/admin/logout',
        'GET api/admin/version',
        'GET api/admin/meta/{model}',
        'GET api/admin/lead',
        'GET api/admin/lead/status-cards',
        'GET api/admin/lead/chats',
        'GET api/admin/lead/chats/{id}',
        'GET api/admin/lead/unread-badges',
        'GET api/admin/lead/{id}',
        'PUT api/admin/lead/{id}',
        'POST api/admin/lead/{id}/toggle-notify-messages',
        'GET api/admin/settings/lead-whatsapp-onboarding',
        'GET api/admin/settings/lead-demo',
        'GET api/admin/task-notification/pending',
        'POST api/admin/task-notification/{id}/seen',
        'POST api/admin/task-notification/seen-all',
        'GET api/admin/push/vapid-public-key',
        'POST api/admin/push/subscribe',
        'POST api/admin/push/unsubscribe',
        'POST api/admin/push/subscription-status',
        'POST broadcasting/auth',
        'POST logout',
    ];

    /**
     * Rutas «WA» y de estado compartido que NO pueden estar en la lista (§2.7, «Afuera a propósito»).
     *
     * @var array<int, string>
     */
    private const RUTAS_QUE_ESCRIBEN_AL_LEAD = [
        'POST api/admin/lead/{id}/send-direct-message',
        'POST api/admin/lead/{id}/send-direct-image',
        'POST api/admin/lead/{id}/send-direct-document',
        'POST api/admin/lead/{id}/send-direct-audio',
        'POST api/admin/lead/{lead_id}/send-template',
        'PUT api/admin/lead-message/{id}/approve',
        'PUT api/admin/lead-message/{id}/approve-with-edit',
        'PUT api/admin/lead-message/{id}/approve-with-actions',
        'PUT api/admin/lead-message/{id}/reaction',
        'POST api/admin/lead/{id}/mark-whatsapp-messages-read',
        'POST api/admin/lead/{id}/marcar-respondido',
        'POST api/admin/lead/{id}/toggle-pinned',
        'POST api/admin/lead/{id}/toggle-manual-unread',
        'POST api/admin/lead/{id}/mark-followup-suggestion-seen',
        'POST api/admin/lead/{id}/toggle-claude-auto-reply',
        'POST api/admin/lead/{id}/simulate-inbound',
        'POST api/admin/lead/{id}/offer-demo',
        'POST api/admin/lead/{id}/promote-to-client',
    ];

    /**
     * Indica si un middleware (tal cual lo devuelve `gatherMiddleware()`) autentica: `auth` o `auth:<guard>`.
     *
     * @param mixed $middleware Elemento de `gatherMiddleware()` (puede ser un Closure).
     *
     * @return bool
     */
    private function autentica($middleware): bool
    {
        return is_string($middleware) && ($middleware === 'auth' || strpos($middleware, 'auth:') === 0);
    }

    /**
     * Verbos de una ruta sin HEAD (Laravel lo agrega solo a todo GET).
     *
     * @param RutaDeLaravel $ruta Ruta registrada.
     *
     * @return array<int, string>
     */
    private function metodos_de(RutaDeLaravel $ruta): array
    {
        return array_values(array_diff($ruta->methods(), ['HEAD']));
    }

    /**
     * Toda ruta autenticada tiene el middleware del perfil. Lista las que no, con su método y uri.
     *
     * @return void
     */
    public function test_toda_ruta_autenticada_tiene_el_middleware(): void
    {
        $autenticadas = 0;
        $sin_middleware = [];

        foreach (Route::getRoutes() as $ruta) {
            $middlewares = $ruta->gatherMiddleware();

            $esta_autenticada = false;
            foreach ($middlewares as $middleware) {
                if ($this->autentica($middleware)) {
                    $esta_autenticada = true;
                    break;
                }
            }
            if (! $esta_autenticada) {
                continue;
            }

            $autenticadas++;
            if (! in_array(self::MIDDLEWARE_DEL_PERFIL, $middlewares, true)) {
                $sin_middleware[] = implode('|', $this->metodos_de($ruta)) . ' ' . $ruta->uri();
            }
        }

        // Guarda anti-vacuidad: hoy hay más de 500 rutas autenticadas; si el recorrido no encuentra ni
        // cien, el test está mirando otra cosa.
        $this->assertGreaterThan(100, $autenticadas, 'El recorrido tiene que encontrar las rutas autenticadas del admin.');

        $this->assertSame(
            [],
            $sin_middleware,
            count($sin_middleware) . ' de ' . $autenticadas . ' rutas autenticadas no tienen el middleware '
                . self::MIDDLEWARE_DEL_PERFIL . ' (la primera: ' . ($sin_middleware[0] ?? '-') . ').'
        );
    }

    /**
     * Toda entrada de `RUTAS_PERMITIDAS` corresponde a una ruta que existe (método y uri), y esa ruta lleva
     * el middleware (si no, el filtro nunca la vería).
     *
     * @return void
     */
    public function test_la_lista_permitida_no_tiene_rutas_inexistentes(): void
    {
        $permitidas = AdminSoloLeadsPolicy::RUTAS_PERMITIDAS;
        $this->assertNotEmpty($permitidas, 'La lista permitida no puede estar vacía.');

        // Índice «MÉTODO uri» => middlewares, de las rutas reales.
        $registradas = [];
        foreach (Route::getRoutes() as $ruta) {
            foreach ($this->metodos_de($ruta) as $metodo) {
                $registradas[$metodo . ' ' . $ruta->uri()] = $ruta->gatherMiddleware();
            }
        }

        $inexistentes = [];
        $sin_middleware = [];
        foreach ($permitidas as $entrada) {
            if (! isset($registradas[$entrada])) {
                $inexistentes[] = $entrada;
            } elseif (! in_array(self::MIDDLEWARE_DEL_PERFIL, $registradas[$entrada], true)) {
                $sin_middleware[] = $entrada;
            }
        }

        $this->assertSame([], $inexistentes, 'Entradas de RUTAS_PERMITIDAS que no son una ruta real (método y uri).');
        $this->assertSame([], $sin_middleware, 'Entradas permitidas cuya ruta no lleva ' . self::MIDDLEWARE_DEL_PERFIL . '.');
    }

    /**
     * La lista no permite escribirle al lead ni cambiar el estado compartido del chat.
     *
     * @return void
     */
    public function test_la_lista_no_permite_escribirle_al_lead(): void
    {
        $permitidas = AdminSoloLeadsPolicy::RUTAS_PERMITIDAS;
        $this->assertNotEmpty($permitidas);

        $this->assertSame(
            [],
            array_values(array_intersect($permitidas, self::RUTAS_QUE_ESCRIBEN_AL_LEAD)),
            'RUTAS_PERMITIDAS contiene rutas que le escriben al lead o cambian el estado compartido del chat.'
        );

        // Red de contención por nombre: ninguna ruta de envío, aprobación o reacción, cualquiera sea su forma.
        $sospechosas = [];
        foreach ($permitidas as $entrada) {
            foreach (['send-', 'approve', 'reaction', 'offer-demo', 'simulate-inbound', 'promote-to-client'] as $fragmento) {
                if (strpos($entrada, $fragmento) !== false) {
                    $sospechosas[] = $entrada;
                }
            }
        }
        $this->assertSame([], $sospechosas, 'Hay entradas permitidas que parecen de envío, aprobación o reacción.');
    }

    /**
     * La lista permitida es exactamente la del plan: sin una entrada de más (una puerta abierta) ni de menos
     * (una pantalla de Leads rota).
     *
     * @return void
     */
    public function test_la_lista_permitida_es_exactamente_la_del_plan(): void
    {
        $permitidas = AdminSoloLeadsPolicy::RUTAS_PERMITIDAS;
        $esperadas  = self::LISTA_DEL_PLAN;

        sort($permitidas);
        sort($esperadas);

        $this->assertSame($esperadas, array_values($permitidas));
    }

    /**
     * Contrato puro de la política: `permite()` respeta método, uri y restricción de parámetros.
     *
     * @return void
     */
    public function test_la_politica_permite_solo_lo_de_la_lista(): void
    {
        $this->assertTrue((new AdminSoloLeadsPolicy())->permite('GET', 'api/admin/lead/chats', []));
        $this->assertTrue((new AdminSoloLeadsPolicy())->permite('PUT', 'api/admin/lead/{id}', ['id' => '5']));

        // El método cuenta.
        $this->assertFalse((new AdminSoloLeadsPolicy())->permite('DELETE', 'api/admin/lead/{id}', ['id' => '5']));
        $this->assertFalse((new AdminSoloLeadsPolicy())->permite('POST', 'api/admin/version', []));

        // La restricción de parámetros: meta/{model} sólo con lead.
        $this->assertTrue((new AdminSoloLeadsPolicy())->permite('GET', 'api/admin/meta/{model}', ['model' => 'lead']));
        $this->assertFalse((new AdminSoloLeadsPolicy())->permite('GET', 'api/admin/meta/{model}', ['model' => 'client']));

        // Lo que no está, no entra.
        $this->assertFalse((new AdminSoloLeadsPolicy())->permite('GET', 'api/admin/client', []));
        $this->assertFalse((new AdminSoloLeadsPolicy())->permite('POST', 'api/admin/lead/{id}/send-direct-message', ['id' => '5']));
    }

    /**
     * Contrato puro del filtro de cuerpo: en `PUT lead/{id}` sólo sobreviven `status` y `notes`; en las demás
     * rutas el cuerpo pasa sin tocar.
     *
     * @return void
     */
    public function test_la_politica_filtra_el_cuerpo_del_put_de_lead(): void
    {
        $this->assertSame(
            ['status' => 'en_pausa', 'notes' => 'Nota'],
            (new AdminSoloLeadsPolicy())->filtrar_cuerpo('PUT', 'api/admin/lead/{id}', [
                'status'       => 'en_pausa',
                'notes'        => 'Nota',
                'contact_name' => 'Otro',
                'phone'        => '000',
            ])
        );

        $this->assertSame(
            [],
            (new AdminSoloLeadsPolicy())->filtrar_cuerpo('PUT', 'api/admin/lead/{id}', ['phone' => '000']),
            'Sin campos editables el cuerpo queda vacío (el endpoint no pisa lo que no viene).'
        );

        $cuerpo = ['endpoint' => 'https://push.test/abc'];
        $this->assertSame($cuerpo, (new AdminSoloLeadsPolicy())->filtrar_cuerpo('POST', 'api/admin/push/subscribe', $cuerpo));
    }
}
