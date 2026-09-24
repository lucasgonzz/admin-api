<?php

namespace App\Services;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Empuja el paquete de IA de un cliente a su `empresa-api`, para que la instancia sepa con qué tope
 * cortar el consumo del asistente (misión foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * Molde EXACTO: `ClientAiTokensSyncService`, pero al revés — aquél hace GET para TRAER el consumo,
 * éste hace PUT para MANDAR el plan. Se conservan sus mismas cuatro reglas, que no son negociables:
 *
 * 🔴 **CUATRO REGLAS QUE GOBIERNAN ESTE SERVICIO:**
 *
 *  1. **Nunca lanza.** Esto corre adentro de un barrido de cuarenta y cinco clientes: un cliente
 *     caído no puede cortar la corrida de los demás. Todos los caminos —los dos cortes previos y
 *     todos los desenlaces del HTTP— terminan escribiendo `ai_plan_sync_*` en el cliente y devolviendo.
 *
 *  2. 🔴 **El `catch (RequestException $e) { $response = $e->response; }` NO es opcional.** En
 *     Laravel 8, `->retry()` convierte cualquier respuesta no-2xx en excepción ANTES de que `put()`
 *     devuelva algo. Sin recuperar la respuesta real acá, la rama del 404 de más abajo sería código
 *     muerto — el mismo pozo documentado en `ClientAiTokensSyncService` y en `ClientScheduleSyncService`.
 *
 *  3. **Cada código de respuesta nombra su causa.** El 404 es el caso ESPERADO —la instancia del
 *     cliente todavía no tiene la ruta `admin-sync/plan-ia` y va a seguir así durante semanas—, se
 *     registra `no_soportado`, no se reintenta y no se avisa a nadie: confundirlo con un fallo
 *     llenaría la ficha de rojos que no hay que arreglar. El 409 nombra el dueño que la base
 *     compartida no puede resolver; el 401/403 nombra la `api_key`. Los tres son `failed` o
 *     `no_soportado`, pero el estado dice QUÉ pasó y el mensaje dice a DÓNDE ir.
 *
 *  4. **El push es idempotente.** Mandar el mismo plan dos veces deja al cliente exactamente igual:
 *     el receptor pisa las columnas del plan del dueño con lo que llega. Por eso el barrido nocturno
 *     puede reenviar sin miedo, y por eso un cliente que estuvo caído se recupera solo en la corrida
 *     siguiente sin cola de reintentos.
 */
class ClientAiPlanSyncService
{
    /**
     * Ruta relativa del endpoint que recibe el plan en el `empresa-api` del cliente.
     */
    const PLAN_IA_PATH = 'api/admin-sync/plan-ia';

    /** El cliente contestó 200 y guardó el plan en el dueño. */
    const ESTADO_SUCCESS = 'success';

    /** 404: su versión de `empresa-api` todavía no tiene la ruta. Es lo esperado, no un fallo. */
    const ESTADO_NO_SOPORTADO = 'no_soportado';

    /** 401/403, 409, 5xx, timeout, o configuración faltante del lado del admin. */
    const ESTADO_FAILED = 'failed';

    /** Caracteres del cuerpo de la respuesta del cliente que se guardan en el mensaje. */
    const CHARS_DE_CUERPO = 300;

    /**
     * @var ClientEmpresaApiUrlResolver Resuelve la URL base del `empresa-api` del cliente.
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para las pruebas.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver === null
            ? new ClientEmpresaApiUrlResolver()
            : $api_url_resolver;
    }

    /**
     * Empuja al `empresa-api` del cliente el paquete que tiene asignado.
     *
     * 🔴 Si el cliente NO tiene paquete (`ai_plan_id` nulo), se manda igual, con las claves en
     * null: eso es un DESTOPE explícito del lado del cliente, no un "no hacer nada". Quitarle el
     * paquete a un cliente tiene que llegar a su instancia como "ahora sin tope", no quedar colgado.
     *
     * @param Client $client Cliente a sincronizar.
     *
     * @return array{estado: string, mensaje: string|null, sincronizado_at: string|null}
     */
    public function pushear_al_cliente(Client $client)
    {
        $url = $this->api_url_resolver->admin_sync_url($client, self::PLAN_IA_PATH);

        // Corte 1: sin URL resoluble. Es configuración faltante del admin, no del cliente.
        if ($url === '') {
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa '
                . 'o api_url legacy).'
            );
        }

        // Corte 2: sin api_key. Mismo motivo.
        if (trim((string) $client->api_key) === '') {
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'El cliente no tiene api_key configurada (tiene que coincidir con '
                . 'ADMIN_API_INBOUND_KEY del empresa-api del cliente).'
            );
        }

        $cuerpo = $this->cuerpo_del_plan($client);

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => (string) $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                /* 🔴 El tercer parámetro NO es adorno: sin él se reintenta CUALQUIER no-2xx,
                 * incluido el 404, que es el caso esperado mientras el parque se actualiza. Un 4xx
                 * no se arregla insistiendo. */
                ->retry(
                    (int) config('services.client_api.retries', 2),
                    500,
                    function ($exception) {
                        return $this->conviene_reintentar($exception);
                    }
                )
                ->put($url, $cuerpo);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // 🔴 Regla 2 del docblock de la clase: sin esto, la rama del 404 es código muerto.
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response !== null && $response->status() === 404) {
            /* 🔴 Regla 3: el 404 es la versión vieja del cliente. No se reintenta y NO se toca
             * `ai_plan_synced_at`: el último push exitoso (si lo hubo) sigue siendo válido. */
            return $this->registrar(
                $client,
                self::ESTADO_NO_SOPORTADO,
                'La versión instalada del empresa-api de este cliente todavía no tiene la ruta '
                . 'admin-sync/plan-ia. El plan va a empezar a viajar solo cuando el cliente se '
                . 'actualice. El cliente sigue sin tope hasta entonces (no corta).'
            );
        }

        if ($response !== null && $response->status() === 409) {
            /* 🔴 El 409 tiene mensaje propio aunque el estado siga siendo `failed`.
             *
             * El proveedor lo devuelve cuando no puede resolver el dueño al que guardarle el plan:
             * el `.env` del frente de ese cliente no tiene `USER_ID` y la base tiene más de un
             * comercio adentro. NO está viejo y NO se cayó la conexión — está MAL CONFIGURADO, y se
             * arregla cargando una línea en un archivo. */
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'A este cliente le falta `USER_ID` en el .env de su frente activo. Su empresa-api '
                . 'comparte la base con otros comercios y sin esa variable no sabe a qué dueño '
                . 'guardarle el plan, así que se niega a adivinar (HTTP 409). No es una versión '
                . 'vieja ni un problema de red: se resuelve cargando USER_ID en ese .env y volviendo '
                . 'a sincronizar. Respuesta del cliente: '
                . substr(trim((string) $response->body()), 0, self::CHARS_DE_CUERPO)
            );
        }

        if ($response !== null && ! $response->successful()) {
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $response->status() . ': '
                . substr((string) $response->body(), 0, self::CHARS_DE_CUERPO);

            if ($response->status() === 401 || $response->status() === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            return $this->registrar($client, self::ESTADO_FAILED, $mensaje);
        }

        if ($response === null) {
            Log::warning('ClientAiPlanSyncService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => $client->id,
                'url'       => $url,
                'error'     => $transport_error,
            ]);

            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'No se pudo contactar al empresa-api del cliente en ' . $url . ': ' . $transport_error
            );
        }

        /* 🔴 Un 200 NO alcanza para dar el push por bueno: hay que reconocer que el cuerpo confirma
         * el guardado (`{ok:true}`, por contrato). El shared hosting de Hostinger sirve su página
         * genérica CON HTTP 200 cuando la cuenta está saturada; sin este corte, ese HTML quedaría
         * como `success` con la fecha estampada y el plan NUNCA habría llegado al cliente, sin que
         * nada lo denuncie. */
        $data = $response->json();

        if (! is_array($data) || ! array_key_exists('ok', $data) || ! $data['ok']) {
            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'El cliente respondió HTTP ' . $response->status() . ' pero el cuerpo no confirma el '
                . 'guardado del plan (falta `ok:true`). Suele ser la página genérica del hosting '
                . 'cuando la cuenta está saturada. Empieza así: '
                . substr(trim((string) $response->body()), 0, self::CHARS_DE_CUERPO)
            );
        }

        return $this->registrar($client, self::ESTADO_SUCCESS, null, true);
    }

    /**
     * Arma el cuerpo del PUT con las CUATRO claves EXACTAS del contrato.
     *
     * 🔴 Los nombres son parte del contrato y el otro lado los lee con `$request->input('nombre')`,
     * etc.: renombrar cualquiera de las cuatro en el camino es justo donde este proyecto ya se quemó
     * (`manual_tasks` vs `tareas`). Si el cliente no tiene paquete, las cuatro van en null (destope
     * de los dos topes de consumo; en las búsquedas web, null = el defecto de 30 de empresa).
     *
     * La cuarta, `tope_busquedas_web_diarias` (misión asistente-fotos-barras-y-compras, 24/9/2026),
     * es OPCIONAL del lado de empresa y por eso se puede mandar a todo el parque sin romper a nadie:
     * un `empresa-api` viejo la ignora (su `PlanIaController` valida y lee solo las otras tres, y
     * contesta 200 igual), y un `empresa-api` nuevo que no la recibe (admin viejo) no toca su columna
     * y aplica el defecto de 30.
     *
     * @param Client $client Cliente dueño.
     *
     * @return array{nombre: string|null, tope_tokens_mensual: int|null, tope_interacciones_diarias: int|null, tope_busquedas_web_diarias: int|null}
     */
    protected function cuerpo_del_plan(Client $client)
    {
        $client->loadMissing('ai_plan');

        $plan = $client->ai_plan;

        if ($plan === null) {
            return [
                'nombre'                     => null,
                'tope_tokens_mensual'        => null,
                'tope_interacciones_diarias' => null,
                'tope_busquedas_web_diarias' => null,
            ];
        }

        return [
            'nombre'                     => $plan->nombre,
            'tope_tokens_mensual'        => $plan->tope_tokens_mensual,
            'tope_interacciones_diarias' => $plan->tope_interacciones_diarias,
            'tope_busquedas_web_diarias' => $plan->tope_busquedas_web_diarias,
        ];
    }

    /**
     * Si conviene reintentar una llamada que falló.
     *
     * Un 4xx no se arregla insistiendo: el 404 es la versión vieja del cliente y el 401 es una
     * api_key que no coincide. Un 5xx o un corte de conexión sí pueden ser pasajeros.
     *
     * @param \Throwable $exception Excepción que levantó el cliente HTTP.
     *
     * @return bool
     */
    protected function conviene_reintentar($exception)
    {
        if (! ($exception instanceof \Illuminate\Http\Client\RequestException)) {
            // ConnectionException, timeout, DNS: puede ser pasajero.
            return true;
        }

        if ($exception->response === null) {
            return true;
        }

        $status = (int) $exception->response->status();

        return $status < 400 || $status >= 500;
    }

    /**
     * Persiste el desenlace en el cliente y lo devuelve.
     *
     * `ai_plan_synced_at` SOLO se toca cuando hubo éxito: un fallo posterior no puede borrar la
     * información de cuándo llegó bien el plan por última vez.
     *
     * @param Client      $client  Cliente a marcar.
     * @param string      $estado  success | no_soportado | failed.
     * @param string|null $mensaje Motivo, cuando no es success.
     * @param bool        $exitoso Si además hay que estampar `ai_plan_synced_at`.
     *
     * @return array{estado: string, mensaje: string|null, sincronizado_at: string|null}
     */
    protected function registrar(Client $client, $estado, $mensaje = null, $exitoso = false)
    {
        $cambios = [
            'ai_plan_sync_status'  => $estado,
            'ai_plan_sync_message' => $mensaje,
        ];

        if ($exitoso) {
            $cambios['ai_plan_synced_at'] = Carbon::now();
        }

        $client->update($cambios);

        return [
            'estado'          => $estado,
            'mensaje'         => $mensaje,
            'sincronizado_at' => $client->ai_plan_synced_at === null
                ? null
                : (string) $client->ai_plan_synced_at,
        ];
    }
}
