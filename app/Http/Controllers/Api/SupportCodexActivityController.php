<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\MartinActivity\MartinActivityService;
use Illuminate\Http\Request;

/**
 * La auditoría de Martín (misión cruzada `auditoria-de-martin`, 11/11/2026).
 *
 * Monitor (`claude/support-codex/...`, `X-Claude-Task-Key`, SIN reserva: la auditoría tiene que quedar aunque la reserva
 * ya se haya usado):
 *  1. `POST claude/support-codex/activity` — la tanda de acciones que el monitor registró.
 *  2. `PUT claude/support-codex/plans/{run_id}` — el plan de la atención (upsert).
 *
 * Panel (`admin/...`, `auth:sanctum`, como la bandeja):
 *  3. `GET admin/support-ticket/{id}/martin-activity` — las atenciones del ticket con su plan, acciones y resumen.
 *
 * Solo valida y arma las respuestas; las reglas viven en `MartinActivityService`. La validación sale con la forma de
 * todo `claude/*` (`{error: "parámetros inválidos", errores, ayuda}`), también lo que las reglas de Laravel no ven
 * (detail de más de 2 KB, ticket o seguimiento inexistente).
 *
 * Compatibilidad: todo aditivo. Un monitor viejo no llama estas rutas (el panel muestra «Sin actividad registrada»);
 * un admin viejo le contesta 404 al monitor nuevo, que guarda la actividad en su archivo local y reintenta.
 */
class SupportCodexActivityController extends Controller
{
    use RespuestasParaClaude;

    /** @var MartinActivityService */
    private $actividad;

    /**
     * @param MartinActivityService $actividad
     */
    public function __construct(MartinActivityService $actividad)
    {
        $this->actividad = $actividad;
    }

    /**
     * Los mensajes del trait más las reglas que usa este controlador y el trait no traduce.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required'         => 'El parámetro :attribute es obligatorio.',
            'required_without' => 'Falta :attribute: cada item va con support_ticket_id o con implementation_followup_id.',
            'present'          => 'El parámetro :attribute tiene que venir (puede ser una lista vacía).',
            'array'            => 'El parámetro :attribute tiene que ser una lista.',
            'date'             => 'El parámetro :attribute tiene que ser una fecha válida (ISO 8601).',
            'integer'          => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'          => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'           => 'El parámetro :attribute tiene que ser texto.',
            'regex'            => 'El parámetro :attribute tiene un formato que no está permitido (letras, números, punto, guion, dos puntos o guion bajo).',
            'in'               => 'El parámetro :attribute tiene un valor que no está permitido. Mirá ' . $this->ayuda_del_schema() . ' para ver los válidos.',
            'min'              => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'              => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * 1. `POST claude/support-codex/activity` — `{items: [...]}` (1..50). 200 `{guardados, repetidos}`.
     *
     * @param Request $request El pedido.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function registrar(Request $request)
    {
        $invalido = $this->validar_o_422($request, MartinActivityService::reglas_de_actividad());
        if ($invalido !== null) {
            return $invalido;
        }

        $items   = array_values((array) $request->input('items'));
        $errores = $this->actividad->errores_de_actividad($items);
        if ($errores !== []) {
            return $this->invalidos($errores);
        }

        return response()->json($this->actividad->registrar($items), 200);
    }

    /**
     * 2. `PUT claude/support-codex/plans/{run_id}` — `{support_ticket_id?|implementation_followup_id?, objetivo?,
     * pasos}`. 200 `{ok: true}`.
     *
     * @param Request $request El pedido.
     * @param string  $run_id  La ejecución.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function plan(Request $request, $run_id)
    {
        $invalido = $this->validar_o_422($request, MartinActivityService::reglas_de_plan());
        if ($invalido !== null) {
            return $invalido;
        }

        $datos   = $request->only(['support_ticket_id', 'implementation_followup_id', 'objetivo', 'pasos']);
        $errores = $this->actividad->errores_de_plan($datos);
        if ($errores !== []) {
            return $this->invalidos($errores);
        }

        $this->actividad->guardar_plan((string) $run_id, $datos);

        return response()->json(['ok' => true], 200);
    }

    /**
     * 3. `GET admin/support-ticket/{id}/martin-activity` — `{atenciones: [...], total_acciones}`. Ticket inexistente:
     * 404.
     *
     * @param int|string $id El ticket.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function del_ticket($id)
    {
        if (! SupportTicket::query()->whereKey((int) $id)->exists()) {
            return $this->error_404('El ticket no existe.');
        }

        return response()->json($this->actividad->del_ticket((int) $id), 200);
    }

    /**
     * El 422 de lo que las reglas de Laravel no ven, con la misma forma que `validar_o_422`.
     *
     * @param array<string, array<int, string>> $errores Los errores.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function invalidos(array $errores)
    {
        return response()->json([
            'error'   => 'parámetros inválidos',
            'errores' => $errores,
            'ayuda'   => 'Consultá ' . $this->ayuda_del_schema() . ' para ver los filtros y valores válidos.',
        ], 422);
    }
}
