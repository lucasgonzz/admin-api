<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pide a Mercado Pago la preferencia de cobro de una cotización y devuelve el link de pago.
 *
 * Es la única pieza de esta misión que sale a la red. Habla contra
 * `POST {base_url}/checkout/preferences` por el `Http` de Laravel (y no por el SDK) por dos
 * motivos medidos antes en este ecosistema:
 *
 * - `Entity::save()` del SDK **no tira ante un 4xx**: la preferencia queda sin crear y el código
 *   sigue como si nada (informe `20260905-mercado-pago-cobro-demo.md`). Acá se mira el código de
 *   respuesta a mano y, si no es 2xx, se propaga el mensaje que devolvió Mercado Pago.
 * - El `Http` de Laravel es fakeable, así que los tests de esta misión corren sin red.
 *
 * 🔴 EL ACCESS TOKEN
 * ------------------
 * Viaja SIEMPRE en el header `Authorization` y jamás por query string: Guzzle copia la URI
 * completa adentro del mensaje de sus excepciones de transporte, así que un token en la URL
 * termina escrito en el laravel.log el día que Mercado Pago no conteste. Tampoco se loguea, ni
 * entero ni parcial ni un prefijo, ni vuelve en ninguna respuesta de la API: lo único que sale
 * hacia el front es el booleano `mercado_pago_configurado` de CotizadorSettings.
 *
 * 🔴 SIN `back_urls` NI `auto_return`
 * -----------------------------------
 * No hay una URL pública del admin a la que mandar al lead después de pagar, y `auto_return` sin
 * `back_urls.success` hace que Mercado Pago **rechace la preferencia entera** (medido el
 * 5/9/2026). Se omiten los dos: el lead paga y queda en la pantalla de Mercado Pago, que es lo
 * correcto para un link de cobro que se manda por WhatsApp.
 *
 * 🔴 NO HAY WEBHOOK. El admin no se entera solo de que el lead pagó — hay que mirarlo en Mercado
 * Pago, buscando por el `external_reference` o por el `preference_id` que queda guardado en el
 * lead. Construir el webhook es otra misión: hace falta una ruta pública en el admin y decidir qué
 * hace el sistema cuando un lead paga.
 */
class MercadoPagoLinkService
{
    /**
     * Motivo exacto que se le muestra al usuario cuando no hay credencial cargada.
     *
     * Es una constante y no un string suelto para que el controller, el test y esta clase digan
     * exactamente lo mismo: el texto nombra la variable del `.env` justamente para que quien lo
     * lea sepa qué tiene que cargar, en vez de comerse un "algo salió mal".
     */
    public const MOTIVO_SIN_CREDENCIAL = 'Falta configurar la credencial de Mercado Pago (MP_ADMIN_ACCESS_TOKEN) en el .env del admin.';

