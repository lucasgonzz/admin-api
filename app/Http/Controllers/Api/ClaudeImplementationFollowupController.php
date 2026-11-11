<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\ImplementationFollowup;
use App\Services\ImplementationFollowups\ImplementationFollowupService;
use App\Services\ImplementationFollowups\SeguimientoException;
use Illuminate\Http\Request;

/**
 * El seguimiento proactivo de las implementaciones (misión cruzada `seguimiento-de-implementaciones`, 11/11/2026):
 * los endpoints que consume el monitor de Martín (`support-codex/implementation-followups/*` y
 * `support-codex/tickets/{id}/implementation-followup`) y los de gestión de Lucas (`implementation-followups`).
 *
 * Solo pone la validación y arma las respuestas; las reglas viven en `ImplementationFollowupService`. Los frenos
 * del contrato (`reservado`, `no_vencido`, `cliente_escribio`, `martin_ocupado`, `sin_seguimiento`...) llegan como
 * `SeguimientoException` y salen con `{error, motivo, message}` y su status.
 *
 * Compatibilidad: todo es aditivo. Un monitor viejo no llama estas rutas; un admin viejo contesta 404 y el monitor
 * nuevo apaga el ciclo.
 */
class ClaudeImplementationFollowupController extends Controller
{
    use RespuestasParaClaude;

    /** Formato de una clave de hito. */
    const REGLA_DE_CLAVE = 'regex:' . ImplementationFollowupService::PATRON_DE_CLAVE;

    /**
     * @param ImplementationFollowupService $servicio
     */
    public function __construct(ImplementationFollowupService $servicio)
    {
        $this->servicio = $servicio;
    }

    /** @var ImplementationFollowupService */
    private $servicio;

    /**
     * Los mensajes del trait más las reglas que usa este controlador y el trait no traduce.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return array_merge($this->mensajes_de_validacion_base(), [
            'uuid'        => 'El parámetro :attribute tiene que ser un uuid.',
            'regex'       => 'El parámetro :attribute tiene un formato que no se acepta.',
            'required_if' => 'El parámetro :attribute es obligatorio en este caso.',
            'between'     => 'El parámetro :attribute está fuera del rango permitido.',
        ]);
    }

    /**
     * Los mensajes base del trait (alias para poder sumarle reglas sin redefinirlos).
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion_base()
    {
        return [
            'required'    => 'El parámetro :attribute es obligatorio.',
            'exists'      => 'El :attribute que mandaste no existe.',
            'array'       => 'El parámetro :attribute tiene que ser una lista.',
            'date'        => 'El parámetro :attribute tiene que ser una fecha válida.',
            'date_format' => 'El parámetro :attribute tiene que venir en formato :format.',
            'integer'     => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'     => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'      => 'El parámetro :attribute tiene que ser texto.',
            'in'          => 'El parámetro :attribute tiene un valor que no está permitido. Mirá ' . $this->ayuda_del_schema() . ' para ver los válidos.',
            'min'         => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'         => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Corre una acción del servicio y traduce sus frenos a la respuesta del contrato.
     *
     * @param callable $accion  Devuelve el cuerpo.
     * @param int      $status  Status del éxito.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function responder(callable $accion, $status = 200)
    {
        try {
            return response()->json($accion(), $status);
        } catch (SeguimientoException $e) {
            return response()->json($e->cuerpo(), $e->status());
        }
    }

    /* ==============================================================================================
     | Monitor
     |============================================================================================= */

