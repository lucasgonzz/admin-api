<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsappPersonal\WhatsappPersonalEnvioService;
use App\Services\WhatsappPersonal\WhatsappPersonalLecturaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El WhatsApp PERSONAL de Lucas (número en coexistencia con Kapso) para los agentes: estado, plantillas, chats,
 * mensajes, marcar leído, media y envío (misión wa-personal-coexistencia, 25/11/2026).
 *
 * Rutas bajo `api/admin/whatsapp-personal/*`, sólo para un `Admin` con `es_agente` (`admin.solo_agente`). La lógica
 * vive en `App\Services\WhatsappPersonal\*`; acá sólo se valida la FORMA del pedido y se traduce a HTTP.
 *
 * Cuerpo de `POST enviar` (lista cerrada, una clave de más = 422):
 *
 *     { telefono: string, texto?: string ≤ 4096 | plantilla?: string, variables?: string[], idioma?: string,
 *       dry_run?: bool (DEFAULT TRUE), confirm_telefono?: string (últimos 4 dígitos), op?: string 8-80 [A-Za-z0-9._:-] }
 *
 * Con `dry_run = false`, `confirm_telefono` y `op` son obligatorios. 🔴 Los errores no repiten el texto del mensaje.
 */
class WhatsappPersonalController extends Controller
{
    /** Claves aceptadas en `POST enviar`. */
    const PARAMETROS_DEL_ENVIO = ['telefono', 'texto', 'plantilla', 'variables', 'idioma', 'dry_run', 'confirm_telefono', 'op'];

    /** @var WhatsappPersonalLecturaService Lectura. */
    private $lectura;

    /** @var WhatsappPersonalEnvioService Envío. */
    private $envio;

    /**
     * @param WhatsappPersonalLecturaService $lectura Servicio de lectura.
     * @param WhatsappPersonalEnvioService   $envio   Servicio de envío.
     */
    public function __construct(WhatsappPersonalLecturaService $lectura, WhatsappPersonalEnvioService $envio)
    {
        $this->lectura = $lectura;
        $this->envio   = $envio;
    }

    /**
     * `GET estado[?telefono=]`: estado del número, sin secretos.
     *
     * @param Request $request Pedido.
     *
     * @return JsonResponse
     */
    public function estado(Request $request): JsonResponse
    {
        $telefono = null;
        if ($request->input('telefono') !== null) {
            $telefono = is_string($request->input('telefono')) ? WhatsappPersonalEnvioService::normalizar_telefono($request->input('telefono')) : null;
            if ($telefono === null) {
                return $this->error(422, 'telefono_invalido', 'El teléfono no es válido.');
            }
        }

        return response()->json($this->lectura->estado($telefono));
    }

    /**
     * `GET plantillas`: plantillas aprobadas de la WABA.
     *
     * @return JsonResponse
     */
    public function plantillas(): JsonResponse
    {
        $resultado = $this->lectura->plantillas();

        return response()->json($resultado, $resultado['ok'] ? 200 : 502);
    }

    /**
     * `GET chats`: chats por último mensaje.
     *
     * @param Request $request Pedido (`solo_no_leidos`, `desde`, `limite`).
     *
     * @return JsonResponse
     */
    public function chats(Request $request): JsonResponse
    {
        $desde = $this->fecha($request->input('desde'));
        if ($desde === false) {
            return $this->error(422, 'validacion', 'desde tiene que ser una fecha.');
        }

        return response()->json(['chats' => $this->lectura->chats(
            filter_var($request->input('solo_no_leidos'), FILTER_VALIDATE_BOOLEAN),
            $desde,
            (int) $request->input('limite', 20)
        )]);
    }

