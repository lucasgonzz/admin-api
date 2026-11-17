<?php

namespace App\Http\Controllers\Api;

use App\Services\ClientWhatsappConexionService;
use Illuminate\Http\Request;

/**
 * La conexión de WhatsApp (Kapso) de UN cliente, vista y provisionada desde el admin
 * (misión `kapso-secretos-fuera-del-navegador`, 17/11/2026, contrato B del plan):
 *
 *   - `GET claude/clients/{id}/whatsapp-conexion`  → cómo está la conexión del cliente (sin secretos).
 *   - `PUT claude/clients/{id}/whatsapp-conexion`  → la provisiona: `phone_number_id`, `webhook_secret`, `is_active`.
 *
 * Extiende `ClaudeClientCatalogoController` a propósito, igual que `ClaudeClientAccesoSoporteController`: hereda SIN copiar el cargado
 * del cliente, los códigos de error estables, el freno de `confirm_client_name`, los booleanos estrictos y el `eco()`. Las rutas
 * heredadas (`clave_json`, `puente_json`) NO están registradas con este controlador.
 *
 * 🔴 LA CLAVE DE KAPSO NO VIAJA DESDE AFUERA. La pone el admin, tomándola de su propia `whatsapp_config` (ver
 * `ClientWhatsappConexionService`). Un `kapso_api_key` en el cuerpo es 422: quien llama no la tiene ni debe tenerla.
 *
 * Pedido del PUT (lista cerrada, una clave de más = 422):
 *
 *     { phone_number_id?: string ≤ 191, webhook_secret?: string ≤ 191, is_active?: bool,
 *       dry_run?: bool (DEFAULT TRUE), confirm_client_name?: string (obligatorio si dry_run=false) }
 *
 * Tiene que venir al menos uno de `phone_number_id`, `webhook_secret`, `is_active`.
 *
 * Respuestas:
 *
 *   - 200 `{client_id, estado, mensaje, conexion}`. `estado` es `ok|version_vieja|sin_clave|clave_rechazada|sin_frente|sin_conexion|
 *     sin_kapso|rechazada`; `conexion` (solo en `ok`) trae booleans y el `phone_number_id`, sin secretos. Igual que `agentes-ia`, el
 *     HTTP es 200 también cuando el estado no es `ok`: el estado dice qué pasó.
 *   - 200 con `dry_run = true` (PUT): `{client_id, estado, mensaje, dry_run: true, listo, cliente, frente: {api_host}, cambiaria, conexion: null}`.
 *     NO llama al cliente: dice a quién y por dónde se le hablaría y qué campos se tocarían. Nunca repite el `webhook_secret`.
 *   - 404 `cliente_inexistente` · 422 `validacion` (con `detalle` por campo).
 *
 * 🔴 ORDEN de las comprobaciones: cliente → validación del pedido → freno del nombre (solo con `dry_run = false`) → servicio. Un pedido
 * inválido no llega a tocar al cliente, y un nombre equivocado no llega a provisionar nada.
 *
 * 🔴 NINGUNA RESPUESTA, LOG NI MENSAJE DE ERROR LLEVA `clients.api_key`, LA CLAVE DE KAPSO NI EL `webhook_secret`. Los errores de validación
 * nombran el campo, nunca repiten su valor.
 *
 * Protegida por el middleware `claude.task.key`, como todo el bloque.
 */
class ClaudeClientWhatsappConexionController extends ClaudeClientCatalogoController
{
    /**
     * Parámetros del PUT (lista cerrada).
     *
     * @var array<int, string>
     */
    const PARAMETROS_DE_LA_CONEXION = ['phone_number_id', 'webhook_secret', 'is_active', 'dry_run', 'confirm_client_name'];

    /** Largo máximo de `phone_number_id` y `webhook_secret` (el del contrato A). */
    const MAX_CAMPO = 191;