    /**
     * 1. `GET claude/support-codex/implementation-followups/pending`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pending()
    {
        return response()->json($this->servicio->pendientes());
    }

    /**
     * 2. `POST claude/support-codex/implementation-followups/{id}/claim`.
     *
     * @param Request    $request Body: lease_token?.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function claim(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, ['lease_token' => 'sometimes|nullable|string|max:128']);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->servicio->reservar((int) $id, $request->input('lease_token'));
        });
    }

    /**
     * 3. `POST claude/support-codex/implementation-followups/{id}/milestones`.
     *
     * @param Request    $request Body: lease_token, medido_at, hitos.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function milestones(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token'         => 'required|string|max:128',
            'medido_at'           => 'required|date',
            'hitos'               => 'present|array|max:50',
            'hitos.*'             => 'array',
            'hitos.*.cantidad'    => 'nullable|integer|min:0',
            'hitos.*.primero_at'  => 'nullable|date',
            'hitos.*.ultimo_at'   => 'nullable|date',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $hitos = (array) $request->input('hitos', []);
        foreach (array_keys($hitos) as $clave) {
            if (! is_string($clave) || ! preg_match(ImplementationFollowupService::PATRON_DE_CLAVE, $clave)) {
                return $this->error_422('La clave de hito «' . $clave . '» no tiene el formato [a-z0-9_] (hasta 60).', ['motivo' => 'clave_invalida']);
            }
        }

        return $this->responder(function () use ($request, $id, $hitos) {
            return $this->servicio->guardar_hitos((int) $id, (string) $request->input('lease_token'), (string) $request->input('medido_at'), $hitos);
        });
    }

    /**
     * 4. `POST claude/support-codex/implementation-followups/{id}/send`.
     *
     * @param Request    $request Body: lease_token, operation_id (uuid), body (1..550).
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token'  => 'required|string|max:128',
            'operation_id' => 'required|uuid',
            'body'         => 'required|string|min:1|max:' . ImplementationFollowupService::LARGO_MAXIMO,
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        if (trim((string) $request->input('body')) === '') {
            return $this->error_422('El mensaje no puede estar vacío. No se mandó nada.', ['motivo' => 'texto_vacio']);
        }

        return $this->responder(function () use ($request, $id) {
            return $this->servicio->enviar((int) $id, (string) $request->input('lease_token'), (string) $request->input('operation_id'), (string) $request->input('body'));
        });
    }

    /**
     * 5. `POST claude/support-codex/implementation-followups/{id}/postpone`.
     *
     * @param Request    $request Body: lease_token, tipo, hasta?, motivo, avisar_a_lucas?, no_aplican?.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function postpone(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token'    => 'required|string|max:128',
            'tipo'           => 'required|string|in:pausa,omitir',
            'hasta'          => 'required_if:tipo,pausa|nullable|date_format:Y-m-d',
            'motivo'         => 'required|string|min:1|max:500',
            'avisar_a_lucas' => 'sometimes|boolean',
            'no_aplican'     => 'sometimes|array|max:20',
            'no_aplican.*'   => 'string|' . self::REGLA_DE_CLAVE,
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->servicio->posponer((int) $id, $request->only(['lease_token', 'tipo', 'hasta', 'motivo', 'avisar_a_lucas', 'no_aplican']));
        });
    }

    /**
     * 6. `POST claude/support-codex/implementation-followups/{id}/release`.
     *
     * @param Request    $request Body: lease_token, error?.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function release(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token' => 'required|string|max:128',
            'error'       => 'sometimes|nullable|string|max:1000',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->servicio->soltar_reserva((int) $id, (string) $request->input('lease_token'), $request->input('error'));
        });
    }

    /**
     * 7. `POST claude/support-codex/tickets/{id}/implementation-followup`: pausar o reanudar desde una atención
     * normal, con la reserva del TICKET.
     *
     * @param Request    $request Body: lease_token, accion, hasta?, motivo.
     * @param int|string $id      El ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function desde_el_ticket(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token' => 'required|string|min:32|max:128',
            'accion'      => 'required|string|in:pausar,reanudar',
            'hasta'       => 'required_if:accion,pausar|nullable|date_format:Y-m-d',
            'motivo'      => 'required|string|min:1|max:500',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->servicio->desde_el_ticket((int) $id, $request->only(['lease_token', 'accion', 'hasta', 'motivo']));
        });
    }

    /* ==============================================================================================
     | Lucas
     |============================================================================================= */

    /**
     * `GET claude/implementation-followups?status=`.
     *
     * @param Request $request Query: status?.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $invalido = $this->validar_o_422($request, ['status' => 'sometimes|nullable|string|in:' . implode(',', ImplementationFollowup::STATUSES)]);
        if ($invalido !== null) {
            return $invalido;
        }

        return response()->json(['followups' => $this->servicio->listar($request->query('status'))]);
    }

    /**
     * `POST claude/implementation-followups` `{client_id}`: alta manual.
     *
     * @param Request $request Body: client_id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $invalido = $this->validar_o_422($request, ['client_id' => 'required|integer|min:1']);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request) {
            return ['seguimiento' => $this->servicio->alta_manual((int) $request->input('client_id'))];
        }, 201);
    }

    /**
     * `PATCH claude/implementation-followups/{id}`.
     *
     * @param Request    $request Body: status?, interval_business_days?, max_unanswered?, paused_until?, pause_reason?, hitos_no_aplican?.
     * @param int|string $id      El seguimiento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $aceptados = ['status', 'interval_business_days', 'max_unanswered', 'paused_until', 'pause_reason', 'hitos_no_aplican'];
        $de_mas    = array_values(array_diff(array_keys($request->all()), $aceptados));
        if (count($de_mas) > 0) {
            return $this->error_422('PATCH claude/implementation-followups/{id} no acepta: ' . implode(', ', $de_mas) . '. No se cambió nada.', [
                'motivo'     => 'parametros_de_mas',
                'aceptados'  => $aceptados,
            ]);
        }

        $invalido = $this->validar_o_422($request, [
            'status'                 => 'sometimes|nullable|string|in:' . implode(',', ImplementationFollowup::STATUSES),
            'interval_business_days' => 'sometimes|integer|between:1,30',
            'max_unanswered'         => 'sometimes|integer|between:1,10',
            'paused_until'           => 'sometimes|nullable|date_format:Y-m-d',
            'pause_reason'           => 'sometimes|nullable|string|max:500',
            'hitos_no_aplican'       => 'sometimes|array|max:20',
            'hitos_no_aplican.*'     => 'string|' . self::REGLA_DE_CLAVE,
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return ['seguimiento' => $this->servicio->editar((int) $id, $request->only(['status', 'interval_business_days', 'max_unanswered', 'paused_until', 'pause_reason', 'hitos_no_aplican']))];
        });
    }
}
