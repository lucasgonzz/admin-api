<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trae del `empresa-api` de un cliente cuántos tokens de IA gastó en un rango de días y los deja
 * espejados en `client_ai_token_usages`.
 *
 * Molde: `ClientMensualidadSyncService` (la llamada saliente) y `ClientScheduleSyncService` (el
 * registro del desenlace en columnas del cliente). De cada uno se toma lo que ya está aprendido:
 *
 * 🔴 **CUATRO REGLAS QUE GOBIERNAN ESTE SERVICIO:**
 *
 *  1. **Nunca lanza.** Esto corre adentro de un barrido de cuarenta y cinco clientes a las 3 de la
 *     mañana: un cliente caído no puede cortar la corrida de los demás. Todos los caminos —los tres
 *     cortes previos y todos los desenlaces del HTTP— terminan escribiendo `ai_tokens_sync_*` en el
 *     cliente y devolviendo.
 *
 *  2. 🔴 **El `catch (RequestException $e) { $response = $e->response; }` NO es opcional.** En
 *     Laravel 8, `->retry()` convierte cualquier respuesta no-2xx en excepción ANTES de que `get()`
 *     devuelva algo (`PendingRequest::send()` llama a `$response->throw()` adentro del retry cuando
 *     `$tries > 1`). Sin recuperar la respuesta real acá, la rama del 404 de más abajo sería código
 *     muerto — el mismo pozo que ya está documentado en `ClientScheduleSyncService.php:146-152` y
 *     en `DeploymentService::step_update_default_version()`.
 *
 *  3. **404 es el caso ESPERADO, no un error.** La instancia del cliente todavía no tiene la ruta
 *     `api/admin-sync/consumo-ia`: va a seguir así durante semanas, hasta que se actualice. Se
 *     registra `no_soportado`, no se reintenta y no se avisa a nadie. Confundirlo con un fallo
 *     llenaría la pestaña de rojos que no hay que arreglar.
 *
 *  4. **El upsert reescribe, no acumula.** Es la propiedad que hace que este dato sea reconstruible
 *     desde la fuente en cualquier momento: volver a pedir el mismo rango deja exactamente el mismo
 *     resultado. Se apoya en el unique `(client_id, fecha, proceso, modelo)` de la tabla, o sea que
 *     no depende de que nadie se acuerde de nada.
 */
class ClientAiTokensSyncService
{
    /**
     * Ruta relativa del endpoint de consumo de IA en el `empresa-api` del cliente.
     */
    const CONSUMO_IA_PATH = 'api/admin-sync/consumo-ia';

    /** El cliente contestó y las filas quedaron guardadas. */
    const ESTADO_SUCCESS = 'success';

    /** 404: su versión de `empresa-api` todavía no tiene el endpoint. Es lo esperado, no un fallo. */
    const ESTADO_NO_SOPORTADO = 'no_soportado';

