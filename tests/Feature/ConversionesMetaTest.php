<?php

namespace Tests\Feature;

use App\Helpers\WhatsappNormalizer;
use App\Jobs\EnviarEventoMetaJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use App\Services\MetaConversionesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

/**
 * Las conversiones de los leads de anuncio a Meta por la Conversions API for Business Messaging
 * (misión origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * Lo que se protege: que cada evento se registre al alcanzar su etapa (o una posterior), una sola
 * vez y en orden; que el POST a Meta salga con la URL, el header y el body exactos del contrato; que
 * sin configuración no salga NINGUNA llamada; que un rechazo de Meta quede escrito; que el
 * conciliador levante lo que el observer no ve; y que el token no aparezca nunca en un log ni en una
 * fila.
 */
class ConversionesMetaTest extends TestCase
{
    use DatabaseTransactions;

    /** Token de prueba: si aparece en un log o en una fila, el test falla. */
    private const TOKEN = 'EAAG-token-de-prueba-capi-SECRETO-1234567890';

    /** Dataset de prueba. */
    private const DATASET = '998877665544332';

    /** WABA por defecto del config. */
    private const WABA = '1290129236552587';

    /** Click id de prueba. */
    private const CLID = 'ARAkLkA-clid-de-prueba-conversiones';

    /**
     * Configuración completa y cola falseada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config([
            'services.meta_capi.token'           => self::TOKEN,
            'services.meta_capi.dataset_id'      => self::DATASET,
            'services.meta_capi.waba_id'         => self::WABA,
            'services.meta_capi.graph_version'   => 'v24.0',
            'services.meta_capi.test_event_code' => '',
            'services.meta_capi.timeout'         => 15,
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Lead que vino de un anuncio (con ctwa_clid), en el estado pedido.
     *
     * @param string      $status
     * @param string|null $clid
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'nuevo', ?string $clid = self::CLID): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Lead de anuncio ' . Str::random(5);
        $lead->phone        = WhatsappNormalizer::normalize('+54911' . random_int(10000000, 99999999));
        $lead->status       = $status;
        $lead->origen       = $clid !== null ? 'anuncio_whatsapp' : 'desconocido';
        $lead->ctwa_clid    = $clid;
        $lead->save();

        return $lead;
    }

    /**
     * Eventos registrados del lead, en orden.
     *
     * @param Lead $lead
     *
     * @return array<int, string>
     */
    private function eventos_de(Lead $lead): array
    {
        return LeadEventoMeta::query()->where('lead_id', $lead->id)->orderBy('id')->pluck('evento')->all();
    }

    /**
     * Id de la fila que lleva un job encolado (la propiedad es privada a propósito).
     *
     * @param EnviarEventoMetaJob $job
     *
     * @return int
     */
    private function id_del_job(EnviarEventoMetaJob $job): int
    {
        $propiedad = new \ReflectionProperty(EnviarEventoMetaJob::class, 'lead_evento_meta_id');
        $propiedad->setAccessible(true);

        return (int) $propiedad->getValue($job);
    }

    /**
     * Corre el job como lo correría el worker.
     *
     * @param LeadEventoMeta $fila
     *
     * @return void
     */
    private function correr_job(LeadEventoMeta $fila): void
    {
        (new EnviarEventoMetaJob((int) $fila->id))->handle(app(MetaConversionesService::class));
    }

