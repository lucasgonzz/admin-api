<?php

namespace App\Http\Controllers;

use App\Jobs\ArmarPruebaJob;
use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaNombres;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVencimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El pool de la prueba gratuita en el panel (`/pruebas` del admin-spa) y los días de prueba por
 * cliente y por lead (misión `prueba-alta-instancia`, 3/11/2026). Todo bajo `auth:sanctum`.
 *
 *  - `GET  prueba/instancias`                      → la lista con cliente, lead, estado y contadores.
 *  - `POST prueba/instancias/mantener`             → encola el armado de las que falten.
 *  - `POST prueba/instancias/{id}/dormir|despertar|borrar|reintentar`.
 *  - `GET  client/{id}/prueba` / `PUT client/{id}/prueba {trial_days}` → los días (C3 hacia el ERP).
 *  - `GET  lead/{id}/prueba` / `PUT lead/{id}/prueba {trial_days}`     → lo mismo, desde el lead.
 */
class PruebaInstanciaController extends Controller
{
    public function index_json(): JsonResponse
    {
        $instancias = TrialInstance::with('client', 'lead')->orderBy('nombre')->get();

        return response()->json([
            'instancias' => $instancias->map(function (TrialInstance $instancia) {
                return $this->instancia_json($instancia);
            })->values()->all(),
            'resumen' => [
                'libres'      => TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE)->count(),
                'creando'     => TrialInstance::where('estado', TrialInstance::ESTADO_CREANDO)->count(),
                'activas'     => PruebaPoolService::activas(),
                'dormidas'    => TrialInstance::whereIn('estado', TrialInstance::ESTADOS_OCUPADA)->whereNotNull('dormida_at')->count(),
                'fallidas'    => TrialInstance::where('estado', TrialInstance::ESTADO_FALLIDA)->count(),
                'pool_minimo' => PruebaSettings::pool_minimo(),
                'max_activas' => PruebaSettings::max_activas(),
                'dias_default' => PruebaSettings::dias_default(),
            ],
        ]);
    }

    public function mantener_json(PruebaPoolService $pool): JsonResponse
    {
        try {
            $nombres = $pool->mantener();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['encoladas' => $nombres]);
    }

    public function dormir_json($id, PruebaCicloService $ciclo): JsonResponse
    {
        return $this->accion($id, function (TrialInstance $instancia) use ($ciclo) {
            $ciclo->dormir($instancia);
        });
    }

    public function despertar_json($id, PruebaCicloService $ciclo): JsonResponse
    {
        return $this->accion($id, function (TrialInstance $instancia) use ($ciclo) {
            $ciclo->despertar($instancia);
        });
    }

    public function borrar_json($id, PruebaCicloService $ciclo): JsonResponse
    {
        return $this->accion($id, function (TrialInstance $instancia) use ($ciclo) {
            $ciclo->borrar($instancia, 'a mano desde el panel');
        });
    }

    /** Vuelve a encolar el armado (del pool o de la asignación) de una instancia fallida. */
    public function reintentar_json($id): JsonResponse
    {
        return $this->accion($id, function (TrialInstance $instancia) {
            if ($instancia->estado !== TrialInstance::ESTADO_FALLIDA) {
                throw new \RuntimeException('Solo se reintenta una instancia fallida.');
            }
            if ($instancia->client_id) {
                ArmarPruebaJob::dispatch($instancia->id);
            } else {
                CrearInstanciaDePoolJob::dispatch($instancia->id);
            }
        });
    }

    public function prueba_del_cliente_json($id): JsonResponse
    {
        $client = Client::find($id);
        if ($client === null) {
            return response()->json(['message' => 'Cliente inexistente.'], 404);
        }

        return response()->json($this->prueba_json($client));
    }

    public function cambiar_dias_del_cliente_json(Request $request, $id, PruebaCicloService $ciclo): JsonResponse
    {
        $client = Client::find($id);
        if ($client === null) {
            return response()->json(['message' => 'Cliente inexistente.'], 404);
        }

        return $this->cambiar_dias($request, $client, $ciclo);
    }

    public function prueba_del_lead_json($id): JsonResponse
    {
        $lead = Lead::find($id);
        if ($lead === null) {
            return response()->json(['message' => 'Lead inexistente.'], 404);
        }
        $client = $lead->promoted_client_id ? Client::find($lead->promoted_client_id) : null;

        return response()->json($this->prueba_json($client, $lead));
    }

    public function cambiar_dias_del_lead_json(Request $request, $id, PruebaCicloService $ciclo): JsonResponse
    {
        $lead = Lead::find($id);
        if ($lead === null) {
            return response()->json(['message' => 'Lead inexistente.'], 404);
        }
        $client = $lead->promoted_client_id ? Client::find($lead->promoted_client_id) : null;
        if ($client === null) {
            return response()->json(['message' => 'El lead no tiene un cliente en prueba.'], 422);
        }

        return $this->cambiar_dias($request, $client, $ciclo, $lead);
    }

    private function cambiar_dias(Request $request, Client $client, PruebaCicloService $ciclo, ?Lead $lead = null): JsonResponse
    {
        $dias = $request->input('trial_days');
        if (! is_numeric($dias) || (int) $dias < 1 || (int) $dias > PruebaSettings::MAX_DIAS) {
            return response()->json([
                'message' => 'Los días de prueba van de 1 a ' . PruebaSettings::MAX_DIAS . '.',
                'errors'  => ['trial_days' => ['Los días de prueba van de 1 a ' . PruebaSettings::MAX_DIAS . '.']],
            ], 422);
        }

        try {
            $c3 = $ciclo->cambiar_dias($client, (int) $dias);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cuerpo       = $this->prueba_json($client->fresh(), $lead ? $lead->fresh() : null);
        $cuerpo['c3'] = $c3;

        return response()->json($cuerpo);
    }

    /**
     * @return array<string, mixed>
     */
    private function prueba_json(?Client $client, ?Lead $lead = null): array
    {
        if ($client === null || ! in_array((string) $client->estado, [PruebaCicloService::CLIENTE_PRUEBA, PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, PruebaCicloService::CLIENTE_PRUEBA_BORRADA], true)) {
            return ['en_prueba' => false, 'estado' => $client ? (string) $client->estado : null, 'instancia' => null];
        }

        $instancia = PruebaCicloService::instancia_de($client);
        $vence     = $client->trial_ends_at ? \Carbon\Carbon::parse($client->trial_ends_at) : null;

        return [
            'en_prueba'      => true,
            'estado'         => (string) $client->estado,
            'client_id'      => (int) $client->id,
            'lead_id'        => $lead ? (int) $lead->id : (int) (Lead::where('promoted_client_id', $client->id)->value('id') ?: 0),
            'trial_days'     => $client->trial_days === null ? null : (int) $client->trial_days,
            'trial_ends_at'  => $vence ? $vence->toIso8601String() : null,
            'dias_restantes' => $vence === null ? null : ($vence->isPast() ? 0 : (int) ceil(now()->floatDiffInDays($vence, false))),
            'inicio_at'      => $client->created_at ? $client->created_at->toIso8601String() : null,
            'url'            => $instancia && $instancia->alias_slug ? 'https://' . PruebaNombres::hosts_del_slug((string) $instancia->alias_slug)['erp_spa'] : null,
            'instancia'      => $instancia ? $this->instancia_json($instancia) : null,
            'max_dias'       => PruebaSettings::MAX_DIAS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function instancia_json(TrialInstance $instancia): array
    {
        $client = $instancia->relationLoaded('client') ? $instancia->client : ($instancia->client_id ? Client::find($instancia->client_id) : null);
        $lead   = $instancia->relationLoaded('lead') ? $instancia->lead : ($instancia->lead_id ? Lead::find($instancia->lead_id) : null);

        return [
            'id'            => (int) $instancia->id,
            'nombre'        => $instancia->nombre,
            'estado'        => $instancia->estado,
            'dormida'       => $instancia->esta_dormida(),
            'alias_slug'    => $instancia->alias_slug,
            'url'           => $instancia->alias_slug ? 'https://' . PruebaNombres::hosts_del_slug((string) $instancia->alias_slug)['erp_spa'] : 'https://' . $instancia->hosts_del_pool()['erp_spa'],
            'version'       => $instancia->version,
            'ecommerce_version' => $instancia->ecommerce_version,
            'user_id'       => $instancia->user_id,
            'etapa'         => $instancia->etapa,
            'progreso'      => (int) $instancia->progreso,
            'error'         => $instancia->error,
            'client'        => $client ? ['id' => (int) $client->id, 'nombre' => $client->resolve_display_name(), 'estado' => (string) $client->estado, 'trial_ends_at' => $client->trial_ends_at ? \Carbon\Carbon::parse($client->trial_ends_at)->toIso8601String() : null] : null,
            'lead'          => $lead ? ['id' => (int) $lead->id, 'nombre' => (string) $lead->contact_name, 'telefono' => (string) $lead->phone] : null,
            'asignada_at'   => $instancia->asignada_at ? $instancia->asignada_at->toIso8601String() : null,
            'lista_at'      => $instancia->lista_at ? $instancia->lista_at->toIso8601String() : null,
            'dormida_at'    => $instancia->dormida_at ? $instancia->dormida_at->toIso8601String() : null,
            'borrada_at'    => $instancia->borrada_at ? $instancia->borrada_at->toIso8601String() : null,
            'respaldo_ruta' => $instancia->respaldo_ruta,
            'created_at'    => $instancia->created_at ? $instancia->created_at->toIso8601String() : null,
        ];
    }

    private function accion($id, \Closure $accion): JsonResponse
    {
        $instancia = TrialInstance::find($id);
        if ($instancia === null) {
            return response()->json(['message' => 'Instancia inexistente.'], 404);
        }

        try {
            $accion($instancia);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'instancia' => $this->instancia_json($instancia->fresh())], 422);
        }

        return response()->json(['instancia' => $this->instancia_json($instancia->fresh())]);
    }
}
