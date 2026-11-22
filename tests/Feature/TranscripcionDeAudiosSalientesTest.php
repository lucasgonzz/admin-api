<?php

namespace Tests\Feature;

use App\Events\LeadConversationUpdated;
use App\Events\SupportMessageReceived;
use App\Jobs\TranscribeOutboundWhatsappAudioJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use App\Services\SupportAiSuggestionService;
use App\Services\WhatsappOutboundAudioTranscriptionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Fakes\BytesDeAudioSinteticos;
use Tests\TestCase;

/**
 * Los audios que MANDA el equipo (leads y tickets de WhatsApp) quedan transcriptos igual que los entrantes.
 *
 * Hoy un audio saliente queda como `[Audio enviado]` (leads) o con el body vacío (soporte): el historial, el
 * monitor de soporte y la ficha del cliente no pueden entenderlo. La misión agrega un servicio que llama a
 * OpenAI y un job que corre fuera del request del envío y escribe el texto en `lead_messages.content` /
 * `support_messages.body`. Está APAGADO por defecto: sin interruptor y sin clave nada cambia.
 *
 * Se le pega a los endpoints reales (envío al lead y mensaje de soporte) con Kapso y OpenAI falseados con
 * `Http::fake`, el disco público falseado y la cola falseada: el job no corre solo, el test toma el que quedó
 * encolado y ejecuta su `handle()` a mano, que es exactamente lo que hará el worker.
 *
 * Criterios de aceptación que cubre: CA1 a CA6 de `.mision/PLAN.md`.
 */
class TranscripcionDeAudiosSalientesTest extends TestCase
{
    use DatabaseTransactions;
    use BytesDeAudioSinteticos;

    /** Lo que "dice" el audio según el OpenAI falso. */
    const TEXTO_TRANSCRIPTO = 'Hola Dany, te paso el precio del plan';

    /** Dirección del endpoint de transcripción de OpenAI. */
    const URL_OPENAI = 'https://api.openai.com/v1/audio/transcriptions';

    /** Marca que deja el envío a un lead mientras no hay transcripción. */
    const MARCA_AUDIO_LEAD = '[Audio enviado]';

    /**
     * Archivos temporales de las subidas de mentira, para borrarlos al terminar.
     *
     * @var array<int, string>
     */
    private $temporales_subidos = [];

