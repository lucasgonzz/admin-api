<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Las tres degradaciones del canal, que son la parte que más importa de todo esto.
 *
 * El motivo es que **el camino feliz es el caso raro durante semanas**. Las cuatro rutas
 * `api/admin-sync/asistente/*` son nuevas y los 40+ clientes corren versiones distintas de
 * `master`: la mayoría va a contestar **404** hasta que alguien los actualice uno por uno. Un canal
 * que solo funciona bien cuando todo está bien, acá, no funciona nunca.
 *
 * Las tres se comportan distinto a propósito, y la diferencia no es cosmética:
 *
 *   404          → no es una falla, es la versión vieja. Texto honesto, aviso acotado, CERO reintentos.
 *   401 / 403    → falta una configuración. Se cierra ya: esperar no carga una api_key.
 *   timeout/5xx  → puede ser pasajero. Reintento acotado y recién después la disculpa.
 */
class DegradacionesDelCanalTest extends BaseDelCanal
{
    /**
     * Deja la fila entrante como la deja el webhook.
     *
     * @param Client $client Cliente dueño del hilo.
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(Client $client): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Cuánto vendí ayer?';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * 404: el cliente todavía no tiene el endpoint. Texto honesto y nada de reintentos.
     *
     * @return void
     */
    public function test_el_404_degrada_con_un_texto_honesto_y_no_reintenta(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr_job($job, $espia);

        $fila->refresh();

        $this->assertSame(
            ClientAssistantMessage::ESTADO_DEGRADADO,
            $fila->estado,
            'El 404 tiene estado propio: no es un error del sistema, es la versión vieja.'
        );

        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_SIN_ENDPOINT, $espia->textos[0]['body']);

