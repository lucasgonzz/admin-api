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
     * Con lo que empieza el mensaje de `trigger_user_setup()` cuando se NEGÓ a llamar (un test que
     * apuntaba a un host real). El job del user setup lo reconoce para decir que el setup no corrió.
     */
    const PREFIJO_BLOQUEADO = 'Bloqueado: ';

    /**
     * Ejecuta el setup remoto del sistema del cliente vía empresa-api.
     *
     * Construye el payload a partir de client.setup_data y datos del cliente, lo envía
     * a `POST {client_api_url}/api/admin-sync/user-setup` y registra el resultado.
     * Cualquier error se loguea sin interrumpir el flujo de implementación.
     *
     * @param Implementation $implementation   Implementación que avanzó a la Etapa 3.
     * @param int|null       $timeout_segundos Techo de la llamada HTTP, en segundos (misión
     *                                         `implementar-cliente`, 5/10/2026). Opcional: sin él —que
     *                                         es como lo llama el panel— se usa
     *                                         `services.client_api.timeout`, exactamente como siempre.
     *                                         Ver resolver_timeout() para el porqué del parámetro.
     *
     * @return array{ok: bool, message: string} Resultado de la ejecución: ok según
     *     $response->successful(), message con el motivo del fallo o la confirmación de éxito.
     *     Los llamadores existentes que ignoran el retorno siguen funcionando sin cambios.
     */
    public function trigger_user_setup(Implementation $implementation, ?int $timeout_segundos = null): array
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
        //
        // 🔴 NORMALIZADA como en todos los demás llamados del admin al sistema de un cliente
        // (`ClientEmpresaApiUrlResolver`): en hosting compartido el docroot de la API es la raíz del proyecto y
        // la aplicación vive bajo `/public`, así que hace falta ese sufijo. Los clientes nuevos (los que crea
        // `PromoteLeadToClientService`, 19 de los 68 de shared al 5/10/2026) guardan `client_apis.url` SIN `/public`
        // y con la URL cruda este POST daba 404 —medido contra quino y doblep: `/api/version-activa` 404 y
        // `/public/api/version-activa` 200—; los viejos la guardan CON `/public`, y la normalización es idempotente.
        // En VPS no agrega nada. Siempre sobre la API ACTIVA: nunca cae a la otra, que es el otro frente.
        $client->loadMissing('active_client_api');
        $client_api  = $client->active_client_api;
        $client_api_url = $client_api !== null
            ? (new ClientEmpresaApiUrlResolver())->normalize_api_base_url($client_api->url, $client_api->hosting_type)
            : '';

        if ($client_api_url === '') {
            Log::channel('daily')->warning('ImplementationUserSetupService: cliente sin client_api_url; no se ejecuta UserSetup.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $client->id,
            ]);
            return ['ok' => false, 'message' => 'El cliente todavía no tiene una client_api activa configurada (con una URL http o https válida).'];
        }

        // Construir el payload completo a partir de los datos del cliente y setup_data.
        $payload = $this->build_payload($client);

        // Endpoint del setup remoto en empresa-api.
        $endpoint = rtrim($client_api_url, '/') . '/api/admin-sync/user-setup';

        // 🔴 Freno de seguridad (5/10/2026): este pedido hace `migrate:fresh` del otro lado. Un test que
        // llegó acá con la URL de un cliente real le vació la base de producción. Con APP_ENV=testing solo
        // se llama a un host .test, .localhost o de loopback; cualquier otro se niega ANTES de armar el
        // pedido, aunque haya un Http::fake() (un fake que cubre un host real es justo el error).
        if (! self::destino_permitido_en_este_entorno($endpoint)) {
            Log::channel('daily')->error('ImplementationUserSetupService: BLOQUEADO el user setup a un host real desde un test.', [
                'implementation_id' => $implementation->id,
                'client_id'         => $client->id,
                'host'              => (string) parse_url($endpoint, PHP_URL_HOST),
            ]);

            return [
                'ok'      => false,
                'message' => self::PREFIJO_BLOQUEADO . 'con APP_ENV=testing el user setup solo puede apuntar a un host .test, .localhost o de '
                    . 'loopback (el destino era «' . (string) parse_url($endpoint, PHP_URL_HOST) . '»). Un test no puede vaciar la base de un sistema real.',
            ];
        }

        try {
            $timeout = $this->resolver_timeout($timeout_segundos);

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
     * ¿Se puede llamar a esta URL desde el entorno en el que corre la aplicación?
     *
     * Fuera de `testing` siempre sí (es el comportamiento de siempre). Con `APP_ENV=testing`, solo si el
     * host es de pruebas: `localhost`, `127.0.0.1`, `::1`, o cualquier nombre terminado en `.test` o
     * `.localhost`. Los mismos que el resto de las herramientas de la casa consideran "desarrollo local".
     *
     * @param string $url URL a la que se va a llamar.
     *
     * @return bool
     */
    public static function destino_permitido_en_este_entorno(string $url): bool
    {
        if (! app()->environment('testing')) {
            return true;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        return substr($host, -5) === '.test' || substr($host, -10) === '.localhost';
    }

    /**
     * Techo de la llamada HTTP del setup remoto: el que pidió el llamador, o el de siempre.
     *
     * 🔴 POR QUÉ EXISTE EL PARÁMETRO (misión `implementar-cliente`, 5/10/2026). El techo de siempre
     * es `services.client_api.timeout`, y esa clave vale 15 s (`CLIENT_API_TIMEOUT`): el `60` del
     * segundo argumento de `config()` NUNCA aplica porque la clave existe. Del otro lado el setup
     * arranca con `migrate:fresh` y siembra todo, y tarda minutos (el demo setup, que hace lo mismo,
     * está medido en ~565 s). Con 15 s el botón del panel corta la espera con "Error de conexión con
     * la client_api" mientras el setup sigue corriendo del otro lado.
     *
     * El panel NO se toca: sigue llamando sin el parámetro y recibiendo exactamente el mismo número
     * que antes. El que lo pide es el job de `POST claude/implementations/{id}/user-setup`, que corre
     * en la cola `database` (donde esperar minutos no cuelga ningún request) y le da más aire.
     *
     * Un valor nulo, cero o negativo se ignora y cae al de siempre: un techo de 0 segundos en
     * Guzzle significa "sin límite", y eso es lo último que se quiere si el llamador se equivoca.
     *
     * @param int|null $timeout_segundos Techo pedido por el llamador, en segundos.
     *
     * @return int Segundos que se le pasan a `Http::timeout()`.
     */
    public function resolver_timeout(?int $timeout_segundos = null): int
    {
        if ($timeout_segundos !== null && $timeout_segundos > 0) {
            return $timeout_segundos;
        }

        return (int) config('services.client_api.timeout', 60);
    }

    /**
     * Construye el payload del UserSetup a partir del cliente y su setup_data.
     *
     * Público (antes privado) para que ImplementationActionService pueda mostrar en el
     * preview de la acción 'user_setup' el payload real que se va a enviar, sin duplicar
     * esta lógica de armado.
     *
     * 🔴 Devuelve la clave de Serper ENTERA, porque es lo que viaja. El preview la tapa antes de
     * mostrarla en el panel (ImplementationActionService::preview_user_setup()).
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

        // Email para el User del ERP: setup_data (formulario nuevo) y, si falta, el email del lead (demo).
        $payload_email = isset($payload['email']) ? trim((string) $payload['email']) : '';
        if ($payload_email === '') {
            $lead = \App\Models\Lead::where('promoted_client_id', $client->id)->first();
            if ($lead !== null) {
                $lead_email = trim((string) ($lead->email ?? ''));
                if ($lead_email !== '') {
                    $payload['email'] = $lead_email;
                }
            }
        }

        // doc_number: si no vino del formulario, dejarlo como string vacío explícito (el ERP lo toma como null).
        if (! isset($payload['doc_number'])) {
            $payload['doc_number'] = '';
        }

        // Los flags de dólar (costos_en_dolares, ventas_en_dolares, cotizar_precios_en_dolares),
        // las redes (facebook, instagram), price_lists_detail (nombre + margen por lista) y
        // payment_discounts (método + tipo + porcentaje) viajan por el spread de setup_data
        // y son consumidos por UserSetupHelper en empresa-api. Si setup_data no los trae
        // (cliente viejo), el ERP usa defaults.

        /* La clave de Serper (misión serper-en-user-setup, 28/9/2026): la de clientes, con el
         * mismo criterio que RunUserSetupService. Viaja solo si está cargada en el admin; si no,
         * empresa-api deja users.serper_api_key en null y el sistema usa la SERPER_API_KEY de su
         * .env. Este camino (la Etapa 3 de la implementación) es uno de los dos por donde nace un
         * cliente, así que sin esto un sistema nuevo podía nacer sin la clave.
         *
         * 🔴 Sale SOLO de la configuración del admin. setup_data se desparrama entero más arriba y
         * se mezcla sobre lo que el cliente ya tuviera, así que primero se descarta cualquier
         * serper_api_key que haya traído: una clave que nadie validó no viaja por acá.
         *
         * La API key de Google no viaja por este camino; es un comportamiento previo y no se tocó.
         *
         * Contrato aditivo: campo nuevo y opcional, un empresa-api anterior lo ignora. */
        unset($payload['serper_api_key']);

        /* 🔴 Los flags que le piden a empresa-api VACIAR una base que ya tiene datos (la guarda `base_con_datos`:
           `forzar_borrado_total` más `confirmar_base_de_datos` con el nombre de la base) NUNCA viajan desde acá. `setup_data`
           se desparrama entero más arriba, y es un JSON del cliente que un edit del admin puede completar con cualquier
           clave: una que coincida con esos nombres le saltearía la guarda al user setup. Forzar un borrado total se decide
           a mano, desde la raíz, mirando el sistema del cliente: no es un dato del formulario. */
        unset($payload['forzar_borrado_total'], $payload['confirmar_base_de_datos']);

        $serper_api_key = ImplementationSettings::get_serper_api_key_default();
        if ($serper_api_key !== '') {
            $payload['serper_api_key'] = $serper_api_key;
        }

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
