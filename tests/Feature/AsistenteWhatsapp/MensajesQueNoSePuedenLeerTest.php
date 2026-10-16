<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use App\Services\SystemErrorWhatsappService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Lo que el dueño manda y el contrato con el `empresa-api` no sabe llevar (misión
 * asistente-envios-a-clientes, 16/10/2026; caso S19 de la prueba del agente personal).
 *
 * Hasta el 16/10 un documento, un video o un sticker sin nada escrito viajaba como un `texto` vacío:
 * el `empresa-api` contestaba 422 y el dueño recibía «Uf, no pude contestarte», con un aviso al
 * equipo por algo que no es ninguna falla. El caso real fue un audio adjuntado desde WhatsApp Web,
 * que no sale como nota de voz sino como `document` con mime `audio/ogg`.
 *
 * Lo que fijan estas pruebas:
 *
 *   1. el documento de audio viaja como `tipo = audio` sin texto (del otro lado, un audio sin
 *      transcribir, que tiene su respuesta fija);
 *   2. un PDF sin epígrafe no viaja: el admin contesta en el acto el texto fijo, sin POST, sin la
 *      disculpa y sin avisar al equipo;
 *   3. eso no rompe la espera de las fotos (no se las lleva) ni la cita (la respuesta fija queda en
 *      la conversación citada);
 *   4. con epígrafe, el documento —de audio o no— viaja como siempre.
 */
class MensajesQueNoSePuedenLeerTest extends BaseDelCanal
{
    /**
     * Teléfono del dueño en todas las pruebas.
     */
    const TELEFONO = '+5493411234567';

