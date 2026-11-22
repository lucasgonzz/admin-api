<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\AsistenteWhatsappService;
use App\Services\SystemErrorWhatsappService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Velocidad del canal del asistente por WhatsApp (misión asistente-whatsapp-velocidad, etapa 1).
 *
 * Lo que se mide acá es que el admin deje de perder segundos DESPUÉS de que la respuesta ya está
 * lista, y que el dueño vea "escribiendo…" mientras espera:
 *
 *   - la escalera de consulta es densa al principio (doce consultas de 2 s) y suma 180 s;
 *   - la respuesta sale en la primera consulta que la encuentra;
 *   - un fallo transitorio nunca se reintenta antes de 5 s, para no golpear un hosting saturado;
 *   - el tope de último recurso (`retryUntil`) alcanza para el peor caso;
 *   - el indicador sale apenas entra el mensaje, por el número por el que entró, se renueva cada
 *     20 s sólo mientras la respuesta está pendiente, y NUNCA rompe ni demora el turno.
 *
 * El indicador pasa por el espía de {@see BaseDelCanal::espiar_sender()} y no por HTTP, así que los
 * `assertSentCount` de las pruebas que ya existen no cambian. El interruptor se lee de
 * `config('asistente_whatsapp.indicador_escribiendo', true)`.
 */
class VelocidadDelCanalTest extends BaseDelCanal
{
    /**
     * Cache en memoria (el indicador usa `Cache::add` para no repetirse) y reloj quieto.
     *
     * El reloj queda fijo porque la renovación cada 20 s se mide contra `now()`: con el reloj andando,
     * una prueba que compara "a los 18 s no, a los 21 s sí" dependería de en qué parte del segundo cayó.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));
    }

    /**
     * Suelta el reloj fijo para no contagiar a las demás pruebas del proceso.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Fila entrante tal como la deja el webhook.
     *
     * @param Client      $client          Cliente dueño del hilo.
     * @param string|null $phone_number_id Número por el que entró el mensaje (null: el principal histórico).
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
        $fila->whatsapp_message_id = 'wamid.ENTRANTE1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Cuánto stock hay de tornillos?';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * Job que anota cada `release()` que pide, para poder comparar la escalera con lo que de verdad se pidió.
     *
     * Corrido derecho (`handle()`), `release()` no encola nada; esta subclase deja a la vista el delay
     * que el job le habría pedido a la cola.
     *
     * @param int $mensaje_id Fila entrante que tramita el job.
     *
     * @return EnviarMensajeAlAsistenteJob El job espía; la lista queda en `$esperas_pedidas`.
     */
    private function job_que_anota_esperas(int $mensaje_id): EnviarMensajeAlAsistenteJob
    {
        return new class($mensaje_id) extends EnviarMensajeAlAsistenteJob {
            /** @var array<int, int> Delays que el job pidió con `release()`, en orden. */
            public $esperas_pedidas = [];

            /**
             * Anota el delay en vez de devolver el job a la cola.
             *
             * @param int $delay Segundos hasta el próximo ingreso.
             *
             * @return void
             */
            public function release($delay = 0)
            {
                $this->esperas_pedidas[] = (int) $delay;
            }
        };
    }

