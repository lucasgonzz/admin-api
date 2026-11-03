<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\TrialInstance;
use Carbon\Carbon;

/**
 * El bloque `prueba` del contexto de un ticket de soporte (`SupportCodexService::context()`), para que
 * Martín (M4) sepa que atiende a un negocio en prueba y en qué hito va. SOLO DATOS: nada de protocolo
 * ni de lógica de Martín acá (eso lo escribe M4 en `herramientas/soporte-codex/prueba.md`).
 *
 * Null si el cliente del ticket no es una prueba.
 *
 * Punto de extensión (acordado con M4 el 3/11/2026): este servicio arma la BASE del bloque y otro servicio
 * le suma claves sin tocar este archivo. Hay dos formas, las dos fusionadas con `array_merge` antes de
 * devolver (lo que se suma pisa a la base si repite una clave):
 *
 *  - `extender(callable)`: se registra una vez (un service provider, un boot) un callable que recibe
 *    `(array $base, Client $client, ?Lead $lead, ?TrialInstance $instancia)` y devuelve las claves a sumar.
 *    Es lo que usa M4 para `dias`, `dia_actual`, `hitos_fuente`, `proximo_hito`, `proximo_hito_label`,
 *    `bienvenida_enviada`, `ultimo_empujon_at` y `semilla_presente`.
 *  - el segundo argumento `$extra` de `bloque_para_el_cliente()`, para quien ya tiene el array armado.
 *
 * Los `hitos` van con la clave CORTA, sin el prefijo `prueba.` (`ingreso`, `importo`, `venta`...), que es
 * como los leen M4 y Martín; el nombre completo del evento queda adentro de cada hito en `evento`.
 */
class PruebaContextoSoporteService
{
    /** @var array<int, callable> Las extensiones registradas con extender(), en orden. */
    protected static $extensiones = [];

    /**
     * Registra un callable que suma claves al bloque. Ver el PHPDoc de la clase.
     */
    public static function extender(callable $extension): void
    {
        static::$extensiones[] = $extension;
    }

    /**
     * Vacía las extensiones registradas (para los tests, que comparten el proceso).
     */
    public static function sin_extensiones(): void
    {
        static::$extensiones = [];
    }

    /**
     * @param  array<string, mixed>  $extra  Claves que se fusionan al final, después de las extensiones.
     * @return array<string, mixed>|null
     */
    public function bloque_para_el_cliente(?Client $client, array $extra = []): ?array
    {
        if ($client === null || ! in_array((string) $client->estado, [PruebaCicloService::CLIENTE_PRUEBA, PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, PruebaCicloService::CLIENTE_PRUEBA_BORRADA], true)) {
            return null;
        }

        $ahora     = Carbon::now();
        $vence     = $client->trial_ends_at ? Carbon::parse($client->trial_ends_at) : null;
        $lead      = Lead::where('promoted_client_id', $client->id)->orderBy('id')->first();
        $instancia = PruebaCicloService::instancia_de($client);
        $slug      = $instancia && $instancia->alias_slug ? (string) $instancia->alias_slug : null;

        $hitos = [];
        if ($lead !== null) {
            $filas = LeadDemoHito::where('lead_id', $lead->id)
                ->where('tipo', PruebaHitosService::TIPO_HITO)
                ->orderBy('orden')
                ->get();
            foreach ($filas as $hito) {
                $evento = (string) $hito->evento_esperado;
                // La clave corta: `prueba.ingreso` → `ingreso`. Si el hito no trae el prefijo, queda como está.
                $clave = preg_replace('/^prueba\./', '', $evento);

                $hitos[$clave] = [
                    'evento'      => $evento,
                    'titulo'      => (string) $hito->titulo,
                    'ocurrido_at' => $hito->accion_hecha_at ? $hito->accion_hecha_at->toIso8601String() : null,
                ];
            }
        }

        $dias_restantes = null;
        if ($vence !== null) {
            $dias_restantes = $vence->isPast() ? 0 : (int) ceil($ahora->floatDiffInDays($vence, false));
        }

        // El link con el token de ingreso solo mientras el token sirva: si venció, Martín no lo manda.
        $token_vigente = $lead !== null
            && $lead->demo_ingreso_token
            && (! $lead->demo_ingreso_token_expira_at || Carbon::parse($lead->demo_ingreso_token_expira_at)->isFuture());

        $base = [
            'estado'         => (string) $client->estado,
            'trial_days'     => $client->trial_days === null ? null : (int) $client->trial_days,
            'trial_ends_at'  => $vence ? $vence->toIso8601String() : null,
            'dias_restantes' => $dias_restantes,
            'vencida'        => $vence !== null && $vence->isPast(),
            'inicio_at'      => $client->created_at ? $client->created_at->toIso8601String() : null,
            'url'            => $slug ? 'https://' . PruebaNombres::hosts_del_slug($slug)['erp_spa'] : null,
            'url_ingreso'    => $slug && $token_vigente ? PruebaNombres::url_ingreso($slug, (string) $lead->demo_ingreso_token) : null,
            'instancia'      => $instancia ? ['nombre' => $instancia->nombre, 'estado' => $instancia->estado, 'dormida' => $instancia->esta_dormida()] : null,
            'lead_id'        => $lead ? (int) $lead->id : null,
            'hitos'          => $hitos,
        ];

        foreach (static::$extensiones as $extension) {
            $sumado = $extension($base, $client, $lead, $instancia);
            if (is_array($sumado)) {
                $base = array_merge($base, $sumado);
            }
        }

        return array_merge($base, $extra);
    }
}
