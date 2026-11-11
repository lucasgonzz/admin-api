<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use App\Services\PruebaDelClienteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `GET claude/prueba/resumen` (misión cruzada prueba-e2e-campana-imperio, 11/11/2026): la foto de las pruebas
 * gratuitas para el informe diario. Un renglón por negocio: en qué día va, qué hitos pasó, qué se le mandó por
 * WhatsApp, por correo y en su sistema (con fecha), el último mensaje de su conversación y la encuesta.
 *
 * Solo lectura. Entran las pruebas en curso, las vencidas hace hasta `vencidas_dias` días y las que recibieron
 * la encuesta en ese mismo lapso (así entra también quien contrató). Por defecto NO le pega a las instancias:
 * los hitos salen de los eventos que cada sistema ya reportó; con `consultar_instancia=1` los lee en vivo.
 */
class ClaudePruebaController extends Controller
{
    /** Cuántos envíos y cuántos caracteres del último mensaje viajan por negocio. */
    const MAX_ENVIOS = 40;
    const MAX_TEXTO  = 300;

    /**
     * @param Request                 $request
     * @param PruebaDelClienteService $pruebas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen(Request $request, PruebaDelClienteService $pruebas)
    {
        $datos = $request->validate([
            'vencidas_dias'       => 'sometimes|integer|min:0|max:60',
            'consultar_instancia' => 'sometimes|boolean',
            'client_id'           => 'sometimes|integer|min:1',
        ]);

        $dias      = isset($datos['vencidas_dias']) ? (int) $datos['vencidas_dias'] : 7;
        $en_vivo   = $request->boolean('consultar_instancia');
        $desde     = Carbon::now()->subDays($dias);
        $encuestas = PruebaEncuesta::query()->where('created_at', '>=', $desde)->pluck('client_id')->all();

        $clientes = Client::query()
            ->where(function ($query) use ($desde, $encuestas) {
                $query->where('estado', PruebaCicloService::CLIENTE_PRUEBA)
                    ->orWhere(function ($vencidas) use ($desde) {
                        $vencidas->where('estado', PruebaCicloService::CLIENTE_PRUEBA_VENCIDA)
                            ->where(function ($fecha) use ($desde) {
                                $fecha->whereNull('trial_ends_at')->orWhere('trial_ends_at', '>=', $desde);
                            });
                    })
                    ->orWhereIn('id', $encuestas === [] ? [0] : $encuestas);
            })
            ->when(isset($datos['client_id']), function ($query) use ($datos) {
                $query->whereKey((int) $datos['client_id']);
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        $filas   = [];
        $totales = ['en_curso' => 0, 'vencidas' => 0, 'contrataron' => 0, 'encuestas_respondidas' => 0];

        foreach ($clientes as $client) {
            $fila    = $this->fila($client, $pruebas, $en_vivo);
            $filas[] = $fila;

            if ($fila['estado'] === PruebaCicloService::CLIENTE_PRUEBA) {
                $totales['en_curso']++;
            } elseif ($fila['estado'] === PruebaCicloService::CLIENTE_PRUEBA_VENCIDA) {
                $totales['vencidas']++;
            } elseif ($fila['estado'] === PruebaCicloService::CLIENTE_ACTIVO) {
                $totales['contrataron']++;
            }
            if ($fila['encuesta'] !== null && $fila['encuesta']['respondida_at'] !== null) {
                $totales['encuestas_respondidas']++;
            }
        }

        return response()->json([
            'generado_at'   => Carbon::now()->toIso8601String(),
            'vencidas_dias' => $dias,
            'hitos_fuente'  => $en_vivo ? 'instancia' : 'admin',
            'hitos_catalogo' => PruebaDelClienteService::HITOS,
            'totales'       => $totales,
            'pruebas'       => $filas,
        ]);
    }

    /**
     * El renglón de un negocio.
     *
     * @param Client                  $client
     * @param PruebaDelClienteService $pruebas
     * @param bool                    $en_vivo
     *
     * @return array<string, mixed>
     */
    protected function fila(Client $client, PruebaDelClienteService $pruebas, bool $en_vivo): array
    {
        $lead      = $pruebas->lead_del_cliente($client);
        $en_prueba = PruebaCicloService::esta_en_prueba($client);
        $dias      = $pruebas->datos($client);
        $hitos     = $pruebas->hitos($client, $lead, $en_vivo && $en_prueba);
        $instancia = TrialInstance::where('client_id', (int) $client->id)->orderByDesc('id')->first();
        $encuesta  = PruebaEncuesta::where('client_id', (int) $client->id)->first();

        $hechos = 0;
        foreach ($hitos['hitos'] as $momento) {
            if ($momento !== null) {
                $hechos++;
            }
        }

        $envios = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->orderByDesc('id')
            ->limit(self::MAX_ENVIOS)
            ->get()
            ->map(function (PruebaWhatsappEnvio $envio) {
                return [
                    'canal'      => $envio->canal !== null ? (string) $envio->canal : PruebaWhatsappEnvio::CANAL_WHATSAPP,
                    'plantilla'  => (string) $envio->plantilla,
                    'hito'       => $envio->hito,
                    'estado'     => (string) $envio->estado,
                    'detalle'    => $envio->detalle,
                    'enviado_at' => $envio->enviado_at !== null ? $envio->enviado_at->toIso8601String() : null,
                    'created_at' => $envio->created_at !== null ? $envio->created_at->toIso8601String() : null,
                ];
            })->values()->all();

        return [
            'client_id'      => (int) $client->id,
            'nombre'         => $client->resolve_display_name(),
            'estado'         => (string) $client->estado,
            'es_ensayo'      => (bool) $client->es_ensayo,
            'lead_id'        => $lead !== null ? (int) $lead->id : null,
            'contacto'       => $lead !== null ? trim((string) $lead->contact_name) : null,
            'tiene_correo'   => (bool) filter_var(trim((string) $client->email), FILTER_VALIDATE_EMAIL),
            'dias'           => $dias['dias'],
            'dia_actual'     => $en_prueba ? $dias['dia_actual'] : null,
            'dias_restantes' => $en_prueba ? $dias['dias_restantes'] : null,
            'inicio_at'      => $dias['inicio_at'],
            'vence_at'       => $dias['vence_at'],
            'vencida'        => $en_prueba ? (bool) $dias['vencida'] : null,
            'instancia'      => $instancia === null ? null : [
                'nombre'     => (string) $instancia->nombre,
                'estado'     => (string) $instancia->estado,
                'alias_slug' => $instancia->alias_slug,
                'dormida_at' => $instancia->dormida_at !== null ? Carbon::parse($instancia->dormida_at)->toIso8601String() : null,
            ],
            'hitos'          => $hitos['hitos'],
            'hitos_hechos'   => $hechos,
            'hitos_total'    => count($hitos['hitos']),
            'hitos_sin_soporte' => $hitos['sin_soporte'],
            'proximo_hito'   => $en_prueba ? PruebaDelClienteService::proximo_hito_faltante($hitos['hitos'], $hitos['sin_soporte']) : null,
            'envios'         => $envios,
            'ultimo_mensaje' => $this->ultimo_mensaje($client),
            'encuesta'       => $encuesta !== null ? $encuesta->resumen() : null,
        ];
    }

