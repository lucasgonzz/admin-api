<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\SupportCodexTaskGrant;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ImportacionesDeMartin\ImportacionesDeMartinException;
use App\Services\ImportacionesDeMartin\ImportacionesDeMartinService;
use App\Services\PruebaImportacionService;
use App\Services\SupportCodexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Las rutas del monitor de la misión cruzada `importaciones-de-martin` (11/11/2026), todas bajo
 * `claude/support-codex/tickets/{id}/...`:
 *
 *  - `POST task-grant` — `{lease_token, alcance: pdf|web}` → 201 `{tarea_token, ticket_id, alcance, vence_at}`.
 *  - `GET catalogo-articulos?despues_de=&limite=&con_imagenes=1` — con la reserva o el permiso de la tarea.
 *  - `POST import-images-proveedor` — `{fuente, items[]}` con la reserva o el permiso `web`.
 *  - `GET import-status?modelo=&corrida_id=` · `POST import-confirm` · `POST import-revert` — clientes y proveedores.
 *
 * Los tokens viajan en el body/query (`lease_token`, `tarea_token`) o en los headers `X-Support-Lease-Token` /
 * `X-Support-Task-Token` (los GET los prefieren en el header: una query termina en los logs de acceso).
 *
 * Solo valida y arma las respuestas; las reglas viven en `ImportacionesDeMartinService`. Los frenos salen como
 * `{error: codigo, motivo: codigo, message: "codigo: texto"}`; los resultados de la importación, como en `import-excel`
 * (`{estado, mensaje, ...}`, con `error`/`motivo`/`message` además cuando no son 200); la validación, con la forma de
 * todo `claude/*` (`{error: "parámetros inválidos", errores, ayuda}`).
 */
class ClaudeImportacionesDeMartinController extends Controller
{
    use RespuestasParaClaude;

    /** El HTTP de cada `estado` de una importación (lo que contestó la instancia viaja adentro de un 200). */
    const HTTP_POR_ESTADO = [
        'aceptado' => 200,
        'rechazado' => 200,
        'version_vieja' => 200,
        'contacto_no_habilitado' => 403,
        'ticket_cerrado' => 409,
        'confirmacion_invalida' => 409,
        'sin_api_key' => 409,
        'sin_url' => 409,
        'sin_fuente' => 422,
        'archivo_invalido' => 422,
        'no_responde' => 502,
    ];

    /** @var ImportacionesDeMartinService */
    private $importaciones;

    /**
     * @param ImportacionesDeMartinService $importaciones
     */
    public function __construct(ImportacionesDeMartinService $importaciones)
    {
        $this->importaciones = $importaciones;
    }

    /**
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required'      => 'El parámetro :attribute es obligatorio.',
            'array'         => 'El parámetro :attribute tiene que ser una lista.',
            'integer'       => 'El parámetro :attribute tiene que ser un número entero.',
            'boolean'       => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'        => 'El parámetro :attribute tiene que ser texto.',
            'in'            => 'El parámetro :attribute tiene un valor que no está permitido. Mirá ' . $this->ayuda_del_schema() . ' para ver los válidos.',
            'min'           => 'El parámetro :attribute está por debajo del mínimo permitido.',
            'max'           => 'El parámetro :attribute supera el máximo permitido.',
            'regex'         => 'El parámetro :attribute no tiene el formato esperado.',
            'between'       => 'El parámetro :attribute está fuera del rango permitido.',
        ];
    }

    /**
     * Corre una acción y traduce sus frenos a la respuesta del contrato.
     *
     * @param callable $accion Devuelve el cuerpo.
     * @param int|null $status Status del éxito; null = según el `estado` del resultado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function responder(callable $accion, $status = null)
    {
        try {
            $cuerpo = $accion();
        } catch (ImportacionesDeMartinException $e) {
            return response()->json($e->cuerpo(), $e->status());
        } catch (HttpException $e) {
            /* Los frenos de SupportCodexService (`codigo: texto`) con la misma forma. */
            $partes = explode(': ', (string) $e->getMessage(), 2);
            $codigo = count($partes) === 2 && preg_match('/^[a-z_]+$/', $partes[0]) ? $partes[0] : 'error';