    /**
     * Reemplaza el log por un registro propio que acepta `Log::channel('daily')`.
     *
     * @return AbstractLogger Con `$lineas`: "nivel mensaje {contexto}".
     */
    private function capturar_el_log(): AbstractLogger
    {
        $registro = new class extends AbstractLogger {
            /** @var array<int, string> */
            public $lineas = [];

            /** @var array<int, string> */
            public $niveles = [];

            public function channel($nombre = null)
            {
                return $this;
            }

            public function log($level, $message, array $context = [])
            {
                $this->niveles[] = (string) $level;
                $this->lineas[]  = $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        };

        Log::swap($registro);

        return $registro;
    }

    /**
     * Ninguna línea de log lleva el token.
     *
     * @param AbstractLogger $registro
     *
     * @return void
     */
    private function assertLogSinElToken(AbstractLogger $registro): void
    {
        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString(self::TOKEN, $linea, 'Una línea de log lleva el token de Meta.');
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El registro
     |----------------------------------------------------------------------------------------- */

    /**
     * Lead de anuncio que pasa a calificado: se registra LeadSubmitted y se encola su envío por la
     * conexión database.
     *
     * @return void
     */
    public function test_al_pasar_a_calificado_se_registra_lead_submitted_y_se_encola(): void
    {
        $lead = $this->crear_lead('nuevo');
        $this->assertSame([], $this->eventos_de($lead), 'En nuevo no se alcanzó ningún evento.');

        $lead->status = 'calificado';
        $lead->save();

        $this->assertSame(['LeadSubmitted'], $this->eventos_de($lead));

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->first();
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $fila->estado);
        $this->assertSame('cc-lead-' . $lead->id . '-leadsubmitted', $fila->event_id);
        $this->assertSame(0, $fila->intentos);
        $this->assertNotNull($fila->event_time);

        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila) {
            return $job->connection === 'database' && $this->id_del_job($job) === (int) $fila->id;
        });
        Queue::assertPushed(EnviarEventoMetaJob::class, 1);
    }

    /**
     * El job manda UN POST con la URL, el header Bearer y el body exactos del contrato, y la fila
     * queda enviada con la respuesta recortada.
     *
     * @return void
     */
    public function test_el_job_manda_el_post_exacto_y_la_fila_queda_enviada(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'AbCdEf123'], 200),
        ]);
        $registro = $this->capturar_el_log();

        $lead         = $this->crear_lead('nuevo');
        $lead->status = 'calificado';
        $lead->save();

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->correr_job($fila);

        $esperado = [
            'data' => [[
                'event_name'        => 'LeadSubmitted',
                'event_time'        => $fila->fresh()->event_time->getTimestamp(),
                'event_id'          => 'cc-lead-' . $lead->id . '-leadsubmitted',
                'action_source'     => 'business_messaging',
                'messaging_channel' => 'whatsapp',
                'user_data'         => [
                    'whatsapp_business_account_id' => self::WABA,
                    'ctwa_clid'                    => self::CLID,
                ],
            ]],
        ];

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($esperado) {
            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v24.0/' . self::DATASET . '/events'
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && strpos($request->url(), self::TOKEN) === false
                && $request->data() === $esperado;
        });

        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $fila->estado);
        $this->assertNotNull($fila->enviado_at);
        $this->assertNull($fila->ultimo_error);
        $this->assertSame(1, $fila->intentos);
        /* Clave por clave y no el array entero: MySQL 8 reordena las claves de una columna `json`
           al guardarla (entra events_received, messages, fbtrace_id y sale en otro orden). El
           contenido se verifica igual de estricto: cada valor con su tipo, y ninguna clave de más. */
        $respuesta = $fila->respuesta;
        $this->assertIsArray($respuesta);
        $this->assertSame(1, $respuesta['events_received']);
        $this->assertSame('AbCdEf123', $respuesta['fbtrace_id']);
        $this->assertSame([], $respuesta['messages']);
        $claves = array_keys($respuesta);
        sort($claves);
        $this->assertSame(['events_received', 'fbtrace_id', 'messages'], $claves, 'La respuesta guardada no puede traer claves de más.');

        /* Correrlo otra vez no manda nada: ya salió. */
        $this->correr_job($fila);
        Http::assertSentCount(1);

        $this->assertLogSinElToken($registro);
    }

    /**
     * Con `test_event_code` configurado viaja en el body; sin él, no.
     *
     * @return void
     */
    public function test_el_codigo_de_prueba_viaja_solo_si_esta_configurado(): void
    {
        config(['services.meta_capi.test_event_code' => 'TEST12345']);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'x'], 200),
        ]);

        $lead = $this->crear_lead('calificado');
        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());

        Http::assertSent(function ($request) {
            return ($request->data()['test_event_code'] ?? null) === 'TEST12345';
        });
    }

    /**
     * Idempotente: un segundo guardado (con o sin cambio de etapa dentro del mismo evento) no
     * registra ni encola nada nuevo.
     *
     * @return void
     */
    public function test_un_segundo_guardado_no_registra_nada_nuevo(): void
    {
        $lead         = $this->crear_lead('nuevo');
        $lead->status = 'calificado';
        $lead->save();

        $lead->notes = 'Una nota cualquiera';
        $lead->save();

        $lead->status = 'solicita_disponibilidad';
        $lead->save();

        $this->assertSame(['LeadSubmitted'], $this->eventos_de($lead));
        Queue::assertPushed(EnviarEventoMetaJob::class, 1);

        $this->assertSame([], app(MetaConversionesService::class)->registrar_alcanzados($lead->fresh()));
    }

    /**
     * El salto de nuevo a demo_agendada (la demo directa) manda los dos eventos, en orden.
     *
     * @return void
     */
    public function test_el_salto_a_demo_agendada_registra_los_dos_eventos_en_orden(): void
    {
        $lead         = $this->crear_lead('nuevo');
        $lead->status = 'demo_agendada';
        $lead->save();

        $this->assertSame(['LeadSubmitted', 'QualifiedLead'], $this->eventos_de($lead));
        Queue::assertPushed(EnviarEventoMetaJob::class, 2);
    }

    /**
     * La promoción a cliente manda Purchase con la moneda y el valor del contrato.
     *
     * @return void
     */
    public function test_la_promocion_registra_purchase_con_moneda_y_valor_del_contrato(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'x'], 200),
        ]);

        $lead = $this->crear_lead('closer_activo');
        $this->assertSame(['LeadSubmitted', 'QualifiedLead'], $this->eventos_de($lead), 'Un lead que nace en closer_activo ya alcanzó los dos.');

        $cliente                  = new Client();
        $cliente->name            = 'Cliente de prueba ' . Str::random(5);
        $cliente->company_name    = 'Negocio de prueba';
        $cliente->slug            = 'conversiones-' . Str::lower(Str::random(10));
        $cliente->api_url         = 'https://ejemplo.test';
        $cliente->api_key         = 'clave-sembrada-' . Str::random(10);
        $cliente->inbound_api_key = 'clave-sembrada-inbound-' . Str::random(10);
        $cliente->is_active       = true;
        $cliente->save();

        $lead->contract_currency        = 'usd';
        $lead->contract_precio_licencia = '2.500';
        $lead->promoted_client_id       = $cliente->id;
        $lead->save();

        $this->assertSame(['LeadSubmitted', 'QualifiedLead', 'Purchase'], $this->eventos_de($lead));

        $purchase = LeadEventoMeta::query()->where('lead_id', $lead->id)->where('evento', 'Purchase')->firstOrFail();
        $this->correr_job($purchase);

        Http::assertSent(function ($request) use ($lead) {
            $dato = $request->data()['data'][0];

            return $dato['event_name'] === 'Purchase'
                && $dato['event_id'] === 'cc-lead-' . $lead->id . '-purchase'
                && $dato['custom_data'] === ['currency' => 'USD', 'value' => 2500];
        });
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $purchase->fresh()->estado);
    }

    /**
     * Sin ctwa_clid no hay a quién atribuir: no se registra nada.
     *
     * @return void
     */
    public function test_un_lead_sin_ctwa_clid_no_registra_nada(): void
    {
        $lead         = $this->crear_lead('nuevo', null);
        $lead->status = 'demo_agendada';
        $lead->save();

        $this->assertSame([], $this->eventos_de($lead));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Sin configuración, con rechazo, y sin el token a la vista
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin token: el evento queda sin_configurar, con un warning, sin encolar y sin NINGUNA llamada
     * HTTP (tampoco si alguien corre el envío a mano).
     *
     * @return void
     */
    public function test_sin_token_queda_sin_configurar_y_no_sale_ninguna_llamada(): void
    {
        config(['services.meta_capi.token' => '']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $registro = $this->capturar_el_log();

        $lead         = $this->crear_lead('nuevo');
        $lead->status = 'calificado';
        $lead->save();

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila->estado);
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, app(MetaConversionesService::class)->enviar($fila));
        Http::assertNothingSent();

        $warnings = array_filter($registro->lineas, function ($linea) {
            return strpos($linea, 'warning') === 0 && strpos($linea, 'META_CAPI_TOKEN') !== false;
        });
        $this->assertNotEmpty($warnings, 'Sin configuración tiene que quedar un warning en el log.');
    }

    /**
     * Sin dataset: lo mismo.
     *
     * @return void
     */
    public function test_sin_dataset_queda_sin_configurar_y_no_sale_ninguna_llamada(): void
    {
        config(['services.meta_capi.dataset_id' => '   ']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $this->capturar_el_log();

        $lead = $this->crear_lead('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila->estado);
        $this->correr_job($fila);

        Http::assertNothingSent();
        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila->fresh()->estado);
    }

    /**
     * Meta contesta 400: la fila queda en error con el mensaje de Meta y el código, sin el token.
     *
     * @return void
     */
    public function test_un_rechazo_de_meta_queda_en_error_con_el_mensaje(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message'       => 'Invalid parameter',
                    'type'          => 'OAuthException',
                    'code'          => 100,
                    'error_subcode' => 2804003,
                    'fbtrace_id'    => 'XyZ987',
                ],
            ], 400),
        ]);
        $registro = $this->capturar_el_log();

        $lead = $this->crear_lead('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, app(MetaConversionesService::class)->enviar($fila));

        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('HTTP 400', (string) $fila->ultimo_error);
        $this->assertStringContainsString('Invalid parameter', (string) $fila->ultimo_error);
        $this->assertSame(100, $fila->respuesta['error']['code']);
        $this->assertSame(2804003, $fila->respuesta['error']['error_subcode']);
        $this->assertSame(1, $fila->intentos);
        $this->assertNull($fila->enviado_at);

        $this->assertLogSinElToken($registro);
    }

    /**
     * 🔴 Una caída de red cuyo mensaje trae el token (Guzzle copia la URI en sus excepciones) no lo
     * deja ni en la fila ni en el log.
     *
     * @return void
     */
    public function test_ninguna_excepcion_deja_el_token_en_la_fila_ni_en_el_log(): void
    {
        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28: Operation timed out for ' . $request->url() . '?access_token=' . self::TOKEN);
        });
        $registro = $this->capturar_el_log();

        $lead = $this->crear_lead('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->correr_job($fila);

        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $fila->estado);
        $this->assertStringNotContainsString(self::TOKEN, (string) $fila->ultimo_error);
        $this->assertStringContainsString('[token]', (string) $fila->ultimo_error);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($fila->respuesta));

        $this->assertNotEmpty($registro->lineas);
        $this->assertLogSinElToken($registro);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El conciliador
     |----------------------------------------------------------------------------------------- */

    /**
     * El conciliador registra los eventos de un cambio de estado hecho con un UPDATE directo (el que
     * usa la demo directa, que esquiva el observer) y reencola un error con intentos disponibles.
     *
     * @return void
     */
    public function test_el_conciliador_registra_lo_que_el_observer_no_vio_y_reintenta_errores(): void
    {
        $lead = $this->crear_lead('nuevo');
        Lead::query()->whereKey($lead->id)->update(['status' => 'demo_agendada']);
        $this->assertSame([], $this->eventos_de($lead), 'El UPDATE directo no pasa por el observer.');

        $con_error = $this->crear_lead('calificado');
        $fila_con_error = LeadEventoMeta::query()->where('lead_id', $con_error->id)->firstOrFail();
        /* El último intento fue hace 20 minutos: pasó la espera antes de reencolar. Por DB::table
           para que el `updated_at` sea el que se escribe y no el de ahora. */
        DB::table('lead_eventos_meta')->where('id', $fila_con_error->id)->update([
            'estado'       => LeadEventoMeta::ESTADO_ERROR,
            'intentos'     => 1,
            'ultimo_error' => 'Meta rechazó el evento (HTTP 500): sin mensaje',
            'updated_at'   => Carbon::now()->subMinutes(20),
        ]);

        $agotado = $this->crear_lead('calificado');
        $fila_agotada = LeadEventoMeta::query()->where('lead_id', $agotado->id)->firstOrFail();
        DB::table('lead_eventos_meta')->where('id', $fila_agotada->id)->update([
            'estado'     => LeadEventoMeta::ESTADO_ERROR,
            'intentos'   => LeadEventoMeta::MAX_INTENTOS,
            'updated_at' => Carbon::now()->subMinutes(20),
        ]);

        $antes = count(Queue::pushed(EnviarEventoMetaJob::class));

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertSame(['LeadSubmitted', 'QualifiedLead'], $this->eventos_de($lead));

        $ids_encolados = [];
        foreach (Queue::pushed(EnviarEventoMetaJob::class) as $job) {
            $ids_encolados[] = $this->id_del_job($job);
        }
        $nuevos = array_slice($ids_encolados, $antes);

        $registrados = LeadEventoMeta::query()->where('lead_id', $lead->id)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        foreach ($registrados as $id) {
            $this->assertContains($id, $nuevos, 'El conciliador tenía que encolar los eventos que registró.');
        }
        $this->assertContains((int) $fila_con_error->id, $nuevos, 'El error con intentos disponibles se reencola.');
        $this->assertNotContains((int) $fila_agotada->id, $nuevos, 'Un error que agotó los intentos no se reencola.');

        /* El reintento manda de verdad y suma un intento. */
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'x'], 200)]);
        $this->correr_job($fila_con_error);
        $fila_con_error->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $fila_con_error->estado);
        $this->assertSame(2, $fila_con_error->intentos);
    }

    /**
     * Un envío colgado (enviando hace más de diez minutos) se libera a `error`; y como error recién
     * tocado, se reencola recién cuando pasa la espera de quince minutos, no en la misma corrida.
     *
     * @return void
     */
    public function test_el_conciliador_libera_un_envio_colgado(): void
    {
        $lead = $this->crear_lead('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        DB::table('lead_eventos_meta')->where('id', $fila->id)->update([
            'estado'     => LeadEventoMeta::ESTADO_ENVIANDO,
            'intentos'   => 1,
            'updated_at' => Carbon::now()->subMinutes(LeadEventoMeta::MINUTOS_PARA_DAR_POR_COLGADO + 5),
        ]);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('colgado', (string) $fila->ultimo_error);
        $this->assertCount(1, $this->encolados_de($fila), 'Recién liberado no se reencola: solo está el encolado del registro.');

        DB::table('lead_eventos_meta')->where('id', $fila->id)
            ->update(['updated_at' => Carbon::now()->subMinutes(LeadEventoMeta::MINUTOS_ANTES_DE_REENCOLAR + 1)]);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertCount(2, $this->encolados_de($fila), 'Pasada la espera, el error se reencola.');
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $fila->fresh()->estado);
    }

    /**
     * (a) Un `pendiente` huérfano (el worker murió después de reservar el job) se reencola cuando
     * lleva más de quince minutos quieto; uno más nuevo, no. Y una vez reencolado, la corrida
     * siguiente no lo vuelve a encolar: queda con el `updated_at` de ahora.
     *
     * @return void
     */
    public function test_el_conciliador_reencola_un_pendiente_huerfano(): void
    {
        $huerfano = LeadEventoMeta::query()->where('lead_id', $this->crear_lead('calificado')->id)->firstOrFail();
        $reciente = LeadEventoMeta::query()->where('lead_id', $this->crear_lead('calificado')->id)->firstOrFail();

        DB::table('lead_eventos_meta')->where('id', $huerfano->id)
            ->update(['updated_at' => Carbon::now()->subMinutes(LeadEventoMeta::MINUTOS_ANTES_DE_REENCOLAR + 5)]);
        DB::table('lead_eventos_meta')->where('id', $reciente->id)
            ->update(['updated_at' => Carbon::now()->subMinutes(5)]);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertCount(2, $this->encolados_de($huerfano), 'El pendiente huérfano se vuelve a encolar.');
        $this->assertCount(1, $this->encolados_de($reciente), 'Un pendiente de hace cinco minutos todavía espera al worker.');
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $huerfano->fresh()->estado);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));
        $this->assertCount(2, $this->encolados_de($huerfano), 'Recién reencolado, la corrida siguiente no lo duplica.');
    }

    /**
     * (c) Un `error` se reencola solo si su último intento tiene más de quince minutos: con la cola
     * atrasada o Meta caído, reencolar en cada corrida quemaría los tres intentos en ráfaga.
     *
     * @return void
     */
    public function test_el_conciliador_reencola_un_error_solo_pasados_quince_minutos(): void
    {
        $viejo  = LeadEventoMeta::query()->where('lead_id', $this->crear_lead('calificado')->id)->firstOrFail();
        $fresco = LeadEventoMeta::query()->where('lead_id', $this->crear_lead('calificado')->id)->firstOrFail();

        DB::table('lead_eventos_meta')->where('id', $viejo->id)->update([
            'estado'     => LeadEventoMeta::ESTADO_ERROR,
            'intentos'   => 1,
            'updated_at' => Carbon::now()->subMinutes(LeadEventoMeta::MINUTOS_ANTES_DE_REENCOLAR + 5),
        ]);
        DB::table('lead_eventos_meta')->where('id', $fresco->id)->update([
            'estado'     => LeadEventoMeta::ESTADO_ERROR,
            'intentos'   => 1,
            'updated_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertCount(2, $this->encolados_de($viejo), 'El error de hace veinte minutos se reencola.');
        $this->assertCount(1, $this->encolados_de($fresco), 'El error de hace cinco minutos espera.');
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $fresco->fresh()->estado);
    }

    /**
     * (b) Si el job falla sin pasar por `handle()` (MaxAttemptsExceeded, timeout), `failed()` deja la
     * fila en `error` con el motivo sin el token; una fila ya enviada no se toca.
     *
     * @return void
     */
    public function test_el_job_que_falla_sin_terminar_deja_la_fila_en_error(): void
    {
        $registro = $this->capturar_el_log();

        $pendiente = LeadEventoMeta::query()->where('lead_id', $this->crear_lead('calificado')->id)->firstOrFail();
        $enviada   = LeadEventoMeta::query()->where('lead_id', $this->crear_lead('calificado')->id)->firstOrFail();
        DB::table('lead_eventos_meta')->where('id', $enviada->id)->update(['estado' => LeadEventoMeta::ESTADO_ENVIADO]);

        $falla = new \RuntimeException('App\Jobs\EnviarEventoMetaJob has been attempted too many times or run too long. ' . self::TOKEN);
        (new EnviarEventoMetaJob((int) $pendiente->id))->failed($falla);
        (new EnviarEventoMetaJob((int) $enviada->id))->failed($falla);

        $pendiente->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $pendiente->estado);
        $this->assertStringContainsString('attempted too many times', (string) $pendiente->ultimo_error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $pendiente->ultimo_error);
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $enviada->fresh()->estado);

        $this->assertLogSinElToken($registro);
    }

    /**
     * Jobs encolados para una fila.
     *
     * @param LeadEventoMeta $fila
     *
     * @return \Illuminate\Support\Collection
     */
    private function encolados_de(LeadEventoMeta $fila)
    {
        return Queue::pushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila) {
            return $this->id_del_job($job) === (int) $fila->id;
        });
    }

    /**
     * Cuando la configuración aparece, el conciliador encola lo que quedó sin_configurar de los
     * últimos siete días, y no lo más viejo.
     *
     * @return void
     */
    public function test_el_conciliador_manda_lo_sin_configurar_cuando_aparece_la_configuracion(): void
    {
        config(['services.meta_capi.token' => '']);
        $this->capturar_el_log();

        $reciente = $this->crear_lead('calificado');
        $viejo    = $this->crear_lead('calificado');

        $fila_reciente = LeadEventoMeta::query()->where('lead_id', $reciente->id)->firstOrFail();
        $fila_vieja    = LeadEventoMeta::query()->where('lead_id', $viejo->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila_reciente->estado);

        LeadEventoMeta::query()->whereKey($fila_vieja->id)->update(['event_time' => Carbon::now()->subDays(LeadEventoMeta::DIAS_DE_VIGENCIA + 1)]);

        config(['services.meta_capi.token' => self::TOKEN]);
        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila_reciente) {
            return $this->id_del_job($job) === (int) $fila_reciente->id;
        });
        Queue::assertNotPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila_vieja) {
            return $this->id_del_job($job) === (int) $fila_vieja->id;
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 4. El backfill del origen no manda conversiones viejas
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un lead de anuncio anterior a la medición, en closer_activo y promovido, tocado ayer: el
     * backfill le carga el ctwa_clid y registra lo que ya alcanzó como `omitido`, sin encolar; y una
     * corrida del conciliador después no encola ni manda nada (antes, reportaba los tres eventos con
     * fecha de ayer). Un `omitido` tampoco sale si alguien corre el job a mano.
     *
     * @return void
     */
    public function test_el_backfill_registra_omitido_lo_ya_alcanzado_y_el_conciliador_no_manda_nada(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'x'], 200)]);

        $cliente  = $this->crear_cliente();
        $telefono = WhatsappNormalizer::normalize('+54911' . random_int(10000000, 99999999));

        $lead                     = new Lead();
        $lead->contact_name       = 'Lead viejo de anuncio';
        $lead->phone              = $telefono;
        $lead->status             = 'closer_activo';
        $lead->promoted_client_id = $cliente->id;
        $lead->save();

        $primero                        = new LeadMessage();
        $primero->lead_id               = $lead->id;
        $primero->sender                = 'lead';
        $primero->status                = 'enviado';
        $primero->is_followup           = false;
        $primero->requiere_verificacion = false;
        $primero->content               = 'Hola, vi el anuncio';
        $primero->whatsapp_message_id   = 'wamid.BACKFILL-' . Str::random(10);
        $primero->sent_at               = Carbon::now()->subDays(40);
        $primero->save();

        /* El referral es de antes del deploy: se guarda sin pasar por el observer. */
        WhatsappAdReferral::withoutEvents(function () use ($telefono, $primero) {
            WhatsappAdReferral::create([
                'phone'       => $telefono,
                'ctwa_clid'   => self::CLID,
                'wamid'       => $primero->whatsapp_message_id,
                'received_at' => $primero->sent_at,
            ]);
        });

        /* Lead anterior a la medición: sin origen, creado hace 40 días y tocado ayer. */
        $hace_40_dias = Carbon::now()->subDays(40);
        DB::table('leads')->where('id', $lead->id)->update([
            'origen'              => null,
            'ctwa_clid'           => null,
            'origen_calculado_at' => null,
            'created_at'          => $hace_40_dias,
            'updated_at'          => Carbon::now()->subDay(),
        ]);

        $this->assertSame(0, Artisan::call('leads:calcular-origen', [
            '--desde'   => $hace_40_dias->format('Y-m-d'),
            '--aplicar' => true,
        ]));

        $lead->refresh();
        $this->assertSame('anuncio_whatsapp', $lead->origen);
        $this->assertSame(self::CLID, $lead->ctwa_clid);

        $filas = LeadEventoMeta::query()->where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertSame(['LeadSubmitted', 'QualifiedLead', 'Purchase'], $filas->pluck('evento')->all());
        foreach ($filas as $fila) {
            $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $fila->estado);
            $this->assertSame(LeadEventoMeta::MOTIVO_OMITIDO, $fila->ultimo_error);
            $this->assertSame(0, $fila->intentos);
            $this->assertNull($fila->enviado_at);
        }
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        /* Ni corriendo el job a mano. */
        $this->correr_job($filas->first());

        Http::assertNothingSent();
        $this->assertSame(
            [LeadEventoMeta::ESTADO_OMITIDO],
            LeadEventoMeta::query()->where('lead_id', $lead->id)->pluck('estado')->unique()->values()->all()
        );
    }

    /**
     * Cliente mínimo para promover un lead (leads.promoted_client_id tiene FK a clients).
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $cliente                  = new Client();
        $cliente->name            = 'Cliente de prueba ' . Str::random(5);
        $cliente->company_name    = 'Negocio de prueba';
        $cliente->slug            = 'conversiones-' . Str::lower(Str::random(10));
        $cliente->api_url         = 'https://ejemplo.test';
        $cliente->api_key         = 'clave-sembrada-' . Str::random(10);
        $cliente->inbound_api_key = 'clave-sembrada-inbound-' . Str::random(10);
        $cliente->is_active       = true;
        $cliente->save();

        return $cliente;
    }
}
