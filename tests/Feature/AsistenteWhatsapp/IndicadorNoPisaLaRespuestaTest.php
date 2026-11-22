<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Services\WhatsappSendService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * El indicador de "escribiendo…" no puede pisar el texto de la respuesta (misión
 * asistente-whatsapp-velocidad, ronda 2, hallazgo 1 de la revisión).
 *
 * Kapso tiene un bloqueo "in-flight" de ~1,2 s por conversación: un segundo envío que llega antes
 * contesta 409. El indicador (read + typing) es un POST a esa misma conversación, así que si el
 * texto sale pegado a él, puede comerse el 409 y la respuesta —que `send_text()` reintenta una sola
 * vez a los 500 ms— se perdería. Dos defensas, las dos medidas acá:
 *
 *   D-A. Separación: antes de CADA texto, si el último indicador de ese teléfono (y número de
 *        salida) fue hace menos de 1,5 s, el job espera lo que falte.
 *   D-B. Reintento: si el texto de la respuesta falla por algo transitorio (409/429/5xx), espera
 *        1,5 s y lo intenta UNA vez más antes de darlo por perdido.
 *
 * El reloj y las pausas del job se controlan desde la prueba (`ahora()` y `pausar()` son
 * `protected` justamente para esto): nada duerme y los tiempos son exactos. Los segundos se eligen
 * con fracciones binarias exactas (0,25 / 0,5) para que la resta de flotantes dé microsegundos
 * redondos.
 */
class IndicadorNoPisaLaRespuestaTest extends BaseDelCanal
{
    /** Pausa de separación y de reintento, en microsegundos (1,5 s). */
    const MAXIMO_US = 1500000;

    /**
     * Reloj del job, en segundos con decimales. Lo mueve cada prueba.
     *
     * @var float
     */
    public $reloj = 1000.0;

    /**
     * Pausas que el job pidió, en microsegundos y en orden.
     *
     * @var array<int, int>
     */
    public $pausas = [];