    /**
     * Deja el entorno sin red real: disco, cola, eventos y configuración de OpenAI de mentira.
     *
     * Arranca con la transcripción PRENDIDA (interruptor y clave); los tests de apagado la bajan.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
        Event::fake([SupportMessageReceived::class, LeadConversationUpdated::class]);

        config([
            'services.openai_transcription.enabled' => true,
            'services.openai_transcription.api_key' => 'sk-test',
            'services.openai_transcription.model'   => 'gpt-4o-mini-transcribe',
        ]);
    }

    /**
     * Borra los temporales de la corrida antes de cerrar el test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->temporales_subidos as $temporal) {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
        $this->temporales_subidos = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------------

    /**
     * Admin operador que manda los audios desde el panel.
     *
     * @param string $email Email único.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Lead con teléfono: sin teléfono no se intenta ningún envío.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Dany';
        $lead->company_name = 'Empresa de Dany';
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        return $lead;
    }

    /**
     * Ticket de soporte abierto por el canal indicado, con la ventana de 24 h de WhatsApp abierta.
     *
     * @param string $source `whatsapp` o `erp`.
     *
     * @return SupportTicket
     */
    private function crear_ticket(string $source): SupportTicket
    {
        $telefono = '+549341' . random_int(1000000, 9999999);

        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = $telefono;
        $client->is_active = true;
        $client->save();

        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => $source,
            'whatsapp_phone'   => $source === 'whatsapp' ? $telefono : null,
            'opened_at'        => now()->subHours(2),
        ]);

        // El entrante reciente es lo que abre la ventana de 24 h: sin él el envío saldría por plantilla.
        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Hola, necesito ayuda',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Configuración de WhatsApp activa y fuera de test_mode, para que los envíos lleguen a Kapso (falseado).
     *
     * @return WhatsappConfig
     */
    private function crear_config_whatsapp(): WhatsappConfig
    {
        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-kapso-de-prueba';
        $config->phone_number_id = '123456789';
        $config->webhook_secret  = 'secreto-de-prueba';
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();

        return $config;
    }

    /**
     * Falsea Kapso (subida de media y envío con un wamid distinto por mensaje) y OpenAI.
     *
     * @param string $openai `ok`, `http_500`, `excepcion` o `texto_vacio`: cómo responde OpenAI.
     *
     * @return void
     */
    private function falsear_http(string $openai = 'ok'): void
    {
        $respuesta_openai = function () use ($openai) {
            if ($openai === 'http_500') {
                return Http::response(['error' => ['message' => 'boom']], 500);
            }
            if ($openai === 'excepcion') {
                throw new ConnectionException('Se cortó la conexión con OpenAI (simulado).');
            }
            if ($openai === 'texto_vacio') {
                return Http::response(['text' => '  '], 200);
            }

            return Http::response(['text' => self::TEXTO_TRANSCRIPTO], 200);
        };

        Http::fake([
            'https://api.openai.com/*'                                    => $respuesta_openai,
            'https://api.kapso.ai/meta/whatsapp/v24.0/123456789/media'    => Http::response(['id' => 'media-1'], 200),
            // Un wamid distinto por envío: la columna whatsapp_message_id es única.
            'https://api.kapso.ai/meta/whatsapp/v24.0/123456789/messages' => function () {
                return Http::response(['messages' => [['id' => 'wamid.salida.' . uniqid()]]], 200);
            },
            '*'                                                           => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Arma un archivo subido real (con bytes en disco) como el que manda el panel.
     *
     * @return UploadedFile
     */
    private function audio_subido(): UploadedFile
    {
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sal_' . uniqid('', true) . '.ogg';
        file_put_contents($ruta, $this->bytes_ogg_opus());
        $this->temporales_subidos[] = $ruta;

        // test=true: Laravel lo trata como una subida válida aunque no venga de un POST real.
        return new UploadedFile($ruta, 'nota.ogg', 'audio/ogg', null, true);
    }

    /**
     * El operador manda un audio a un lead por el endpoint real.
     *
     * @param Lead $lead Lead destino.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_audio_a_lead(Lead $lead)
    {
        $admin = $this->crear_admin('audio-lead-' . uniqid() . '@test.local');

        return $this->actingAs($admin, 'sanctum')->post('/api/admin/lead/' . $lead->id . '/send-direct-audio', [
            'audio' => $this->audio_subido(),
        ], ['Accept' => 'application/json']);
    }

    /**
     * El operador manda un audio al ticket por el endpoint real.
     *
     * @param SupportTicket $ticket Ticket destino.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_audio_a_ticket(SupportTicket $ticket)
    {
        $admin = $this->crear_admin('audio-ticket-' . uniqid() . '@test.local');

        return $this->actingAs($admin, 'sanctum')->post('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind'       => 'audio',
            'attachment' => $this->audio_subido(),
        ], ['Accept' => 'application/json']);
    }

    /**
     * Mensaje de audio que el equipo le mandó a un lead.
     *
     * @param Lead $lead Lead.
     *
     * @return LeadMessage
     */
    private function mensaje_de_audio_del_lead(Lead $lead): LeadMessage
    {
        return LeadMessage::where('lead_id', $lead->id)->where('sender', 'setter')->where('kind', 'audio')->firstOrFail();
    }

    /**
     * Mensaje de audio que el operador mandó a un ticket.
     *
     * @param SupportTicket $ticket Ticket.
     *
     * @return SupportMessage
     */
    private function mensaje_de_audio_del_ticket(SupportTicket $ticket): SupportMessage
    {
        return SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->where('kind', 'audio')->firstOrFail();
    }

    /**
     * Mensaje de lead con audio saliente tal como lo deja el envío (sin pasar por HTTP), con su archivo en disco.
     *
     * @param string $content Contenido del mensaje.
     *
     * @return LeadMessage
     */
    private function crear_audio_de_lead_guardado(string $content = self::MARCA_AUDIO_LEAD): LeadMessage
    {
        $lead = $this->crear_lead();

        $mensaje = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'setter',
            'kind'                  => 'audio',
            'content'               => $content,
            'status'                => 'enviado',
            'sent_at'               => now(),
            'is_followup'           => false,
            'requiere_verificacion' => false,
            'whatsapp_message_id'   => 'wamid.guardado.' . uniqid(),
        ]);

        $ruta = 'lead_messages/' . $lead->id . '/out_20261121_100000_abcd1234.ogg';
        Storage::disk('public')->put($ruta, $this->bytes_ogg_opus());
        LeadMessageAttachment::create([
            'lead_message_id' => $mensaje->id,
            'disk'            => 'public',
            'path'            => $ruta,
            'mime'            => 'audio/ogg',
            'size'            => strlen($this->bytes_ogg_opus()),
        ]);

        return $mensaje;
    }

    /**
     * Mensaje de soporte con audio saliente tal como lo deja el envío (sin pasar por HTTP), con su archivo en disco.
     *
     * @param string|null $body Cuerpo del mensaje (null en un audio recién enviado).
     *
     * @return SupportMessage
     */
    private function crear_audio_de_ticket_guardado(?string $body = null): SupportMessage
    {
        $ticket = $this->crear_ticket('whatsapp');

        $mensaje = SupportMessage::create([
            'support_ticket_id'   => $ticket->id,
            'sender_type'         => 'admin',
            'kind'                => 'audio',
            'body'                => $body,
            'whatsapp_message_id' => 'wamid.guardado.' . uniqid(),
        ]);

        $ruta = 'support_messages/' . $ticket->id . '/out_20261121_100000_abcd1234.ogg';
        Storage::disk('public')->put($ruta, $this->bytes_ogg_opus());
        SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $ruta,
            'mime'               => 'audio/ogg',
            'size'               => strlen($this->bytes_ogg_opus()),
        ]);

        return $mensaje;
    }

    // ------------------------------------------------------------------------------------------------
    // Utilidades de aserción
    // ------------------------------------------------------------------------------------------------

    /**
     * Pedidos que salieron hacia el endpoint de transcripción de OpenAI.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_a_openai(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), self::URL_OPENAI) === 0) {
                $pedidos[] = $par[0];
            }
        }

        return $pedidos;
    }

    /**
     * El job de transcripción que quedó encolado, afirmando que es el único, que va por la conexión
     * `database` y que es exactamente el esperado (canal + id del mensaje).
     *
     * @param string $canal      `lead` o `support`.
     * @param int    $message_id Id del mensaje que tiene que transcribir.
     *
     * @return TranscribeOutboundWhatsappAudioJob
     */
    private function job_encolado(string $canal, int $message_id): TranscribeOutboundWhatsappAudioJob
    {
        Queue::assertPushed(TranscribeOutboundWhatsappAudioJob::class, 1);
        Queue::assertPushed(TranscribeOutboundWhatsappAudioJob::class, function ($job) {
            return $job->connection === 'database';
        });

        $esperado = (new TranscribeOutboundWhatsappAudioJob($canal, $message_id))->onConnection('database');
        $pusheado = Queue::pushed(TranscribeOutboundWhatsappAudioJob::class)->first();
        $this->assertEquals($esperado, $pusheado, 'El job encolado no es el de (' . $canal . ', ' . $message_id . ') por la conexión database.');

        return $pusheado;
    }

    /**
     * Ejecuta el job como lo haría el worker (con sus dependencias inyectadas).
     *
     * @param TranscribeOutboundWhatsappAudioJob $job Job a correr.
     *
     * @return void
     */
    private function correr_job(TranscribeOutboundWhatsappAudioJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    /**
     * Historial del ticket tal como lo arma el monitor de soporte para el prompt.
     *
     * @param int $ticket_id Ticket.
     *
     * @return string
     */
    private function historial_para_el_monitor(int $ticket_id): string
    {
        $servicio = app(SupportAiSuggestionService::class);
        $metodo   = new \ReflectionMethod($servicio, 'format_conversation_history');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke($servicio, $ticket_id);
    }

    // ------------------------------------------------------------------------------------------------
    // CA1 — audio saliente a un lead
    // ------------------------------------------------------------------------------------------------

    /**
     * CA1: el audio que se le manda a un lead se encola y, al correr el job, el texto reemplaza a `[Audio enviado]`.
     *
     * Verifica además que el servicio habla con OpenAI como corresponde: una sola request multipart con el modelo,
     * el idioma y un archivo cuyo nombre lleva la extensión real del que se guardó.
     *
     * @return void
     */
    public function test_el_audio_que_se_le_manda_a_un_lead_queda_transcripto()
    {
        $this->crear_config_whatsapp();
        $this->falsear_http();
        $lead = $this->crear_lead();

        // El interruptor y la clave prendidos hacen que el servicio diga que está habilitado.
        $this->assertTrue(app(WhatsappOutboundAudioTranscriptionService::class)->is_enabled());

        $this->enviar_audio_a_lead($lead)->assertStatus(200);

        // El envío en sí no cambió: mensaje de audio del setter, con wamid y adjunto, todavía sin texto.
        $mensaje = $this->mensaje_de_audio_del_lead($lead);
        $this->assertSame(self::MARCA_AUDIO_LEAD, $mensaje->content);
        $this->assertStringStartsWith('wamid.salida.', (string) $mensaje->whatsapp_message_id);
        $adjunto = LeadMessageAttachment::where('lead_message_id', $mensaje->id)->firstOrFail();
        $this->assertCount(0, $this->pedidos_a_openai(), 'Se llamó a OpenAI dentro del request del envío.');

        // Quedó encolado el job correcto, por la conexión database, y sólo ése.
        $job = $this->job_encolado('lead', (int) $mensaje->id);

        $this->correr_job($job);

        $mensaje->refresh();
        $this->assertSame(self::TEXTO_TRANSCRIPTO, $mensaje->content);
        $this->assertSame('audio', $mensaje->kind);
        $this->assertSame('setter', $mensaje->sender);
        $this->assertStringStartsWith('wamid.salida.', (string) $mensaje->whatsapp_message_id, 'Se perdió el wamid del envío.');
        $adjunto_despues = LeadMessageAttachment::where('lead_message_id', $mensaje->id)->firstOrFail();
        $this->assertSame($adjunto->path, $adjunto_despues->path);
        $this->assertSame($adjunto->mime, $adjunto_despues->mime);

        // Exactamente una request a OpenAI, multipart con modelo, idioma y archivo con la extensión real.
        $pedidos = $this->pedidos_a_openai();
        $this->assertCount(1, $pedidos);
        $cuerpo = $pedidos[0]->body();
        $this->assertTrue($pedidos[0]->hasHeader('Authorization', 'Bearer sk-test'), 'Falta la clave de OpenAI en el encabezado.');
        $this->assertMatchesRegularExpression('/name="model"\r\n(?:[^\r\n]+\r\n)*\r\ngpt-4o-mini-transcribe\r\n/', $cuerpo, 'No viajó el modelo.');
        $this->assertMatchesRegularExpression('/name="language"\r\n(?:[^\r\n]+\r\n)*\r\nes\r\n/', $cuerpo, 'No viajó el idioma.');
        $extension = pathinfo($adjunto->path, PATHINFO_EXTENSION);
        $this->assertNotSame('', $extension);
        $this->assertMatchesRegularExpression('/filename="[^"]*\.' . preg_quote($extension, '/') . '"/', $cuerpo, 'El archivo no viajó con la extensión real.');

        // El panel se entera para mostrar el texto bajo el audio.
        Event::assertDispatched(LeadConversationUpdated::class);
    }

    // ------------------------------------------------------------------------------------------------
    // CA2 — audio saliente en un ticket de WhatsApp
    // ------------------------------------------------------------------------------------------------

    /**
     * CA2: el audio del operador en un ticket de WhatsApp queda en `body` y el monitor lo lee como texto.
     *
     * @return void
     */
    public function test_el_audio_del_operador_en_un_ticket_de_whatsapp_queda_transcripto_y_llega_al_historial()
    {
        $this->crear_config_whatsapp();
        $this->falsear_http();
        $ticket = $this->crear_ticket('whatsapp');

        $this->enviar_audio_a_ticket($ticket)->assertStatus(201);

        // Antes de transcribir, el monitor sólo ve una marca de audio.
        $mensaje = $this->mensaje_de_audio_del_ticket($ticket);
        $this->assertSame('', trim((string) $mensaje->body));
        $this->assertStringContainsString('Operador: [AUDIO]', $this->historial_para_el_monitor($ticket->id));
        $adjunto = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();

        $job = $this->job_encolado('support', (int) $mensaje->id);

        $eventos_antes = Event::dispatched(SupportMessageReceived::class)->count();
        $this->correr_job($job);

        $mensaje->refresh();
        $this->assertSame(self::TEXTO_TRANSCRIPTO, $mensaje->body);
        $this->assertSame('audio', $mensaje->kind);
        $this->assertSame('admin', $mensaje->sender_type);
        $adjunto_despues = SupportMessageAttachment::where('support_message_id', $mensaje->id)->firstOrFail();
        $this->assertSame($adjunto->path, $adjunto_despues->path);
        $this->assertCount(1, $this->pedidos_a_openai());

        // El hilo se refresca en el panel y el monitor lee la transcripción como cualquier texto.
        $this->assertSame($eventos_antes + 1, Event::dispatched(SupportMessageReceived::class)->count());
        $historial = $this->historial_para_el_monitor($ticket->id);
        $this->assertStringContainsString('Operador: ' . self::TEXTO_TRANSCRIPTO, $historial);
        $this->assertStringNotContainsString('[AUDIO]', $historial);
    }

    // ------------------------------------------------------------------------------------------------
    // CA3 — lo que NO se encola
    // ------------------------------------------------------------------------------------------------

    /**
     * CA3: un ticket del ERP no encola nada; uno de WhatsApp, en el mismo test, sí (control).
     *
     * El control evita que el test pase por la razón equivocada: si nunca se encolara nada, "el del ERP no
     * encola" sería cierto sin que la misión haya hecho nada.
     *
     * @return void
     */
    public function test_un_ticket_del_erp_no_encola_transcripcion()
    {
        $this->crear_config_whatsapp();
        $this->falsear_http();

        $ticket_erp = $this->crear_ticket('erp');
        $this->enviar_audio_a_ticket($ticket_erp)->assertStatus(201);

        Queue::assertNothingPushed();
        $this->assertCount(0, $this->pedidos_a_openai());

        // Control: el mismo audio en un ticket de WhatsApp sí encola su transcripción.
        $ticket_whatsapp = $this->crear_ticket('whatsapp');
        $this->enviar_audio_a_ticket($ticket_whatsapp)->assertStatus(201);

        $mensaje = $this->mensaje_de_audio_del_ticket($ticket_whatsapp);
        $this->job_encolado('support', (int) $mensaje->id);
    }

    /**
     * CA3: un audio ENTRANTE de un lead sigue con la transcripción de Kapso: no se encola nada ni se llama a OpenAI.
     *
     * Es comportamiento que ya existe y la misión no puede tocar ("no rehacer eso"): este test es el candado.
     *
     * @return void
     */
    public function test_el_audio_entrante_de_un_lead_sigue_con_la_transcripcion_de_kapso()
    {
        // Config en test_mode: la bienvenida del onboarding no sale de verdad.
        WhatsappConfig::query()->update(['is_active' => false]);
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = 'secreto-de-prueba-del-webhook';
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();

        $this->falsear_http();

        $telefono = '+5493416660011';
        $payload  = [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Marina López'],
            'message'      => [
                'id'        => 'wamid.ENTRANTE.AUDIO1',
                'from'      => $telefono,
                'type'      => 'audio',
                'audio'     => ['id' => 'media-entrante-1', 'mime_type' => 'audio/ogg; codecs=opus'],
                'kapso'     => ['transcript' => ['text' => 'Quiero ver cuánto sale el sistema']],
                'timestamp' => (string) time(),
            ],
        ];
        $body = json_encode($payload);

        $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, 'secreto-de-prueba-del-webhook'),
        ], $body)->assertStatus(200);

        $mensaje = LeadMessage::where('whatsapp_message_id', 'wamid.ENTRANTE.AUDIO1')->first();
        $this->assertNotNull($mensaje, 'El webhook tenía que guardar el audio entrante del lead.');
        $this->assertSame('lead', $mensaje->sender);
        $this->assertSame('audio', $mensaje->kind);
        $this->assertSame('Quiero ver cuánto sale el sistema', $mensaje->content);

        Queue::assertNotPushed(TranscribeOutboundWhatsappAudioJob::class);
        $this->assertCount(0, $this->pedidos_a_openai());
    }

    // ------------------------------------------------------------------------------------------------
    // CA4 — apagado por defecto
    // ------------------------------------------------------------------------------------------------

    /**
     * Combinaciones de configuración que dejan la transcripción apagada.
     *
     * @return array<string, array{0: bool, 1: string|null}>
     */
    public function configuraciones_apagadas(): array
    {
        return [
            'interruptor apagado'  => [false, 'sk-test'],
            'clave vacía'          => [true, ''],
            'clave sin definir'    => [true, null],
        ];
    }

    /**
     * CA4: con el interruptor apagado o sin clave, los envíos responden igual y no se encola ni se llama a OpenAI.
     *
     * @dataProvider configuraciones_apagadas
     *
     * @param bool        $habilitado Valor de `services.openai_transcription.enabled`.
     * @param string|null $clave      Valor de `services.openai_transcription.api_key`.
     *
     * @return void
     */
    public function test_apagado_por_defecto_no_encola_ni_llama_a_openai(bool $habilitado, ?string $clave)
    {
        config([
            'services.openai_transcription.enabled' => $habilitado,
            'services.openai_transcription.api_key' => $clave,
        ]);
        $this->crear_config_whatsapp();
        $this->falsear_http();

        // El servicio mismo reporta que está apagado.
        $this->assertFalse(app(WhatsappOutboundAudioTranscriptionService::class)->is_enabled());

        // Envío a un lead.
        $lead = $this->crear_lead();
        $this->enviar_audio_a_lead($lead)->assertStatus(200);
        $mensaje_lead = $this->mensaje_de_audio_del_lead($lead);
        $this->assertSame(self::MARCA_AUDIO_LEAD, $mensaje_lead->content);
        $this->assertStringStartsWith('wamid.salida.', (string) $mensaje_lead->whatsapp_message_id);
        $this->assertSame(1, LeadMessageAttachment::where('lead_message_id', $mensaje_lead->id)->count());

        // Envío a un ticket de WhatsApp.
        $ticket = $this->crear_ticket('whatsapp');
        $this->enviar_audio_a_ticket($ticket)->assertStatus(201);
        $mensaje_ticket = $this->mensaje_de_audio_del_ticket($ticket);
        $this->assertSame('', trim((string) $mensaje_ticket->body));
        $this->assertStringStartsWith('wamid.salida.', (string) $mensaje_ticket->whatsapp_message_id);
        $this->assertSame(1, SupportMessageAttachment::where('support_message_id', $mensaje_ticket->id)->count());

        Queue::assertNothingPushed();
        $this->assertCount(0, $this->pedidos_a_openai());
    }

    // ------------------------------------------------------------------------------------------------
    // CA5 — fallas
    // ------------------------------------------------------------------------------------------------

    /**
     * Canales y formas de fallar de OpenAI.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function fallas_de_openai(): array
    {
        return [
            'lead con HTTP 500'          => ['lead', 'http_500'],
            'lead con excepción'         => ['lead', 'excepcion'],
            'lead con texto vacío'       => ['lead', 'texto_vacio'],
            'soporte con HTTP 500'       => ['support', 'http_500'],
            'soporte con excepción'      => ['support', 'excepcion'],
            'soporte con texto vacío'    => ['support', 'texto_vacio'],
        ];
    }

    /**
     * CA5: si OpenAI falla (500, excepción de red o texto vacío) el job no lanza y el mensaje queda como estaba.
     *
     * El envío es el mismo que con OpenAI andando: misma respuesta HTTP, mismo wamid, mismo adjunto.
     *
     * @dataProvider fallas_de_openai
     *
     * @param string $canal `lead` o `support`.
     * @param string $modo  Cómo falla OpenAI.
     *
     * @return void
     */
    public function test_si_openai_falla_el_mensaje_queda_como_estaba(string $canal, string $modo)
    {
        $this->crear_config_whatsapp();
        $this->falsear_http($modo);

        if ($canal === 'lead') {
            $lead = $this->crear_lead();
            $this->enviar_audio_a_lead($lead)->assertStatus(200);
            $mensaje = $this->mensaje_de_audio_del_lead($lead);
        } else {
            $ticket = $this->crear_ticket('whatsapp');
            $this->enviar_audio_a_ticket($ticket)->assertStatus(201);
            $mensaje = $this->mensaje_de_audio_del_ticket($ticket);
        }
        $wamid = (string) $mensaje->whatsapp_message_id;
        $this->assertStringStartsWith('wamid.salida.', $wamid);

        $job = $this->job_encolado($canal, (int) $mensaje->id);

        // No lanza: un job de transcripción nunca puede terminar como failed_job.
        $this->correr_job($job);

        $mensaje->refresh();
        if ($canal === 'lead') {
            $this->assertSame(self::MARCA_AUDIO_LEAD, $mensaje->content);
            $this->assertSame(1, LeadMessageAttachment::where('lead_message_id', $mensaje->id)->count());
        } else {
            $this->assertSame('', trim((string) $mensaje->body));
            $this->assertSame(1, SupportMessageAttachment::where('support_message_id', $mensaje->id)->count());
        }
        $this->assertSame($wamid, (string) $mensaje->whatsapp_message_id);
    }

    /**
     * CA5: si encolar el job lanza, el envío de soporte igual responde 201 y deja el mensaje guardado.
     *
     * La cola se reemplaza por una que rechaza todo `push`; se cuenta cuántas veces se intentó encolar para que
     * el test falle si la misión nunca lo intenta.
     *
     * @return void
     */
    public function test_si_no_se_puede_encolar_el_envio_de_soporte_igual_responde_201()
    {
        $this->crear_config_whatsapp();
        $this->falsear_http();

        $cola_rota = new class($this->app) extends QueueFake {
            /** @var int Veces que se intentó encolar. */
            public $intentos = 0;

            /**
             * Rechaza todo intento de encolar (pushOn, later y laterOn delegan acá).
             *
             * @param mixed $job   Job a encolar.
             * @param mixed $data  Datos extra.
             * @param mixed $queue Nombre de la cola.
             *
             * @return mixed
             */
            public function push($job, $data = '', $queue = null)
            {
                $this->intentos++;

                throw new \RuntimeException('La cola está caída (simulado).');
            }
        };
        Queue::swap($cola_rota);

        $ticket = $this->crear_ticket('whatsapp');
        $this->enviar_audio_a_ticket($ticket)->assertStatus(201);

        $this->assertGreaterThanOrEqual(1, $cola_rota->intentos, 'La misión nunca intentó encolar la transcripción.');
        $mensaje = $this->mensaje_de_audio_del_ticket($ticket);
        $this->assertStringStartsWith('wamid.salida.', (string) $mensaje->whatsapp_message_id);
        $this->assertSame(1, SupportMessageAttachment::where('support_message_id', $mensaje->id)->count());
    }

    // ------------------------------------------------------------------------------------------------
    // CA6 — idempotencia
    // ------------------------------------------------------------------------------------------------

    /**
     * CA6: el job no pisa un texto que ya existe, no hace nada con un id inexistente y no transcribe dos veces.
     *
     * @return void
     */
    public function test_no_pisa_un_texto_existente_ni_transcribe_dos_veces()
    {
        $this->falsear_http();

        // Un lead cuyo `content` ya no es la marca de audio (alguien lo corrigió a mano o ya se transcribió).
        $lead_con_texto = $this->crear_audio_de_lead_guardado('Texto que escribió una persona');
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('lead', (int) $lead_con_texto->id));
        $this->assertSame('Texto que escribió una persona', $lead_con_texto->fresh()->content);

        // Un mensaje de soporte con body no vacío.
        $soporte_con_texto = $this->crear_audio_de_ticket_guardado('Texto que escribió una persona');
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('support', (int) $soporte_con_texto->id));
        $this->assertSame('Texto que escribió una persona', $soporte_con_texto->fresh()->body);

        // Ids inexistentes (mensaje borrado porque el envío falló): no lanza y no llama a nadie.
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('lead', 987654321));
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('support', 987654321));

        $this->assertCount(0, $this->pedidos_a_openai(), 'Se llamó a OpenAI donde no correspondía.');

        // Lead: dos corridas seguidas del mismo mensaje, una sola request.
        $lead = $this->crear_audio_de_lead_guardado();
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('lead', (int) $lead->id));
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('lead', (int) $lead->id));
        $this->assertSame(self::TEXTO_TRANSCRIPTO, $lead->fresh()->content);
        $this->assertCount(1, $this->pedidos_a_openai());

        // Soporte: ídem.
        $soporte = $this->crear_audio_de_ticket_guardado();
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('support', (int) $soporte->id));
        $this->correr_job(new TranscribeOutboundWhatsappAudioJob('support', (int) $soporte->id));
        $this->assertSame(self::TEXTO_TRANSCRIPTO, $soporte->fresh()->body);
        $this->assertCount(2, $this->pedidos_a_openai());
    }
}