    /**
     * Cómo está la conexión de WhatsApp del cliente.
     *
     * @param Request                       $request  Request entrante.
     * @param int|string                    $id       Id numérico o uuid del cliente.
     * @param ClientWhatsappConexionService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function whatsapp_conexion_estado_json(Request $request, $id, ClientWhatsappConexionService $servicio)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $resultado = $servicio->consultar($client);

        return response()->json(array_merge(['client_id' => (int) $client->id], $resultado), 200)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Provisiona (o simula provisionar) la conexión de WhatsApp del cliente.
     *
     * `dry_run` es `true` por defecto y estricto: un valor que no se entiende es 422, nunca "provisionar". Con `dry_run = false` el
     * `confirm_client_name` es obligatorio y se compara contra `clients.name`; el error NO revela el nombre correcto.
     *
     * @param Request                       $request  Request entrante.
     * @param int|string                    $id       Id numérico o uuid del cliente.
     * @param ClientWhatsappConexionService $servicio Inyectado por el contenedor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function whatsapp_conexion_provisionar_json(Request $request, $id, ClientWhatsappConexionService $servicio)
    {
        $client = $this->cargar_cliente($id);

        if ($client === null) {
            return $this->responder_cliente_inexistente($id);
        }

        $pedido = $this->leer_el_pedido_de_la_conexion($request);

        if (count($pedido['errores']) > 0) {
            return $this->responder_validacion($pedido['errores']);
        }

        if (! $pedido['dry_run']) {
            $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, '');

            if ($rechazo !== null) {
                $datos = $rechazo->getData(true);

                return $this->responder_validacion(
                    ['confirm_client_name' => trim((string) $datos['error'])],
                    ['client_id' => (int) $client->id, 'ayuda' => isset($datos['ayuda']) ? (string) $datos['ayuda'] : null]
                );
            }
        }

        if ($pedido['dry_run']) {
            $previo = $servicio->verificar_que_se_puede_provisionar($client);
            $listo  = $previo['estado'] === ClientWhatsappConexionService::ESTADO_OK;

            return response()->json([
                'client_id' => (int) $client->id,
                'estado'    => $previo['estado'],
                'mensaje'   => $previo['mensaje'],
                'dry_run'   => true,
                'listo'     => $listo,
                'cliente'   => ['id' => (int) $client->id, 'nombre' => $this->eco($client->name, self::MAX_NOMBRE)],
                'frente'    => ['api_host' => $previo['host']],
                'cambiaria' => [
                    'phone_number_id' => $pedido['phone_number_id'],
                    'webhook_secret'  => $pedido['webhook_secret'] !== null,
                    'is_active'       => $pedido['is_active'],
                    'kapso_api_key'   => 'la pone el admin con la clave de su proyecto de Kapso',
                ],
                'conexion'  => null,
            ], 200)->header('Cache-Control', 'no-store, private');
        }

        $resultado = $servicio->provisionar($client, $pedido['phone_number_id'], $pedido['webhook_secret'], $pedido['is_active']);

        return response()->json(array_merge(['client_id' => (int) $client->id], $resultado), 200)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Lee y valida a mano el pedido del PUT. No toca nada ni le pega a nadie.
     *
     * 🔴 Un `kapso_api_key` en el cuerpo es un 422 con su propio motivo: la clave del proyecto la pone el admin. Ningún mensaje de error
     * repite el valor de un campo.
     *
     * @param Request $request Request entrante.
     *
     * @return array{errores: array<string, string>, dry_run: bool, phone_number_id: string|null, webhook_secret: string|null, is_active: bool|null}
     */
    protected function leer_el_pedido_de_la_conexion(Request $request)
    {
        $errores = [];
        $todos   = $request->all();

        if (array_key_exists('kapso_api_key', $todos)) {
            $errores['kapso_api_key'] = 'La clave de Kapso del proyecto la pone el admin con la suya y no se envía desde afuera. Sacá este campo del pedido.';
        }

        foreach ($this->parametros_de_mas($request, self::PARAMETROS_DE_LA_CONEXION) as $campo => $motivo) {
            if ($campo !== 'kapso_api_key') {
                $errores[$campo] = $motivo;
            }
        }

        $dry_run = true;
        $bruto   = $request->input('dry_run');

        if ($bruto !== null && $bruto !== '') {
            $leido = $this->leer_booleano($bruto);

            if ($leido === null) {
                $errores['dry_run'] = 'Tiene que ser booleano (true, false, 1 o 0). Sin él es true: no provisiona nada.';
            } else {
                $dry_run = $leido;
            }
        }

        $nombre = $request->input('confirm_client_name');

        if ($nombre !== null && (! is_string($nombre) || mb_strlen($nombre) > self::MAX_NOMBRE)) {
            $errores['confirm_client_name'] = 'Tiene que ser un texto de hasta ' . self::MAX_NOMBRE . ' caracteres.';
        }

        $phone  = $this->leer_texto_de_la_conexion($request->input('phone_number_id'), 'phone_number_id', $errores);
        $secret = $this->leer_texto_de_la_conexion($request->input('webhook_secret'), 'webhook_secret', $errores);

        $is_active = null;
        $bruto_ia  = $request->input('is_active');

        if ($bruto_ia !== null && $bruto_ia !== '') {
            $leido = $this->leer_booleano($bruto_ia);

            if ($leido === null) {
                $errores['is_active'] = 'Tiene que ser booleano (true, false, 1 o 0).';
            } else {
                $is_active = $leido;
            }
        }

        if (count($errores) === 0 && $phone === null && $secret === null && $is_active === null) {
            $errores['phone_number_id'] = 'No hay nada para cambiar: mandá al menos uno de phone_number_id, webhook_secret o is_active.';
        }

        return [
            'errores'         => $errores,
            'dry_run'         => $dry_run,
            'phone_number_id' => $phone,
            'webhook_secret'  => $secret,
            'is_active'       => $is_active,
        ];
    }

    /**
     * Un texto técnico de la conexión: vacío o ausente es null (no se toca); si viene tiene que ser texto de hasta 191 caracteres.
     *
     * @param mixed                 $crudo   Lo que vino en el pedido.
     * @param string                $campo   Nombre del campo (para el error).
     * @param array<string, string> $errores Errores del pedido, por referencia.
     *
     * @return string|null
     */
    protected function leer_texto_de_la_conexion($crudo, $campo, array &$errores)
    {
        if ($crudo === null || $crudo === '') {
            return null;
        }

        if (! is_string($crudo) || ! mb_check_encoding($crudo, 'UTF-8')) {
            $errores[$campo] = 'Tiene que ser un texto de hasta ' . self::MAX_CAMPO . ' caracteres.';

            return null;
        }

        $texto = trim($crudo);

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > self::MAX_CAMPO) {
            $errores[$campo] = 'Es demasiado largo (máximo ' . self::MAX_CAMPO . ' caracteres).';

            return null;
        }

        return $texto;
    }
}
