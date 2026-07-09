<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincronización OPCIONAL de mensualidad entre admin-api y el empresa-api de
 * cada cliente (prompt 335). Complementa el cálculo local y autónomo de
 * `ClientMensualidadService` (328/329): permite (a) traer los conteos vivos
 * del cliente (empleados, ecommerce, mercado libre, tienda nube) para
 * completar el formulario sin cargarlos a mano, y (b) empujar la fecha de
 * próximo pago (y los precios actuales) al sistema del cliente.
 *
 * Es explícitamente degradable: si la empresa-api del cliente es vieja (no
 * tiene los endpoints admin-sync/mensualidad-info|update — responde 404), o
 * el cliente no tiene `api_url`/`api_key` configurados, se devuelve
 * `soportado = false` con un mensaje claro en vez de un error genérico, para
 * que el front deshabilite los botones con un aviso sin romper el flujo
 * local de carga manual + facturación.
 */
class ClientMensualidadSyncService
{
    /**
     * Ruta relativa GET del snapshot vivo de mensualidad en empresa-api
     * (conteos + precios guardados + datos fiscales).
     */
    const MENSUALIDAD_INFO_PATH = 'api/admin-sync/mensualidad-info';

    /**
     * Ruta relativa PUT para actualizar la mensualidad en empresa-api
     * (fecha de pago + precios), reutilizando el mismo cálculo que la Blade.
     */
    const MENSUALIDAD_UPDATE_PATH = 'api/admin-sync/mensualidad-update';