        /* Un segundo ingreso no vuelve a pegarle a nadie: la fila ya está en un estado final. */
        $this->correr_job($job, $espia);
        $this->assertCount(1, $espia->textos, 'El 404 no se reintenta: la ruta no va a aparecer esperando.');
    }

    /**
     * El texto del 404 le dice al dueño qué pasa y por dónde seguir, sin tecnicismos.
     *
     * Es la diferencia entre un dueño que entiende y espera la actualización, y uno que reintenta
     * cinco veces contra algo que no va a andar.
     *
     * @return void
     */
    public function test_el_texto_del_404_explica_y_ofrece_salida(): void
    {
        $texto = AsistenteWhatsappService::TEXTO_SIN_ENDPOINT;

        $this->assertStringContainsString('actualización', $texto);
        $this->assertStringContainsString('desde el sistema', $texto);
        $this->assertStringNotContainsString('404', $texto);
        $this->assertStringNotContainsString('endpoint', $texto);
    }

    /**
     * El aviso del 404 sale una vez por cliente y por día, no una vez por mensaje.
     *
     * 🔴 Con el canal prendido sobre un cliente sin actualizar, CADA mensaje de ese dueño da 404. Un
     * dueño conversador genera cincuenta avisos en una tarde, y cincuenta avisos son cero avisos.
     *
     * @return void
     */
    public function test_el_aviso_del_404_sale_una_vez_por_cliente_y_por_dia(): void
    {
        $client = $this->crear_cliente();
        Cache::flush();

        $asistente = app(AsistenteWhatsappService::class);

        $this->assertTrue($asistente->avisar_cliente_sin_endpoint($client), 'El primero del día tiene que avisar.');
        $this->assertFalse($asistente->avisar_cliente_sin_endpoint($client), 'El segundo del mismo día, no.');
        $this->assertFalse($asistente->avisar_cliente_sin_endpoint($client));

        /* Otro cliente tiene su propio cupo: el aviso sirve para acordarse de actualizar a ESE. */
        $otro = $this->crear_cliente('+5493419999999');
        $this->assertTrue($asistente->avisar_cliente_sin_endpoint($otro));
    }

    /**
     * 401: la clave no coincide. Se cierra con la disculpa y el motivo queda escrito.
     *
     * @return void
     */
    public function test_el_401_cierra_con_disculpa_y_deja_el_motivo(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('api_key', (string) $fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * 403: al dueño le falta la extensión del asistente. Mismo tratamiento, motivo distinto.
     *
     * @return void
     */
    public function test_el_403_dice_que_falta_la_extension(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http(['*' => Http::response(['message' => 'Forbidden'], 403)]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('asistente_ia', (string) $fila->error);
    }

    /**
     * Sin `api_key` no se manda nada, ni siquiera a ver qué pasa.
     *
     * 🔴 Este canal escribe plata y stock del otro lado. Pegarle sin clave solo convierte un problema
     * de configuración en un 401 que después hay que ir a interpretar.
     *
     * @return void
     */
    public function test_sin_api_key_no_se_sale_a_la_red(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+5493411234567', true, '');
        $fila   = $this->fila_entrante($client);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();

        Http::assertNothingSent();
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('api_key', (string) $fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * 5xx: se reintenta un rato y recién cuando se acaba el presupuesto sale la disculpa.
     *
     * Se corre el job tantas veces como esperas tiene el presupuesto de 180 s, más una: hasta la
     * anteúltima no puede haber salido ningún texto, y en la última tiene que salir la disculpa.
     *
     * @return void
     */
    public function test_el_5xx_se_reintenta_hasta_que_se_acaba_el_presupuesto(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);

        $esperas = count(EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING);

        for ($i = 0; $i < $esperas; $i++) {
            $this->correr_job($job, $espia);

            $fila->refresh();
            $this->assertSame(
                ClientAssistantMessage::ESTADO_RECIBIDO,
                $fila->estado,
                'Mientras queden reintentos, el mensaje sigue en curso (ingreso ' . ($i + 1) . ').'
            );
            $this->assertCount(0, $espia->textos, 'Todavía no hay que disculparse (ingreso ' . ($i + 1) . ').');
        }

        /* Se acabó el presupuesto. */
        $this->correr_job($job, $espia);

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('500', (string) $fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * El presupuesto de polling es de 180 segundos exactos.
     *
     * No es un número lindo: del otro lado el job del asistente tiene `timeout` 240 y un presupuesto
     * interno de 150 s. A los 180 o ya contestó, o falló y el estado lo dice, o se colgó de una
     * forma que esperar más no arregla — y una conversación de WhatsApp no se banca más que eso.
     *
     * @return void
     */
    public function test_el_presupuesto_de_polling_son_180_segundos(): void
    {
        $this->assertSame(180, array_sum(EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING));

        /* Y las esperas crecen: las primeras respuestas llegan casi de una y no tiene sentido hacer
         * esperar diez segundos a quien ya tiene la respuesta lista. */
        $esperas = EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING;
        $this->assertSame(3, $esperas[0]);
        $this->assertGreaterThan($esperas[0], $esperas[1]);
        $this->assertGreaterThan($esperas[1], $esperas[2]);
    }

    /**
     * El asistente que nunca contesta termina en disculpa, no en silencio.
     *
     * Es el peor final posible y el que hay que evitar: el dueño mandó un mensaje, no pasó nada, y
     * nadie se enteró de nada.
     *
     * @return void
     */
    public function test_el_asistente_que_nunca_contesta_termina_en_disculpa(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes'     => Http::response(['ai_conversation_id' => 5, 'ai_message_id' => 9], 202),
            '*/asistente/mensajes/9'   => Http::response(['estado' => 'pendiente', 'contenido' => null], 200),
        ]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);

        /* La ida más todas las consultas del presupuesto. */
        for ($i = 0; $i <= count(EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING); $i++) {
            $this->correr_job($job, $espia);
        }

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('180 segundos', (string) $fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * Si Meta rechaza la respuesta, la fila saliente queda igual con el motivo.
     *
     * Sin ella no habría ningún rastro de una respuesta que el asistente produjo y que el dueño
     * nunca vio, que es justo lo que después hay que poder ver.
     *
     * @return void
     */
    public function test_una_respuesta_que_meta_rechaza_deja_rastro(): void
    {
        $espia  = $this->espiar_sender(false);
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes'   => Http::response(['ai_conversation_id' => 5, 'ai_message_id' => 9], 202),
            '*/asistente/mensajes/9' => Http::response([
                'estado'             => 'listo',
                'contenido'          => 'Ayer vendiste $10.000.',
                'ai_conversation_id' => 5,
            ], 200),
        ]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr_job($job, $espia);
        $this->correr_job($job, $espia);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente, 'Una respuesta rechazada por Meta igual deja fila.');
        $this->assertNull($saliente->whatsapp_message_id);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $saliente->estado);
        $this->assertStringContainsString('Meta rechazó', (string) $saliente->error);
        $this->assertSame('Ayer vendiste $10.000.', $saliente->texto);
    }

    /**
     * Un 202 sin `ai_message_id` es un desacuerdo de contrato, no algo que se arregle esperando.
     *
     * @return void
     */
    public function test_una_respuesta_fuera_de_contrato_no_se_reintenta(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http(['*' => Http::response(['ok' => true], 200)]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('Respuesta inesperada', (string) $fila->error);
    }
}
