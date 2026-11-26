<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Services\ClientAvisoDeActualizacionService;
use Illuminate\Http\Request;

/**
 * Las cuatro rutas `claude/` del aviso "estamos actualizando el sistema" (misión
 * aviso-actualizacion-sistema, 25/11/2026). Son las que usa la skill de actualizar clientes:
 *
 *   POST   claude/upgrades/{id}/pedido-de-ok            pide el OK a las computadoras conectadas
 *   GET    claude/upgrades/{id}/pedido-de-ok            lee el estado (quién falta, cuánto queda)
 *   DELETE claude/upgrades/{id}/pedido-de-ok            cancela el pedido (queda para la noche)
 *   POST   claude/upgrades/{id}/aviso-de-actualizacion  prende o suelta el aviso a mano
 *
 * No hacen nada por sí mismas: traducen cada llamada a una request al admin-sync de empresa-api del
 * cliente del upgrade (`ClientAvisoDeActualizacionService`) y devuelven su resultado con la forma
 * que la skill ya espera (plan §4.5):
 *
 *   200  {"ok": true, "soportado": true, "aviso": <AvisoEstado sin "ok">}
 *   409  {"error": "aviso_apagado"}                        el interruptor del admin está apagado
 *   409  {"error": "version_sin_aviso", "soportado": false}  empresa-api contestó 404 (versión vieja)
 *   409  {"error": "ya_actualizando"}                      sólo en DELETE: el pedido ya pasó a bloquear
 *   502  {"error": "cliente_no_responde", "http": n|null}   cualquier otra falla de empresa-api
 *
 * 🔴 CON EL INTERRUPTOR APAGADO (`AVISO_DE_ACTUALIZACION_HABILITADO`, default false) NO HACEN
 * NADA: contestan 409 `aviso_apagado` sin salir a ninguna red. Es lo que permite desplegar el
 * código al admin antes de que el resto del sistema esté listo.
 *
 * Ninguna de las cuatro exige `confirm_client_name`: no arrancan ni detienen ningún deployment ni
 * tocan datos del cliente, sólo muestran un aviso y piden una confirmación a sus usuarios. El freno
 * es el interruptor, la clave `X-Claude-Task-Key` y la existencia del upgrade.
 *
 * No toca `DeploymentService` ni el pipeline: los ganchos que prenden y sueltan el aviso durante el
 * despliegue viven allá. Estas rutas son el canal para la skill y para soltar el aviso a mano (por
 * ejemplo al volver atrás con el respaldo, antes del `php artisan up`).
 */
class ClaudeAvisoDeActualizacionController extends Controller
{
    /**
     * Helpers genéricos del bloque `claude/*` (validación JSON, 404, textos). Usa la versión del
     * trait de `validar_o_422()` y de `mensajes_de_validacion()`, que ya traen `integer`, `in`,
     * `min` y `max` en español.
     */
    use RespuestasParaClaude;

