<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * La foto sin epígrafe espera su instrucción (misión asistente-espera-foto, 27/9/2026).
 *
 * Lo pidió Lucas así: *"si le mando una imagen de la nada, que espere unos 30 segundos a recibir la
 * instrucción para esa imagen"*. La foto de la factura primero, el audio de "escaneame esta factura"
 * después, y las dos tienen que llegarle al asistente en UN solo turno: si no, la foto dispara un
 * turno caro que no sabe qué hacer, y el audio otro donde el modelo ya no la ve.
 *
 * Lo que estas pruebas cuidan, además del camino feliz:
 *
 *   1. **Que la foto no se pierda.** Ni cuando vence la espera sin instrucción, ni cuando el canal se
 *      apaga en el medio, ni cuando hay más fotos de las que viajan en un mensaje.
 *   2. **Que no viaje dos veces.** El mensaje que se la lleva y el temporizador que despierta después
 *      compiten por la misma fila.
 *   3. **Que la URL firmada no quede en claro.** Mientras espera vive cifrada en la base, y se borra
 *      en cuanto alguien la reclama.
 *
 * Los mensajes entran por el webhook REAL (`postear_webhook()`), con la cola fakeada para poder
 * mirar qué se despachó y con qué `delay`. Los jobs se corren después a mano con `correr_job()`, que
 * es lo que hace el worker cuando les llega el turno.
 */
class EsperaDeLaFotoTest extends BaseDelCanal
{
    /**
     * Teléfono del dueño en todas las pruebas.
     */
    const TELEFONO = '+5493411234567';

    /**
     * La cola de verdad, guardada antes de fakearla, para las pruebas de atomicidad.
     *
     * @var \Illuminate\Queue\QueueManager
     */
    private $cola_real;

    /**
     * Último id de `jobs` antes de que la prueba empiece a encolar de verdad.
     *
     * @var int
     */
    private $ultimo_job_previo = 0;

    /**
     * Cola fakeada, configuración de WhatsApp activa y el reloj quieto.
     *
     * El reloj quieto no es comodidad: el `delay` del temporizador y la vigencia de diez minutos se
     * miden contra `now()`, y con el reloj andando una prueba que compara "30 segundos" daría 29 o
     * 30 según en qué parte del segundo cayó.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cola_real = Queue::getFacadeRoot();

        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00'));

        $this->crear_config_whatsapp();
    }

    /**
     * 1. La foto sin epígrafe espera: fila `esperando`, metadata cifrada y temporizador a 30 s.
     *
     * @return void
     */
    public function test_la_foto_sin_epigrafe_espera_su_instruccion(): void
    {
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        $fila = $this->fila('wamid.FOTO1');

        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $fila->estado);
        $this->assertSame('image', $fila->tipo);
        $this->assertNull($fila->texto, 'Una foto sin epígrafe no tiene texto: nadie le inventa uno.');

        /* 🔴 En crudo la columna NO dice nada: la URL firmada de Kapso es una credencial. */
        $crudo = $this->media_cruda($fila);
        $this->assertNotNull($crudo, 'La metadata de la foto tenía que quedar guardada mientras espera.');
        $this->assertStringNotContainsString('kapso', $crudo);
        $this->assertStringNotContainsString('firma=secreta', $crudo);

        /* Descifrada por el modelo sí está entera, que es lo que va a necesitar el reclamo. */
        $this->assertSame($this->url_de_foto('1'), $fila->media_en_espera[0]['url']);

