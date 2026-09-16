<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Http;

/**
 * El viaje completo de un mensaje: ida al `empresa-api`, polling y vuelta por WhatsApp.
 *
 * El job se corre DERECHO (`$job->handle(...)`) en vez de por la cola, y eso hace dos cosas que
 * importan. La primera es que `release()` no reencola nada cuando no hay job de cola detrás, así
 * que cada llamada a `handle()` es exactamente una consulta y la prueba controla cuántas hubo. La
 * segunda es que no hay tres minutos de espera real: el reloj no participa.
 *
 * Todo lo que sale a la red está fakeado: el `empresa-api` con `Http::fake()` y el envío a Meta con
 * el espía de `WhatsappSendService`.
 */
class ViajeDelMensajeTest extends BaseDelCanal
{
    /**
     * Deja la fila entrante como la deja el webhook.
     *
     * @param Client      $client Cliente dueño del hilo.
     * @param string      $texto  Lo que escribió el dueño.
     * @param int|null    $conversacion Conversación deducida de una cita, si hubo.
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(Client $client, string $texto = 'Cuánto vendí ayer?', ?int $conversacion = null): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE1';
        $fila->tipo                = 'text';
        $fila->texto               = $texto;
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->ai_conversation_id  = $conversacion;
        $fila->save();

        return $fila;
    }

    /**
     * Corre un ingreso del job sobre la misma instancia, para simular el polling.
     *
     * @param EnviarMensajeAlAsistenteJob $job
     * @param WhatsappSendService         $sender
     *
     * @return void
     */
    private function correr(EnviarMensajeAlAsistenteJob $job, WhatsappSendService $sender): void
    {
        $job->handle(
            app(AsistenteWhatsappService::class),
            app(ClientEmpresaApiUrlResolver::class),
            $sender
        );
    }

