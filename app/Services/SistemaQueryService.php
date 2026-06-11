<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\WhatsappConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Canal "sistema:" de WhatsApp de soporte.
 *
 * Cuando un cliente activo (o un empleado autorizado) escribe "sistema: ..." al WhatsApp
 * de soporte, este servicio:
 *  1. Consulta el empresa-api del cliente (endpoint admin-sync/sistema-query) con datos reales.
 *  2. Le pide a Claude que redacte una respuesta natural en base a esos datos.
 *  3. Responde directamente por WhatsApp, sin crear ticket de soporte.
 */
class SistemaQueryService
{
    /**
     * Prefijo que activa el canal sistema.
     *
     * @var string
     */
    protected const PREFIX = 'sistema:';

    /**
     * Ruta relativa del endpoint de consulta en empresa-api (admin-sync).
     *
     * @var string
     */
    protected const SISTEMA_QUERY_PATH = 'api/admin-sync/sistema-query';

    /**
     * Resolver de la URL base del empresa-api del cliente.
     *
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * Servicio de envío de mensajes WhatsApp.
     *
     * @var WhatsappSendService
     */
    protected $send_service;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver
     * @param WhatsappSendService|null         $send_service
     */
    public function __construct(
        ?ClientEmpresaApiUrlResolver $api_url_resolver = null,
        ?WhatsappSendService $send_service = null
    ) {
        $this->api_url_resolver = $api_url_resolver ?? new ClientEmpresaApiUrlResolver();
        $this->send_service = $send_service ?? new WhatsappSendService();
    }

    /**
     * Indica si el cuerpo de un mensaje pertenece al canal "sistema:".
     *
     * @param  string  $body
     * @return bool
     */
    public static function is_sistema_query(string $body): bool
    {
        return str_starts_with(strtolower(trim($body)), self::PREFIX);
    }

    /**
     * Indica si el remitente puede usar el canal sistema.
     *
     * El dueño (sin client_employee) siempre puede. El empleado solo si tiene
     * can_query_system = true.
     *
     * @param  Client               $client
     * @param  ClientEmployee|null  $client_employee
     * @return bool
     */
    public static function client_employee_can_query(Client $client, ?ClientEmployee $client_employee): bool
    {
        // El dueño (sin client_employee) siempre puede.
        if ($client_employee === null) {
            return true;
        }

        // El empleado solo si tiene el permiso explícito activado por el admin.
        return (bool) $client_employee->can_query_system;
    }

    /**
     * Procesa una consulta del canal sistema y responde por WhatsApp.
     *
     * @param  string               $body            Cuerpo completo del mensaje (incluye el prefijo "sistema:").
     * @param  Client               $client          Cliente activo dueño de la conversación.
     * @param  ClientEmployee|null  $client_employee Empleado remitente (o null si es el dueño).
     * @param  WhatsappConfig       $config          Config WhatsApp activa para responder.
     * @param  string               $from            Número del remitente (E.164) para enviar la respuesta.
     * @return void
     */
    public function handle(
        string $body,
        Client $client,
        ?ClientEmployee $client_employee,
        WhatsappConfig $config,
        string $from
    ): void {
        // 1) Consulta limpia, sin el prefijo "sistema:".
        $query = trim(substr($body, strlen(self::PREFIX)));

        // 2) Consulta vacía: pedir al cliente que aclare qué quiere.
        if ($query === '') {
            $this->send_service->send_text(
                $from,
                '¿Qué querés consultar? Podés preguntarme sobre stock, ventas o clientes.'
            );

            return;
        }

        // 3) Consultar el empresa-api del cliente para obtener datos reales.
        $data = $this->fetch_system_data($client, $query);

        // Si la consulta al empresa-api falló (null), ya se notificó el error; cortar acá.
        if ($data === null) {
            $this->send_service->send_text(
                $from,
                'Hubo un problema al consultar el sistema. Intentá de nuevo en unos minutos.'
            );

            return;
        }

        // 4) Pedir a Claude que redacte la respuesta en base a los datos.
        $answer = $this->ask_claude($query, $data);

        if ($answer === '') {
            $this->send_service->send_text(
                $from,
                'No pude generar una respuesta en este momento. Intentá de nuevo en unos minutos.'
            );

            return;
        }

        // 5) Responder por WhatsApp.
        $this->send_service->send_text($from, $answer);

        Log::channel('daily')->info('SistemaQueryService: consulta respondida.', [
            'client_id'          => $client->id,
            'client_employee_id' => $client_employee ? $client_employee->id : null,
            'from'               => $from,
            'query'              => $query,
        ]);
    }