    /**
     * El último mensaje real de las conversaciones de soporte del negocio (de cualquiera de los dos canales).
     *
     * @param Client $client
     *
     * @return array<string, mixed>|null
     */
    protected function ultimo_mensaje(Client $client): ?array
    {
        $query = DB::table('support_messages')
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->where('support_tickets.client_id', (int) $client->id)
            ->whereIn('support_messages.sender_type', ['user', 'admin']);
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');

        $mensaje = $query->orderByDesc('support_messages.id')->first([
            'support_messages.id', 'support_messages.support_ticket_id', 'support_messages.sender_type',
            'support_messages.kind', 'support_messages.body', 'support_messages.created_at', 'support_tickets.source',
        ]);

        if ($mensaje === null) {
            return null;
        }

        return [
            'id'         => (int) $mensaje->id,
            'ticket_id'  => (int) $mensaje->support_ticket_id,
            'canal'      => $mensaje->source === 'whatsapp' ? 'whatsapp' : 'sistema',
            /* `user` = lo escribió el negocio; `admin` = el equipo (Martín o un aviso automático). */
            'de'         => $mensaje->sender_type === 'user' ? 'negocio' : 'equipo',
            'kind'       => (string) $mensaje->kind,
            'texto'      => mb_substr(trim((string) $mensaje->body), 0, self::MAX_TEXTO),
            'created_at' => $mensaje->created_at !== null ? Carbon::parse($mensaje->created_at)->toIso8601String() : null,
        ];
    }
}
