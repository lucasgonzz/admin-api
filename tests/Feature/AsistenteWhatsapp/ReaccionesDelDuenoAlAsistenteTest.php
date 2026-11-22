<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Jobs\ReenviarReaccionAlAsistenteJob;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * La reacción del dueño (un 👎, una cara enojada) a un mensaje de SU asistente tiene que llegar al
 * `empresa-api` de su sistema, que es donde se valora la respuesta.
 *
 * El admin es quien recibe el webhook de Kapso del número de ComercioCity y el único que sabe qué
 * `wamid` salió del asistente (`client_assistant_messages`), así que acá se decide si una reacción
 * es "del asistente" y se la reenvía con `POST api/admin-sync/asistente/reacciones`. Lo que estas
 * pruebas protegen:
 *
 * 1. Que el reenvío ocurra por los DOS números (el del asistente y el principal si el dueño no
 *    tiene segundo número) y viaje con el contrato exacto: header `X-Admin-Api-Key`, y cuerpo
 *    `whatsapp_message_id`, `emoji` (también `""`, que es "quitó la reacción"), `ai_message_id` y
 *    `reaction_message_id`.
 * 2. Que el camino de las reacciones de LEADS y de soporte siga EXACTAMENTE como hoy.
 * 3. Que el teléfono que reacciona coincida con el de la fila, y que una fila de demo nunca se
 *    reenvíe: sin esa guarda, cualquiera que conozca un wamid valoraría la respuesta de otro.
 * 4. Que la reacción NUNCA dispare un turno del asistente (no crea fila, no despacha el job que
 *    le contesta al dueño).
 * 5. Que el job trate los códigos HTTP igual que `AsistenteAvisosService`: 404/401/403/409/422 son
 *    "log y fin" (empresa viejo, clave, sin módulo, dueño no resuelto, cuerpo rechazado) y 5xx o
 *    caída de red se reintenta.
 *
 * Se le pega al webhook real, con firma HMAC y ruteo de verdad; lo único falso es la red.
 */
class ReaccionesDelDuenoAlAsistenteTest extends BaseDelCanal
{
    /**
     * Teléfono del dueño, tal cual lo manda Meta (sin `+`).
     */
    const TELEFONO_DUENO = '5493415551234';

    /**
     * URL del endpoint del `empresa-api` del cliente de prueba (hosting compartido → `/public`).
     */
    const URL_EMPRESA = 'https://api-ferreteria-de-prueba.test/public/api/admin-sync/asistente/reacciones';

    /**
     * Arranca sin rastros de otras pruebas en la caché (idempotencia del webhook, etc.).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Cliente con `ClientApi` y clave, listo para recibir el reenvío.
     *
     * @return Client
     */
    private function cliente_con_api(): Client
    {
        $client = $this->crear_cliente('+' . self::TELEFONO_DUENO, true, 'clave-del-cliente');
        $this->crear_client_api($client);

        return $client;
    }

    /**
     * Mensaje saliente del asistente, como lo deja `registrar_saliente()`.
     *
     * @param Client               $client Dueño del hilo.
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return ClientAssistantMessage
     */
    private function saliente_del_asistente(Client $client, array $campos = []): ClientAssistantMessage
    {
        return ClientAssistantMessage::create(array_merge([
            'client_id'           => $client->id,
            'telefono'            => '+' . self::TELEFONO_DUENO,
            'direccion'           => ClientAssistantMessage::DIRECCION_SALIENTE,
            'whatsapp_message_id' => 'wamid.SAL1',
            'ai_message_id'       => 777,
            'tipo'                => 'text',
            'texto'               => 'Vendiste $1.000 ayer',
            'estado'              => ClientAssistantMessage::ESTADO_ENVIADO,
        ], $campos));
    }