    /**
     * Consulta el empresa-api del cliente y devuelve los datos crudos.
     *
     * @param  Client  $client  Cliente con api_key y URL de empresa-api configuradas.
     * @param  string  $query   Texto de la consulta del cliente.
     * @return array<string, mixed>|null  Datos { data, query_type } o null si hubo error.
     */
    protected function fetch_system_data(Client $client, string $query): ?array
    {
        // URL absoluta hacia el endpoint sistema-query del empresa-api del cliente.
        $url = $this->api_url_resolver->admin_sync_url($client, self::SISTEMA_QUERY_PATH);
        if ($url === '') {
            Log::channel('daily')->warning('SistemaQueryService: sin URL válida de empresa-api.', [
                'client_id' => $client->id,
            ]);

            return null;
        }

        // api_key del cliente = ADMIN_API_INBOUND_KEY del empresa-api (header X-Admin-Api-Key).
        if (empty($client->api_key)) {
            Log::channel('daily')->warning('SistemaQueryService: cliente sin api_key configurada.', [
                'client_id' => $client->id,
            ]);

            return null;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout(15)
                ->post($url, [
                    'query' => $query,
                ]);

            if (! $response->successful()) {
                Log::channel('daily')->error('SistemaQueryService: error HTTP del empresa-api.', [
                    'client_id' => $client->id,
                    'status'    => $response->status(),
                    'body'      => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return null;
            }

            return $payload;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SistemaQueryService: excepción al consultar empresa-api.', [
                'client_id' => $client->id,
                'error'     => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Pide a Claude (Anthropic) que redacte la respuesta en base a los datos del sistema.
     *
     * Sigue el patrón HTTP de SupportAiSuggestionService (x-api-key + anthropic-version).
     *
     * @param  string                $query  Consulta original del cliente.
     * @param  array<string, mixed>  $data   Datos devueltos por el empresa-api.
     * @return string  Texto de la respuesta, o cadena vacía si falló.
     */
    protected function ask_claude(string $query, array $data): string
    {
        $api_key = (string) config('services.anthropic.api_key');
        if ($api_key === '') {
            Log::channel('daily')->warning('SistemaQueryService: ANTHROPIC_API_KEY no configurada.');

            return '';
        }

        // System prompt: tono natural de WhatsApp, sin markdown, parte del equipo del negocio.
        $system_prompt = <<<SYSTEM
Sos el asistente del sistema ERP de este negocio. El dueño o un empleado autorizado te hizo una consulta sobre su propio sistema.
Respondé en español rioplatense, de forma natural y directa, como si fueras parte del equipo.
Sin markdown, sin asteriscos, sin listas con guiones. Texto plano, como un mensaje de WhatsApp.
Sé conciso pero completo. Si hay varios resultados, listá los más relevantes de forma natural.
Si no hay datos, decilo claramente y de forma amigable.
SYSTEM;

        // Mensaje user: la consulta + los datos crudos del sistema en JSON legible.
        $data_json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user_message = "Consulta: {$query}\n\nDatos del sistema:\n{$data_json}";

        // Modelo y parámetros (mismo default que el resto de admin-api).
        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');

        try {
            $response = $this->build_anthropic_http_client($api_key)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 600,
                    'system'     => $system_prompt,
                    'messages'   => [
                        ['role' => 'user', 'content' => $user_message],
                    ],
                ]);

            if ($response->failed()) {
                Log::channel('daily')->error('SistemaQueryService: error Anthropic.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);

                return '';
            }

            return $this->extract_response_text($response->json());
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('SistemaQueryService: excepción al llamar a Anthropic.', [
                'error' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Cliente HTTP hacia Anthropic con la misma configuración TLS que el resto de admin-api.
     *
     * @param  string  $api_key
     * @return PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key): PendingRequest
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(120);

        // Misma configuración TLS que SupportAiSuggestionService (WAMP/Windows suele requerir ca_bundle).
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Extrae el texto de los bloques text de la respuesta de Anthropic.
     *
     * @param  array<string, mixed>  $body  Respuesta JSON de Anthropic.
     * @return string
     */
    protected function extract_response_text(array $body): string
    {
        $text = '';

        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }

        return trim($text);
    }
}
