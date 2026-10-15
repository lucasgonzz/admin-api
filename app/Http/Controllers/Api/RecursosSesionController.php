<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RecursosSesion;
use App\Models\RecursosSesion as RecursosSesionModelo;
use App\Services\RecursosSesionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrar y salir del centro de recursos (contrato C2 de la misión centro-de-recursos-clientes):
 *
 *   POST recursos/sesion/pase  {pase, api} → {token, usuario} · 422 {error: cliente_no_encontrado | pase_invalido | cliente_no_responde}
 *                                            (`usuario.solo_lectura: true` SOLO si el pase vino de un login maestro; ausente = false)
 *   POST recursos/sesion/link  {t}         → {token, usuario} · 422 {error: link_invalido}
 *   POST recursos/salir        (con sesión) → {ok: true}
 *
 * Toda la lógica vive en `RecursosSesionService`; acá están la forma de la respuesta y nada más.
 */
class RecursosSesionController extends Controller
{
    /**
     * @var RecursosSesionService
     */
    protected $servicio;

    /**
     * @param RecursosSesionService $servicio
     */
    public function __construct(RecursosSesionService $servicio)
    {
        $this->servicio = $servicio;
    }

    /**
     * Canjea el pase que emitió el sistema del cliente.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function pase_json(Request $request): JsonResponse
    {
        $resultado = $this->servicio->por_pase($request->input('pase'), $request->input('api'));

        return $this->respuesta($resultado);
    }

    /**
     * Abre sesión con el link personal del dueño.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function link_json(Request $request): JsonResponse
    {
        $resultado = $this->servicio->por_link($request->input('t'));

        return $this->respuesta($resultado);
    }

    /**
     * Invalida el token con el que vino el pedido. Las otras sesiones del usuario (el celular, otra
     * compu) siguen andando.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function salir_json(Request $request): JsonResponse
    {
        $sesion = $request->attributes->get(RecursosSesion::ATRIBUTO_SESION);

        if ($sesion instanceof RecursosSesionModelo) {
            RecursosSesionModelo::where('id', $sesion->id)->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * `{token, usuario}` o el 422 con el código de error.
     *
     * @param array<string, mixed> $resultado
     *
     * @return JsonResponse
     */
    protected function respuesta(array $resultado): JsonResponse
    {
        if (empty($resultado['ok'])) {
            return response()->json(['error' => $resultado['error']], 422);
        }

        $usuario = $resultado['usuario']->para_si_mismo();

        // Solo cuando es true: ausente = false, así la forma no cambia para nadie más (contrato C2).
        if (! empty($resultado['solo_lectura'])) {
            $usuario['solo_lectura'] = true;
        }

        return response()->json([
            'token'   => $resultado['token'],
            'usuario' => $usuario,
        ]);
    }
}