    /**
     * POST claude/upgrades/{id}/pedido-de-ok
     *
     * Le pide el OK a las computadoras conectadas del cliente. Manda a empresa-api el id del upgrade,
     * la versión destino y el plazo (10 minutos por defecto).
     *
     * @param Request    $request Request entrante (`minutos_para_responder` opcional, 1..30).
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pedir_ok_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'minutos_para_responder' => 'nullable|integer|min:1|max:30',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $contexto = $this->contexto($id);
        if ($contexto instanceof \Illuminate\Http\JsonResponse) {
            return $contexto;
        }

        $minutos = $this->entero_o_null($request->input('minutos_para_responder'));

        return $this->responder(
            $contexto['servicio']->pedir_ok($contexto['client'], $contexto['upgrade'], $minutos)
        );
    }

    /**
     * GET claude/upgrades/{id}/pedido-de-ok
     *
     * Lee el estado del aviso del cliente: `libre`, `pidiendo_ok` (con quiénes faltan y cuántos
     * segundos quedan), `rechazado` (con quién dijo "Ahora no") o `actualizando`.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function estado_json(Request $request, $id)
    {
        $contexto = $this->contexto($id);
        if ($contexto instanceof \Illuminate\Http\JsonResponse) {
            return $contexto;
        }

        return $this->responder($contexto['servicio']->leer($contexto['client'], $contexto['upgrade']));
    }

    /**
     * DELETE claude/upgrades/{id}/pedido-de-ok
     *
     * Cancela el pedido de OK (la skill lo usa cuando alguien aprieta "Ahora no" o se acaba el tiempo
     * de espera). Si el pedido ya pasó a bloquear el sistema, empresa-api contesta `ya_actualizando`:
     * para soltar ese estado se usa `aviso-de-actualizacion` con `accion: liberar`.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelar_json(Request $request, $id)
    {
        $contexto = $this->contexto($id);
        if ($contexto instanceof \Illuminate\Http\JsonResponse) {
            return $contexto;
        }

        return $this->responder($contexto['servicio']->cancelar_pedido($contexto['client'], $contexto['upgrade']));
    }

    /**
     * POST claude/upgrades/{id}/aviso-de-actualizacion
     *
     * Prende (`accion: prender`) o suelta (`accion: liberar`) el aviso a mano. `prender` acepta
     * `vence_en_minutos` (5..240, 120 por defecto): pasado ese tiempo sin renovarse, el aviso se
     * suelta solo.
     *
     * @param Request    $request Request entrante (`accion` obligatoria, `vence_en_minutos` opcional).
     * @param int|string $id      Id numérico o uuid del upgrade.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function aviso_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'accion'           => 'required|string|in:prender,liberar',
            'vence_en_minutos' => 'nullable|integer|min:5|max:240',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $contexto = $this->contexto($id);
        if ($contexto instanceof \Illuminate\Http\JsonResponse) {
            return $contexto;
        }

        if ($request->input('accion') === 'prender') {
            return $this->responder($contexto['servicio']->prender(
                $contexto['client'],
                $contexto['upgrade'],
                $this->entero_o_null($request->input('vence_en_minutos'))
            ));
        }

        return $this->responder($contexto['servicio']->liberar($contexto['client'], $contexto['upgrade']));
    }

    /**
     * Lo que tienen en común las cuatro rutas antes de llamar a empresa-api: el interruptor
     * prendido, el upgrade y su cliente.
     *
     * @param int|string $id Id numérico o uuid del upgrade.
     *
     * @return array{servicio: ClientAvisoDeActualizacionService, upgrade: ClientVersionUpgrade, client: Client}|\Illuminate\Http\JsonResponse
     *         La respuesta de error que corresponda (409 apagado, 404, 422), o el contexto.
     */
    private function contexto($id)
    {
        $servicio = app(ClientAvisoDeActualizacionService::class);

        /* 🔴 Primero el interruptor: apagado no hay lectura de la base ni red que valga. */
        if (! $servicio->habilitado()) {
            return response()->json([
                'error'   => 'aviso_apagado',
                'message' => 'El aviso de actualización está apagado en el admin (AVISO_DE_ACTUALIZACION_HABILITADO). '
                    . 'No se le habló al cliente.',
            ], 409);
        }

        $upgrade = $this->buscar_upgrade($id);
        if ($upgrade === null) {
            return $this->error_404('no existe el upgrade ' . $id);
        }

        $client = $upgrade->client_id === null ? null : Client::query()->where('id', (int) $upgrade->client_id)->first();
        if ($client === null) {
            return $this->error_422('El upgrade no tiene cliente asociado.');
        }

        return ['servicio' => $servicio, 'upgrade' => $upgrade, 'client' => $client];
    }

    /**
     * Busca el upgrade por id numérico o uuid.
     *
     * @param int|string $route_id Segmento de la ruta.
     *
     * @return ClientVersionUpgrade|null
     */
    private function buscar_upgrade($route_id)
    {
        return is_numeric($route_id)
            ? ClientVersionUpgrade::where('id', (int) $route_id)->first()
            : ClientVersionUpgrade::where('uuid', (string) $route_id)->first();
    }

    /**
     * Traduce el resultado del servicio a la respuesta de la ruta.
     *
     * @param array{soportado: bool, http: int|null, aviso: array|null, error: string|null} $resultado
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function responder(array $resultado)
    {
        if ($resultado['aviso'] !== null) {
            return response()->json([
                'ok'        => true,
                'soportado' => true,
                'aviso'     => $resultado['aviso'],
            ], 200);
        }

        /* 404 de empresa-api: la versión instalada del cliente no tiene el aviso. */
        if ($resultado['soportado'] === false) {
            return response()->json([
                'error'     => 'version_sin_aviso',
                'soportado' => false,
                'message'   => 'La versión instalada en el cliente todavía no tiene el aviso de actualización (empresa-api '
                    . 'contestó 404). Se actualiza como hasta ahora: con el negocio cerrado.',
            ], 409);
        }

        /* El pedido ya bloquea el sistema: se pasa el código de empresa-api tal cual. */
        if ($resultado['http'] === 409 && $resultado['error'] === 'ya_actualizando') {
            return response()->json([
                'error'   => 'ya_actualizando',
                'message' => 'El sistema del cliente ya está actualizando: el pedido no se puede cancelar. Para soltarlo '
                    . 'usá POST claude/upgrades/{id}/aviso-de-actualizacion con accion=liberar.',
            ], 409);
        }

        return response()->json([
            'error'   => 'cliente_no_responde',
            'http'    => $resultado['http'],
            'detalle' => $resultado['error'],
            'message' => 'El empresa-api del cliente no contestó lo esperado'
                . ($resultado['http'] === null ? ' (sin respuesta).' : ' (HTTP ' . $resultado['http'] . ').'),
        ], 502);
    }
}