    /**
     * @var ClientEmpresaApiUrlResolver Resuelve la URL base del empresa-api del cliente.
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para tests; por default resuelve uno nuevo.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver ?? new ClientEmpresaApiUrlResolver();
    }

    /**
     * Trae del empresa-api del cliente los conteos vivos (empleados,
     * ecommerce, mercado libre, tienda nube) y los datos fiscales cargados,
     * mapeados a los campos locales que usa el formulario de mensualidad.
     *
     * No persiste nada en admin: devuelve los valores para que el front los
     * muestre y Lucas los confirme con el botón "Guardar" habitual (o los
     * ajuste antes de guardar).
     *
     * @param  Client $client Cliente a consultar (necesita api_url/api_key configurados).
     * @return array{soportado: bool, error?: string, cantidad_empleados?: int, tiene_ecommerce?: bool, tiene_mercado_libre?: bool, tiene_tienda_nube?: bool, precio_plan?: float|null, precio_por_cuenta?: float|null, precio_ecommerce?: float|null, precio_mercado_libre?: float|null, precio_tienda_nube?: float|null, payment_expired_at?: string|null, afip_information?: array|null}
     */
    public function traer_del_cliente(Client $client): array
    {
        /** URL absoluta del endpoint de consulta, o vacía/con error si el cliente no está configurado. */
        $preparado = $this->preparar_llamada($client, self::MENSUALIDAD_INFO_PATH);
        if ($preparado['error'] !== null) {
            return $this->no_soportado($preparado['error']);
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->get($preparado['url']);

            // 404: la empresa-api del cliente todavía no tiene este endpoint (versión sin prompt 326).
            if ($response->status() === 404) {
                return $this->no_soportado('Este cliente todavía no soporta sincronización (versión antigua de empresa-api).');
            }

            if (! $response->successful()) {
                Log::warning(
                    'ClientMensualidadSyncService::traer_del_cliente status '
                    . $response->status() . ' body ' . $response->body()
                );

                return $this->no_soportado('No se pudo consultar la mensualidad del cliente (HTTP ' . $response->status() . ').');
            }

            /** Snapshot vivo devuelto por empresa-api (conteos, precios guardados y datos fiscales). */
            $data = $response->json();
            if (! is_array($data)) {
                $data = [];
            }

            /** Conteos vivos: empleados, ecommerce, mercado_libre, tienda_nube. */
            $conteos = is_array($data['conteos'] ?? null) ? $data['conteos'] : [];

            return [
                'soportado' => true,
                // Conteos vivos mapeados a los campos locales de mensualidad (cantidad_empleados y toggles).
                'cantidad_empleados'   => (int) ($conteos['empleados'] ?? 0),
                'tiene_ecommerce'      => (int) ($conteos['ecommerce'] ?? 0) > 0,
                'tiene_mercado_libre'  => (int) ($conteos['mercado_libre'] ?? 0) > 0,
                'tiene_tienda_nube'    => (int) ($conteos['tienda_nube'] ?? 0) > 0,
                // Precios ya guardados en el cliente, por si Lucas quiere ofrecerlos como referencia.
                'precio_plan'          => $data['precio_plan'] ?? null,
                'precio_por_cuenta'    => $data['precio_por_cuenta'] ?? null,
                'precio_ecommerce'     => $data['precio_ecommerce'] ?? null,
                'precio_mercado_libre' => $data['precio_mercado_libre'] ?? null,
                'precio_tienda_nube'   => $data['precio_tienda_nube'] ?? null,
                'payment_expired_at'   => $data['payment_expired_at'] ?? null,
                // Datos fiscales para pre-cargar los campos de facturación si están vacíos en admin.
                'afip_information'     => $data['afip_information'] ?? null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('ClientMensualidadSyncService::traer_del_cliente exception: ' . $exception->getMessage());

            return $this->no_soportado('Error al conectar con el empresa-api del cliente: ' . $exception->getMessage());
        }
    }

    /**
     * Empuja al empresa-api del cliente la fecha de próximo pago y los
     * precios actuales guardados en admin (`clients.*`), para que el cliente
     * no tenga que cargarlos a mano en su propio sistema. Devuelve el total
     * recalculado por el cliente cuando la operación es soportada.
     *
     * @param  Client $client Cliente a actualizar (necesita api_url/api_key y payment_expired_at cargados).
     * @return array{soportado: bool, error?: string, payment_expired_at?: string|null, total_mensualidad?: float|null}
     */
    public function actualizar_en_cliente(Client $client): array
    {
        /** URL absoluta del endpoint de actualización, o vacía/con error si el cliente no está configurado. */
        $preparado = $this->preparar_llamada($client, self::MENSUALIDAD_UPDATE_PATH);
        if ($preparado['error'] !== null) {
            return $this->no_soportado($preparado['error']);
        }

        // Sin fecha de pago cargada en admin no hay nada que empujar (evita mandar null al PUT del cliente).
        if (empty($client->payment_expired_at)) {
            return $this->no_soportado('Cargá primero la fecha de próximo pago en admin antes de sincronizarla.');
        }

        /** Payload esperado por empresa-api (misma validación que UserPaymentExpiredAtController::update). */
        $payload = [
            'payment_expired_at'   => substr((string) $client->payment_expired_at, 0, 10),
            'precio_plan'          => (float) ($client->precio_plan ?? 0),
            'precio_por_cuenta'    => (float) ($client->precio_por_cuenta ?? 0),
            'precio_ecommerce'     => $client->precio_ecommerce,
            'precio_mercado_libre' => $client->precio_mercado_libre,
            'precio_tienda_nube'   => $client->precio_tienda_nube,
        ];

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->put($preparado['url'], $payload);

            if ($response->status() === 404) {
                return $this->no_soportado('Este cliente todavía no soporta sincronización (versión antigua de empresa-api).');
            }

            if (! $response->successful()) {
                Log::warning(
                    'ClientMensualidadSyncService::actualizar_en_cliente status '
                    . $response->status() . ' body ' . $response->body()
                );

                return $this->no_soportado('No se pudo actualizar la mensualidad en el cliente (HTTP ' . $response->status() . ').');
            }

            /** Resultado devuelto por empresa-api tras recalcular y guardar. */
            $data = $response->json();
            if (! is_array($data)) {
                $data = [];
            }

            return [
                'soportado'          => true,
                'payment_expired_at' => $data['payment_expired_at'] ?? null,
                'total_mensualidad'  => $data['total_mensualidad'] ?? null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('ClientMensualidadSyncService::actualizar_en_cliente exception: ' . $exception->getMessage());

            return $this->no_soportado('Error al conectar con el empresa-api del cliente: ' . $exception->getMessage());
        }
    }

    /**
     * Valida que el cliente tenga URL resoluble y `api_key` configurada, y
     * arma la URL absoluta del endpoint pedido. Centraliza la validación
     * común a `traer_del_cliente` y `actualizar_en_cliente`.
     *
     * @param  Client $client Cliente a validar.
     * @param  string $path   Ruta relativa del endpoint (info o update).
     * @return array{url: string, error: string|null}
     */
    protected function preparar_llamada(Client $client, string $path): array
    {
        $sync_url = $this->api_url_resolver->admin_sync_url($client, $path);

        if ($sync_url === '') {
            return [
                'url'   => '',
                'error' => 'Este cliente no tiene una URL válida de empresa-api configurada (ClientApi activa o api_url legacy).',
            ];
        }

        if (empty($client->api_key)) {
            return [
                'url'   => '',
                'error' => 'El cliente no tiene api_key configurada (debe coincidir con ADMIN_API_INBOUND_KEY en empresa-api).',
            ];
        }

        return ['url' => $sync_url, 'error' => null];
    }

    /**
     * Arma la respuesta uniforme de "no soportado" (versión vieja del
     * cliente o configuración faltante), para que el front distinga este
     * caso de un error genérico y deshabilite los botones con aviso.
     *
     * @param  string $message Mensaje explicativo para el operador.
     * @return array{soportado: bool, error: string}
     */
    protected function no_soportado(string $message): array
    {
        return [
            'soportado' => false,
            'error'     => $message,
        ];
    }
}