    /** 401/403, 5xx, timeout, o configuración faltante del lado del admin. */
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
     * Trae el consumo de IA del cliente en el rango pedido y lo espeja localmente.
     *
     * @param Client $client Cliente a consultar.
     * @param string $desde  Primer día del rango, AAAA-MM-DD.
     * @param string $hasta  Último día del rango, AAAA-MM-DD (inclusive).
     *
     * @return array{estado: string, mensaje: string|null, filas: int, sincronizado_at: string|null}
     */
    public function traer_del_cliente(Client $client, $desde, $hasta)
    {
        $url = $this->api_url_resolver->admin_sync_url($client, self::CONSUMO_IA_PATH);

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

        // Respuesta HTTP real (si se pudo obtener) y error de transporte (si no hubo respuesta).
        $response        = null;
        $transport_error = '';

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => (string) $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->get($url, [
                    'desde' => (string) $desde,
                    'hasta' => (string) $hasta,
                ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // 🔴 Regla 2 del docblock de la clase: sin esto, la rama del 404 es código muerto.
            $response = $e->response;
        } catch (\Throwable $e) {
            // ConnectionException, timeout, DNS: no hay respuesta HTTP asociada.
            $transport_error = $e->getMessage();
        }

        if ($response !== null && $response->status() === 404) {
            /* 🔴 Regla 3: el 404 es la versión vieja del cliente. No se reintenta y NO se toca
             * `ai_tokens_synced_at`: lo que ya se haya traído alguna vez sigue siendo válido. */
            return $this->registrar(
                $client,
                self::ESTADO_NO_SOPORTADO,
                'La versión instalada del empresa-api de este cliente todavía no tiene el endpoint '
                . 'admin-sync/consumo-ia. Los tokens van a empezar a viajar solos cuando el cliente '
                . 'se actualice.'
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
            Log::warning('ClientAiTokensSyncService: no se pudo contactar al empresa-api del cliente.', [
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

        $data = $response->json();
        if (! is_array($data)) {
            $data = [];
        }

        $dias = isset($data['dias']) && is_array($data['dias']) ? $data['dias'] : [];

        try {
            $filas = $this->espejar($client, $dias);
        } catch (\Throwable $e) {
            /* Que una fila venga mal formada no puede tumbar el barrido. La transacción de
             * `espejar()` ya hizo rollback, así que el cliente queda como estaba. */
            Log::warning('ClientAiTokensSyncService: falló el guardado del consumo del cliente.', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->registrar(
                $client,
                self::ESTADO_FAILED,
                'El cliente contestó bien pero no se pudo guardar su consumo: ' . $e->getMessage()
            );
        }

        return $this->registrar($client, self::ESTADO_SUCCESS, null, true, $filas);
    }

    /**
     * Vuelca las filas del payload a `client_ai_token_usages`, reescribiendo lo que ya estuviera.
     *
     * 🔴 `updateOrCreate` sobre la clave única y NADA de borrar el rango antes. No hace falta y
     * sería peligroso: `ai_token_usages` del lado del cliente es append-only, así que el agregado
     * de un día solo puede crecer — nunca encogerse ni desaparecer. Borrar primero dejaría al
     * cliente sin datos durante la transacción y, si el payload llegara recortado por cualquier
     * motivo, perdería lo que ya estaba bien.
     *
     * Todo adentro de UNA transacción por cliente: un rango se guarda entero o no se guarda.
     *
     * @param Client                        $client Cliente dueño del consumo.
     * @param array<int, array<string, mixed>> $dias Bloque `dias` del payload del cliente.
     *
     * @return int Cantidad de filas escritas o actualizadas.
     */
    protected function espejar(Client $client, array $dias)
    {
        $escritas = 0;

        DB::transaction(function () use ($client, $dias, &$escritas) {
            foreach ($dias as $fila) {
                if (! is_array($fila)) {
                    continue;
                }

                $fecha = trim((string) (isset($fila['fecha']) ? $fila['fecha'] : ''));

                // Una fila sin fecha no se puede ubicar en el tiempo: se descarta en vez de
                // inventarle un día. El resto del rango se guarda igual.
                if ($fecha === '') {
                    continue;
                }

                /* `proceso` y `modelo` nunca viajan como null hacia la base: en MySQL un NULL no
                 * colisiona con otro NULL dentro de un índice único, y eso solo alcanzaría para
                 * que el upsert deje de ser idempotente sin que nada avise. */
                $clave = [
                    'client_id' => (int) $client->id,
                    'fecha'     => substr($fecha, 0, 10),
                    'proceso'   => (string) (isset($fila['proceso']) ? $fila['proceso'] : ''),
                    'modelo'    => (string) (isset($fila['modelo']) ? $fila['modelo'] : ''),
                ];

                ClientAiTokenUsage::updateOrCreate($clave, [
                    'proveedor'                   => (string) (isset($fila['proveedor']) ? $fila['proveedor'] : 'anthropic'),
                    'llamadas'                    => (int) (isset($fila['llamadas']) ? $fila['llamadas'] : 0),
                    'input_tokens'                => (int) (isset($fila['input_tokens']) ? $fila['input_tokens'] : 0),
                    'output_tokens'               => (int) (isset($fila['output_tokens']) ? $fila['output_tokens'] : 0),
                    'cache_creation_input_tokens' => (int) (isset($fila['cache_creation_input_tokens']) ? $fila['cache_creation_input_tokens'] : 0),
                    'cache_read_input_tokens'     => (int) (isset($fila['cache_read_input_tokens']) ? $fila['cache_read_input_tokens'] : 0),
                ]);

                $escritas++;
            }
        });

        return $escritas;
    }

    /**
     * Persiste el desenlace en el cliente y lo devuelve.
     *
     * `ai_tokens_synced_at` SOLO se toca cuando hubo éxito: un fallo posterior no puede borrar la
     * información de hasta cuándo el dato es confiable.
     *
     * @param Client      $client  Cliente a marcar.
     * @param string      $estado  success | no_soportado | failed.
     * @param string|null $mensaje Motivo, cuando no es success.
     * @param bool        $exitoso Si además hay que estampar `ai_tokens_synced_at`.
     * @param int         $filas   Filas escritas, para que el llamador lo muestre.
     *
     * @return array{estado: string, mensaje: string|null, filas: int, sincronizado_at: string|null}
     */
    protected function registrar(Client $client, $estado, $mensaje = null, $exitoso = false, $filas = 0)
    {
        $cambios = [
            'ai_tokens_sync_status'  => $estado,
            'ai_tokens_sync_message' => $mensaje,
        ];

        if ($exitoso) {
            $cambios['ai_tokens_synced_at'] = Carbon::now();
        }

        $client->update($cambios);

        return [
            'estado'          => $estado,
            'mensaje'         => $mensaje,
            'filas'           => (int) $filas,
            'sincronizado_at' => $client->ai_tokens_synced_at === null
                ? null
                : (string) $client->ai_tokens_synced_at,
        ];
    }
}
