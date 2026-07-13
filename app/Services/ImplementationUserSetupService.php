<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Implementation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispara el UserSetup en empresa-api con los datos de configuración recolectados
 * durante la Etapa 1 de implementación.
 *
 * Se invoca al avanzar a la Etapa 3 (instalación del sistema) para que el sistema
 * del cliente quede configurado a medida desde el inicio (listas de precios,
 * depósitos, online_configurations con logo y redes, etc.).
 */
class ImplementationUserSetupService
{
    /**
     * Ejecuta el setup remoto del sistema del cliente vía empresa-api.
     *
     * Construye el payload a partir de client.setup_data y datos del cliente, lo envía
     * a `POST {client_api_url}/api/admin-sync/user-setup` y registra el resultado.
     * Cualquier error se loguea sin interrumpir el flujo de implementación.
     *
     * @param Implementation $implementation Implementación que avanzó a la Etapa 3.
     *
     * @return array{ok: bool, message: string} Resultado de la ejecución: ok según
     *     $response->successful(), message con el motivo del fallo o la confirmación de éxito.
     *     Los llamadores existentes que ignoran el retorno siguen funcionando sin cambios.
     */
    public function trigger_user_setup(Implementation $implementation): array
    {
        // Cliente dueño de la implementación.
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if ($client === null) {
            Log::channel('daily')->warning('ImplementationUserSetupService: cliente no encontrado; no se ejecuta UserSetup.', [
                'implementation_id' => $implementation->id,
            ]);
            return ['ok' => false, 'message' => 'No se encontró el cliente de la implementación.'];
        }

        // URL de la API del cliente (empresa-api desplegada): destino del setup remoto.
        $client->loadMissing('active_client_api');
        $client_api  = $client->active_client_api;
        $client_api_url = $client_api !== null ? trim((string) ($client_api->url ?? '')) : '';

        if ($client_api_url === '') {
            Log::channel('daily')->warning('ImplementationUserSetupService: cliente sin client_api_url; no se ejecuta UserSetup.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $client->id,
            ]);
            return ['ok' => false, 'message' => 'El cliente todavía no tiene una client_api activa configurada.'];
        }

        // Construir el payload completo a partir de los datos del cliente y setup_data.
        $payload = $this->build_payload($client);

        // Endpoint del setup remoto en empresa-api.
        $endpoint = rtrim($client_api_url, '/') . '/api/admin-sync/user-setup';

        try {
            $timeout = (int) config('services.client_api.timeout', 60);

            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payload);

            if ($response->successful()) {
                Log::channel('daily')->info('ImplementationUserSetupService: UserSetup ejecutado con éxito.', [
                    'implementation_id' => $implementation->id,
                    'client_id'         => $client->id,
                    'endpoint'          => $endpoint,
                    'status'            => $response->status(),
                ]);

                return ['ok' => true, 'message' => 'Configuración aplicada correctamente.'];
            }

            Log::channel('daily')->warning('ImplementationUserSetupService: UserSetup respondió con error.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $client->id,
                'endpoint'          => $endpoint,
                'status'            => $response->status(),
                'body'              => mb_substr((string) $response->body(), 0, 500),
            ]);

            return [
                'ok'      => false,
                'message' => 'La client_api respondió con error (status ' . $response->status() . '): '
                    . mb_substr((string) $response->body(), 0, 300),
            ];
        } catch (\Throwable $exception) {
            // No bloquear el flujo de implementación si el setup remoto falla.
            Log::channel('daily')->error('ImplementationUserSetupService: excepción al ejecutar UserSetup.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $client->id,
                'endpoint'          => $endpoint,
                'message'           => $exception->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Error de conexión con la client_api: ' . $exception->getMessage()];
        }
    }

    /**
     * Construye el payload del UserSetup a partir del cliente y su setup_data.
     *
     * Público (antes privado) para que ImplementationActionService pueda mostrar en el
     * preview de la acción 'user_setup' el payload real que se va a enviar, sin duplicar
     * esta lógica de armado.
     *
     * @param Client $client Cliente con setup_data poblado en la Etapa 1.
     *
     * @return array<string, mixed>
     */
    public function build_payload(Client $client): array
    {
        // Datos de configuración recolectados (cast 'array' en el modelo Client).
        $setup_data = is_array($client->setup_data) ? $client->setup_data : [];

        // Datos base del cliente requeridos por UserSetupHelper.
        $payload = [
            'user_id'      => $client->user_id,
            'company_name' => (string) ($client->company_name ?? ''),
            'name'         => (string) ($client->name ?? ''),
            'phone'        => (string) ($client->phone ?? ''),
        ];

        // Incorporar todos los campos de setup_data tal cual fueron recolectados.
        foreach ($setup_data as $key => $value) {
            $payload[$key] = $value;
        }

        // Convertir la cadena de listas de precios a price_type_1..3 (split por salto de línea o coma).
        $price_lists = (string) ($setup_data['price_lists'] ?? '');
        $price_types = $this->split_to_named_fields($price_lists, 'price_type_', 3);
        $payload     = array_merge($payload, $price_types);

        // Convertir la cadena de depósitos a address_1..3 (split por salto de línea o coma).
        $deposit_names = (string) ($setup_data['deposit_names'] ?? '');
        $addresses     = $this->split_to_named_fields($deposit_names, 'address_', 3);
        $payload       = array_merge($payload, $addresses);

        // Mapear cuenta corriente por defecto → omitir_cuentas_corrientes.
        // siempre_omitir_en_cuenta_corriente ya equivale a NOT default_cuenta_corriente.
        $payload['omitir_cuentas_corrientes'] = ($setup_data['siempre_omitir_en_cuenta_corriente'] ?? false) === true;

        // Mapear dollar_prices → cotizar_precios_en_dolares (ya presente en setup_data, se reafirma).
        $payload['cotizar_precios_en_dolares'] = ($setup_data['cotizar_precios_en_dolares'] ?? false) === true;

        return $payload;
    }

    /**
     * Divide una cadena multi-línea/CSV en campos numerados (ej: price_type_1, price_type_2…).
     *
     * @param string $value  Texto con valores separados por salto de línea o coma.
     * @param string $prefix Prefijo del nombre del campo (ej: 'price_type_').
     * @param int    $max    Cantidad máxima de campos a generar.
     *
     * @return array<string, string>
     */
    private function split_to_named_fields(string $value, string $prefix, int $max): array
    {
        $fields = [];

        // Separar por saltos de línea o comas y limpiar cada segmento.
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];

        $index = 1;
        foreach ($parts as $part) {
            $clean = trim((string) $part);

            if ($clean === '') {
                continue;
            }

            if ($index > $max) {
                break;
            }

            $fields[$prefix . $index] = $clean;
            $index++;
        }

        return $fields;
    }
}