    /**
     * Cola fakeada y número principal listo: el webhook deja la fila y el job, y la prueba corre el
     * job a mano.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->crear_config_whatsapp();
    }

    /**
     * El documento de audio sin epígrafe llega a la empresa como `tipo = audio`, sin texto.
     *
     * @return void
     */
    public function test_el_documento_de_audio_viaja_como_audio_sin_texto(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook(
            $this->payload_de_documento('wamid.AUDIO1', 'audio/ogg', 'audio-ventas-de-ayer.ogg')
        )->assertStatus(200);

        $fila = $this->fila('wamid.AUDIO1');
        $this->assertSame('audio', $fila->tipo, 'Para el asistente es un audio sin transcribir, no un documento.');
        $this->assertNull($fila->texto, 'Nadie le inventa un texto.');
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $fila->estado);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
        ]);

        $this->correr_job($this->job_de($fila), $espia);

        $partes = $this->partes_del_post();
        $this->assertNotSame([], $partes, 'El audio tiene que viajar al sistema del cliente.');
        $this->assertSame('audio', $this->parte($partes, 'tipo'));
        $this->assertSame('', $this->parte($partes, 'texto'));

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->estado);
        $this->assertSame([], $espia->textos, 'El admin no le contesta nada por su cuenta: contesta el asistente.');
    }

    /**
     * El mime con parámetros (`audio/ogg; codecs=opus`) también es audio.
     *
     * @return void
     */
    public function test_el_documento_de_audio_con_parametros_en_el_mime_tambien_es_audio(): void
    {
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook(
            $this->payload_de_documento('wamid.AUDIO1', 'Audio/OGG; codecs=opus', 'nota.ogg')
        )->assertStatus(200);

        $this->assertSame('audio', $this->fila('wamid.AUDIO1')->tipo);
    }

    /**
     * Un PDF sin epígrafe: texto fijo en el acto, sin POST, sin «Uf» y sin aviso al equipo.
     *
     * @return void
     */
    public function test_un_pdf_sin_epigrafe_se_contesta_con_el_texto_fijo_sin_ir_al_sistema_del_cliente(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->mock(SystemErrorWhatsappService::class, function ($mock) {
            $mock->shouldNotReceive('notify_send_error');
        });

        $this->postear_webhook(
            $this->payload_de_documento('wamid.PDF1', 'application/pdf', 'lista-de-precios.pdf')
        )->assertStatus(200);

        $fila = $this->fila('wamid.PDF1');
        $this->assertSame('document', $fila->tipo);

        /* Sale por la cola del canal, como todo lo demás: con el worker permanente, en un segundo. */
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class, function ($job) {
            return $job->connection === 'database' && $job->queue === 'asistente';
        });

        $this->correr_job($this->job_de($fila), $espia);

        Http::assertNothingSent();

        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_NO_SE_PUEDE_LEER, $espia->textos[0]['body']);
        $this->assertSame(self::TELEFONO, $espia->textos[0]['to']);
        $this->assertSame(
            'Ese tipo de mensaje todavía no lo puedo leer: escribime, mandame una nota de voz o una foto.',
            AsistenteWhatsappService::TEXTO_NO_SE_PUEDE_LEER
        );

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado, 'No falló nada: la fila queda cerrada, no en error.');
        $this->assertNull($fila->error);
        $this->assertNull($fila->ai_message_id, 'Nunca viajó al sistema del cliente.');

        $salientes = ClientAssistantMessage::where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)->get();
        $this->assertCount(1, $salientes);
        $this->assertSame(AsistenteWhatsappService::TEXTO_NO_SE_PUEDE_LEER, $salientes[0]->texto);
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $salientes[0]->estado);

        foreach ($espia->textos as $texto) {
            $this->assertNotSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $texto['body']);
        }

        /* Y un ingreso más del mismo job (un reintento a mano) no contesta dos veces. */
        $this->correr_job($this->job_de($fila), $espia);
        $this->assertCount(1, $espia->textos);
    }

    /**
     * Un sticker también, y si Meta rechaza el texto fijo queda escrito, sin disculpa ni aviso.
     *
     * @return void
     */
    public function test_un_sticker_sin_texto_tambien_y_el_rechazo_de_meta_queda_escrito(): void
    {
        $espia = $this->espiar_sender(false);
        $this->crear_cliente(self::TELEFONO);

        $this->mock(SystemErrorWhatsappService::class, function ($mock) {
            $mock->shouldNotReceive('notify_send_error');
        });

        $this->postear_webhook([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => self::TELEFONO, 'contact_name' => 'Dueño de prueba'],
            'message'      => [
                'id'        => 'wamid.STICKER1',
                'from'      => self::TELEFONO,
                'type'      => 'sticker',
                'sticker'   => ['id' => 'media.sticker.1', 'mime_type' => 'image/webp'],
                'timestamp' => (string) time(),
            ],
        ])->assertStatus(200);

        $fila = $this->fila('wamid.STICKER1');
        $this->correr_job($this->job_de($fila), $espia);

        Http::assertNothingSent();
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_NO_SE_PUEDE_LEER, $espia->textos[0]['body']);

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNotNull($fila->error, 'El rechazo de Meta es lo único que hay que poder ver.');
    }

    /**
     * 🔴 Un PDF sin epígrafe NO se lleva las fotos que el dueño tenía esperando su instrucción.
     *
     * Las fotos siguen esperando, y el audio que llega después se las lleva como siempre.
     *
     * @return void
     */
    public function test_un_pdf_sin_epigrafe_no_rompe_la_espera_de_las_fotos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1'))->assertStatus(200);
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $this->fila('wamid.FOTO1')->estado);

        Carbon::setTestNow(now()->addSeconds(5));
        $this->postear_webhook(
            $this->payload_de_documento('wamid.PDF1', 'application/pdf', 'remito.pdf')
        )->assertStatus(200);

        $foto = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $foto->estado, 'La foto sigue esperando su instrucción.');
        $this->assertNull($foto->agrupado_en_id);

        $pdf = $this->fila('wamid.PDF1');
        $del_pdf = $this->jobs_de($pdf);
        $this->assertCount(1, $del_pdf);
        $this->assertSame([], $this->imagenes_del_job($del_pdf[0]), 'El PDF no se lleva ninguna foto.');

        /* El texto que llega después sí se la lleva, como siempre. */
        Carbon::setTestNow(now()->addSeconds(5));
        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Cargame esta factura', 'wamid.TEXTO1')
        )->assertStatus(200);

        $foto->refresh();
        $texto = $this->fila('wamid.TEXTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto->estado);
        $this->assertSame((int) $texto->id, (int) $foto->agrupado_en_id);
        $this->assertCount(1, $this->imagenes_del_job($this->jobs_de($texto)[0]));

        Carbon::setTestNow();
    }

    /**
     * La cita no se rompe: el PDF que cita una respuesta del asistente deja el texto fijo en esa
     * conversación, y citar el texto fijo lleva de vuelta ahí.
     *
     * @return void
     */
    public function test_la_respuesta_fija_queda_en_la_conversacion_citada(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente(self::TELEFONO);

        /* Una respuesta vieja del asistente, de la conversación 77. */
        app(AsistenteWhatsappService::class)->registrar_saliente_de(
            (int) $client->id,
            self::TELEFONO,
            'Ayer vendiste $184.500.',
            'wamid.RESPUESTA.VIEJA',
            77,
            ClientAssistantMessage::ESTADO_RESPONDIDO
        );

        $payload = $this->payload_de_documento('wamid.PDF1', 'application/pdf', 'remito.pdf');
        $payload['message']['context'] = ['id' => 'wamid.RESPUESTA.VIEJA'];

        $this->postear_webhook($payload)->assertStatus(200);

        $fila = $this->fila('wamid.PDF1');
        $this->assertSame(77, (int) $fila->ai_conversation_id);

        $this->correr_job($this->job_de($fila), $espia);

        $this->assertSame(
            77,
            ClientAssistantMessage::conversacion_por_cita((int) $client->id, 'wamid.saliente.1'),
            'Citar el texto fijo tiene que volver a la conversación que el dueño había citado.'
        );
    }

    /**
     * Con epígrafe nada cambia: el PDF viaja como `texto` con su epígrafe, y el audio-documento con
     * epígrafe también (el epígrafe no es la transcripción de nada).
     *
     * @return void
     */
    public function test_con_epigrafe_el_documento_viaja_como_siempre(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook(
            $this->payload_de_documento('wamid.AUDIO1', 'audio/ogg', 'nota.ogg', 'Escuchá esto y decime')
        )->assertStatus(200);

        $fila = $this->fila('wamid.AUDIO1');
        $this->assertSame('document', $fila->tipo);
        $this->assertSame('Escuchá esto y decime', $fila->texto);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305], 202),
        ]);

        $this->correr_job($this->job_de($fila), $espia);

        $partes = $this->partes_del_post();
        $this->assertSame('texto', $this->parte($partes, 'tipo'));
        $this->assertSame('Escuchá esto y decime', $this->parte($partes, 'texto'));
        $this->assertSame([], $espia->textos);
    }

    /**
     * La regla, sola: qué se puede leer y qué no.
     *
     * @return void
     */
    public function test_la_regla_de_lo_que_se_puede_leer(): void
    {
        $servicio = app(AsistenteWhatsappService::class);
        $foto     = [['url' => 'https://kapso.test/f.jpg', 'mime' => 'image/jpeg']];

        $this->assertFalse($servicio->no_se_puede_leer('Hola', 'text', []), 'Algo escrito se lee.');
        $this->assertFalse($servicio->no_se_puede_leer('Mirá esto', 'document', []), 'Un epígrafe se lee.');
        $this->assertFalse($servicio->no_se_puede_leer(null, 'document', $foto), 'Con fotos viaja.');
        $this->assertFalse($servicio->no_se_puede_leer(null, 'audio', []), 'Un audio tiene su respuesta del otro lado.');
        $this->assertFalse($servicio->no_se_puede_leer('', 'ptt', []));
        $this->assertFalse($servicio->no_se_puede_leer(null, 'image', []), 'Una foto sin metadata es una falla, no un tipo que no se lee.');

        $this->assertTrue($servicio->no_se_puede_leer(null, 'document', []));
        $this->assertTrue($servicio->no_se_puede_leer('   ', 'video', []));
        $this->assertTrue($servicio->no_se_puede_leer(null, 'sticker', []));
        $this->assertTrue($servicio->no_se_puede_leer(null, 'text', []));
    }

    /**
     * Payload de un documento entrante, con epígrafe opcional.
     *
     * @param string      $wamid    ID del mensaje.
     * @param string      $mime     Mime del documento.
     * @param string      $nombre   Nombre del archivo.
     * @param string|null $epigrafe Epígrafe, si el dueño escribió alguno.
     *
     * @return array<string, mixed>
     */
    private function payload_de_documento(string $wamid, string $mime, string $nombre, ?string $epigrafe = null): array
    {
        $documento = [
            'id'        => 'media.doc.' . $wamid,
            'mime_type' => $mime,
            'filename'  => $nombre,
            'link'      => 'https://kapso.test/media/' . $nombre . '?firma=secreta',
        ];

        if ($epigrafe !== null) {
            $documento['caption'] = $epigrafe;
        }

        return [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => self::TELEFONO, 'contact_name' => 'Dueño de prueba'],
            'message'      => [
                'id'        => $wamid,
                'from'      => self::TELEFONO,
                'type'      => 'document',
                'document'  => $documento,
                'timestamp' => (string) time(),
            ],
        ];
    }

    /**
     * Payload de una foto sin epígrafe.
     *
     * @param string $wamid ID del mensaje.
     *
     * @return array<string, mixed>
     */
    private function payload_de_foto(string $wamid): array
    {
        return [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => self::TELEFONO, 'contact_name' => 'Dueño de prueba'],
            'message'      => [
                'id'        => $wamid,
                'from'      => self::TELEFONO,
                'type'      => 'image',
                'image'     => [
                    'id'        => 'media.foto.' . $wamid,
                    'mime_type' => 'image/jpeg',
                    'link'      => 'https://kapso.test/media/' . $wamid . '.jpg?firma=secreta',
                ],
                'timestamp' => (string) time(),
            ],
        ];
    }

    /**
     * La fila entrante de un mensaje, por su wamid.
     *
     * @param string $wamid
     *
     * @return ClientAssistantMessage
     */
    private function fila(string $wamid): ClientAssistantMessage
    {
        return ClientAssistantMessage::where('whatsapp_message_id', $wamid)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->firstOrFail();
    }

    /**
     * Los jobs despachados para una fila, en orden.
     *
     * @param ClientAssistantMessage $fila
     *
     * @return array<int, EnviarMensajeAlAsistenteJob>
     */
    private function jobs_de(ClientAssistantMessage $fila): array
    {
        return array_values(Queue::pushed(EnviarMensajeAlAsistenteJob::class, function ($job) use ($fila) {
            return (int) $this->propiedad($job, 'mensaje_id') === (int) $fila->id;
        })->all());
    }

    /**
     * El único job despachado para una fila.
     *
     * @param ClientAssistantMessage $fila
     *
     * @return EnviarMensajeAlAsistenteJob
     */
    private function job_de(ClientAssistantMessage $fila): EnviarMensajeAlAsistenteJob
    {
        $jobs = $this->jobs_de($fila);
        $this->assertCount(1, $jobs, 'Cada mensaje despacha un solo job.');

        return $jobs[0];
    }

    /**
     * Las fotos que lleva un job en el payload.
     *
     * @param EnviarMensajeAlAsistenteJob $job
     *
     * @return array<int, array<string, mixed>>
     */
    private function imagenes_del_job(EnviarMensajeAlAsistenteJob $job): array
    {
        return (array) $this->propiedad($job, 'imagenes');
    }

    /**
     * Una propiedad privada del job.
     *
     * @param EnviarMensajeAlAsistenteJob $job
     * @param string                      $nombre
     *
     * @return mixed
     */
    private function propiedad(EnviarMensajeAlAsistenteJob $job, string $nombre)
    {
        $propiedad = new \ReflectionProperty(EnviarMensajeAlAsistenteJob::class, $nombre);
        $propiedad->setAccessible(true);

        return $propiedad->getValue($job);
    }

    /**
     * Las partes del POST multipart al sistema del cliente, o vacío si no salió ninguno.
     *
     * @return array<int, array<string, mixed>>
     */
    private function partes_del_post(): array
    {
        $partes = [];

        foreach (Http::recorded() as $par) {
            $request = $par[0];
            if ($request->method() === 'POST' && strpos($request->url(), '/asistente/mensajes') !== false) {
                $partes = $request->data();
            }
        }

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
}
