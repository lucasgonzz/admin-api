<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\WhatsappConfig;
use App\Services\AsistenteWhatsappService;
use Illuminate\Support\Facades\Http;

/**
 * Lo que el POST del mensaje le anuncia al `empresa-api` sobre el aviso por WhatsApp.
 *
 * Misión asistente-aviso-escaneo-factura (16/10/2026). El admin suma dos partes OPCIONALES al
 * multipart de `POST admin-sync/asistente/mensajes`:
 *
 *   - `avisos = 1`: este admin consulta `avisos-pendientes` cada minuto, así que el `empresa-api`
 *     puede prometerle al dueño "cuando termine de leer la factura te aviso por acá".
 *   - `spa_url`: la URL del SPA del cliente que el admin conoce (`client_apis.spa_url`), para que el
 *     link del aviso apunte a SU sistema.
 *
 * Y de paso se fija que la regla de "por qué número le contesto" siga siendo una sola: se mudó del
 * job a `AsistenteWhatsappService::proposito_de_salida()` y el job la sigue usando igual.
 */
class AnuncioDeAvisosEnElMensajeTest extends BaseDelCanal
{
    /**
     * Fila entrante como la deja el webhook.
     *
     * @param Client      $client          Cliente dueño del hilo.
     * @param string|null $phone_number_id Número por el que entró, o null (filas viejas).
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(Client $client, ?string $phone_number_id = null): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->phone_number_id     = $phone_number_id;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE-AVISOS';
        $fila->tipo                = 'image';
        $fila->texto               = 'Cargame esta factura';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * Las partes del último POST multipart que salió.
     *
     * @return array<int, array<string, mixed>>
     */
    private function partes_del_post(): array
    {
        $partes = [];

        Http::assertSent(function ($request) use (&$partes) {
            if ($request->method() === 'POST') {
                $partes = $request->data();
            }

            return true;
        });

        return $partes;
    }

    /**
     * El valor de una parte por nombre, o null si no viajó.
     *
     * @param array<int, array<string, mixed>> $partes
     * @param string                           $nombre
     *
     * @return string|null
     */
    private function parte(array $partes, string $nombre): ?string
    {
        foreach ($partes as $parte) {
            if (isset($parte['name']) && $parte['name'] === $nombre) {
                return (string) $parte['contents'];
            }
        }

        return null;
    }

    /**
     * Con el SPA del cliente cargado, viajan `avisos = 1` y su `spa_url` sin barra final.
     *
     * @return void
     */
    public function test_el_post_anuncia_los_avisos_y_la_url_del_spa(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $api    = $this->crear_client_api($client);
        $api->spa_url = 'https://ferreteria.comerciocity.com/';
        $api->save();

        $fila = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 70], 202),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $partes = $this->partes_del_post();

        $this->assertSame('1', $this->parte($partes, 'avisos'), 'El admin tiene que anunciar que consulta avisos-pendientes.');
        $this->assertSame('https://ferreteria.comerciocity.com', $this->parte($partes, 'spa_url'));

        /* Lo de siempre sigue viajando igual. */
        $this->assertSame('Cargame esta factura', $this->parte($partes, 'texto'));
        $this->assertSame('imagen', $this->parte($partes, 'tipo'));
        $this->assertSame('wamid.ENTRANTE-AVISOS', $this->parte($partes, 'whatsapp_message_id'));
    }

    /**
     * Sin SPA conocido, `spa_url` no viaja (nunca vacía) y `avisos` sí.
     *
     * Una `spa_url` vacía del otro lado sería un link roto; sin la parte, el `empresa-api` usa la
     * suya o ninguna.
     *
     * @return void
     */
    public function test_sin_spa_conocido_no_viaja_spa_url_pero_si_avisos(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 70], 202),
        ]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $partes = $this->partes_del_post();

        $this->assertSame('1', $this->parte($partes, 'avisos'));
        $this->assertNull($this->parte($partes, 'spa_url'), 'Sin SPA conocido no se manda una spa_url vacía.');
    }

    /**
     * La regla del número de salida, ya en su lugar compartido: entra por el asistente → sale por el
     * asistente; sin dato o por cualquier otro número → principal.
     *
     * @return void
     */
    public function test_la_regla_del_numero_de_salida_es_la_misma_de_siempre(): void
    {
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->crear_cliente();

        $this->assertSame(
            WhatsappConfig::PROPOSITO_ASISTENTE,
            AsistenteWhatsappService::proposito_de_salida($this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE))
        );
        $this->assertSame(
            WhatsappConfig::PROPOSITO_PRINCIPAL,
            AsistenteWhatsappService::proposito_de_salida($this->fila_entrante($client, self::PHONE_NUMBER_ID_PRINCIPAL))
        );
        $this->assertSame(
            WhatsappConfig::PROPOSITO_PRINCIPAL,
            AsistenteWhatsappService::proposito_de_salida($this->fila_entrante($client, null))
        );
        $this->assertSame(
            WhatsappConfig::PROPOSITO_PRINCIPAL,
            AsistenteWhatsappService::proposito_de_salida($this->fila_entrante($client, '999999'))
        );
    }
}