    /**
     * `GET chats/{telefono}/mensajes`: mensajes de un chat.
     *
     * @param Request $request  Pedido (`desde`, `antes_de_id`, `limite`).
     * @param string  $telefono Teléfono en cualquier formato.
     *
     * @return JsonResponse
     */
    public function mensajes(Request $request, string $telefono): JsonResponse
    {
        $chat = WhatsappPersonalEnvioService::normalizar_telefono($telefono);
        $desde = $this->fecha($request->input('desde'));
        if ($chat === null) {
            return $this->error(422, 'telefono_invalido', 'El teléfono no es válido.');
        }
        if ($desde === false) {
            return $this->error(422, 'validacion', 'desde tiene que ser una fecha.');
        }

        $antes = $request->input('antes_de_id');
        $resultado = $this->lectura->mensajes($chat, $desde, $antes !== null ? (int) $antes : null, (int) $request->input('limite', 30));

        return response()->json(['telefono' => $chat] + $resultado);
    }

    /**
     * `POST chats/{telefono}/leer`: mueve el cursor de lectura. No manda ningún acuse a Meta.
     *
     * @param string $telefono Teléfono en cualquier formato.
     *
     * @return JsonResponse
     */
    public function leer(string $telefono): JsonResponse
    {
        $chat = WhatsappPersonalEnvioService::normalizar_telefono($telefono);
        if ($chat === null) {
            return $this->error(422, 'telefono_invalido', 'El teléfono no es válido.');
        }

        $cursor = $this->lectura->leer($chat);
        if ($cursor === null) {
            return $this->error(404, 'chat_inexistente', 'No hay un chat con ese teléfono.');
        }

        return response()->json(['ok' => true, 'telefono' => $chat, 'leido_hasta' => $cursor]);
    }

    /**
     * `GET mensajes/{id}/media`: el adjunto, bajado a pedido, sin guardarlo ni cachearlo.
     *
     * @param int $id Id del mensaje.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function media(int $id)
    {
        $resultado = $this->lectura->media($id);
        if ($resultado['status'] !== 200) {
            return $this->error($resultado['status'], $resultado['code'], 'No se puede devolver el adjunto.');
        }

        $mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+#i', $resultado['mime'], $partes) === 1 ? strtolower($partes[0]) : 'application/octet-stream';
        $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', $resultado['filename']);

        return response($resultado['binario'], 200, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'attachment; filename="' . ($nombre !== '' ? $nombre : 'adjunto') . '"',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * `POST enviar`: envía desde el número personal (o muestra qué enviaría).
     *
     * @param Request $request Pedido.
     *
     * @return JsonResponse
     */
    public function enviar(Request $request): JsonResponse
    {
        $pedido = $this->leer_pedido_de_envio($request);
        if (isset($pedido['errores'])) {
            return $this->error(422, 'validacion', 'El pedido no es válido.', ['detalle' => $pedido['errores']]);
        }

        $resultado = $this->envio->enviar($pedido);

        return response()->json($resultado['body'], $resultado['status']);
    }