    /**
     * El camino feliz: 202, dos consultas, y la respuesta le llega al dueño.
     *
     * @return void
     */
    public function test_el_mensaje_va_se_pollea_y_la_respuesta_vuelve(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response([
                'ai_conversation_id' => 91,
                'ai_message_id'      => 305,
                'estado'             => 'pendiente',
            ], 202),
            '*/asistente/mensajes/305' => Http::sequence()
                ->push(['estado' => 'pendiente', 'contenido' => null, 'ai_conversation_id' => 91], 200)
                ->push(['estado' => 'listo', 'contenido' => 'Ayer vendiste $184.500 en 23 ventas.', 'ai_conversation_id' => 91], 200),
        ]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);

        /* Ingreso 1: la ida. */
        $this->correr($job, $espia);
        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);
        $this->assertSame(91, (int) $fila->ai_conversation_id);
        $this->assertSame(305, (int) $fila->ai_message_id);
        $this->assertCount(0, $espia->textos, 'Todavía no hay nada que mandarle al dueño.');

        /* Ingreso 2: sigue pendiente. */
        $this->correr($job, $espia);
        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);
        $this->assertCount(0, $espia->textos);

        /* Ingreso 3: llegó la respuesta. */
        $this->correr($job, $espia);
        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertSame('Ayer vendiste $184.500 en 23 ventas.', $espia->textos[0]['body']);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);
    }

    /**
     * La respuesta deja su fila saliente CON el wamid, que es lo que hace andar la cita siguiente.
     *
     * 🔴 Sin esta fila, citar la respuesta del asistente no lleva a ningún lado y el corte por
     * tiempo pasa a ser el único criterio. Es la mitad admin del mecanismo de conversaciones.
     *
     * @return void
     */
    public function test_la_respuesta_deja_la_fila_que_hace_posible_la_proxima_cita(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
            '*/asistente/mensajes/305' => Http::response([
                'estado'             => 'listo',
                'contenido'          => 'Listo.',
                'ai_conversation_id' => 91,
            ], 200),
        ]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr($job, $espia);
        $this->correr($job, $espia);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente, 'La respuesta tenía que dejar su propia fila.');
        $this->assertSame('wamid.saliente.1', $saliente->whatsapp_message_id);
        $this->assertSame(91, (int) $saliente->ai_conversation_id);

        $this->assertSame(
            91,
            ClientAssistantMessage::conversacion_por_cita((int) $client->id, 'wamid.saliente.1'),
            'Citar esa respuesta tiene que devolver la misma conversación.'
        );
    }

    /**
     * Una conversación deducida de una cita viaja en el POST; sin cita, no viaja nada.
     *
     * Es el reparto que fija el plan: el admin manda `ai_conversation_id` SOLO cuando lo dedujo de
     * un wamid citado. Si no lo manda, la conversación la elige el `empresa-api` con su corte por
     * tiempo, que es el dato que solo él tiene.
     *
     * @return void
     */
    public function test_la_conversacion_citada_viaja_en_el_post_y_la_no_citada_no(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 412, 'ai_message_id' => 900], 202),
        ]);

        $con_cita = $this->fila_entrante($client, 'Sí, dale', 412);
        $this->correr(new EnviarMensajeAlAsistenteJob((int) $con_cita->id), $espia);

        $cuerpo = $this->cuerpo_del_ultimo_post();
        $this->assertStringContainsString('ai_conversation_id', $cuerpo);
        $this->assertStringContainsString('412', $cuerpo);

        $sin_cita = $this->fila_entrante($client, 'Hola', null);
        $sin_cita->whatsapp_message_id = 'wamid.ENTRANTE2';
        $sin_cita->save();

        $this->correr(new EnviarMensajeAlAsistenteJob((int) $sin_cita->id), $espia);

        $this->assertStringNotContainsString(
            'ai_conversation_id',
            $this->cuerpo_del_ultimo_post(),
            'Sin cita, la conversación la tiene que elegir el empresa-api.'
        );
    }

    /**
     * El POST va con la clave del cliente en el header y a la URL con /public del shared hosting.
     *
     * 🔴 La regla de `/public` es la que `SupportClientSyncService` NO aplica (usa `client->api_url`
     * crudo), y por eso este canal usa `ClientEmpresaApiUrlResolver::admin_sync_url()`. En el shared
     * hosting el subdominio apunta a `<slug>/api` y hay que entrar por `/public`; sin eso son 404 en
     * todo, que del lado del admin se leería como "este cliente no tiene el endpoint" y le mandaría
     * al dueño el texto equivocado.
     *
     * @return void
     */
    public function test_el_post_lleva_la_clave_del_cliente_y_la_url_con_public(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_client_api($client, 'https://api-ferreteria-de-prueba.test', 'shared_hosting');
        $fila = $this->fila_entrante($client);

        $this->fakear_http([
            '*' => Http::response(['ai_conversation_id' => 1, 'ai_message_id' => 2], 202),
        ]);

        $this->correr(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-ferreteria-de-prueba.test/public/api/admin-sync/asistente/mensajes'
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente');
        });
    }

    /**
     * En el VPS la misma URL va SIN `/public`: ahí el docroot ya es `public/`.
     *
     * Es el otro lado de la misma regla, y agregarlo de más da `public/public` y 404 en todo.
     *
     * @return void
     */
    public function test_en_el_vps_la_url_va_sin_public(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_client_api($client, 'https://api-ferreteria-de-prueba.test', 'vps');
        $fila = $this->fila_entrante($client);

        $this->fakear_http([
            '*' => Http::response(['ai_conversation_id' => 1, 'ai_message_id' => 2], 202),
        ]);

        $this->correr(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-ferreteria-de-prueba.test/api/admin-sync/asistente/mensajes';
        });
    }

    /**
     * Si el asistente del cliente falla, el dueño recibe la disculpa y la fila queda en error.
     *
     * @return void
     */
    public function test_un_error_del_asistente_termina_en_disculpa(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
            '*/asistente/mensajes/305' => Http::response([
                'estado'        => 'error',
                'contenido'     => null,
                'error_mensaje' => 'Se acabó el presupuesto de herramientas.',
            ], 200),
        ]);

        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr($job, $espia);
        $this->correr($job, $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('presupuesto de herramientas', (string) $fila->error);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * Una fila ya resuelta no se vuelve a tramitar.
     *
     * Es la guarda contra un `release()` que llega tarde: sin ella, el dueño podría recibir dos
     * veces la misma respuesta, o una disculpa después de la respuesta buena.
     *
     * @return void
     */
    public function test_una_fila_ya_resuelta_no_se_vuelve_a_tramitar(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $fila->estado = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->save();

        $this->correr(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        Http::assertNothingSent();
        $this->assertCount(0, $espia->textos);
    }

    /**
     * Apagar el canal frena lo que ya estaba en vuelo, y lo frena en silencio.
     *
     * En silencio a propósito: apagar la casilla es una decisión deliberada, no una falla, y el
     * dueño no tiene por qué recibir una disculpa por algo que decidió el equipo.
     *
     * @return void
     */
    public function test_apagar_el_canal_frena_el_mensaje_en_vuelo(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $client->asistente_whatsapp_activo = false;
        $client->save();

        $this->correr(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertCount(0, $espia->textos, 'Apagar el canal no le manda ningún texto al dueño.');
        Http::assertNothingSent();
    }

    /**
     * Cuerpo crudo del último POST que salió, para inspeccionar el multipart.
     *
     * @return string
     */
    private function cuerpo_del_ultimo_post(): string
    {
        $cuerpo = '';

        Http::assertSent(function ($request) use (&$cuerpo) {
            if ($request->method() === 'POST') {
                $cuerpo = (string) $request->body();
            }

            return true;
        });

        return $cuerpo;
    }
}