    /**
     * Cache en memoria (el indicador y su marca viven ahí) y todo en cero.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->crear_config_whatsapp();
        $this->reloj  = 1000.0;
        $this->pausas = [];
    }

    /**
     * Fila entrante tal como la deja el webhook.
     *
     * @param Client $client Cliente dueño del hilo.
     * @param string $wamid  Id del mensaje del dueño.
     *
     * @return ClientAssistantMessage
     */
    private function fila_entrante(Client $client, string $wamid = 'wamid.ENTRANTE1'): ClientAssistantMessage
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = $wamid;
        $fila->tipo                = 'text';
        $fila->texto               = 'Cuánto stock hay de tornillos?';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        return $fila;
    }

    /**
     * Job con reloj y pausas controlados por la prueba, y sin reencolar nada en `release()`.
     *
     * @param int $mensaje_id Fila entrante que tramita.
     *
     * @return EnviarMensajeAlAsistenteJob
     */
    private function job(int $mensaje_id): EnviarMensajeAlAsistenteJob
    {
        return new class($mensaje_id, $this) extends EnviarMensajeAlAsistenteJob {
            /** @var IndicadorNoPisaLaRespuestaTest */
            private $prueba;

            public function __construct(int $mensaje_id, IndicadorNoPisaLaRespuestaTest $prueba)
            {
                parent::__construct($mensaje_id);

                $this->prueba = $prueba;
            }

            protected function ahora(): float
            {
                return $this->prueba->reloj;
            }

            protected function pausar(int $microsegundos): void
            {
                $this->prueba->pausas[] = $microsegundos;
            }

            public function release($delay = 0)
            {
                // No reencola: cada llamada a handle() es una consulta.
            }
        };
    }

    /**
     * La ida (202, pendiente) y la consulta de la respuesta.
     *
     * @param array<string, mixed> $consulta Cuerpo que devuelve la consulta.
     * @param int                  $ida      Status de la ida.
     *
     * @return void
     */
    private function fakear_empresa(array $consulta, int $ida = 202): void
    {
        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(
                $ida === 202
                    ? ['ai_conversation_id' => 91, 'ai_message_id' => 305, 'estado' => 'pendiente']
                    : ['message' => 'invalido'],
                $ida
            ),
            '*/asistente/mensajes/305' => Http::response($consulta, 200),
        ]);
    }

    /**
     * Cuerpo de una respuesta lista.
     *
     * @return array<string, mixed>
     */
    private function listo(): array
    {
        return ['estado' => 'listo', 'contenido' => 'Hay 14 unidades', 'ai_conversation_id' => 91];
    }

    /**
     * Corre la ida de una fila en el segundo indicado del reloj.
     *
     * @param ClientAssistantMessage $fila  Fila entrante.
     * @param WhatsappSendService    $espia Espía del envío.
     * @param float                  $en    Segundo del reloj en que corre la ida.
     *
     * @return EnviarMensajeAlAsistenteJob El job, para correrle después la consulta.
     */
    private function hacer_la_ida(ClientAssistantMessage $fila, WhatsappSendService $espia, float $en): EnviarMensajeAlAsistenteJob
    {
        $this->reloj = $en;
        $job         = $this->job((int) $fila->id);
        $this->correr_job($job, $espia);

        return $job;
    }

    /**
     * D-A caso 1. Un indicador recién pedido a ese teléfono: la respuesta espera lo que falta.
     *
     * M1 espera respuesta; entra M2 (mismo dueño) y su indicador sale a t=1000,5. A t=1000,75 la
     * consulta de M1 encuentra la respuesta: habían pasado 0,25 s, así que faltan 1,25 s.
     *
     * @return void
     */
    public function test_el_texto_espera_lo_que_falta_hasta_1500_ms_tras_el_indicador(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->fakear_empresa($this->listo());

        $fila_uno = $this->fila_entrante($client, 'wamid.M1');
        $fila_dos = $this->fila_entrante($client, 'wamid.M2');

        $job_uno = $this->hacer_la_ida($fila_uno, $espia, 1000.0);
        $this->hacer_la_ida($fila_dos, $espia, 1000.5);
        $this->assertCount(2, $espia->indicadores, 'Cada ida pidió su indicador.');
        $this->assertSame([], $this->pausas, 'Hasta acá nadie esperó nada.');

        $this->reloj = 1000.75;
        $this->correr_job($job_uno, $espia);

        $this->assertSame([1250000], $this->pausas, 'Faltaban 1,25 s para completar los 1,5 s.');
        $this->assertCount(1, $espia->textos, 'La respuesta salió.');
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila_uno->fresh()->estado);
    }

    /**
     * D-A caso 2a. Un indicador viejo (más de 1,5 s) no hace esperar a nadie.
     *
     * @return void
     */
    public function test_con_el_indicador_viejo_no_hay_pausa(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->fakear_empresa($this->listo());

        $fila = $this->fila_entrante($client);
        $job  = $this->hacer_la_ida($fila, $espia, 1000.0);

        $this->reloj = 1002.0;
        $this->correr_job($job, $espia);

        $this->assertSame([], $this->pausas, 'A los 2 s ya no hay nada que separar.');
        $this->assertCount(1, $espia->textos);
    }

    /**
     * D-A caso 2b. El indicador de OTRO teléfono no cuenta: es otra conversación en Kapso.
     *
     * El último indicador al teléfono propio fue a t=1000; el del otro, a t=1000,5. A t=1001,75
     * pasaron 1,75 s para el propio (nada que esperar) y 1,25 s para el ajeno (que no cuenta).
     *
     * @return void
     */
    public function test_el_indicador_de_otro_telefono_no_hace_esperar(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente('+5493411234567');
        $otro   = $this->crear_cliente('+5493417654321');
        $this->fakear_empresa($this->listo());

        $fila_propia = $this->fila_entrante($client, 'wamid.PROPIO');
        $fila_ajena  = $this->fila_entrante($otro, 'wamid.AJENO');

        $job = $this->hacer_la_ida($fila_propia, $espia, 1000.0);
        $this->hacer_la_ida($fila_ajena, $espia, 1000.5);
        $this->assertCount(2, $espia->indicadores);

        $this->reloj = 1001.75;
        $this->correr_job($job, $espia);

        $this->assertSame([], $this->pausas, 'El indicador del otro teléfono no cuenta.');
        $this->assertCount(1, $espia->textos);
    }

    /**
     * D-A caso 3. Un cierre con error justo después de la ida también espera la separación.
     *
     * La empresa contesta 422 a la ida: el indicador ya salió y a continuación sale la disculpa, en
     * el mismo ingreso y con el mismo reloj (0 s de distancia).
     *
     * @return void
     */
    public function test_la_disculpa_tras_un_422_espera_la_separacion(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->fakear_empresa($this->listo(), 422);
        $fila = $this->fila_entrante($client);

        $this->hacer_la_ida($fila, $espia, 1000.0);

        $this->assertCount(1, $espia->indicadores);
        $this->assertSame([self::MAXIMO_US], $this->pausas, 'La disculpa espera los 1,5 s completos.');
        $this->assertCount(1, $espia->textos, 'La disculpa salió.');
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->fresh()->estado);
    }

    /**
     * D-B caso 4. El primer texto da 409 y el segundo sale: un solo mensaje entregado.
     *
     * @return void
     */
    public function test_un_409_en_el_texto_se_reintenta_una_vez_y_sale(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->fakear_empresa($this->listo());
        $fila = $this->fila_entrante($client);

        $job = $this->hacer_la_ida($fila, $espia, 1000.0);

        /* El indicador es viejo para cuando llega la respuesta: la única pausa es la del reintento. */
        $this->reloj                          = 1010.0;
        $espia->falla_texto_transitoria_veces = 1;
        $this->correr_job($job, $espia);

        $this->assertCount(2, $espia->textos, 'Dos intentos: el que dio 409 y el que salió.');
        $this->assertSame([self::MAXIMO_US], $this->pausas, 'Una sola pausa: la del reintento.');

        $fila = $fila->fresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertNull($fila->error, 'Salió al segundo intento: la fila no queda con error.');

        $salientes = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->get();
        $this->assertCount(1, $salientes, 'Una sola fila saliente: la respuesta no se duplica.');
        $this->assertSame('wamid.saliente.2', $salientes[0]->whatsapp_message_id);
    }

    /**
     * D-B caso 5. Un rechazo que no es transitorio no se reintenta.
     *
     * @return void
     */
    public function test_un_rechazo_definitivo_no_se_reintenta(): void
    {
        $espia  = $this->espiar_sender(false);
        $client = $this->crear_cliente();
        $this->fakear_empresa($this->listo());
        $fila = $this->fila_entrante($client);

        $job = $this->hacer_la_ida($fila, $espia, 1000.0);

        $this->reloj = 1010.0;
        $this->correr_job($job, $espia);

        $this->assertCount(1, $espia->textos, 'Un solo intento.');
        $this->assertSame([], $this->pausas, 'Sin pausa de reintento.');
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->fresh()->estado);
        $this->assertNotEmpty($fila->fresh()->error, 'Queda el motivo, como hoy.');
    }

    /**
     * D-B caso 6. Fallan los dos intentos: queda igual que hoy cuando falla el envío.
     *
     * @return void
     */
    public function test_si_fallan_los_dos_intentos_queda_como_hoy(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->fakear_empresa($this->listo());
        $fila = $this->fila_entrante($client);

        $job = $this->hacer_la_ida($fila, $espia, 1000.0);

        $this->reloj                          = 1010.0;
        $espia->falla_texto_transitoria_veces = 2;
        $this->correr_job($job, $espia);

        $this->assertCount(2, $espia->textos, 'Dos intentos y ahí se corta: no hay un tercero.');
        $this->assertSame([self::MAXIMO_US], $this->pausas);

        $fila = $fila->fresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertStringContainsString('409', (string) $fila->error, 'Queda el motivo del último intento.');

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();
        $this->assertNotNull($saliente);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $saliente->estado);
        $this->assertNull($saliente->whatsapp_message_id);
    }
}
