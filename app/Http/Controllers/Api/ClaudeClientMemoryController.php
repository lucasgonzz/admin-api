<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportTicket;
use App\Services\ClientMemory\ClientSupportMemoryService;
use App\Services\ClientMemory\FichaClienteException;
use App\Services\SupportCodexService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La ficha del cliente de Martín, el título/resumen del ticket y la búsqueda en todo el historial (misión cruzada
 * `ficha-del-cliente`, 11/11/2026): las rutas del monitor (`claude/support-codex/...`, con la reserva del ticket o del
 * seguimiento) y las de Lucas por API (`claude/client-memory`).
 *
 * Solo valida y arma las respuestas; las reglas viven en `ClientSupportMemoryService`. Los frenos salen como
 * `{error: codigo, motivo: codigo, message: "codigo: texto"}` con su status; la validación, con la forma de todo
 * `claude/*` (`{error: "parámetros inválidos", errores, ayuda}`).
 *
 * Compatibilidad: todo es aditivo. Un monitor viejo no llama estas rutas; un admin viejo contesta 404 y el monitor
 * nuevo devuelve `{disponible: false}`.
 */
class ClaudeClientMemoryController extends Controller
{
    use RespuestasParaClaude;

    /** @var ClientSupportMemoryService */
    private $memoria;

    /**
     * @param ClientSupportMemoryService $memoria
     */
    public function __construct(ClientSupportMemoryService $memoria)
    {
        $this->memoria = $memoria;
    }

    /**
     * Los mensajes del trait más las reglas que usa este controlador y el trait no traduce.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required'        => 'El parámetro :attribute es obligatorio.',
            'required_if'     => 'El parámetro :attribute es obligatorio en este caso.',
            'required_unless' => 'El parámetro :attribute es obligatorio en este caso.',
            'exists'          => 'El :attribute que mandaste no existe.',
            'array'           => 'El parámetro :attribute tiene que ser una lista.',
            'date_format'     => 'El parámetro :attribute tiene que venir en formato :format.',
            'integer'         => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'         => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'          => 'El parámetro :attribute tiene que ser texto.',
            'in'              => 'El parámetro :attribute tiene un valor que no está permitido. Mirá ' . $this->ayuda_del_schema() . ' para ver los válidos.',
            'min'             => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'             => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Corre una acción del servicio y traduce sus frenos a la respuesta del contrato.
     *
     * @param callable $accion Devuelve el cuerpo.
     * @param int      $status Status del éxito.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder(callable $accion, $status = 200)
    {
        try {
            return response()->json($accion(), $status);
        } catch (FichaClienteException $e) {
            return response()->json($e->cuerpo(), $e->status());
        } catch (HttpException $e) {
            /* Los frenos de SupportCodexService (`codigo: texto`) con la misma forma que los de la ficha. */
            $partes = explode(': ', (string) $e->getMessage(), 2);
            $codigo = count($partes) === 2 && preg_match('/^[a-z_]+$/', $partes[0]) ? $partes[0] : 'error';