    /**
     * Payload de Kapso de una reacción nativa.
     *
     * @param string      $emoji           Emoji de la reacción ('' = la quitó).
     * @param string      $objetivo        wamid del mensaje reaccionado.
     * @param string      $telefono        Quien reacciona.
     * @param string|null $phone_number_id Número que recibió el evento (por defecto el principal, como lo manda Kapso).
     * @param string      $id_del_evento   wamid del evento de reacción.
     *
     * @return array<string, mixed>
     */
    private function payload_de_reaccion(
        string $emoji,
        string $objetivo = 'wamid.SAL1',
        string $telefono = self::TELEFONO_DUENO,
        ?string $phone_number_id = self::PHONE_NUMBER_ID_PRINCIPAL,
        string $id_del_evento = 'wamid.REACT1'
    ): array {
        $payload = [
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => '+' . $telefono],
            'message'      => [
                'id'        => $id_del_evento,
                'from'      => $telefono,
                'timestamp' => '1763790000',
                'type'      => 'reaction',
                'reaction'  => ['message_id' => $objetivo, 'emoji' => $emoji],
            ],
        ];

        if ($phone_number_id !== null) {
            $payload['phone_number_id'] = $phone_number_id;
        }

        return $payload;
    }

    /**
     * Saca de la cola el job de reenvío que despachó el webhook y lo corre derecho.
     *
     * @return void
     */
    private function correr_el_reenvio_despachado(): void
    {
        $job = Queue::pushed(ReenviarReaccionAlAsistenteJob::class)->first();
        $this->assertNotNull($job, 'El webhook tenía que despachar el reenvío.');

        $job->handle(app(ClientEmpresaApiUrlResolver::class));
    }

    /**
     * Job armado a mano, con un job de cola falso para espiar `release()`.
     *
     * @param ClientAssistantMessage $fila  Saliente reaccionado.
     * @param string                 $emoji Emoji de la reacción.
     *
     * @return array{0: ReenviarReaccionAlAsistenteJob, 1: \Mockery\MockInterface}
     */
    private function job_con_cola_falsa(ClientAssistantMessage $fila, string $emoji = '👎'): array
    {
        $job      = new ReenviarReaccionAlAsistenteJob((int) $fila->id, $emoji, 'wamid.SAL1', 'wamid.REACT1');
        $cola     = \Mockery::mock(QueueJob::class)->shouldIgnoreMissing();
        $job->setJob($cola);

        return [$job, $cola];
    }

    // ---------------------------------------------------------------------------------------------
    // CA-A1 / CA-A2 / CA-A3: el reenvío
    // ---------------------------------------------------------------------------------------------

    /**
     * 😡 por el número del ASISTENTE → POST al empresa-api con el contrato exacto.
     *
     * @return void
     */
    public function test_reaccion_por_el_numero_del_asistente_se_reenvia_al_empresa(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->cliente_con_api();
        $this->saliente_del_asistente($client);

        $payload = $this->payload_de_reaccion('😡', 'wamid.SAL1', self::TELEFONO_DUENO, self::PHONE_NUMBER_ID_ASISTENTE);

        $this->postear_webhook($payload, self::SECRETO_DEL_ASISTENTE)->assertStatus(200);

        Queue::assertPushed(ReenviarReaccionAlAsistenteJob::class, 1);
        $this->correr_el_reenvio_despachado();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === self::URL_EMPRESA
                && $request->method() === 'POST'
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $request->data() === [
                    'whatsapp_message_id' => 'wamid.SAL1',
                    'emoji'               => '😡',
                    'ai_message_id'       => 777,
                    'reaction_message_id' => 'wamid.REACT1',
                ];
        });
    }

    /**
     * El dueño sin segundo número habla con el asistente por el principal: mismo reenvío, y no se
     * toca ningún hilo de lead ni de soporte.
     *
     * @return void
     */
    public function test_reaccion_por_el_numero_principal_al_asistente_se_reenvia(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_api();
        $this->saliente_del_asistente($client);

        $leads    = LeadMessage::count();
        $soportes = SupportMessage::count();

        $this->postear_webhook($this->payload_de_reaccion('👎'))->assertStatus(200);

        Queue::assertPushed(ReenviarReaccionAlAsistenteJob::class, 1);
        $this->correr_el_reenvio_despachado();

        Http::assertSent(function ($request) {
            return $request->url() === self::URL_EMPRESA
                && ($request->data()['emoji'] ?? null) === '👎'
                && ($request->data()['whatsapp_message_id'] ?? null) === 'wamid.SAL1';
        });
        $this->assertSame($leads, LeadMessage::count());
        $this->assertSame($soportes, SupportMessage::count());
    }

    /**
     * Quitar la reacción viaja con `emoji` vacío: no se descarta ni se manda null.
     *
     * @return void
     */
    public function test_quitar_la_reaccion_viaja_con_emoji_vacio(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_api();
        $this->saliente_del_asistente($client);

        $this->postear_webhook($this->payload_de_reaccion(''))->assertStatus(200);

        Queue::assertPushed(ReenviarReaccionAlAsistenteJob::class, 1);
        $this->correr_el_reenvio_despachado();

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return $request->url() === self::URL_EMPRESA
                && array_key_exists('emoji', $cuerpo)
                && $cuerpo['emoji'] === '';
        });
    }

    // ---------------------------------------------------------------------------------------------
    // CA-A4: lo que no es del asistente sigue como hoy
    // ---------------------------------------------------------------------------------------------

    /**
     * Una reacción a un mensaje de LEAD por el principal se aplica al lead como siempre; una a un
     * wamid desconocido por el número del asistente no hace nada. En ninguno hay job ni HTTP.
     *
     * @return void
     */
    public function test_reaccion_a_un_lead_o_desconocida_sigue_el_camino_de_hoy(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $this->cliente_con_api();

        $telefono_lead = '5493416660001';
        $lead               = new Lead();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa del lead';
        $lead->phone        = $telefono_lead;
        $lead->status       = 'contactado';
        $lead->save();

        $mensaje = LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'admin',
            'content'             => 'Hola, ¿viste la demo?',
            'status'              => 'enviado',
            'whatsapp_message_id' => 'wamid.DELLEAD1',
            'sent_at'             => now(),
        ]);

        // Por el número principal: la reacción del lead se aplica como hoy.
        $this->postear_webhook($this->payload_de_reaccion('👍', 'wamid.DELLEAD1', $telefono_lead, self::PHONE_NUMBER_ID_PRINCIPAL, 'wamid.REACTLEAD'))
            ->assertStatus(200);

        $this->assertSame('👍', (string) $mensaje->fresh()->lead_reaction_emoji);

        // Por el número del asistente, a un wamid que nadie conoce: 200 y nada más.
        $this->postear_webhook(
            $this->payload_de_reaccion('😡', 'wamid.NADIE', self::TELEFONO_DUENO, self::PHONE_NUMBER_ID_ASISTENTE, 'wamid.REACTX'),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        Queue::assertNotPushed(ReenviarReaccionAlAsistenteJob::class);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------------
    // CA-A5: guarda de teléfono y de demos
    // ---------------------------------------------------------------------------------------------

    /**
     * El teléfono que reacciona tiene que ser el de la fila; y una fila de demo no se reenvía nunca.
     *
     * @return void
     */
    public function test_telefono_ajeno_o_demo_no_se_reenvia(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_api();

        // 1. El wamid es del asistente, pero reacciona OTRA persona.
        $this->saliente_del_asistente($client);
        $this->postear_webhook($this->payload_de_reaccion('👎', 'wamid.SAL1', '5493419998877'))->assertStatus(200);

        // 2. Hilo de demo de un lead (client_id = 0): aunque el teléfono coincida.
        $this->saliente_del_asistente($client, [
            'client_id'           => 0,
            'whatsapp_message_id' => 'wamid.DEMO1',
        ]);
        $this->postear_webhook($this->payload_de_reaccion('👎', 'wamid.DEMO1', self::TELEFONO_DUENO, self::PHONE_NUMBER_ID_PRINCIPAL, 'wamid.REACTD1'))
            ->assertStatus(200);

        // 3. Fila con instancia de demo (demo_id > 0), aunque tenga client_id.
        $this->saliente_del_asistente($client, [
            'demo_id'             => 5,
            'whatsapp_message_id' => 'wamid.DEMO2',
        ]);
        $this->postear_webhook($this->payload_de_reaccion('👎', 'wamid.DEMO2', self::TELEFONO_DUENO, self::PHONE_NUMBER_ID_PRINCIPAL, 'wamid.REACTD2'))
            ->assertStatus(200);

        // 4. Un mensaje ENTRANTE del dueño con ese wamid no es un saliente del asistente.
        $this->saliente_del_asistente($client, [
            'direccion'           => ClientAssistantMessage::DIRECCION_ENTRANTE,
            'whatsapp_message_id' => 'wamid.ENT1',
        ]);
        $this->postear_webhook($this->payload_de_reaccion('👎', 'wamid.ENT1', self::TELEFONO_DUENO, self::PHONE_NUMBER_ID_PRINCIPAL, 'wamid.REACTE1'))
            ->assertStatus(200);

        Queue::assertNotPushed(ReenviarReaccionAlAsistenteJob::class);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------------
    // CA-A6: la reacción no dispara el asistente
    // ---------------------------------------------------------------------------------------------

    /**
     * La reacción no crea ninguna fila del hilo ni despacha el job que le contesta al dueño.
     *
     * @return void
     */
    public function test_la_reaccion_no_crea_turno_del_asistente(): void
    {
        Queue::fake();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        $client = $this->cliente_con_api();
        $this->saliente_del_asistente($client);

        $filas_antes = ClientAssistantMessage::count();

        $this->postear_webhook(
            $this->payload_de_reaccion('😡', 'wamid.SAL1', self::TELEFONO_DUENO, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);

        Queue::assertPushed(ReenviarReaccionAlAsistenteJob::class, 1);
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertSame($filas_antes, ClientAssistantMessage::count(), 'La reacción no es un mensaje del hilo.');
    }

    // ---------------------------------------------------------------------------------------------
    // CA-A7: códigos HTTP del empresa-api
    // ---------------------------------------------------------------------------------------------

    /**
     * 404/401/403/409/422 → una sola request, sin `release`, sin excepción. 500, 503 y caída de red
     * → se pide reintento. Con `api_key` vacía no sale ninguna request.
     *
     * @return void
     */
    public function test_respuestas_del_empresa_404_4xx_5xx_y_sin_clave(): void
    {
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_api();
        $fila   = $this->saliente_del_asistente($client);
        $resolver = app(ClientEmpresaApiUrlResolver::class);

        // Respuestas definitivas: se registra y se termina.
        foreach ([404, 401, 403, 409, 422] as $codigo) {
            $this->fakear_http(['*' => Http::response(['message' => 'x'], $codigo)]);
            list($job, $cola) = $this->job_con_cola_falsa($fila);
            $cola->shouldNotReceive('release');

            $job->handle($resolver);

            Http::assertSentCount(1);
        }

        // 200 con aplicada:false (mensaje desconocido): también termina.
        $this->fakear_http(['*' => Http::response(['ok' => true, 'aplicada' => false, 'motivo' => 'mensaje_desconocido'], 200)]);
        list($job, $cola) = $this->job_con_cola_falsa($fila);
        $cola->shouldNotReceive('release');
        $job->handle($resolver);
        Http::assertSentCount(1);

        // Transitorias: se pide reintento.
        foreach ([500, 502, 503] as $codigo) {
            $this->fakear_http(['*' => Http::response('caído', $codigo)]);
            list($job, $cola) = $this->job_con_cola_falsa($fila);
            $cola->shouldReceive('release')->once();

            $job->handle($resolver);

            Http::assertSentCount(1);
        }

        // Caída de red: también se reintenta, sin que la excepción salga del job.
        $this->fakear_http(['*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout simulado');
        }]);
        list($job, $cola) = $this->job_con_cola_falsa($fila);
        $cola->shouldReceive('release')->once();
        $job->handle($resolver);

        // Sin clave contra el empresa-api: no se llama a nadie.
        $client->api_key = '';
        $client->save();
        $this->fakear_http();
        list($job, $cola) = $this->job_con_cola_falsa($fila);
        $cola->shouldNotReceive('release');
        $job->handle($resolver);
        Http::assertNothingSent();
    }
}