    /**
     * Crea la preferencia de cobro y devuelve su id y su link.
     *
     * Un item por sistema cotizado y no un total ciego: en el checkout el lead lee
     * "ComercioCity Gestión — $X", "ComercioCity E-Commerce — $Y" y entiende qué está pagando.
     * Cuesta lo mismo que mandar un item único.
     *
     * @param int                              $lead_id      Id del lead que se está cotizando.
     * @param array<int, array<string, mixed>> $items        Detalle de CotizadorLeadService: [{key, label, precio_usd, precio_ars}].
     * @param int                              $cuotas       Cuotas sin interés a ofrecer.
     * @param Carbon                           $vence_at     Instante en que el link deja de cobrar.
     *
     * @throws \RuntimeException Si no hay credencial, si Mercado Pago rechaza la preferencia o si
     *                           no se pudo llegar al servicio.
     *
     * @return array{preference_id: string, link_pago: string} Id de la preferencia e `init_point`.
     */
    public function crear_preferencia(int $lead_id, array $items, int $cuotas, Carbon $vence_at): array
    {
        $token = trim((string) config('services.mercadopago.admin_access_token', ''));
        if ($token === '') {
            throw new \RuntimeException(self::MOTIVO_SIN_CREDENCIAL);
        }

        $base_url = rtrim((string) config('services.mercadopago.base_url', 'https://api.mercadopago.com'), '/');
        $timeout  = (int) config('services.mercadopago.timeout', 20);

        $payload = [
            'items'              => $this->armar_items($items),
            'payment_methods'    => [
                // Las tres cuotas van fijadas en los dos campos a propósito: `installments` es el
                // TECHO de cuotas que Mercado Pago ofrece y `default_installments` es la opción
                // que aparece elegida al abrir el checkout. Con solo el techo, el lead entra
                // viendo "1 cuota" y tiene que ir a buscar las tres.
                'installments'         => $cuotas,
                'default_installments' => $cuotas,
            ],
            // Con qué lead se ata este cobro. Es lo único que permite reconocerlo en el panel de
            // Mercado Pago mientras no haya webhook.
            'external_reference' => 'lead-'.$lead_id,
            'metadata'           => [
                'lead_id' => $lead_id,
            ],
            // El link vence: una cotización hecha con el dólar de hoy no puede seguir cobrando
            // dentro de dos meses.
            'expires'            => true,
            // Formato con milisegundos y offset, que es el que documenta Mercado Pago
            // ("2020-01-01T00:00:00.000-03:00"). Deliberadamente distinto del `vence_at` que la
            // API le devuelve al front (ISO 8601 sin milisegundos): uno es el contrato del
            // proveedor y el otro el nuestro, y mezclarlos es pedirle a Mercado Pago que rechace
            // la preferencia por un formato.
            'expiration_date_to' => $vence_at->format('Y-m-d\TH:i:s.vP'),
        ];

        $http = Http::timeout($timeout)->withHeaders([
            // 🔴 El token, acá y en ningún otro lado. Nunca en la URL.
            'Authorization' => 'Bearer '.$token,
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'ComercioCity-Admin-API',
        ]);

        // TLS, mismo criterio que el resto de las integraciones salientes del repo: en WAMP hay
        // que apuntar el CA bundle a mano o el handshake falla con "unable to get local issuer
        // certificate". En producción `verify_ssl` es true y no se toca — por el header
        // Authorization viaja el token.
        $verify_ssl = (bool) config('services.mercadopago.verify_ssl', true);
        $ca_bundle  = config('services.mercadopago.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        try {
            $response = $http->post($base_url.'/checkout/preferences', $payload);
        } catch (ConnectionException $e) {
            // Falla de transporte (Mercado Pago caído, DNS, timeout, TLS). Se traduce a un motivo
            // legible en vez de dejar salir un 500: quien está cotizando tiene que poder leer qué
            // pasó y reintentar. El mensaje de Guzzle trae la URI, que no tiene ningún secreto
            // adentro justamente porque el token va por header.
            //
            // 🔴 Se captura ConnectionException y NO \Throwable a propósito. Con \Throwable, un
            // error de programación acá adentro —un método que no existe, un tipo mal pasado—
            // salía como "No se pudo contactar a Mercado Pago" y un 422 que nadie mira, en vez de
            // un 500 que alguien tiene que arreglar. Un bug disfrazado de problema de red es un
            // bug que vive para siempre.
            throw new \RuntimeException('No se pudo contactar a Mercado Pago: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $motivo = $this->motivo_del_rechazo($response->status(), $response->json(), $response->body());

            // 🔴 Lo que se loguea es el código HTTP y el motivo que devolvió Mercado Pago. NUNCA
            // el payload ni los headers: ahí adentro va el access token.
            Log::error('MercadoPagoLinkService: Mercado Pago rechazó la preferencia', [
                'lead_id' => $lead_id,
                'status'  => $response->status(),
                'motivo'  => $motivo,
            ]);

            throw new \RuntimeException($motivo);
        }

        $body = $response->json();
        if (! is_array($body) || empty($body['id']) || empty($body['init_point'])) {
            throw new \RuntimeException('Mercado Pago respondió sin id de preferencia ni link de pago.');
        }

        return [
            'preference_id' => (string) $body['id'],
            'link_pago'     => (string) $body['init_point'],
        ];
    }

    /**
     * Traduce el detalle de la cotización al array de items de la preferencia.
     *
     * `unit_price` va en PESOS y no en dólares: la preferencia es en ARS (`currency_id`), que es
     * la moneda con la que el lead efectivamente paga. El precio en dólares queda guardado en el
     * lead para poder auditar con qué números se cotizó.
     *
     * @param array<int, array<string, mixed>> $items Detalle de CotizadorLeadService.
     *
     * @return array<int, array<string, mixed>>
     */
    private function armar_items(array $items): array
    {
        $resultado = [];

        foreach ($items as $item) {
            $resultado[] = [
                'id'          => (string) $item['key'],
                // La etiqueta sale del catálogo de CotizadorSettings, no del request: es lo que el
                // lead lee en el checkout.
                'title'       => (string) $item['label'],
                'quantity'    => 1,
                'currency_id' => 'ARS',
                'unit_price'  => (float) $item['precio_ars'],
            ];
        }

        return $resultado;
    }

    /**
     * Arma el motivo legible de un rechazo de Mercado Pago.
     *
     * Propaga el texto que devolvió Mercado Pago en vez de un "algo salió mal": el 99% de los
     * rechazos de una preferencia son un campo del payload que no le gustó, y ese texto dice cuál.
     *
     * @param int    $status Código HTTP de la respuesta.
     * @param mixed  $json   Cuerpo decodificado, si era JSON.
     * @param string $body   Cuerpo crudo, para cuando no lo era.
     *
     * @return string
     */
    private function motivo_del_rechazo(int $status, $json, string $body): string
    {
        if (is_array($json)) {
            // Mercado Pago contesta el motivo en `message`, y a veces agrega el detalle campo por
            // campo en `cause`.
            if (! empty($json['message'])) {
                return 'Mercado Pago rechazó la cotización ('.$status.'): '.((string) $json['message']);
            }

            if (! empty($json['error'])) {
                return 'Mercado Pago rechazó la cotización ('.$status.'): '.((string) $json['error']);
            }
        }

        $crudo = trim($body);
        if ($crudo !== '') {
            return 'Mercado Pago rechazó la cotización ('.$status.'): '.$crudo;
        }

        return 'Mercado Pago rechazó la cotización con código '.$status.' y sin dar un motivo.';
    }
}
