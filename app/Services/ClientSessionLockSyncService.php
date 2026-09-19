<?php

namespace App\Services;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Empuja el candado de sesión por pestaña de un cliente a su empresa-api
 * (`PUT {base_url}/api/admin-sync/session-lock`), para que el owner que prendió el interruptor
 * desde el admin quede endurecido también del lado del cliente.
 *
 * 🔴 Calcado de `ClientScheduleSyncService` a propósito, forma literal — leer su docblock primero
 * si algo de acá no queda claro, porque las reglas de fondo son las mismas:
 *
 *  1. **Nunca se propaga una excepción.** Cuando esto corre, el guardado del interruptor en el
 *     admin ya terminó y fue exitoso: un empresa-api caído no puede convertirse en un error del
 *     admin. Los tres cortes previos y todos los desenlaces del HTTP escriben su motivo en las
 *     columnas `pestanas_sync_*` del cliente y devuelven, punto.
 *
 *  2. **Compatibilidad hacia atrás.** Hoy el `empresa-api` de los clientes NO tiene esta ruta: el
 *     404 es el caso ESPERADO y se degrada a `manual_required`, exactamente como
 *     `ClientScheduleSyncService` con `business-hours`. El día que salga la mitad de empresa, el
 *     mismo push empieza a devolver 200 sin tocar una línea de acá.
 *
 * A diferencia de horarios, el payload de este contrato no tiene nada que resolver: es un solo
 * booleano, tomado directo de la columna del cliente.
 */
class ClientSessionLockSyncService
{
    /** El empresa-api del cliente confirmó la recepción (2xx). */
    const ESTADO_SUCCESS = 'success';

    /** No se pudo empujar por configuración o por versión vieja del cliente: lo resuelve una persona. */
    const ESTADO_MANUAL_REQUIRED = 'manual_required';

    /** El cliente está inactivo: no se llama a nadie y no es un fallo. */
    const ESTADO_SKIPPED = 'skipped';

    /** El empresa-api contestó algo inesperado o no se lo pudo contactar. */
    const ESTADO_FAILED = 'failed';

    /** Caracteres del cuerpo de la respuesta del cliente que se guardan en el mensaje. */
    const CHARS_DE_CUERPO = 300;

    /**
     * @var ClientEmpresaApiUrlResolver Resuelve la URL base del empresa-api del cliente.
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para tests.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver === null ? new ClientEmpresaApiUrlResolver() : $api_url_resolver;
    }

    /**
     * Empuja el interruptor del cliente a su empresa-api y persiste el desenlace.
     *
     * 🔴 No lanza NUNCA: todos los caminos terminan escribiendo `pestanas_sync_status` y devolviendo
     * el resultado.
     *
     * @param Client $client Cliente cuyo interruptor se sincroniza.
     *
     * @return array ['status' => string, 'message' => string|null, 'synced_at' => string|null]
     */
    public function sync(Client $client)
    {
        // Corte 1: cliente inactivo. Mismo criterio que ClientScheduleSyncService::sync().
        if (! $client->is_active) {
            return $this->registrar(
                $client,
                self::ESTADO_SKIPPED,
                'El cliente está inactivo: no se sincroniza el candado de sesión a su empresa-api.'
            );
        }

        $url = $this->api_url_resolver->admin_sync_url(
            $client,
            ClientEmpresaApiUrlResolver::SESSION_LOCK_PATH
        );

        // Corte 2: sin URL resoluble. Es un problema de configuración del admin, no del cliente.
        if ($url === '') {
            return $this->registrar(
                $client,
                self::ESTADO_MANUAL_REQUIRED,
                'No hay URL válida del empresa-api de este cliente. Configurá una ClientApi con URL '
                . 'http/https y marcala como API activa, o cargá api_url en el cliente.'
            );
        }

        // Corte 3: sin api_key. Mismo motivo: configuración faltante del lado del admin.
        if (empty($client->api_key)) {
            return $this->registrar(
                $client,
                self::ESTADO_MANUAL_REQUIRED,
                'El cliente no tiene api_key configurada (tiene que coincidir con ADMIN_API_INBOUND_KEY '
                . 'del empresa-api del cliente).'
            );
        }

        $payload = $this->build_payload($client);

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->put($url, $payload);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            /* En Laravel 8, ->retry() convierte cualquier respuesta no-2xx en excepción antes de que
             * put() devuelva algo. Sin recuperar acá la respuesta real, la rama del 404 de más abajo
             * sería código muerto — el mismo pozo que ya documentó
             * ClientScheduleSyncService::sync() (y antes, DeploymentService). */
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response !== null && $response->successful()) {
            return $this->registrar($client, self::ESTADO_SUCCESS, null, true);
        }

        if ($response !== null && $response->status() === 404) {
            /* 🔴 El 404 es el caso ESPERADO hasta que salga la mitad de empresa-api: la instancia del
             * cliente todavía no tiene la ruta. Se degrada limpio y NO se toca pestanas_synced_at. */
            return $this->registrar(
                $client,
                self::ESTADO_MANUAL_REQUIRED,
                'El empresa-api de este cliente respondió 404 en ' . $url . '. La versión instalada '
                . 'todavía no tiene el endpoint admin-sync/session-lock, o la URL de la ClientApi está '
                . 'mal cargada (revisar si falta el /public de hosting compartido). El candado va a '
                . 'viajar solo cuando el cliente se actualice.'
            );
        }

        if ($response !== null) {
            $mensaje = 'El empresa-api del cliente respondió HTTP ' . $response->status() . ': '
                . substr((string) $response->body(), 0, self::CHARS_DE_CUERPO);

            if ($response->status() === 401 || $response->status() === 403) {
                $mensaje .= ' Probablemente la api_key del cliente no coincide con '
                    . 'ADMIN_API_INBOUND_KEY del empresa-api.';
            }

            return $this->registrar($client, self::ESTADO_FAILED, $mensaje);
        }

        Log::warning('ClientSessionLockSyncService: no se pudo contactar al empresa-api del cliente.', [
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

    /**
     * Arma el payload del contrato con empresa-api: `{ bloquear_pestanas_duplicadas: bool }`.
     *
     * @param Client $client Cliente dueño del interruptor.
     *
     * @return array
     */
    public function build_payload(Client $client)
    {
        return [
            'bloquear_pestanas_duplicadas' => (bool) $client->bloquear_pestanas_duplicadas,
        ];
    }

    /**
     * Persiste el desenlace en el cliente y lo devuelve.
     *
     * `pestanas_synced_at` SOLO se toca cuando hubo éxito: un fallo posterior no puede borrar la
     * información de cuándo fue la última vez que el cliente quedó al día.
     *
     * @param Client      $client  Cliente a marcar.
     * @param string      $status  Uno de los cuatro estados.
     * @param string|null $mensaje Motivo, cuando no es success.
     * @param bool        $exitoso Si además hay que estampar pestanas_synced_at.
     *
     * @return array ['status' => string, 'message' => string|null, 'synced_at' => string|null]
     */
    private function registrar(Client $client, $status, $mensaje = null, $exitoso = false)
    {
        $cambios = [
            'pestanas_sync_status'  => $status,
            'pestanas_sync_message' => $mensaje,
        ];

        if ($exitoso) {
            $cambios['pestanas_synced_at'] = Carbon::now();
        }

        $client->update($cambios);

        return [
            'status'    => $status,
            'message'   => $mensaje,
            'synced_at' => $client->pestanas_synced_at === null ? null : (string) $client->pestanas_synced_at,
        ];
    }
}
