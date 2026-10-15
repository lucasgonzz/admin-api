<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AppTime;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;

/**
 * Panel de inicio del admin: los tres números que un operador necesita ver al entrar.
 */
class InicioController extends Controller
{
    /**
     * Las tres tarjetas del panel de inicio, con el momento en que se calcularon.
     *
     * 🔴 UNA CONSULTA POR TARJETA, tres en total. No se usa
     * `LeadStatusCardsService::cards_for_statuses()`, que son ~8 consultas y trae el catálogo
     * entero de estados: para tres números es traer de más en la pantalla que se abre primero.
     *
     * 🔴 Los tres contadores son GLOBALES, no por admin: no hay ningún `Auth::id()` adentro de
     * ningún criterio. El panel dice "esto está pendiente", no "esto es tuyo" — y si el número
     * dependiera de quién está logueado, el deep link a la bandeja (que arranca filtrada en `mine`)
     * mostraría una cantidad de filas distinta de la que dice la tarjeta. Es la misma lección de
     * `test_el_scope_no_depende_del_admin_autenticado` del test de paridad de leads.
     *
     * De dónde sale cada uno:
     * - `tickets_sin_responder`: {@see SupportTicket::scopeSinResponder()} — ticket `open` cuyo
     *   último mensaje real es del cliente.
     * - `tickets_abiertos_hoy`: {@see SupportTicket::scopeAbiertoHoy()} — `opened_at` desde el
     *   comienzo del día en curso.
     * - `leads_sin_responder`: `Lead::requiereRevision(true)`, LA MISMA definición que cuenta la
     *   tarjeta "sin responder" del listado de leads ({@see \App\Services\LeadStatusCardsService}).
     *   🔴 Esa y solo esa: no se inventa un criterio nuevo ni se copia a mano. Hay un test que
     *   compara este número contra el del scope directo, justamente para que nadie la reescriba.
     *
     * @return JsonResponse
     */
    public function tarjetas(): JsonResponse
    {
        return response()->json([
            'tickets_sin_responder' => (int) SupportTicket::query()->sinResponder()->count(),
            'tickets_abiertos_hoy'  => (int) SupportTicket::query()->abiertoHoy()->count(),
            'leads_sin_responder'   => (int) Lead::query()->requiereRevision(true)->count(),
            /* Momento del cálculo, para el "actualizado hace…" de la vista. Sale de AppTime y no de
               now() para respetar el reloj virtual de local, igual que el resto del sistema. */
            'calculado_en'          => AppTime::now()->toIso8601String(),
        ], 200);
    }
}
