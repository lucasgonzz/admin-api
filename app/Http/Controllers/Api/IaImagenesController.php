<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\IaImagenPedido;
use App\Services\IaApp\IaImagenesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * La cola de imágenes de la app del agente del dueño (misión cruzada `app-ia-y-memoria`, 29/10/2026). Dos
 * puertas, un solo servicio (`IaImagenesService`):
 *
 *  - `POST api/inbound/ia-imagenes` (contrato C2.1): el `empresa-api` del cliente da de alta un pedido.
 *    Middleware `admin.inbound.key`, el mismo que los mensajes de soporte (`SupportSyncHelper`).
 *  - `api/claude/ia-imagenes/*` (contrato C3): la PC de Lucas lista, reserva y devuelve imagen o fallo.
 *    Middleware `claude.task.key`.
 *
 * Todo error sale con `{code, message}`.
 */
class IaImagenesController extends Controller
{
    /* ==============================================================================================
     | C2.1 · POST api/inbound/ia-imagenes
     |============================================================================================= */

    /**
     * @param Request           $request
     * @param IaImagenesService $imagenes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function alta(Request $request, IaImagenesService $imagenes)
    {
        $client = $request->attributes->get('sync_client');

        if (! $client instanceof Client) {
            return $this->error(401, 'clave_invalida', 'No se pudo identificar el sistema que pide la imagen.');
        }

        $invalido = $this->validar($request, [
            'client_uuid'   => 'required|string|max:64',
            'uuid'          => 'required|string|size:36|regex:/^[0-9a-fA-F-]{36}$/',
            'prompt'        => 'required|string|max:4000',
            'tamano'        => 'required|string|in:' . implode(',', IaImagenPedido::TAMANOS),
            'estilo'        => 'nullable|string|max:200',
            'ai_message_id' => 'required|integer|min:1',
        ]);

        if ($invalido !== null) {
            return $invalido;
        }

        /* 🔴 El cliente es el que resolvió la clave (o el client_uuid en el modo sin clave). Un client_uuid
           del body que no es el suyo es otro sistema pidiendo a nombre ajeno: se corta. */
        if (! hash_equals((string) $client->uuid, (string) $request->input('client_uuid'))) {
            return $this->error(403, 'cliente_no_coincide', 'El client_uuid no corresponde a la clave del sistema.');
        }

        $resultado = $imagenes->alta($client, [
            'uuid'          => strtolower((string) $request->input('uuid')),
            'prompt'        => (string) $request->input('prompt'),
            'tamano'        => (string) $request->input('tamano'),
            'estilo'        => $request->input('estilo'),
            'ai_message_id' => (int) $request->input('ai_message_id'),
        ]);

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /* ==============================================================================================
     | C3 · api/claude/ia-imagenes/*
     |============================================================================================= */

    /**
     * `GET api/claude/ia-imagenes/pendientes`.
     *
     * @param IaImagenesService $imagenes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendientes(IaImagenesService $imagenes)
    {
        return response()->json($imagenes->pendientes());
    }

    /**
     * `POST api/claude/ia-imagenes/{id}/reservar` `{worker_id, lease_token?}`.
     *
     * @param Request           $request
     * @param int|string        $id
     * @param IaImagenesService $imagenes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reservar(Request $request, $id, IaImagenesService $imagenes)
    {
        $invalido = $this->validar($request, [
            'worker_id'   => 'required|string|max:150',
            'lease_token' => 'sometimes|nullable|string|min:32|max:128',
        ]);

        if ($invalido !== null) {
            return $invalido;
        }

        $resultado = $imagenes->reservar((int) $id, [
            'worker_id'   => (string) $request->input('worker_id'),
            'lease_token' => $request->input('lease_token'),
        ]);

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * `POST api/claude/ia-imagenes/{id}/resultado` multipart `{lease_token, imagen}`.
     *
     * @param Request           $request
     * @param int|string        $id
     * @param IaImagenesService $imagenes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resultado(Request $request, $id, IaImagenesService $imagenes)
    {
        $invalido = $this->validar($request, [
            'lease_token' => 'required|string|min:32|max:128',
            // 15 MB = 15360 KB.
            'imagen'      => 'required|file|mimes:png,jpg,jpeg,webp|max:15360',
        ]);

        if ($invalido !== null) {
            return $invalido;
        }

        $resultado = $imagenes->resultado((int) $id, (string) $request->input('lease_token'), $request->file('imagen'));

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * `POST api/claude/ia-imagenes/{id}/fallo` `{lease_token, error}`.
     *
     * @param Request           $request
     * @param int|string        $id
     * @param IaImagenesService $imagenes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function fallo(Request $request, $id, IaImagenesService $imagenes)
    {
        $invalido = $this->validar($request, [
            'lease_token' => 'required|string|min:32|max:128',
            'error'       => 'nullable|string|max:500',
        ]);

        if ($invalido !== null) {
            return $invalido;
        }

        $resultado = $imagenes->fallo((int) $id, (string) $request->input('lease_token'), (string) $request->input('error'));

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * Validación que siempre contesta JSON `{code, message, errores}` con 422.
     *
     * @param Request               $request
     * @param array<string, string> $reglas
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function validar(Request $request, array $reglas)
    {
        $validador = Validator::make($request->all(), $reglas);

        if ($validador->fails()) {
            return response()->json([
                'code'    => 'validacion',
                'message' => 'Parámetros inválidos.',
                'errores' => $validador->errors()->toArray(),
            ], 422);
        }

        return null;
    }

    /**
     * @param int    $http
     * @param string $codigo
     * @param string $mensaje
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function error($http, $codigo, $mensaje)
    {
        return response()->json(['code' => $codigo, 'message' => $mensaje], $http);
    }
}
