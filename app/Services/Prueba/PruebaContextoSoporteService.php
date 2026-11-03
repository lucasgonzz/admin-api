<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use Carbon\Carbon;

/**
 * El bloque `prueba` del contexto de un ticket de soporte (`SupportCodexService::context()`), para que
 * Martín (M4) sepa que atiende a un negocio en prueba y en qué hito va. SOLO DATOS: nada de protocolo
 * ni de lógica de Martín acá (eso lo escribe M4 en `herramientas/soporte-codex/prueba.md`).
 *
 * Null si el cliente del ticket no es una prueba.
 */
class PruebaContextoSoporteService
{
    /**
     * @return array<string, mixed>|null
     */
    public function bloque_para_el_cliente(?Client $client): ?array
    {
        if ($client === null || ! in_array((string) $client->estado, [PruebaCicloService::CLIENTE_PRUEBA, PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, PruebaCicloService::CLIENTE_PRUEBA_BORRADA], true)) {
            return null;
        }

        $ahora     = Carbon::now();
        $vence     = $client->trial_ends_at ? Carbon::parse($client->trial_ends_at) : null;
        $lead      = Lead::where('promoted_client_id', $client->id)->orderBy('id')->first();
        $instancia = PruebaCicloService::instancia_de($client);

        $hitos = [];
        if ($lead !== null) {
            $filas = LeadDemoHito::where('lead_id', $lead->id)
                ->where('tipo', PruebaHitosService::TIPO_HITO)
                ->orderBy('orden')
                ->get();
            foreach ($filas as $hito) {
                $hitos[(string) $hito->evento_esperado] = [
                    'titulo'      => (string) $hito->titulo,
                    'ocurrido_at' => $hito->accion_hecha_at ? $hito->accion_hecha_at->toIso8601String() : null,
                ];
            }
        }

        $dias_restantes = null;
        if ($vence !== null) {
            $dias_restantes = $vence->isPast() ? 0 : (int) ceil($ahora->floatDiffInDays($vence, false));
        }

        return [
            'estado'         => (string) $client->estado,
            'trial_days'     => $client->trial_days === null ? null : (int) $client->trial_days,
            'trial_ends_at'  => $vence ? $vence->toIso8601String() : null,
            'dias_restantes' => $dias_restantes,
            'vencida'        => $vence !== null && $vence->isPast(),
            'inicio_at'      => $client->created_at ? $client->created_at->toIso8601String() : null,
            'url'            => $instancia && $instancia->alias_slug ? 'https://' . PruebaNombres::hosts_del_slug((string) $instancia->alias_slug)['erp_spa'] : null,
            'instancia'      => $instancia ? ['nombre' => $instancia->nombre, 'estado' => $instancia->estado, 'dormida' => $instancia->esta_dormida()] : null,
            'lead_id'        => $lead ? (int) $lead->id : null,
            'hitos'          => $hitos,
        ];
    }
}