            return response()->json(['error' => $codigo, 'motivo' => $codigo, 'message' => (string) $e->getMessage()], $e->getStatusCode());
        }
    }

    /* ==============================================================================================
     | Monitor
     |============================================================================================= */

    /**
     * 1. `POST claude/support-codex/tickets/{id}/client-memory` — `{lease_token, operaciones: [...]}`.
     *
     * @param Request    $request El pedido.
     * @param int|string $id      El ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function desde_el_ticket(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, array_merge([
            'lease_token' => 'required|string|min:32|max:128',
        ], ClientSupportMemoryService::reglas_de_operaciones()));
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->memoria->operar_desde_el_ticket((int) $id, (string) $request->input('lease_token'), (array) $request->input('operaciones'));
        });
    }

    /**
     * 2. `POST claude/support-codex/implementation-followups/{id}/client-memory` — igual, con la reserva del
     * seguimiento.
     *
     * @param Request    $request El pedido.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function desde_el_seguimiento(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, array_merge([
            'lease_token' => 'required|string|max:128',
        ], ClientSupportMemoryService::reglas_de_operaciones()));
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->memoria->operar_desde_el_seguimiento((int) $id, (string) $request->input('lease_token'), (array) $request->input('operaciones'));
        });
    }

    /**
     * 3. `GET claude/support-codex/tickets/{id}/history-search?q=&limite=` — sin reserva, como `history`.
     *
     * @param Request    $request El pedido.
     * @param int|string $id      El ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function buscar_desde_el_ticket(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, $this->reglas_de_busqueda());
        if ($invalido !== null) {
            return $invalido;
        }

        $ticket = SupportTicket::with('client', 'client_employee')->find((int) $id);
        if ($ticket === null) {
            return response()->json(['error' => 'sin_ticket', 'motivo' => 'sin_ticket', 'message' => 'sin_ticket: No existe el ticket ' . (int) $id . '.'], 404);
        }

        return $this->responder(function () use ($request, $ticket) {
            return $this->memoria->buscar_en_el_historial($ticket, (string) $request->input('q'), $this->limite($request));
        });
    }

    /**
     * 4. `GET claude/support-codex/implementation-followups/{id}/history-search?q=&lease_token=` — con la reserva
     * vigente del seguimiento; busca como el dueño (todos los tickets del cliente).
     *
     * @param Request    $request El pedido.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function buscar_desde_el_seguimiento(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, array_merge(['lease_token' => 'required|string|max:128'], $this->reglas_de_busqueda()));
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            $followup = $this->memoria->seguimiento_con_reserva((int) $id, (string) $request->input('lease_token'), false);
            $client   = Client::find((int) $followup->client_id);
            if ($client === null) {
                throw new FichaClienteException(409, 'sin_cliente', 'El seguimiento no tiene un cliente que exista.');
            }

            return $this->memoria->buscar_en_el_historial($this->memoria->ticket_del_dueno($client), (string) $request->input('q'), $this->limite($request));
        });
    }

    /**
     * 5. `GET claude/support-codex/tickets/{id}/history[?ticket_id=N]` — sin el parámetro, los 5 anteriores como
     * siempre; con él, solo ese ticket del cliente (visible para el contacto) con sus últimos 60 mensajes reales.
     *
     * @param Request             $request El pedido.
     * @param int|string          $id      El ticket actual.
     * @param SupportCodexService $service El puente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function historial(Request $request, $id, SupportCodexService $service)
    {
        $invalido = $this->validar_o_422($request, ['ticket_id' => 'sometimes|integer|min:1']);
        if ($invalido !== null) {
            return $invalido;
        }

        $ticket = SupportTicket::findOrFail((int) $id);
        $puntual = $request->query('ticket_id');

        return $this->responder(function () use ($service, $ticket, $puntual) {
            return $service->history($ticket, $puntual === null ? null : (int) $puntual);
        });
    }

    /**
     * `POST claude/support-codex/tickets/{id}/title` — `{lease_token, titulo (3..80), resumen? (≤600)}`.
     *
     * @param Request    $request El pedido.
     * @param int|string $id      El ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function titular(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token' => 'required|string|min:32|max:128',
            'titulo'      => 'required|string|min:' . ClientSupportMemoryService::TITULO_MINIMO . '|max:' . ClientSupportMemoryService::TITULO_MAXIMO,
            'resumen'     => 'sometimes|nullable|string|max:' . ClientSupportMemoryService::RESUMEN_MAXIMO,
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->memoria->titular((int) $id, (string) $request->input('lease_token'), (string) $request->input('titulo'), $request->input('resumen'));
        });
    }

    /* ==============================================================================================
     | Lucas (API)
     |============================================================================================= */

    /**
     * `GET claude/client-memory?client_id=&incluir_historial=0|1`.
     *
     * @param Request $request El pedido.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id'         => 'required|integer|exists:clients,id',
            'incluir_historial' => 'sometimes|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client_id = (int) $request->input('client_id');

        return response()->json(array_merge(
            $this->memoria->listar($client_id, $request->boolean('incluir_historial')),
            ['ficha_cliente' => $this->memoria->bloque_para_el_cliente($client_id)]
        ));
    }

    /**
     * `POST claude/client-memory` — `{client_id, operaciones: [...]}` con `source: lucas`.
     *
     * @param Request $request El pedido.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $invalido = $this->validar_o_422($request, array_merge([
            'client_id' => 'required|integer|exists:clients,id',
        ], ClientSupportMemoryService::reglas_de_operaciones()));
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request) {
            return $this->memoria->operar_como_lucas((int) $request->input('client_id'), (array) $request->input('operaciones'));
        });
    }

    /**
     * @return array<string, string>
     */
    private function reglas_de_busqueda()
    {
        return [
            'q'      => 'required|string|min:2|max:100',
            'limite' => 'sometimes|integer|min:1|max:' . ClientSupportMemoryService::COINCIDENCIAS_MAXIMAS,
        ];
    }

    /**
     * @param Request $request El pedido.
     *
     * @return int
     */
    private function limite(Request $request)
    {
        return $request->has('limite') ? (int) $request->input('limite') : ClientSupportMemoryService::COINCIDENCIAS_POR_DEFECTO;
    }
}