        /* Y no sale al serializar la fila. */
        $this->assertArrayNotHasKey('media_en_espera', $fila->toArray());

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);

        $temporizador = $jobs[0];
        $this->assertSame('database', $temporizador->connection);
        $this->assertInstanceOf(\DateTimeInterface::class, $temporizador->delay);
        $this->assertSame(30, (int) now()->diffInSeconds($temporizador->delay));
        $this->assertSame(30, $this->segundos_del_job($temporizador));
        $this->assertSame(
            [],
            $this->imagenes_del_job($temporizador),
            'Mientras espera, la URL no viaja en el payload: la tabla jobs la guardaría en claro.'
        );

        $this->assertSame([], $this->posts_al_asistente(), 'Mientras espera no se le manda nada al sistema del cliente.');
    }

    /**
     * 2. Foto y después audio: un solo POST con las dos cosas, y el temporizador no hace nada.
     *
     * @return void
     */
    public function test_la_foto_viaja_con_el_audio_que_llega_despues(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(12));
        $this->postear_webhook($this->payload_de_audio('wamid.AUDIO1', 'Cargame este artículo al sistema'))->assertStatus(200);

        $foto  = $this->fila('wamid.FOTO1');
        $audio = $this->fila('wamid.AUDIO1');

        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto->estado);
        $this->assertSame((int) $audio->id, $foto->agrupado_en_id);
        $this->assertNull($this->media_cruda($foto), 'Al reclamarla, la metadata se borra de la base.');
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $audio->estado);

        $jobs = $this->jobs();
        $this->assertCount(2, $jobs);
        list($temporizador, $del_audio) = $jobs;

        $this->assertNull($del_audio->delay, 'El mensaje que cierra la espera sale en el acto.');
        $imagenes = $this->imagenes_del_job($del_audio);
        $this->assertCount(1, $imagenes);
        $this->assertSame($this->url_de_foto('1'), $imagenes[0]['url']);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($del_audio, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(1, $this->contar_imagenes($posts[0]));
        $this->assertSame('Cargame este artículo al sistema', $this->parte($posts[0], 'texto'));
        $this->assertSame('audio', $this->parte($posts[0], 'tipo'));
        $this->assertSame('wamid.AUDIO1', $this->parte($posts[0], 'whatsapp_message_id'));

        /* El temporizador de la foto despierta después y no tiene nada que hacer. */
        $this->correr_job($temporizador, $espia);

        $this->assertCount(1, $this->posts_al_asistente(), 'La foto no puede viajar dos veces.');
        $this->assertCount(2, $this->jobs(), 'El temporizador de una foto ya agrupada no despacha nada.');
        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto->refresh()->estado);
        $this->assertCount(0, $espia->textos);
    }

    /**
     * 3. Foto sola que vence sin instrucción: sale sola, con el texto vacío, como antes.
     *
     * @return void
     */
    public function test_la_foto_sola_sale_cuando_vence_la_espera(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        Carbon::setTestNow(now()->addSeconds(30));
        $this->correr_job($this->jobs()[0], $espia);

        /* El temporizador cierra la espera y despacha el job común: la ida no la hace él. */
        $this->assertSame([], $this->posts_al_asistente());

        $fila = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $fila->estado);
        $this->assertNull($this->media_cruda($fila));

        $jobs = $this->jobs();
        $this->assertCount(2, $jobs);
        $comun = $jobs[1];

        $this->assertSame('database', $comun->connection);
        $this->assertNull($comun->delay);
        $this->assertSame(0, $this->segundos_del_job($comun));
        $this->assertCount(1, $this->imagenes_del_job($comun), 'La foto viaja en el payload del job común.');

        $this->correr_job($comun, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(1, $this->contar_imagenes($posts[0]));
        $this->assertSame('', $this->parte($posts[0], 'texto'));
        $this->assertSame('imagen', $this->parte($posts[0], 'tipo'));

        $this->assertSame(ClientAssistantMessage::ESTADO_ENVIADO, $fila->refresh()->estado);
    }

    /**
     * 4. Dos fotos y un texto: un POST con las dos fotos EN ORDEN y el texto.
     *
     * @return void
     */
    public function test_dos_fotos_y_un_texto_viajan_juntas_y_en_orden(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(5));
        $this->postear_webhook($this->payload_de_foto('wamid.FOTO2', '2'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(5));
        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Escaneame esta factura de dos páginas', 'wamid.TEXTO1')
        )->assertStatus(200);

        $texto = $this->fila('wamid.TEXTO1');
        foreach (['wamid.FOTO1', 'wamid.FOTO2'] as $wamid) {
            $foto = $this->fila($wamid);
            $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto->estado);
            $this->assertSame((int) $texto->id, $foto->agrupado_en_id);
        }

        $jobs = $this->jobs();
        $this->assertCount(3, $jobs);
        $del_texto = $jobs[2];

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*/media/foto-1*'      => Http::response($this->png(4), 200, ['Content-Type' => 'image/png']),
            '*/media/foto-2*'      => Http::response($this->png(6), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($del_texto, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame('Escaneame esta factura de dos páginas', $this->parte($posts[0], 'texto'));
        $this->assertSame('texto', $this->parte($posts[0], 'tipo'));

        /* El orden es el de llegada: la primera página primero. Cada foto tiene sus bytes. */
        $this->assertSame(
            [$this->png(4), $this->png(6)],
            $this->bytes_de_las_imagenes($posts[0])
        );
    }

    /**
     * 5. Dos fotos sin texto: el temporizador de la primera no hace nada; el de la segunda manda
     *    las dos. La ventana se reinicia con cada foto.
     *
     * @return void
     */
    public function test_la_rafaga_la_cierra_el_temporizador_de_la_ultima_foto(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(20));
        $this->postear_webhook($this->payload_de_foto('wamid.FOTO2', '2'))->assertStatus(200);

        list($temporizador_1, $temporizador_2) = $this->jobs();

        /* La segunda foto reinicia la ventana: su temporizador vence 30 s después de ELLA. */
        $this->assertSame(30, (int) now()->diffInSeconds($temporizador_2->delay));

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*/media/foto-1*'      => Http::response($this->png(4), 200, ['Content-Type' => 'image/png']),
            '*/media/foto-2*'      => Http::response($this->png(6), 200, ['Content-Type' => 'image/png']),
        ]);

        /* Vence la primera: hay una más nueva esperando, así que no cierra nada. */
        Carbon::setTestNow(now()->addSeconds(10));
        $this->correr_job($temporizador_1, $espia);

        $this->assertCount(2, $this->jobs(), 'El temporizador de la primera no despacha nada.');
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $this->fila('wamid.FOTO1')->estado);
        $this->assertNotNull($this->media_cruda($this->fila('wamid.FOTO1')));

        /* Vence la segunda: cierra la ráfaga con las dos. */
        Carbon::setTestNow(now()->addSeconds(20));
        $this->correr_job($temporizador_2, $espia);

        $foto_1 = $this->fila('wamid.FOTO1');
        $foto_2 = $this->fila('wamid.FOTO2');

        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto_1->estado);
        $this->assertSame((int) $foto_2->id, $foto_1->agrupado_en_id);
        $this->assertNull($this->media_cruda($foto_1));
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $foto_2->estado);
        $this->assertNull($this->media_cruda($foto_2));

        $jobs = $this->jobs();
        $this->assertCount(3, $jobs);

        $this->correr_job($jobs[2], $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame([$this->png(4), $this->png(6)], $this->bytes_de_las_imagenes($posts[0]));
        $this->assertSame('', $this->parte($posts[0], 'texto'));
        $this->assertSame('wamid.FOTO2', $this->parte($posts[0], 'whatsapp_message_id'));
    }

    /**
     * 6. Una foto CON epígrafe no espera, y se lleva la foto que estaba esperando.
     *
     * @return void
     */
    public function test_la_foto_con_epigrafe_no_espera_y_se_lleva_la_anterior(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(8));
        $this->postear_webhook(
            $this->payload_de_foto('wamid.FOTO2', '2', 'Esta es la compra de Distribuidora Norte')
        )->assertStatus(200);

        $foto_1 = $this->fila('wamid.FOTO1');
        $foto_2 = $this->fila('wamid.FOTO2');

        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $foto_2->estado);
        $this->assertNull($this->media_cruda($foto_2), 'La foto con epígrafe nunca guarda su metadata.');
        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto_1->estado);
        $this->assertSame((int) $foto_2->id, $foto_1->agrupado_en_id);

        $jobs = $this->jobs();
        $this->assertCount(2, $jobs);
        $de_la_foto_2 = $jobs[1];

        $this->assertNull($de_la_foto_2->delay);
        $this->assertSame(0, $this->segundos_del_job($de_la_foto_2));

        $urls = array_map(function ($media) {
            return $media['url'];
        }, $this->imagenes_del_job($de_la_foto_2));
        $this->assertSame([$this->url_de_foto('1'), $this->url_de_foto('2')], $urls);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($de_la_foto_2, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(2, $this->contar_imagenes($posts[0]));
        $this->assertSame('Esta es la compra de Distribuidora Norte', $this->parte($posts[0], 'texto'));
        $this->assertSame('imagen', $this->parte($posts[0], 'tipo'));
    }

    /**
     * 7. Una foto que espera hace más de diez minutos no se pega a un mensaje nuevo.
     *
     * Es la foto cuyo temporizador se perdió (un worker caído, un deploy en el medio): el dueño que
     * escribe después no está hablando de ella. Y no se pierde: su temporizador, cuando corre, la
     * manda sola.
     *
     * @return void
     */
    public function test_una_foto_vencida_no_la_reclama_un_mensaje_nuevo(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Cuánto vendí ayer?', 'wamid.TEXTO1')
        )->assertStatus(200);

        $foto = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $foto->estado);
        $this->assertNull($foto->agrupado_en_id);

        list($temporizador, $del_texto) = $this->jobs();
        $this->assertSame([], $this->imagenes_del_job($del_texto), 'El texto nuevo viaja sin la foto vieja.');

        /* El temporizador atrasado igual la saca: una foto vieja sale sola, no se queda guardada. */
        $this->correr_job($temporizador, $espia);

        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $this->fila('wamid.FOTO1')->estado);
        $jobs = $this->jobs();
        $this->assertCount(3, $jobs);
        $this->assertCount(1, $this->imagenes_del_job($jobs[2]));
    }

    /**
     * 8. Un texto sin fotos esperando sigue exactamente igual que antes.
     *
     * @return void
     */
    public function test_un_texto_sin_fotos_en_espera_sigue_igual(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Cuánto vendí ayer?', 'wamid.TEXTO1')
        )->assertStatus(200);

        $fila = $this->fila('wamid.TEXTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $fila->estado);
        $this->assertNull($this->media_cruda($fila));

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs[0]->delay, 'Un texto sale en el acto, como siempre.');
        $this->assertSame(0, $this->segundos_del_job($jobs[0]));
        $this->assertSame([], $this->imagenes_del_job($jobs[0]));

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
        ]);

        $this->correr_job($jobs[0], $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(0, $this->contar_imagenes($posts[0]));
        $this->assertSame('Cuánto vendí ayer?', $this->parte($posts[0], 'texto'));
        $this->assertSame('texto', $this->parte($posts[0], 'tipo'));
        $this->assertNull($fila->refresh()->error);
    }

    /**
     * 9. Después del 202, las fotos agrupadas tienen la conversación: citar la foto lleva a ella.
     *
     * @return void
     */
    public function test_citar_la_foto_agrupada_lleva_a_la_conversacion(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        $this->postear_webhook($this->payload_de_audio('wamid.AUDIO1', 'Escaneame esta factura'))->assertStatus(200);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 77, 'ai_message_id' => 501], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($this->jobs()[1], $espia);

        $foto = $this->fila('wamid.FOTO1');
        $this->assertSame(77, (int) $foto->ai_conversation_id);
        $this->assertSame(501, (int) $foto->ai_message_id);
        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto->estado, 'Pasarle la conversación no la reabre.');

        $this->assertSame(
            77,
            ClientAssistantMessage::conversacion_por_cita((int) $client->id, 'wamid.FOTO1'),
            'Citar la foto de la factura tiene que volver a la conversación donde se cargó.'
        );
    }

    /**
     * 9 bis. Si la foto citaba una respuesta vieja y el audio no cita nada, manda la cita de la foto.
     *
     * El dueño citó una respuesta del asistente al mandar la foto: quería seguir ESA conversación.
     * El audio que llega después es la segunda mitad del mismo pedido, aunque no cite nada.
     *
     * @return void
     */
    public function test_la_cita_de_la_foto_viaja_si_el_mensaje_que_cierra_no_cita(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente(self::TELEFONO);

        $anterior                      = new ClientAssistantMessage();
        $anterior->client_id           = $client->id;
        $anterior->telefono            = self::TELEFONO;
        $anterior->direccion           = ClientAssistantMessage::DIRECCION_SALIENTE;
        $anterior->whatsapp_message_id = 'wamid.RESPUESTA-VIEJA';
        $anterior->ai_conversation_id  = 412;
        $anterior->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $anterior->save();

        $this->postear_webhook(
            $this->payload_de_foto('wamid.FOTO1', '1', null, 'wamid.RESPUESTA-VIEJA')
        )->assertStatus(200);
        $this->postear_webhook($this->payload_de_audio('wamid.AUDIO1', 'Sumale esta también'))->assertStatus(200);

        $this->assertSame(412, (int) $this->fila('wamid.AUDIO1')->ai_conversation_id);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 412, 'ai_message_id' => 900], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($this->jobs()[1], $espia);

        $this->assertSame('412', $this->parte($this->posts_al_asistente()[0], 'ai_conversation_id'));
    }

    /**
     * 10. Cuatro fotos y un texto: viajan tres, y la nota dice la verdad.
     *
     * 🔴 Las que quedan afuera por el tope llegaron bien. La nota de siempre ("no se pudo recibir,
     * pedile que la mande de nuevo") sería mentirle al asistente, y le haría pedirle al dueño que
     * reenvíe algo que no falló sin explicarle que el problema es la cantidad.
     *
     * @return void
     */
    public function test_con_mas_fotos_que_el_tope_la_nota_dice_la_verdad(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        for ($i = 1; $i <= 4; $i++) {
            $this->postear_webhook($this->payload_de_foto('wamid.FOTO' . $i, (string) $i))->assertStatus(200);
            Carbon::setTestNow(now()->addSeconds(3));
        }

        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Son las fotos del remito', 'wamid.TEXTO1')
        )->assertStatus(200);

        $jobs      = $this->jobs();
        $del_texto = $jobs[4];
        $this->assertCount(4, $this->imagenes_del_job($del_texto));

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($del_texto, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(3, $this->contar_imagenes($posts[0]));

        $texto = $this->parte($posts[0], 'texto');
        $this->assertStringContainsString('Son las fotos del remito', $texto);
        $this->assertStringContainsString('por este canal llegan hasta 3 por mensaje', $texto);
        $this->assertStringContainsString('una quedó afuera', $texto);
        $this->assertStringNotContainsString('viste', $texto, 'La nota del tope no afirma cuántas vio el asistente.');
        $this->assertStringNotContainsString('no se pudo recibir', $texto);
        $this->assertStringNotContainsString('no se pudieron recibir', $texto);

        $this->assertStringContainsString('Se descartaron 1 foto(s)', (string) $this->fila('wamid.TEXTO1')->error);
    }

    /**
     * 10 ter. Más fotos que el tope Y una de las que entran no se pudo bajar: cada nota dice lo suyo.
     *
     * La del tope cuenta las que quedaron afuera; la de siempre, la que falló. Ninguna afirma
     * cuántas vio el asistente, que acá son dos y no tres.
     *
     * @return void
     */
    public function test_con_el_tope_y_una_descarga_fallida_cada_nota_dice_lo_suyo(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        for ($i = 1; $i <= 4; $i++) {
            $this->postear_webhook($this->payload_de_foto('wamid.FOTO' . $i, (string) $i))->assertStatus(200);
            Carbon::setTestNow(now()->addSeconds(3));
        }

        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Son las fotos del remito', 'wamid.TEXTO1')
        )->assertStatus(200);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            /* La foto 2 no baja ni por su URL ni por su id de Meta (el segundo camino de la descarga). */
            '*/media/foto-2*'      => Http::response('', 500),
            '*media.foto.2*'       => Http::response('', 500),
            '*'                    => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($this->jobs()[4], $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(2, $this->contar_imagenes($posts[0]));

        $texto = $this->parte($posts[0], 'texto');
        $this->assertStringContainsString('una foto que no se pudo recibir', $texto);
        $this->assertStringContainsString('una quedó afuera', $texto);
        $this->assertStringNotContainsString('viste', $texto);
    }

    /**
     * 10 bis. Tres fotos esperando y una cuarta CON epígrafe: la del epígrafe entra siempre.
     *
     * 🔴 Es la que trae la instrucción. Con el orden de llegada a secas, `preparar()` se quedaba con
     * las tres que esperaban y la dejaba afuera justo a ella. Los lugares que sobran se llenan con
     * las reclamadas más viejas, y en el POST el orden sigue siendo el de llegada.
     *
     * @return void
     */
    public function test_la_foto_con_epigrafe_entra_siempre_aunque_se_pase_del_tope(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        for ($i = 1; $i <= 3; $i++) {
            $this->postear_webhook($this->payload_de_foto('wamid.FOTO' . $i, (string) $i))->assertStatus(200);
            Carbon::setTestNow(now()->addSeconds(3));
        }

        $this->postear_webhook(
            $this->payload_de_foto('wamid.FOTO4', '4', 'Cargá esta compra de Distribuidora Norte')
        )->assertStatus(200);

        $jobs      = $this->jobs();
        $de_la_4   = $jobs[3];
        $urls      = array_map(function ($media) {
            return $media['url'];
        }, $this->imagenes_del_job($de_la_4));

        /* Las que entran primero (en orden de llegada) y al final la que queda afuera por el tope. */
        $this->assertSame(
            [$this->url_de_foto('1'), $this->url_de_foto('2'), $this->url_de_foto('4'), $this->url_de_foto('3')],
            $urls
        );

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*/media/foto-1*'      => Http::response($this->png(4), 200, ['Content-Type' => 'image/png']),
            '*/media/foto-2*'      => Http::response($this->png(5), 200, ['Content-Type' => 'image/png']),
            '*/media/foto-3*'      => Http::response($this->png(6), 200, ['Content-Type' => 'image/png']),
            '*/media/foto-4*'      => Http::response($this->png(7), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($de_la_4, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts);
        $this->assertSame(
            [$this->png(4), $this->png(5), $this->png(7)],
            $this->bytes_de_las_imagenes($posts[0]),
            'Viajan las dos más viejas y la del epígrafe, en orden de llegada.'
        );

        $texto = $this->parte($posts[0], 'texto');
        $this->assertStringContainsString('Cargá esta compra de Distribuidora Norte', $texto);
        $this->assertStringContainsString('por este canal llegan hasta 3 por mensaje', $texto);
        $this->assertSame('imagen', $this->parte($posts[0], 'tipo'));
    }

    /**
     * 11. Si el canal se apaga mientras la foto espera, la fila queda en error, sin disculpa y sin
     *     la metadata guardada.
     *
     * @return void
     */
    public function test_apagar_el_canal_mientras_la_foto_espera(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        $client->asistente_whatsapp_activo = false;
        $client->save();

        $this->correr_job($this->jobs()[0], $espia);

        $fila = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertNull($this->media_cruda($fila), 'Una fila muerta no guarda la URL de la foto.');
        $this->assertCount(0, $espia->textos, 'Apagar el canal es una decisión, no una falla: sin disculpa.');
        $this->assertSame([], $this->posts_al_asistente());
        $this->assertCount(1, $this->jobs(), 'Con el canal apagado no se despacha ningún job.');
    }

    /**
     * 11 bis. El canal se apaga ENTRE el temporizador de la primera foto y el de la segunda: la
     *         primera no queda esperando para siempre.
     *
     * 🔴 Es el caso de la foto huérfana. El temporizador de la primera vio una más nueva y le cedió
     * el cierre; el de la segunda encuentra el canal apagado. Si ese corte cerrara solo su fila, la
     * primera quedaría `esperando` con la URL guardada y ningún temporizador volvería por ella.
     *
     * @return void
     */
    public function test_apagar_el_canal_entre_los_temporizadores_no_deja_fotos_huerfanas(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(20));
        $this->postear_webhook($this->payload_de_foto('wamid.FOTO2', '2'))->assertStatus(200);

        list($temporizador_1, $temporizador_2) = $this->jobs();

        Carbon::setTestNow(now()->addSeconds(10));
        $this->correr_job($temporizador_1, $espia);
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $this->fila('wamid.FOTO1')->estado);

        $client->asistente_whatsapp_activo = false;
        $client->save();

        Carbon::setTestNow(now()->addSeconds(20));
        $this->correr_job($temporizador_2, $espia);

        foreach (['wamid.FOTO1', 'wamid.FOTO2'] as $wamid) {
            $foto = $this->fila($wamid);
            $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $foto->estado, $wamid . ' no puede quedar esperando.');
            $this->assertNull($this->media_cruda($foto), $wamid . ' no puede dejar la URL guardada.');
        }

        $this->assertStringContainsString('se apagó', (string) $this->fila('wamid.FOTO1')->error);
        $this->assertCount(0, $espia->textos, 'Con el canal apagado no sale ninguna disculpa, tampoco por la primera.');
        $this->assertSame([], $this->posts_al_asistente());
        $this->assertCount(2, $this->jobs());
    }

    /**
     * 11 ter. El worker da por muerto al temporizador de la última foto: `failed()` cierra también
     *         la anterior, con UNA sola disculpa.
     *
     * @return void
     */
    public function test_el_failed_del_temporizador_de_la_ultima_foto_cierra_la_rafaga(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(20));
        $this->postear_webhook($this->payload_de_foto('wamid.FOTO2', '2'))->assertStatus(200);

        list($temporizador_1, $temporizador_2) = $this->jobs();

        Carbon::setTestNow(now()->addSeconds(10));
        $this->correr_job($temporizador_1, $espia);

        $temporizador_2->failed(new \RuntimeException('El worker dio el job por muerto (simulado en la prueba).'));

        $foto_1 = $this->fila('wamid.FOTO1');
        $foto_2 = $this->fila('wamid.FOTO2');

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $foto_2->estado);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $foto_1->estado, 'La anterior no puede quedar esperando.');
        $this->assertNull($this->media_cruda($foto_1));
        $this->assertNull($this->media_cruda($foto_2));
        $this->assertStringContainsString('#' . $foto_2->id, (string) $foto_1->error);

        $this->assertCount(1, $espia->textos, 'Una sola disculpa por la ráfaga, no una por foto.');
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * 11 quater. El temporizador de la última foto se perdió: el de la primera, que corre tarde,
     *            cierra la ráfaga entera y sale UN solo POST con las dos.
     *
     * La foto más nueva sostiene la ráfaga solo mientras su propio temporizador todavía puede
     * llegar (su espera más un margen de 60 s). Acá la primera corre cuando ya pasó ese
     * vencimiento, así que no le cede nada a nadie.
     *
     * @return void
     */
    public function test_si_el_temporizador_de_la_ultima_foto_se_perdio_cierra_el_de_la_primera(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);
        Carbon::setTestNow(now()->addSeconds(20));
        $this->postear_webhook($this->payload_de_foto('wamid.FOTO2', '2'))->assertStatus(200);

        list($temporizador_1, $temporizador_2) = $this->jobs();

        /* La segunda vencía a los 30 s de llegar; con el margen de 60, un segundo más ya es tarde. */
        Carbon::setTestNow(now()->addSeconds(30 + 60 + 1));
        $this->correr_job($temporizador_1, $espia);

        $foto_1 = $this->fila('wamid.FOTO1');
        $foto_2 = $this->fila('wamid.FOTO2');

        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $foto_1->estado);
        $this->assertSame(ClientAssistantMessage::ESTADO_AGRUPADO, $foto_2->estado);
        $this->assertSame((int) $foto_1->id, $foto_2->agrupado_en_id);
        $this->assertNull($this->media_cruda($foto_1));
        $this->assertNull($this->media_cruda($foto_2));

        $jobs = $this->jobs();
        $this->assertCount(3, $jobs);

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 7, 'ai_message_id' => 9], 202),
            '*/media/foto-1*'      => Http::response($this->png(4), 200, ['Content-Type' => 'image/png']),
            '*/media/foto-2*'      => Http::response($this->png(6), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->correr_job($jobs[2], $espia);

        /* Si el temporizador de la segunda llega igual, sale en silencio. */
        $this->correr_job($temporizador_2, $espia);

        $posts = $this->posts_al_asistente();
        $this->assertCount(1, $posts, 'Un solo POST para toda la ráfaga.');
        $this->assertSame([$this->png(4), $this->png(6)], $this->bytes_de_las_imagenes($posts[0]));
        $this->assertSame('wamid.FOTO1', $this->parte($posts[0], 'whatsapp_message_id'));
        $this->assertCount(3, $this->jobs());
    }

    /**
     * El reclamo del webhook y su despacho son atómicos: si algo falla después de encolar, se
     * deshacen juntos y el mensaje sale UNA vez, por el despacho de repliegue, sin las fotos.
     *
     * Con la cola `database` de verdad (el insert en `jobs` va en la misma transacción) y una falla
     * simulada justo después de encolar. Si el despacho quedara afuera de la transacción, la foto
     * quedaría `agrupado` sin metadata y habría dos jobs del mismo mensaje.
     *
     * @return void
     */
    public function test_si_el_reclamo_falla_despues_de_encolar_el_mensaje_sale_una_vez_sin_las_fotos(): void
    {
        $this->usar_la_cola_de_verdad();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        $this->fallar_una_vez_al_encolar();

        Carbon::setTestNow(now()->addSeconds(10));
        $this->postear_webhook(
            $this->payload_de_texto(self::TELEFONO, 'Cargame este artículo', 'wamid.TEXTO1')
        )->assertStatus(200);

        /* El reclamo se deshizo junto con el job que alcanzó a encolar: la foto sigue esperando. */
        $foto = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $foto->estado);
        $this->assertNull($foto->agrupado_en_id);
        $this->assertNotNull($this->media_cruda($foto));

        $texto = $this->fila('wamid.TEXTO1');
        $del_texto = array_values(array_filter($this->jobs_en_la_tabla(), function ($job) use ($texto) {
            return (int) $this->propiedad($job, 'mensaje_id') === (int) $texto->id;
        }));

        $this->assertCount(1, $del_texto, 'El mensaje tiene que salir una vez, ni cero ni dos.');
        $this->assertSame([], $this->imagenes_del_job($del_texto[0]), 'El repliegue sale sin las fotos.');
        $this->assertCount(2, $this->jobs_en_la_tabla(), 'En `jobs` quedan el temporizador de la foto y el del texto.');
    }

    /**
     * El cierre de la ráfaga del temporizador y su despacho son atómicos: si algo falla después de
     * encolar, no queda en `jobs` un job común para una foto que se cerró con error.
     *
     * @return void
     */
    public function test_si_el_cierre_de_la_rafaga_falla_despues_de_encolar_no_queda_un_job_suelto(): void
    {
        $this->usar_la_cola_de_verdad();
        $espia = $this->espiar_sender();
        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        $jobs = $this->jobs_en_la_tabla();
        $this->assertCount(1, $jobs);
        $temporizador = $jobs[0];
        $this->assertSame(30, $this->segundos_del_job($temporizador));

        $this->fallar_una_vez_al_encolar();

        Carbon::setTestNow(now()->addSeconds(30));
        $this->correr_job($temporizador, $espia);

        $foto = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $foto->estado);
        $this->assertNull($this->media_cruda($foto));
        $this->assertCount(1, $espia->textos, 'La foto que no pudo salir recibe su disculpa.');

        $this->assertCount(
            1,
            $this->jobs_en_la_tabla(),
            'El job común se deshizo junto con el reclamo: en `jobs` queda solo el temporizador.'
        );
    }

    /**
     * 12. La ventana de reintentos del temporizador suma la espera, y un job viejo cae en 600.
     *
     * @return void
     */
    public function test_el_retry_until_del_temporizador_suma_la_espera(): void
    {
        $this->assertTrue(
            now()->addSeconds(630)->equalTo((new EnviarMensajeAlAsistenteJob(1, [], 30))->retryUntil())
        );
        $this->assertTrue(
            now()->addSeconds(600)->equalTo((new EnviarMensajeAlAsistenteJob(1))->retryUntil())
        );

        /* 🔴 Un job serializado ANTES del deploy no trae la propiedad nueva. Se lo reconstruye como
         * lo hace el worker (`__unserialize()` con el payload viejo) y tiene que caer en 0. */
        $datos = (new EnviarMensajeAlAsistenteJob(1, [], 30))->__serialize();
        unset($datos["\0" . EnviarMensajeAlAsistenteJob::class . "\0segundos_de_espera"]);

        $viejo = (new \ReflectionClass(EnviarMensajeAlAsistenteJob::class))->newInstanceWithoutConstructor();
        $viejo->__unserialize($datos);

        $this->assertSame(0, $this->segundos_del_job($viejo));
        $this->assertTrue(now()->addSeconds(600)->equalTo($viejo->retryUntil()));
    }

    /**
     * 13 a. Mismo teléfono, OTRO cliente: la foto que espera en un cliente no se la lleva un
     *       mensaje del otro.
     *
     * Es el dueño de dos comercios con el mismo número. El webhook resuelve un solo cliente por
     * teléfono, así que el caso se arma llamando a `recibir()` con cada cliente: es la frontera que
     * cuida el reclamo, y no depende de cómo el webhook elija.
     *
     * @return void
     */
    public function test_la_foto_de_otro_cliente_con_el_mismo_telefono_no_se_reclama(): void
    {
        $comercio_a = $this->crear_cliente(self::TELEFONO);
        $comercio_b = $this->crear_cliente(self::TELEFONO);

        $servicio = app(AsistenteWhatsappService::class);
        $servicio->recibir($this->parsed_de_foto('wamid.FOTO-B', '1', self::TELEFONO), $comercio_b);
        $servicio->recibir($this->parsed_de_texto('wamid.TEXTO-A', 'Cuánto vendí ayer?', self::TELEFONO), $comercio_a);

        $foto = $this->fila('wamid.FOTO-B');
        $this->assertSame((int) $comercio_b->id, (int) $foto->client_id);
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $foto->estado);
        $this->assertNull($foto->agrupado_en_id);
        $this->assertNotNull($this->media_cruda($foto));

        list(, $del_texto) = $this->jobs();
        $this->assertSame([], $this->imagenes_del_job($del_texto));
    }

    /**
     * 13 b. Mismo cliente, OTRO teléfono: la foto que mandó un número no se pega al mensaje de otro.
     *
     * El dueño es el par cliente + teléfono. Con el mismo cliente y otro número, la foto es de otra
     * persona (o de otro celular) y no tiene nada que ver con lo que escribe este.
     *
     * @return void
     */
    public function test_la_foto_de_otro_telefono_del_mismo_cliente_no_se_reclama(): void
    {
        $client = $this->crear_cliente(self::TELEFONO);

        $servicio = app(AsistenteWhatsappService::class);
        $servicio->recibir($this->parsed_de_foto('wamid.FOTO-OTRO-CEL', '1', '+5493419999999'), $client);
        $servicio->recibir($this->parsed_de_texto('wamid.TEXTO1', 'Cuánto vendí ayer?', self::TELEFONO), $client);

        $foto = $this->fila('wamid.FOTO-OTRO-CEL');
        $this->assertSame(ClientAssistantMessage::ESTADO_ESPERANDO, $foto->estado);
        $this->assertNull($foto->agrupado_en_id);
        $this->assertNotNull($this->media_cruda($foto));

        list(, $del_texto) = $this->jobs();
        $this->assertSame([], $this->imagenes_del_job($del_texto));
    }

    /**
     * Con la espera en 0, la foto sin epígrafe sale en el acto, como antes de esta misión.
     *
     * @return void
     */
    public function test_con_la_espera_en_cero_la_foto_sale_en_el_acto(): void
    {
        config(['services.asistente_whatsapp.segundos_de_espera_de_foto' => 0]);

        $this->crear_cliente(self::TELEFONO);

        $this->postear_webhook($this->payload_de_foto('wamid.FOTO1', '1'))->assertStatus(200);

        $fila = $this->fila('wamid.FOTO1');
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $fila->estado);
        $this->assertNull($this->media_cruda($fila));

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs[0]->delay);
        $this->assertCount(1, $this->imagenes_del_job($jobs[0]));
    }

    /**
     * URL firmada de una foto de prueba, como la manda Kapso.
     *
     * @param string $sufijo
     *
     * @return string
     */
    private function url_de_foto(string $sufijo): string
    {
        return 'https://api.kapso.ai/media/foto-' . $sufijo . '?firma=secreta';
    }

    /**
     * Payload de una foto entrante, con epígrafe y cita opcionales.
     *
     * @param string      $wamid   ID del mensaje.
     * @param string      $sufijo  Para que cada foto tenga su URL.
     * @param string|null $caption Epígrafe, o null para una foto sola.
     * @param string|null $cita    wamid citado, si el mensaje cita alguno.
     *
     * @return array<string, mixed>
     */
    private function payload_de_foto(
        string $wamid,
        string $sufijo,
        ?string $caption = null,
        ?string $cita = null
    ): array {
        $telefono = self::TELEFONO;

        $imagen = [
            'id'        => 'media.foto.' . $sufijo,
            'mime_type' => 'image/jpeg',
            'link'      => $this->url_de_foto($sufijo),
        ];

        if ($caption !== null) {
            $imagen['caption'] = $caption;
        }

        $message = [
            'id'        => $wamid,
            'from'      => $telefono,
            'type'      => 'image',
            'image'     => $imagen,
            'timestamp' => (string) time(),
        ];

        if ($cita !== null) {
            $message['context'] = ['id' => $cita];
        }

        return [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Dueño de prueba'],
            'message'      => $message,
        ];
    }

    /**
     * Payload de una nota de voz ya transcripta por Kapso.
     *
     * @param string $wamid         ID del mensaje.
     * @param string $transcripcion Lo que dijo el dueño.
     *
     * @return array<string, mixed>
     */
    private function payload_de_audio(string $wamid, string $transcripcion): array
    {
        return [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => self::TELEFONO, 'contact_name' => 'Dueño de prueba'],
            'message'      => [
                'id'        => $wamid,
                'from'      => self::TELEFONO,
                'type'      => 'audio',
                'audio'     => ['id' => 'media.audio.' . $wamid, 'mime_type' => 'audio/ogg'],
                'kapso'     => ['transcript' => ['text' => $transcripcion]],
                'timestamp' => (string) time(),
            ],
        ];
    }

    /**
     * Una foto sin epígrafe ya parseada, con la forma que deja `parse_inbound_message()`.
     *
     * Para las pruebas que llaman a `recibir()` sin pasar por el webhook.
     *
     * @param string $wamid    ID del mensaje.
     * @param string $sufijo   Para que cada foto tenga su URL.
     * @param string $telefono Remitente.
     *
     * @return array<string, mixed>
     */
    private function parsed_de_foto(string $wamid, string $sufijo, string $telefono): array
    {
        return [
            'from'                => $telefono,
            'message_id'          => $wamid,
            'type'                => 'image',
            'body'                => null,
            'inbound_media'       => [
                'url'               => $this->url_de_foto($sufijo),
                'mime'              => 'image/jpeg',
                'filename'          => null,
                'whatsapp_media_id' => 'media.foto.' . $sufijo,
            ],
            'reply_to_message_id' => null,
        ];
    }

    /**
     * Un texto ya parseado, con la forma que deja `parse_inbound_message()`.
     *
     * @param string $wamid    ID del mensaje.
     * @param string $texto    Lo que escribió.
     * @param string $telefono Remitente.
     *
     * @return array<string, mixed>
     */
    private function parsed_de_texto(string $wamid, string $texto, string $telefono): array
    {
        return [
            'from'                => $telefono,
            'message_id'          => $wamid,
            'type'                => 'text',
            'body'                => $texto,
            'inbound_media'       => null,
            'reply_to_message_id' => null,
        ];
    }

    /**
     * La fila entrante de un wamid, fresca de la base.
     *
     * @param string $wamid
     *
     * @return ClientAssistantMessage
     */
    private function fila(string $wamid): ClientAssistantMessage
    {
        $fila = ClientAssistantMessage::where('whatsapp_message_id', $wamid)
            ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
            ->first();

        $this->assertNotNull($fila, 'El mensaje ' . $wamid . ' tenía que dejar su fila.');

        return $fila;
    }

    /**
     * Lo que hay en la columna `media_en_espera`, SIN pasar por el cast del modelo.
     *
     * @param ClientAssistantMessage $fila
     *
     * @return string|null
     */
    private function media_cruda(ClientAssistantMessage $fila): ?string
    {
        $crudo = DB::table('client_assistant_messages')->where('id', $fila->id)->value('media_en_espera');

        return $crudo === null ? null : (string) $crudo;
    }

    /**
     * Los jobs del canal que se despacharon, en orden.
     *
     * @return array<int, EnviarMensajeAlAsistenteJob>
     */
    private function jobs(): array
    {
        return Queue::pushed(EnviarMensajeAlAsistenteJob::class)->values()->all();
    }

    /**
     * Vuelve a la cola `database` de verdad: el insert en `jobs` va en la transacción de la prueba.
     *
     * Hace falta para medir atomicidad: con la cola fakeada, un rollback no deshace nada de lo que
     * la cola anotó, y la prueba no podría distinguir un despacho adentro de la transacción de uno
     * afuera.
     *
     * @return void
     */
    private function usar_la_cola_de_verdad(): void
    {
        Queue::swap($this->cola_real);

        $this->ultimo_job_previo = (int) DB::table('jobs')->max('id');
    }

    /**
     * Hace fallar la PRÓXIMA vez que algo se encola, justo después del insert en `jobs`.
     *
     * `JobQueued` se dispara en forma sincrónica apenas la fila quedó insertada, así que tirar ahí
     * es exactamente "se cortó todo después de encolar y antes del commit".
     *
     * @return void
     */
    private function fallar_una_vez_al_encolar(): void
    {
        $ya_fallo = false;

        Event::listen(JobQueued::class, function () use (&$ya_fallo) {
            if ($ya_fallo) {
                return;
            }

            $ya_fallo = true;

            throw new \RuntimeException('Se cortó la base justo después de encolar (simulado en la prueba).');
        });
    }

    /**
     * Los jobs del canal que quedaron en la tabla `jobs` durante esta prueba, en orden.
     *
     * @return array<int, EnviarMensajeAlAsistenteJob>
     */
    private function jobs_en_la_tabla(): array
    {
        $jobs = [];

        $filas = DB::table('jobs')->where('id', '>', $this->ultimo_job_previo)->orderBy('id')->get();
        foreach ($filas as $fila) {
            $payload = json_decode((string) $fila->payload, true);
            $job     = unserialize((string) $payload['data']['command']);

            if ($job instanceof EnviarMensajeAlAsistenteJob) {
                $jobs[] = $job;
            }
        }

        return $jobs;
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
     * La espera con la que se despachó un job.
     *
     * @param EnviarMensajeAlAsistenteJob $job
     *
     * @return int
     */
    private function segundos_del_job(EnviarMensajeAlAsistenteJob $job): int
    {
        return (int) $this->propiedad($job, 'segundos_de_espera');
    }

    /**
     * Lee una propiedad privada del job: lo que viaja en el payload no tiene otra forma de verse.
     *
     * @param EnviarMensajeAlAsistenteJob $job
     * @param string                      $nombre
     *
     * @return mixed
     */
    private function propiedad(EnviarMensajeAlAsistenteJob $job, string $nombre)
    {
        $reflexion = new \ReflectionProperty(EnviarMensajeAlAsistenteJob::class, $nombre);
        $reflexion->setAccessible(true);

        return $reflexion->getValue($job);
    }

    /**
     * Las partes de cada POST que salió al asistente del cliente, en orden.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function posts_al_asistente(): array
    {
        return Http::recorded(function ($request) {
            return $request->method() === 'POST'
                && strpos($request->url(), 'asistente/mensajes') !== false;
        })->map(function ($par) {
            return $par[0]->data();
        })->values()->all();
    }

    /**
     * Cuántas partes `imagenes[]` viajaron en un POST.
     *
     * @param array<int, array<string, mixed>> $partes
     *
     * @return int
     */
    private function contar_imagenes(array $partes): int
    {
        return count($this->bytes_de_las_imagenes($partes));
    }

    /**
     * Los bytes de cada parte `imagenes[]`, en el orden en que viajaron.
     *
     * @param array<int, array<string, mixed>> $partes
     *
     * @return array<int, string>
     */
    private function bytes_de_las_imagenes(array $partes): array
    {
        $bytes = [];
        foreach ($partes as $parte) {
            if (isset($parte['name']) && $parte['name'] === 'imagenes[]') {
                $bytes[] = (string) $parte['contents'];
            }
        }

        return $bytes;
    }

    /**
     * El valor de una parte por nombre.
     *
     * @param array<int, array<string, mixed>> $partes
     * @param string                           $nombre
     *
     * @return string
     */
    private function parte(array $partes, string $nombre): string
    {
        foreach ($partes as $parte) {
            if (isset($parte['name']) && $parte['name'] === $nombre) {
                return (string) $parte['contents'];
            }
        }

        return '';
    }

    /**
     * Bytes de una imagen PNG real: el tipo de cada foto se decide por sus bytes, no por el payload.
     *
     * @param int $lado Lado en píxeles. Tamaños distintos dan bytes distintos, que es lo que deja
     *                  comprobar el orden.
     *
     * @return string
     */
    private function png(int $lado = 4): string
    {
        $imagen = imagecreatetruecolor($lado, $lado);
        ob_start();
        imagepng($imagen);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }
}