    /**
     * Respuestas del `empresa-api`: la ida (202) y una secuencia de consultas.
     *
     * @param array<int, array<string, mixed>> $consultas Cuerpos de cada consulta, en orden.
     *
     * @return void
     */
    private function fakear_empresa(array $consultas): void
    {
        $secuencia = Http::sequence();
        foreach ($consultas as $cuerpo) {
            $secuencia->push($cuerpo, 200);
        }

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response([
                'ai_conversation_id' => 91,
                'ai_message_id'      => 305,
                'estado'             => 'pendiente',
            ], 202),
            '*/asistente/mensajes/305' => $secuencia,
        ]);
    }

    /**
     * CA1. La escalera es densa al principio y suma 180 s.
     *
     * Doce consultas de 2 s cubren la franja donde caen las respuestas de la mediana y el p90 de los
     * VPS; después se estira hasta el minuto, donde cae el p90 del hosting compartido. La suma sigue
     * siendo 180 porque la empresa supone ese presupuesto (105 s y 165 s medidos desde `created_at`).
     *
     * @return void
     */
    public function test_la_escalera_es_densa_al_principio_y_suma_180(): void
    {
        $esperas = EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING;

        $this->assertSame(180, array_sum($esperas));
        $this->assertCount(23, $esperas);
        $this->assertSame(array_fill(0, 12, 2), array_slice($esperas, 0, 12), 'Las doce primeras esperas valen exactamente 2.');

        for ($i = 1; $i < count($esperas); $i++) {
            $this->assertGreaterThanOrEqual($esperas[$i - 1], $esperas[$i], 'La espera ' . $i . ' no puede ser menor que la anterior.');
        }

        $this->assertLessThanOrEqual(24, array_sum(array_slice($esperas, 0, 12)), 'Tras la consulta 12 el acumulado nominal no pasa de 24 s.');
    }

    /**
     * CA2. La respuesta sale en la primera consulta que la encuentra.
     *
     * Ida 202, dos consultas `pendiente` y la tercera `listo`: las esperas pedidas son `[2, 2, 2]` (la
     * ida y las dos pendientes) y en el cuarto ingreso sale un solo texto.
     *
     * @return void
     */
    public function test_la_respuesta_sale_en_la_primera_consulta_que_la_encuentra(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_empresa([
            ['estado' => 'pendiente', 'contenido' => null, 'ai_conversation_id' => 91],
            ['estado' => 'pendiente', 'contenido' => null, 'ai_conversation_id' => 91],
            ['estado' => 'listo', 'contenido' => 'Hay 14 unidades', 'ai_conversation_id' => 91],
        ]);

        $job = $this->job_que_anota_esperas((int) $fila->id);

        for ($ingreso = 1; $ingreso <= 4; $ingreso++) {
            $this->correr_job($job, $espia);
        }

        $this->assertSame([2, 2, 2], $job->esperas_pedidas, 'La ida y las dos consultas pendientes esperan 2 s cada una.');

        $this->assertCount(1, $espia->textos);
        $this->assertSame('Hay 14 unidades', $espia->textos[0]['body']);
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->fresh()->estado);
    }

    /**
     * CA3. El tope de último recurso alcanza para el peor caso.
     *
     * `retryUntil()` es ahora + 720 s (más la espera de la foto, que se mantiene), y el peor caso con
     * fallos lentos —cada espera con el piso de 5 s y cada ingreso gastando el timeout HTTP más un
     * segundo de toma— entra con 60 s de margen: hoy 603 contra 660.
     *
     * @return void
     */
    public function test_el_tope_de_ultimo_recurso_cubre_el_peor_caso(): void
    {
        $this->assertEquals(
            Carbon::now()->addSeconds(720)->getTimestamp(),
            (new EnviarMensajeAlAsistenteJob(1))->retryUntil()->getTimestamp(),
            'retryUntil() tiene que ser ahora + 720 s.'
        );

        $this->assertEquals(
            Carbon::now()->addSeconds(720 + 30)->getTimestamp(),
            (new EnviarMensajeAlAsistenteJob(1, [], 30))->retryUntil()->getTimestamp(),
            'La espera de la foto se sigue sumando al techo.'
        );

        $constante = EnviarMensajeAlAsistenteJob::class . '::ESPERA_MINIMA_TRAS_FALLO';
        $this->assertTrue(defined($constante), 'Falta la constante ESPERA_MINIMA_TRAS_FALLO.');

        $piso    = (int) constant($constante);
        $esperas = EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING;
        $timeout = (int) config('services.client_api.timeout', 15);

        $suma_de_esperas = 0;
        foreach ($esperas as $espera) {
            $suma_de_esperas += max($espera, $piso);
        }

        $peor_caso = $suma_de_esperas + (count($esperas) + 1) * ($timeout + 1);

        $this->assertLessThanOrEqual(720 - 60, $peor_caso, 'El peor caso (' . $peor_caso . ' s) tiene que entrar en 720 s con 60 s de margen.');
    }

    /**
     * CA4. Un fallo transitorio nunca se reintenta antes de 5 s.
     *
     * Con la empresa contestando 500, un hosting saturado devuelve el error al instante: con la
     * escalera nueva serían doce pedidos en 36 s contra un servidor que ya está caído. Los primeros
     * 13 delays son `[5 × 13]` (max(2,5) doce veces y después max(3,5)), el índice sigue avanzando de
     * a uno —sigue habiendo exactamente `count(ESPERAS)` ingresos sin texto— y la disculpa sale en el
     * siguiente.
     *
     * @return void
     */
    public function test_los_fallos_transitorios_esperan_al_menos_cinco_segundos(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $job     = $this->job_que_anota_esperas((int) $fila->id);
        $esperas = EnviarMensajeAlAsistenteJob::ESPERAS_DE_POLLING;

        for ($ingreso = 1; $ingreso <= 13; $ingreso++) {
            $this->correr_job($job, $espia);
        }

        $this->assertSame(array_fill(0, 13, 5), $job->esperas_pedidas, 'Los primeros 13 delays son de 5 s: el piso pisa a los 2 s y al 3.');

        $this->assertSame(
            (int) constant(EnviarMensajeAlAsistenteJob::class . '::ESPERA_MINIMA_TRAS_FALLO'),
            5,
            'El piso es de 5 s.'
        );

        /* El resto del presupuesto: cada delay es el de la escalera, con el piso. */
        for ($ingreso = 14; $ingreso <= count($esperas); $ingreso++) {
            $this->correr_job($job, $espia);
        }

        $esperado = [];
        foreach ($esperas as $espera) {
            $esperado[] = max($espera, 5);
        }

        $this->assertSame($esperado, $job->esperas_pedidas);
        $this->assertCount(0, $espia->textos, 'Hasta agotar el presupuesto no sale ningún texto.');
        $this->assertSame(ClientAssistantMessage::ESTADO_RECIBIDO, $fila->fresh()->estado);

        /* Se acabó el presupuesto: la disculpa sale en el ingreso siguiente. */
        $this->correr_job($job, $espia);

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->fresh()->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_DE_DISCULPA, $espia->textos[0]['body']);
    }

    /**
     * CA6. El indicador sale en la ida, ANTES del POST a la empresa, por el número por el que entró el mensaje.
     *
     * Se mira el orden desde adentro del fake del POST: en el momento en que el `empresa-api` recibe
     * el mensaje, el indicador ya tiene que estar pedido. Es lo que hace que el dueño vea
     * "escribiendo…" en ≤2 s y no después de bajar las fotos y de esperar la ida.
     *
     * @return void
     */
    public function test_el_indicador_sale_en_la_ida_por_el_numero_de_entrada(): void
    {
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client, self::PHONE_NUMBER_ID_ASISTENTE);

        $indicadores_al_llegar_el_post = null;

        $this->fakear_http([
            '*/asistente/mensajes' => function () use ($espia, &$indicadores_al_llegar_el_post) {
                $indicadores_al_llegar_el_post = count($espia->indicadores);

                return Http::response(['ai_conversation_id' => 91, 'ai_message_id' => 305, 'estado' => 'pendiente'], 202);
            },
        ]);

        $this->correr_job($this->job_que_anota_esperas((int) $fila->id), $espia);

        $this->assertSame(1, $indicadores_al_llegar_el_post, 'El indicador tiene que estar pedido ANTES del POST a la empresa.');
        $this->assertCount(1, $espia->indicadores);
        $this->assertSame($fila->whatsapp_message_id, $espia->indicadores[0]['wamid']);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, $espia->indicadores[0]['phone_number_id'], 'Sale por el número del asistente.');
    }

    /**
     * CA6. Si el mensaje entró por el principal, el indicador sale por el principal.
     *
     * @return void
     */
    public function test_el_indicador_de_un_mensaje_del_principal_sale_por_el_principal(): void
    {
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();

        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client, self::PHONE_NUMBER_ID_PRINCIPAL);

        $this->fakear_empresa([]);

        $this->correr_job($this->job_que_anota_esperas((int) $fila->id), $espia);

        $this->assertCount(1, $espia->indicadores);
        $this->assertSame(self::PHONE_NUMBER_ID_PRINCIPAL, $espia->indicadores[0]['phone_number_id']);
    }

    /**
     * CA6. Con la empresa caída el indicador igual figura: se pide antes de la ida.
     *
     * @return void
     */
    public function test_el_indicador_sale_aunque_la_empresa_este_caida(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $this->fakear_http([
            '*/asistente/mensajes' => function () {
                throw new ConnectionException('Sin conexión (simulado en la prueba).');
            },
        ]);

        $this->correr_job($this->job_que_anota_esperas((int) $fila->id), $espia);

        $this->assertCount(1, $espia->indicadores, 'El indicador sale antes de intentar la ida.');
        $this->assertSame($fila->whatsapp_message_id, $espia->indicadores[0]['wamid']);
    }

    /**
     * CA6. El interruptor apaga el indicador sin tocar el resto del turno.
     *
     * @return void
     */
    public function test_el_interruptor_apaga_el_indicador(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        /* Control: con el interruptor prendido (el valor por defecto) la ida SÍ pide el indicador.
         * Sin esto la prueba pasaría también cuando el indicador no existe. */
        $fila_prendida = $this->fila_entrante($client);
        $this->fakear_empresa([]);
        $this->correr_job($this->job_que_anota_esperas((int) $fila_prendida->id), $espia);
        $this->assertCount(1, $espia->indicadores, 'Prendido, la ida pide el indicador.');

        /* Apagado: ni la ida ni las consultas posteriores lo piden. */
        config(['asistente_whatsapp.indicador_escribiendo' => false]);
        Cache::flush();

        $espia = $this->espiar_sender();
        $fila  = $this->fila_entrante($client);
        $fila->whatsapp_message_id = 'wamid.ENTRANTE2';
        $fila->save();

        $this->fakear_empresa([
            ['estado' => 'pendiente', 'contenido' => null, 'ai_conversation_id' => 91],
            ['estado' => 'listo', 'contenido' => 'Hay 14 unidades', 'ai_conversation_id' => 91],
        ]);

        $job = $this->job_que_anota_esperas((int) $fila->id);

        $this->correr_job($job, $espia);

        /* Pasa el tiempo suficiente para que, prendido, una consulta pendiente renovara el indicador. */
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->correr_job($job, $espia);
        $this->correr_job($job, $espia);

        $this->assertSame([], $espia->indicadores);
        $this->assertCount(1, $espia->textos, 'El turno sale igual.');
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->fresh()->estado);
    }

    /**
     * CA7. El indicador se renueva cada 20 s y sólo mientras la respuesta está pendiente.
     *
     * Ida en t=0: un indicador. Las consultas pendientes de t=2, 4 y 18 no agregan ninguno; la de
     * t=21 agrega el segundo. La consulta que encuentra `listo` (t=45, con la marca de 20 s ya
     * vencida) no agrega otro: mandar la respuesta es lo que apaga el indicador.
     *
     * @return void
     */
    public function test_el_indicador_se_renueva_cada_veinte_segundos_mientras_esta_pendiente(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $fila   = $this->fila_entrante($client);

        $pendiente = ['estado' => 'pendiente', 'contenido' => null, 'ai_conversation_id' => 91];

        $this->fakear_empresa([
            $pendiente,
            $pendiente,
            $pendiente,
            $pendiente,
            ['estado' => 'listo', 'contenido' => 'Hay 14 unidades', 'ai_conversation_id' => 91],
        ]);

        $job    = $this->job_que_anota_esperas((int) $fila->id);
        $inicio = Carbon::now();

        /* t=0: la ida. */
        $this->correr_job($job, $espia);
        $this->assertCount(1, $espia->indicadores, 'La ida pide el primer indicador.');

        /* t=2, 4 y 18: pendiente, el indicador de la ida sigue vivo. */
        foreach ([2, 4, 18] as $segundos) {
            Carbon::setTestNow($inicio->copy()->addSeconds($segundos));
            $this->correr_job($job, $espia);
            $this->assertCount(1, $espia->indicadores, 'A los ' . $segundos . ' s todavía no hay que renovarlo.');
        }

        /* t=21: pendiente y ya pasaron 20 s desde el primero. */
        Carbon::setTestNow($inicio->copy()->addSeconds(21));
        $this->correr_job($job, $espia);
        $this->assertCount(2, $espia->indicadores, 'A los 21 s se renueva.');
        $this->assertSame($fila->whatsapp_message_id, $espia->indicadores[1]['wamid']);

        /* t=45: la consulta encuentra `listo`; ya venció la marca del segundo, y aun así no se pide. */
        Carbon::setTestNow($inicio->copy()->addSeconds(45));
        $this->correr_job($job, $espia);

        $this->assertCount(2, $espia->indicadores, 'La consulta que encuentra la respuesta no pide indicador.');
        $this->assertCount(1, $espia->textos);
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->fresh()->estado);
    }

    /**
     * CA7. Ni el temporizador de la foto en espera ni un mensaje que no se puede leer muestran indicador.
     *
     * @return void
     */
    public function test_sin_indicador_en_el_temporizador_y_en_lo_que_no_se_lee(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        /* Un PDF sin epígrafe: se contesta con el texto fijo, sin ir al sistema del cliente. */
        $pdf       = $this->fila_entrante($client);
        $pdf->tipo  = 'document';
        $pdf->texto = null;
        $pdf->whatsapp_message_id = 'wamid.PDF1';
        $pdf->save();

        $this->correr_job($this->job_que_anota_esperas((int) $pdf->id), $espia);

        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteWhatsappService::TEXTO_NO_SE_PUEDE_LEER, $espia->textos[0]['body'], 'La fila se contestó por el camino de "no se puede leer".');
        $this->assertSame([], $espia->indicadores, 'Un mensaje que no se puede leer no muestra "escribiendo…".');

        /* Una foto sin epígrafe esperando su instrucción: su temporizador cierra la espera. */
        Queue::fake();

        $foto                      = $this->fila_entrante($client);
        $foto->tipo                = 'image';
        $foto->texto               = null;
        $foto->whatsapp_message_id = 'wamid.FOTO1';
        $foto->estado              = ClientAssistantMessage::ESTADO_ESPERANDO;
        $foto->media_en_espera     = [['id' => 'media.foto.1', 'mime_type' => 'image/jpeg', 'link' => 'https://api.kapso.ai/media/foto-1?firma=secreta']];
        $foto->save();

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $foto->id, [], 30), $espia);

        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertSame([], $espia->indicadores, 'El temporizador de la foto no muestra "escribiendo…".');

        /* Control: un texto común, por el mismo camino y el mismo espía, SÍ lo pide. Sin esto la
         * prueba pasaría también cuando el indicador no existe. */
        $texto                      = $this->fila_entrante($client);
        $texto->whatsapp_message_id = 'wamid.TEXTO1';
        $texto->save();

        $this->fakear_empresa([]);
        $this->correr_job($this->job_que_anota_esperas((int) $texto->id), $espia);

        $this->assertCount(1, $espia->indicadores, 'Un texto común sí pide el indicador.');
        $this->assertSame('wamid.TEXTO1', $espia->indicadores[0]['wamid']);
    }

    /**
     * CA8. Un indicador que explota no toca el turno.
     *
     * Ida, una consulta pendiente que intenta renovarlo (pasaron 25 s) y la consulta `listo`: el
     * turno termina `respondido` con el texto enviado, sin error y sin aviso a los admins.
     *
     * @return void
     */
    public function test_un_indicador_que_falla_no_toca_el_turno(): void
    {
        foreach (['explota', 'rechaza'] as $modo) {
            Cache::flush();
            Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00'));

            $espia = $this->espiar_sender();
            if ($modo === 'explota') {
                $espia->explota_indicador = true;
            } else {
                $espia->confirma_indicador = false;
            }

            $this->mock(SystemErrorWhatsappService::class, function ($mock) {
                $mock->shouldNotReceive('notify_send_error');
            });

            $client = $this->crear_cliente();
            $fila   = $this->fila_entrante($client);

            $this->fakear_empresa([
                ['estado' => 'pendiente', 'contenido' => null, 'ai_conversation_id' => 91],
                ['estado' => 'listo', 'contenido' => 'Hay 14 unidades', 'ai_conversation_id' => 91],
            ]);

            $job = $this->job_que_anota_esperas((int) $fila->id);

            $this->correr_job($job, $espia);
            Carbon::setTestNow(Carbon::now()->addSeconds(25));
            $this->correr_job($job, $espia);
            $this->correr_job($job, $espia);

            $fila->refresh();

            $this->assertGreaterThanOrEqual(1, count($espia->indicadores), '[' . $modo . '] El indicador se intentó.');
            $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado, '[' . $modo . '] El turno termina respondido.');
            $this->assertNull($fila->error, '[' . $modo . '] Sin error en la fila.');
            $this->assertCount(1, $espia->textos, '[' . $modo . '] La respuesta salió.');
            $this->assertSame('Hay 14 unidades', $espia->textos[0]['body']);
        }
    }
}