    /**
     * Valida la FORMA del pedido de envío (lista cerrada de claves, tipos y booleano estricto).
     *
     * @param Request $request Pedido.
     *
     * @return array<string, mixed> El pedido limpio, o `['errores' => [campo => motivo]]`.
     */
    private function leer_pedido_de_envio(Request $request): array
    {
        $datos   = $request->input();
        $errores = [];

        foreach (array_diff(array_keys($datos), self::PARAMETROS_DEL_ENVIO) as $de_mas) {
            $errores[(string) $de_mas] = 'Parámetro desconocido.';
        }

        $telefono = isset($datos['telefono']) ? $datos['telefono'] : null;
        if (! is_string($telefono) && ! is_int($telefono)) {
            $errores['telefono'] = 'Tiene que ser un único teléfono (texto).';
        }

        // `dry_run` es true por defecto y estricto: un valor que no se entiende es 422, nunca «enviar».
        $dry_run = true;
        if (array_key_exists('dry_run', $datos)) {
            $leido = is_bool($datos['dry_run']) ? $datos['dry_run'] : (in_array($datos['dry_run'], [1, 0, '1', '0', 'true', 'false'], true)
                ? in_array($datos['dry_run'], [1, '1', 'true'], true) : null);
            if ($leido === null) {
                $errores['dry_run'] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es true: no envía nada.';
            } else {
                $dry_run = $leido;
            }
        }

        $texto     = array_key_exists('texto', $datos) ? $datos['texto'] : null;
        $plantilla = array_key_exists('plantilla', $datos) ? $datos['plantilla'] : null;
        if (($texto === null) === ($plantilla === null)) {
            $errores['texto'] = 'Mandá exactamente uno: texto o plantilla.';
        }
        if ($texto !== null && (! is_string($texto) || trim($texto) === '' || mb_strlen($texto) > 4096)) {
            $errores['texto'] = 'Tiene que ser un texto de 1 a 4096 caracteres.';
        }

        $variables = [];
        if ($plantilla !== null) {
            if (! is_string($plantilla) || preg_match('/^[a-z0-9_]{1,512}$/', $plantilla) !== 1) {
                $errores['plantilla'] = 'Nombre de plantilla inválido.';
            }
            $variables = array_key_exists('variables', $datos) ? $datos['variables'] : [];
            if (! is_array($variables)) {
                $errores['variables'] = 'Tiene que ser una lista de textos.';
            } else {
                foreach ($variables as $variable) {
                    if (! is_scalar($variable) || is_bool($variable) || trim((string) $variable) === '') {
                        $errores['variables'] = 'Ninguna variable puede quedar vacía: Meta descarta la plantilla.';
                        break;
                    }
                }
            }
        } elseif (array_key_exists('variables', $datos) || array_key_exists('idioma', $datos)) {
            $errores['variables'] = 'variables e idioma sólo van con plantilla.';
        }

        $idioma = array_key_exists('idioma', $datos) ? $datos['idioma'] : 'es_AR';
        if (! is_string($idioma) || preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $idioma) !== 1) {
            $errores['idioma'] = 'Idioma inválido (ej. es_AR).';
        }

        $confirm = array_key_exists('confirm_telefono', $datos) ? $datos['confirm_telefono'] : null;
        $op      = array_key_exists('op', $datos) ? $datos['op'] : null;
        if (! $dry_run) {
            if (! is_scalar($confirm) || preg_match('/^\d{4}$/', (string) $confirm) !== 1) {
                $errores['confirm_telefono'] = 'Con dry_run=false van los últimos 4 dígitos del teléfono.';
            }
            if (! is_string($op) || preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $op) !== 1) {
                $errores['op'] = 'Con dry_run=false hace falta una op de 8 a 80 caracteres [A-Za-z0-9._:-].';
            }
        }

        if ($errores !== []) {
            return ['errores' => $errores];
        }

        return [
            'telefono'         => (string) $telefono,
            'texto'            => $texto,
            'plantilla'        => $plantilla,
            'variables'        => array_map('strval', array_values($variables)),
            'idioma'           => $idioma,
            'dry_run'          => $dry_run,
            'confirm_telefono' => $confirm !== null ? (string) $confirm : '',
            'op'               => $op !== null ? (string) $op : '',
        ];
    }

    /**
     * Fecha opcional de la query.
     *
     * @param mixed $valor Valor de la query.
     *
     * @return string|false|null Texto ISO, null si no vino, false si no es una fecha.
     */
    private function fecha($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (! is_string($valor)) {
            return false;
        }

        try {
            return Carbon::parse($valor)->toIso8601String();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Respuesta de error `{code, mensaje, ...}`.
     *
     * @param int                  $status  HTTP.
     * @param string               $code    Código estable.
     * @param string               $mensaje Texto sin datos privados.
     * @param array<string, mixed> $extra   Campos extra.
     *
     * @return JsonResponse
     */
    private function error(int $status, string $code, string $mensaje, array $extra = []): JsonResponse
    {
        return response()->json(['code' => $code, 'mensaje' => $mensaje] + $extra, $status);
    }
}