            return response()->json(['error' => $codigo, 'motivo' => $codigo, 'message' => (string) $e->getMessage()], $e->getStatusCode());
        }

        if ($status !== null) {
            return response()->json($cuerpo, $status);
        }

        $estado = (string) ($cuerpo['estado'] ?? '');
        $http = self::HTTP_POR_ESTADO[$estado] ?? 200;
        if ($http !== 200) {
            $cuerpo += ['error' => $estado, 'motivo' => $estado, 'message' => $estado . ': ' . (string) ($cuerpo['mensaje'] ?? '')];
        }

        return response()->json($cuerpo, $http);
    }

    private function lease(Request $request): ?string
    {
        $header = trim((string) $request->header('X-Support-Lease-Token'));

        return $header !== '' ? $header : ($request->input('lease_token') === null ? null : (string) $request->input('lease_token'));
    }

    private function tarea(Request $request): ?string
    {
        $header = trim((string) $request->header('X-Support-Task-Token'));

        return $header !== '' ? $header : ($request->input('tarea_token') === null ? null : (string) $request->input('tarea_token'));
    }

    /** Las reglas de los dos tokens (uno de los dos lo exige el servicio). */
    private function reglas_de_tokens(): array
    {
        return [
            'lease_token' => 'sometimes|nullable|string|min:32|max:128',
            'tarea_token' => 'sometimes|nullable|string|min:32|max:128',
        ];
    }

    /**
     * `POST claude/support-codex/tickets/{id}/task-grant` — el permiso delegado de 24 h de una tarea nativa.
     *
     * @param Request    $request `{lease_token, alcance: pdf|web}`.
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse 201 `{tarea_token, ticket_id, alcance, vence_at}`.
     */
    public function task_grant(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'lease_token' => 'required|string|min:32|max:128',
            'alcance' => 'required|string|in:' . implode(',', SupportCodexTaskGrant::ALCANCES),
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            return $this->importaciones->emitir_permiso((int) $id, (string) $request->input('lease_token'), (string) $request->input('alcance'));
        }, 201);
    }

    /**
     * `GET claude/support-codex/tickets/{id}/catalogo-articulos?despues_de=&limite=&con_imagenes=1` — los artículos
     * del dueño por cursor de id, con la reserva del ticket o el permiso de la tarea (`pdf` o `web`).
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse 200 `{estado: aceptado, http, cuerpo: {articulos, siguiente, total}}` |
     *                                       `{estado: version_vieja}`.
     */
    public function catalogo_articulos(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, $this->reglas_de_tokens() + [
            'despues_de' => 'sometimes|integer|min:0',
            'limite' => 'sometimes|integer|min:1|max:' . ImportacionesDeMartinService::LIMITE_CATALOGO,
            'con_imagenes' => 'sometimes|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            $ticket = $this->importaciones->ticket_autorizado((int) $id, $this->lease($request), $this->tarea($request), ImportacionesDeMartinService::RUTA_CATALOGO);

            return $this->importaciones->catalogo_articulos($ticket, [
                'despues_de' => $request->input('despues_de', 0),
                'limite' => $request->input('limite', 500),
                'con_imagenes' => $request->boolean('con_imagenes'),
            ]);
        });
    }

    /**
     * `POST claude/support-codex/tickets/{id}/import-images-proveedor` — una tanda de hasta 25 fotos de la web del
     * proveedor, con la reserva del ticket o el permiso `web` de la tarea.
     *
     * @param Request    $request `{fuente, items: [{article_id, tipo: exacto|dudosa, content_base64, url_origen?, codigo_web?}]}`.
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse 200 `{estado: aceptado|rechazado|version_vieja, http, cuerpo: {run_id, asignadas,
     *                                       a_revisar, salteadas}, enviadas, rechazadas_antes_de_enviar}`.
     */
    public function import_images_proveedor(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, $this->reglas_de_tokens() + [
            'fuente' => ['required', 'string', 'max:190', 'regex:/\A[A-Za-z0-9][A-Za-z0-9.-]{0,189}\z/'],
            'items' => 'required|array|min:1|max:' . ImportacionesDeMartinService::MAX_ITEMS,
            'items.*.article_id' => 'required|integer|min:1',
            'items.*.tipo' => 'required|string|in:' . implode(',', ImportacionesDeMartinService::TIPOS),
            'items.*.content_base64' => 'required|string|max:5600000',
            'items.*.filename' => 'sometimes|nullable|string|max:160',
            'items.*.url_origen' => ['sometimes', 'nullable', 'string', 'max:2000', 'regex:~\Ahttps?://~i'],
            'items.*.codigo_web' => 'sometimes|nullable|string|max:120',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            $ticket = $this->importaciones->ticket_autorizado((int) $id, $this->lease($request), $this->tarea($request), ImportacionesDeMartinService::RUTA_IMAGENES);

            return $this->importaciones->importar_imagenes_proveedor($ticket, [
                'fuente' => (string) $request->input('fuente'),
                'items' => (array) $request->input('items'),
            ]);
        });
    }

    /** Las reglas comunes de las rutas de una corrida de clientes o proveedores. */
    private function reglas_de_corrida(): array
    {
        return [
            'modelo' => 'required|string|in:' . implode(',', ImportacionesDeMartinService::MODELOS),
            'corrida_id' => 'required|integer|min:1',
        ];
    }

    /**
     * `GET claude/support-codex/tickets/{id}/import-status?modelo=&corrida_id=` — el estado y el resumen de una
     * corrida de clientes o proveedores (con la reserva del ticket).
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function import_status(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, $this->reglas_de_corrida() + ['lease_token' => 'sometimes|nullable|string|min:32|max:128']);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            $ticket = $this->importaciones->ticket_con_reserva((int) $id, (string) $this->lease($request));

            return $this->importaciones->estado($ticket, (string) $request->input('modelo'), (int) $request->input('corrida_id'));
        });
    }

    /**
     * `POST claude/support-codex/tickets/{id}/import-confirm` — confirma la corrida cuando el último mensaje del
     * ticket (el de la reserva) es el `CONFIRMAR <código>` del dueño.
     *
     * @param Request                  $request `{lease_token, expected_message_id, modelo, corrida_id}`.
     * @param int|string               $id
     * @param SupportCodexService      $soporte
     * @param PruebaImportacionService $importacion
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function import_confirm(Request $request, $id, SupportCodexService $soporte, PruebaImportacionService $importacion)
    {
        $invalido = $this->validar_o_422($request, $this->reglas_de_corrida() + [
            'lease_token' => 'required|string|min:32|max:128',
            'expected_message_id' => 'required|integer|min:1',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id, $soporte, $importacion) {
            return DB::transaction(function () use ($request, $id, $soporte, $importacion) {
                $ticket = SupportTicket::with('client')->lockForUpdate()->find((int) $id);
                if ($ticket === null) {
                    throw new ImportacionesDeMartinException(404, 'sin_ticket', 'No existe el ticket ' . (int) $id . '.');
                }
                $puerta = $importacion->puerta_activo($ticket);
                if ($puerta !== null) {
                    return $puerta;
                }
                $soporte->assert_current($ticket, [
                    'lease_token' => (string) $request->input('lease_token'),
                    'expected_message_id' => (int) $request->input('expected_message_id'),
                    'action' => 'responder',
                ]);
                $mensaje = SupportMessage::whereKey((int) $request->input('expected_message_id'))
                    ->where('support_ticket_id', $ticket->id)->where('sender_type', 'user')->first();
                if ($mensaje === null) {
                    return ['estado' => 'confirmacion_invalida', 'mensaje' => 'Hace falta la respuesta del dueño en este ticket.'];
                }

                return $this->importaciones->confirmar($ticket, $mensaje, (string) $request->input('modelo'), (int) $request->input('corrida_id'));
            });
        });
    }

    /**
     * `POST claude/support-codex/tickets/{id}/import-revert` — deshace una corrida de clientes o proveedores, a pedido
     * del dueño (con la reserva del ticket).
     *
     * @param Request    $request `{lease_token, modelo, corrida_id}`.
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function import_revert(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, $this->reglas_de_corrida() + ['lease_token' => 'required|string|min:32|max:128']);
        if ($invalido !== null) {
            return $invalido;
        }

        return $this->responder(function () use ($request, $id) {
            $ticket = $this->importaciones->ticket_con_reserva((int) $id, (string) $request->input('lease_token'));

            return $this->importaciones->revertir($ticket, (string) $request->input('modelo'), (int) $request->input('corrida_id'));
        });
    }
}
