<?php

namespace Tests\Feature;

use App\Helpers\WhatsappNormalizer;
use App\Jobs\EnviarEventoMetaJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use App\Models\WhatsappConfig;
use App\Services\LeadOrigenService;
use App\Services\MetaConversionesService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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
 * Las conversiones de los leads que vinieron de la WEB al conjunto de datos del píxel, por la API de
 * conversiones (segunda tanda de M6c, 13/10/2026: «quiero que extiendas la función para que se mande
 * la info de los leads que fueron a la landing y agendaron una demo»).
 *
 * Lo que se protege: que un lead `landing` registre `Lead`, `Schedule` y `Purchase` en el canal
 * `web` (acumulativos, una vez cada uno); que el POST salga al dataset de la web con el body exacto
 * (`action_source: chat`, `ph` en las dos variantes argentinas, sin `ctwa_clid` ni
 * `messaging_channel`); que la web necesite su propia configuración, sin caer a la de WhatsApp;
 * que un lead de anuncio siga yendo por WhatsApp; que el único sea por canal; que el backfill no
 * mande nada; que el conciliador levante a los leads de la web; y que ningún token quede en una
 * fila o en el log.
 */
class ConversionesWebMetaTest extends TestCase
{
    use DatabaseTransactions;

    /** Token de WhatsApp: si aparece en un log o en una fila, el test falla. */
    private const TOKEN = 'EAAG-token-de-prueba-capi-SECRETO-1234567890';

    /** Dataset de la WABA. */
    private const DATASET = '998877665544332';

    /** Token de la web: misma regla. */
    private const WEB_TOKEN = 'EAAG-token-web-de-prueba-SECRETO-0987654321';

    /** Dataset del píxel de la web. */
    private const WEB_DATASET = '112233445566778';

    /** WABA por defecto del config. */
    private const WABA = '1290129236552587';

    /** Click id de prueba. */
    private const CLID = 'ARAkLkA-clid-de-prueba-conversiones-web';

    /** Secreto con el que se firman los webhooks de Kapso. */
    private const SECRETO_KAPSO = 'secreto-de-prueba-del-webhook-conversiones-web';

    /**
     * Configuración completa de los dos canales y cola falseada.
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
            'services.meta_capi.web_token'       => self::WEB_TOKEN,
            'services.meta_capi.web_dataset_id'  => self::WEB_DATASET,
            'services.meta_capi.waba_id'         => self::WABA,
            'services.meta_capi.graph_version'   => 'v24.0',
            'services.meta_capi.test_event_code' => '',
            'services.meta_capi.timeout'         => 15,
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El registro
     |----------------------------------------------------------------------------------------- */

    /**
     * Lead de la web que agenda la demo de una (la demo directa lo lleva de nuevo a demo_agendada):
     * se registran `Lead` y `Schedule` en el canal web, en orden, con el event_id del canal, y se
     * encolan por la conexión database.
     *
     * @return void
     */
    public function test_un_lead_de_la_web_que_agenda_registra_lead_y_schedule_por_el_canal_web(): void
    {
        $lead = $this->crear_lead_de_la_web('nuevo');
        $this->assertSame([], $this->filas_de($lead), 'En nuevo no se alcanzó ningún evento.');

        $lead->status = 'demo_agendada';
        $lead->save();

        $this->assertSame([
            ['web', 'Lead', 'cc-lead-' . $lead->id . '-web-lead', LeadEventoMeta::ESTADO_PENDIENTE],
            ['web', 'Schedule', 'cc-lead-' . $lead->id . '-web-schedule', LeadEventoMeta::ESTADO_PENDIENTE],
        ], $this->filas_de($lead));

        Queue::assertPushed(EnviarEventoMetaJob::class, 2);
        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) {
            return $job->connection === 'database';
        });
    }

    /**
     * Calificado registra solo `Lead`; la promoción suma `Schedule` y `Purchase` (acumulativos). Un
     * segundo guardado no registra nada.
     *
     * @return void
     */
    public function test_los_eventos_web_son_acumulativos_y_no_se_repiten(): void
    {
        $lead         = $this->crear_lead_de_la_web('nuevo');
        $lead->status = 'calificado';
        $lead->save();
        $this->assertSame(['Lead'], $this->eventos_de($lead));

        $lead->notes = 'Una nota cualquiera';
        $lead->save();
        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $this->assertSame(['Lead'], $this->eventos_de($lead));

        $lead->promoted_client_id = $this->crear_cliente()->id;
        $lead->save();
        $this->assertSame(['Lead', 'Schedule', 'Purchase'], $this->eventos_de($lead));

        Queue::assertPushed(EnviarEventoMetaJob::class, 3);
        $this->assertSame([], app(MetaConversionesService::class)->registrar_alcanzados($lead->fresh()));
    }

    /**
     * Un lead sin canal (desconocido, de Instagram, o sin origen calculado) y sin ctwa_clid no
     * registra nada, llegue a donde llegue.
     *
     * @return void
     */
    public function test_un_lead_sin_canal_no_registra_nada(): void
    {
        foreach ([LeadOrigenService::ORIGEN_DESCONOCIDO, LeadOrigenService::ORIGEN_INSTAGRAM, null] as $origen) {
            $lead         = $this->crear_lead_de_la_web('nuevo');
            $lead->origen = $origen;
            $lead->save();

            $lead->status = 'demo_agendada';
            $lead->save();

            $this->assertSame([], $this->filas_de($lead), 'Origen ' . var_export($origen, true) . ' no tiene canal.');
        }

        Queue::assertNotPushed(EnviarEventoMetaJob::class);
    }

    /**
     * El camino real, sin `origen` en el alta: el webhook de Kapso (firmado) crea el lead con la
     * frase del botón de la web como primer mensaje, y el origen lo escribe el observer con un
     * UPDATE. El lead queda `landing` sin ningún evento (está en `nuevo`), y al pasar a
     * `demo_agendada` se registran y encolan `Lead` y `Schedule` en el canal web.
     *
     * @return void
     */
    public function test_el_webhook_con_la_frase_de_la_web_deja_al_lead_en_el_canal_web(): void
    {
        Http::fake();
        $this->configurar_whatsapp();

        $telefono = '+54911' . random_int(10000000, 99999999);
        $this->postear_kapso($telefono, LeadOrigenService::FRASE_LANDING, 'wamid.WEB-KAPSO-' . Str::random(10), time() - 60)
            ->assertStatus(200);

        $lead = Lead::query()->where('phone', WhatsappNormalizer::normalize($telefono))->orderByDesc('id')->first();
        $this->assertNotNull($lead, 'El webhook de Kapso tenía que crear el lead.');
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen);
        $this->assertNull($lead->ctwa_clid);
        $this->assertSame([], $this->filas_de($lead), 'En nuevo no se alcanzó ningún evento.');
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        $lead->status = 'demo_agendada';
        $lead->save();

        $this->assertSame([
            ['web', 'Lead', 'cc-lead-' . $lead->id . '-web-lead', LeadEventoMeta::ESTADO_PENDIENTE],
            ['web', 'Schedule', 'cc-lead-' . $lead->id . '-web-schedule', LeadEventoMeta::ESTADO_PENDIENTE],
        ], $this->filas_de($lead));

        foreach (LeadEventoMeta::query()->where('lead_id', $lead->id)->get() as $fila) {
            Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila) {
                return $job->connection === 'database' && $this->id_del_job($job) === (int) $fila->id;
            });
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El POST
     |----------------------------------------------------------------------------------------- */

    /**
     * El job manda UN POST al dataset de la web con el token de la web en el header y el body exacto:
     * `action_source: chat`, `ph` con las dos variantes argentinas, `em`, `country`, `external_id`,
     * `fn` y `ln`, todo en SHA-256; sin `messaging_channel` ni `ctwa_clid`.
     *
     * @return void
     */
    public function test_el_job_manda_el_post_exacto_al_dataset_de_la_web(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'WebTrace1'], 200),
        ]);
        $registro = $this->capturar_el_log();

        $numero              = (string) random_int(1000000, 9999999);
        $lead                = $this->crear_lead_de_la_web('nuevo', '+549351' . $numero);
        $lead->email         = '  Guille@Ferreteria.COM.ar ';
        $lead->contact_name  = '  Guillermo   González ';
        $lead->status        = 'calificado';
        $lead->save();

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->correr_job($fila);

        $esperado = [
            'data' => [[
                'event_name'    => 'Lead',
                'event_time'    => $fila->fresh()->event_time->getTimestamp(),
                'event_id'      => 'cc-lead-' . $lead->id . '-web-lead',
                'action_source' => 'chat',
                'user_data'     => [
                    'ph'          => [hash('sha256', '549351' . $numero), hash('sha256', '54351' . $numero)],
                    'em'          => [hash('sha256', 'guille@ferreteria.com.ar')],
                    'country'     => [hash('sha256', 'ar')],
                    'external_id' => [hash('sha256', 'cc-lead-' . $lead->id)],
                    'fn'          => [hash('sha256', 'guillermo')],
                    'ln'          => [hash('sha256', 'gonzález')],
                ],
            ]],
        ];

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($esperado) {
            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v24.0/' . self::WEB_DATASET . '/events'
                && $request->hasHeader('Authorization', 'Bearer ' . self::WEB_TOKEN)
                && strpos($request->url(), self::WEB_TOKEN) === false
                && $request->data() === $esperado;
        });

        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $fila->estado);
        $this->assertSame(1, $fila->intentos);
        $this->assertNotNull($fila->enviado_at);
        /* Clave por clave: MySQL reordena las claves de una columna json. */
        $this->assertSame(1, $fila->respuesta['events_received']);
        $this->assertSame('WebTrace1', $fila->respuesta['fbtrace_id']);

        /* Correrlo otra vez no manda nada: ya salió. */
        $this->correr_job($fila);
        Http::assertSentCount(1);

        $this->assertLogSinLosTokens($registro);
    }

    /**
     * Con `test_event_code` configurado viaja también en el body de la web (es compartido).
     *
     * @return void
     */
    public function test_el_codigo_de_prueba_viaja_tambien_por_la_web(): void
    {
        config(['services.meta_capi.test_event_code' => 'TEST98765']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $lead = $this->crear_lead_de_la_web('calificado');
        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());

        Http::assertSent(function ($request) {
            return ($request->data()['test_event_code'] ?? null) === 'TEST98765'
                && $request->data()['data'][0]['action_source'] === 'chat';
        });
    }

    /**
     * El Purchase de la web lleva la moneda y el valor del contrato, igual que el de WhatsApp.
     *
     * @return void
     */
    public function test_el_purchase_web_lleva_moneda_y_valor_del_contrato(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $lead                           = $this->crear_lead_de_la_web('closer_activo');
        $lead->contract_currency        = 'pesos';
        $lead->contract_precio_licencia = '$ 2.500.000';
        $lead->promoted_client_id       = $this->crear_cliente()->id;
        $lead->save();

        $purchase = LeadEventoMeta::query()->where('lead_id', $lead->id)->where('evento', 'Purchase')->firstOrFail();
        $this->assertSame('web', $purchase->canal);
        $this->assertSame('cc-lead-' . $lead->id . '-web-purchase', $purchase->event_id);

        $this->correr_job($purchase);

        Http::assertSent(function ($request) {
            $dato = $request->data()['data'][0];

            return $dato['event_name'] === 'Purchase'
                && $dato['action_source'] === 'chat'
                && $dato['custom_data'] === ['currency' => 'ARS', 'value' => 2500000];
        });
    }

    /**
     * Un teléfono de otro país viaja en una sola variante y sin `country`; un nombre de una sola
     * palabra no manda `fn` ni `ln`; sin mail, no hay `em`.
     *
     * @return void
     */
    public function test_un_telefono_extranjero_va_sin_pais_y_un_nombre_suelto_sin_fn_ni_ln(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $lead               = $this->crear_lead_de_la_web('nuevo', '+59899123456');
        $lead->contact_name = 'Guille 🔥';
        $lead->status       = 'calificado';
        $lead->save();

        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());

        Http::assertSent(function ($request) use ($lead) {
            return $request->data()['data'][0]['user_data'] === [
                'ph'          => [hash('sha256', '59899123456')],
                'external_id' => [hash('sha256', 'cc-lead-' . $lead->id)],
            ];
        });
    }

    /**
     * Sin teléfono ni mail no hay con qué cruzar al lead: la fila queda en error y no sale ninguna
     * llamada.
     *
     * @return void
     */
    public function test_sin_telefono_ni_mail_no_sale_la_llamada(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $this->capturar_el_log();

        $lead = $this->crear_lead_de_la_web('calificado', null);
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->correr_job($fila);

        Http::assertNothingSent();
        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('ni un mail', (string) $fila->ultimo_error);
    }

    /**
     * La partición del nombre para `fn`/`ln`.
     *
     * @return void
     */
    public function test_nombre_y_apellido_se_parten_como_pide_meta(): void
    {
        $this->assertSame(['guillermo', 'gonzález'], MetaConversionesService::nombre_y_apellido('Guillermo González'));
        $this->assertSame(['juan', 'pérez'], MetaConversionesService::nombre_y_apellido(' Juan  Carlos Pérez '));
        $this->assertSame(['maríajosé', 'obrien'], MetaConversionesService::nombre_y_apellido("María-José O'Brien"));
        $this->assertNull(MetaConversionesService::nombre_y_apellido('Guille 🔥'));
        $this->assertNull(MetaConversionesService::nombre_y_apellido(''));
        $this->assertNull(MetaConversionesService::nombre_y_apellido(null));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. La configuración
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin fallback: con la configuración de WhatsApp completa y a la de la web le falta el token o
     * el dataset, los eventos de la web quedan `sin_configurar`, el envío no sale a ningún lado (ni
     * al dataset de la WABA) y el conciliador no los reencola, pero avisa qué falta del canal web.
     * Un lead de anuncio, en cambio, sale normal.
     *
     * @return void
     */
    public function test_sin_su_config_propia_la_web_no_cae_a_la_de_whatsapp(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $this->capturar_el_log();

        config(['services.meta_capi.web_token' => '', 'services.meta_capi.web_dataset_id' => self::WEB_DATASET]);
        $sin_token = $this->crear_lead_de_la_web('calificado');

        config(['services.meta_capi.web_token' => self::WEB_TOKEN, 'services.meta_capi.web_dataset_id' => '  ']);
        $sin_dataset = $this->crear_lead_de_la_web('calificado');

        foreach ([$sin_token, $sin_dataset] as $lead) {
            $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
            $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila->estado);
            $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, app(MetaConversionesService::class)->enviar($fila));
        }
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
        Http::assertNothingSent();

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));
        $this->assertStringContainsString('Canal web: falta META_CAPI_WEB_TOKEN o META_CAPI_WEB_DATASET_ID', Artisan::output());
        $this->assertStringNotContainsString('Canal whatsapp', Artisan::output());
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        /* WhatsApp está configurado: un lead de anuncio sale a su dataset. */
        $anuncio            = $this->crear_lead_de_la_web('nuevo');
        $anuncio->origen    = LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP;
        $anuncio->ctwa_clid = self::CLID;
        $anuncio->status    = 'calificado';
        $anuncio->save();
        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $anuncio->id)->firstOrFail());

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v24.0/' . self::DATASET . '/events'
                && $request->data()['data'][0]['action_source'] === 'business_messaging';
        });
    }

    /**
     * Sin ninguna configuración (ni la de la web ni la de WhatsApp): sin_configurar, un warning que
     * nombra las variables de la web (y no las de WhatsApp, que no le sirven), nada encolado y
     * ninguna llamada.
     *
     * @return void
     */
    public function test_sin_ninguna_config_queda_sin_configurar_y_no_sale_nada(): void
    {
        config([
            'services.meta_capi.token'          => '',
            'services.meta_capi.web_token'      => '',
            'services.meta_capi.web_dataset_id' => '',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $registro = $this->capturar_el_log();

        $lead = $this->crear_lead_de_la_web('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila->estado);
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, app(MetaConversionesService::class)->enviar($fila));
        Http::assertNothingSent();

        $warnings = array_filter($registro->lineas, function ($linea) {
            return strpos($linea, 'warning') === 0 && strpos($linea, 'META_CAPI_WEB_TOKEN') !== false;
        });
        $this->assertNotEmpty($warnings, 'Sin configuración tiene que quedar un warning que nombre las variables de la web.');
        foreach ($warnings as $linea) {
            $this->assertStringNotContainsString('META_CAPI_TOKEN o', $linea, 'El warning de la web no puede mandar a cargar las de WhatsApp.');
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Los dos canales conviven
     |----------------------------------------------------------------------------------------- */

    /**
     * Un lead de anuncio sigue yendo por WhatsApp aunque la web tenga otro dataset y otro token: al
     * dataset de la WABA, con su token, `business_messaging` y el `ctwa_clid`. Y un lead de la web
     * que trae `ctwa_clid` también va por WhatsApp: el click id gana.
     *
     * @return void
     */
    public function test_un_lead_de_anuncio_sigue_yendo_por_whatsapp(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $anuncio            = $this->crear_lead_de_la_web('nuevo');
        $anuncio->origen    = LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP;
        $anuncio->ctwa_clid = self::CLID;
        $anuncio->status    = 'calificado';
        $anuncio->save();

        $web_con_clid            = $this->crear_lead_de_la_web('nuevo');
        $web_con_clid->ctwa_clid = self::CLID;
        $web_con_clid->status    = 'calificado';
        $web_con_clid->save();

        foreach ([$anuncio, $web_con_clid] as $lead) {
            $this->assertSame([
                ['whatsapp', 'LeadSubmitted', 'cc-lead-' . $lead->id . '-leadsubmitted', LeadEventoMeta::ESTADO_PENDIENTE],
            ], $this->filas_de($lead));

            $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());
        }

        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($anuncio) {
            $dato = $request->data()['data'][0];

            return $request->url() === 'https://graph.facebook.com/v24.0/' . self::DATASET . '/events'
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && $dato['event_id'] === 'cc-lead-' . $anuncio->id . '-leadsubmitted'
                && $dato['action_source'] === 'business_messaging'
                && $dato['messaging_channel'] === 'whatsapp'
                && $dato['user_data'] === ['whatsapp_business_account_id' => self::WABA, 'ctwa_clid' => self::CLID];
        });
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), self::WEB_DATASET) !== false;
        });
    }

    /**
     * 🔴 El único es por canal: el mismo lead puede tener un `Purchase` de cada canal (si le llegó un
     * anuncio después de la web), pero no dos del mismo canal.
     *
     * @return void
     */
    public function test_el_unico_es_por_lead_canal_y_evento(): void
    {
        $lead = $this->crear_lead_de_la_web('nuevo');
        $base = [
            'lead_id'    => $lead->id,
            'evento'     => 'Purchase',
            'estado'     => LeadEventoMeta::ESTADO_OMITIDO,
            'event_time' => Carbon::now(),
        ];

        LeadEventoMeta::create($base + ['canal' => 'whatsapp', 'event_id' => 'cc-lead-' . $lead->id . '-purchase']);
        LeadEventoMeta::create($base + ['canal' => 'web', 'event_id' => 'cc-lead-' . $lead->id . '-web-purchase']);
        $this->assertSame(2, LeadEventoMeta::query()->where('lead_id', $lead->id)->count());

        $duplicado = false;
        try {
            DB::transaction(function () use ($base, $lead) {
                LeadEventoMeta::create($base + ['canal' => 'web', 'event_id' => 'cc-lead-' . $lead->id . '-otro-id']);
            });
        } catch (QueryException $exception) {
            $duplicado = true;
        }
        $this->assertTrue($duplicado, 'Dos filas del mismo lead, canal y evento tienen que chocar contra el único.');

        /* Una fila sin canal explícito es de WhatsApp (el default de la columna). */
        $sin_canal = LeadEventoMeta::create([
            'lead_id'    => $lead->id,
            'evento'     => 'LeadSubmitted',
            'estado'     => LeadEventoMeta::ESTADO_OMITIDO,
            'event_id'   => 'cc-lead-' . $lead->id . '-leadsubmitted',
            'event_time' => Carbon::now(),
        ]);
        $this->assertSame('whatsapp', $sin_canal->fresh()->canal);
    }

    /**
     * 🔴 El canal es pegajoso. Un lead entra por la web y sale `Lead` por la web; horas después llega
     * el referral de su anuncio (con ctwa_clid) por el observer real del referral. El lead queda en
     * el canal web: no se registra ningún evento de WhatsApp para etapas ya reportadas, y lo que
     * alcanza después (`Schedule`) también sale por la web. Y una fila de WhatsApp que hubiera
     * quedado pendiente para ese lead no sale: queda `omitido` porque el lead es de otro canal.
     *
     * @return void
     */
    public function test_un_lead_de_la_web_al_que_le_llega_el_anuncio_sigue_en_el_canal_web(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $lead    = $this->crear_lead_de_la_web('nuevo');
        $primero = $this->crear_primer_mensaje($lead, LeadOrigenService::FRASE_LANDING);

        $lead->status = 'calificado';
        $lead->save();
        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());
        Http::assertSentCount(1);

        /* El referral llega tarde, con el wamid del primer mensaje y su click id. */
        WhatsappAdReferral::create([
            'phone'       => $lead->phone,
            'ctwa_clid'   => self::CLID,
            'wamid'       => $primero->whatsapp_message_id,
            'received_at' => $primero->sent_at,
        ]);

        $lead->refresh();
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $lead->origen, 'El anuncio gana el origen.');
        $this->assertSame(self::CLID, $lead->ctwa_clid);
        $this->assertSame(LeadEventoMeta::CANAL_WHATSAPP, MetaConversionesService::canal_natural($lead));
        $this->assertSame(LeadEventoMeta::CANAL_WEB, MetaConversionesService::canal_del_lead($lead), 'Ya se reportó por la web: el canal no cambia.');

        $lead->status = 'demo_agendada';
        $lead->save();

        $this->assertSame([
            ['web', 'Lead', 'cc-lead-' . $lead->id . '-web-lead', LeadEventoMeta::ESTADO_ENVIADO],
            ['web', 'Schedule', 'cc-lead-' . $lead->id . '-web-schedule', LeadEventoMeta::ESTADO_PENDIENTE],
        ], $this->filas_de($lead), 'Ningún evento de WhatsApp para un lead que ya se reportó por la web.');

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));
        $this->assertSame(0, LeadEventoMeta::query()->where('lead_id', $lead->id)->where('canal', 'whatsapp')->count());

        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->where('evento', 'Schedule')->firstOrFail());
        Http::assertSentCount(2);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/' . self::DATASET . '/') !== false;
        });

        /* Una fila de WhatsApp que hubiera quedado pendiente (por ejemplo, de antes de esta regla)
           no sale: el lead es del canal web. */
        $de_whatsapp = LeadEventoMeta::create([
            'lead_id'    => $lead->id,
            'canal'      => 'whatsapp',
            'evento'     => 'LeadSubmitted',
            'estado'     => LeadEventoMeta::ESTADO_PENDIENTE,
            'event_id'   => 'cc-lead-' . $lead->id . '-leadsubmitted',
            'event_time' => Carbon::now(),
        ]);
        $this->correr_job($de_whatsapp);

        Http::assertSentCount(2);
        $de_whatsapp->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $de_whatsapp->estado);
        $this->assertStringStartsWith(LeadEventoMeta::MOTIVO_CANAL_CAMBIADO, (string) $de_whatsapp->ultimo_error);
        $this->assertStringContainsString('ahora es web', (string) $de_whatsapp->ultimo_error);
    }

    /**
     * 🔴 Un lead de la web que se queda sin canal (el referral de su anuncio llega SIN ctwa_clid y lo
     * pasa a `anuncio_whatsapp`): su evento web pendiente no sale, queda `omitido` con el motivo, el
     * conciliador no lo reencola, y lo que alcance después no registra nada.
     *
     * @return void
     */
    public function test_un_lead_que_se_queda_sin_canal_no_manda_lo_pendiente(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $lead    = $this->crear_lead_de_la_web('nuevo');
        $primero = $this->crear_primer_mensaje($lead, LeadOrigenService::FRASE_LANDING);

        $lead->status = 'calificado';
        $lead->save();
        $pendiente = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_PENDIENTE, $pendiente->estado);

        WhatsappAdReferral::create([
            'phone'       => $lead->phone,
            'ctwa_clid'   => null,
            'wamid'       => $primero->whatsapp_message_id,
            'received_at' => $primero->sent_at,
        ]);

        $lead->refresh();
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $lead->origen);
        $this->assertNull($lead->ctwa_clid);
        $this->assertNull(MetaConversionesService::canal_del_lead($lead));

        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, app(MetaConversionesService::class)->enviar($pendiente));
        Http::assertNothingSent();

        $pendiente->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $pendiente->estado);
        $this->assertStringStartsWith(LeadEventoMeta::MOTIVO_CANAL_CAMBIADO, (string) $pendiente->ultimo_error);
        $this->assertStringContainsString('ya no tiene canal', (string) $pendiente->ultimo_error);

        $lead->status = 'demo_agendada';
        $lead->save();
        $antes = count(Queue::pushed(EnviarEventoMetaJob::class));
        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertSame([
            ['web', 'Lead', 'cc-lead-' . $lead->id . '-web-lead', LeadEventoMeta::ESTADO_OMITIDO],
        ], $this->filas_de($lead));
        $this->assertCount($antes, Queue::pushed(EnviarEventoMetaJob::class), 'El conciliador no reencola un omitido.');
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 5. El backfill y el conciliador
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un lead viejo de la web (primer mensaje con la frase del botón, demo realizada y promovido,
     * tocado ayer): el backfill lo deja `landing` y registra lo que ya alcanzó como `omitido` en el
     * canal web, sin encolar; el conciliador después no encola ni manda nada.
     *
     * @return void
     */
    public function test_el_backfill_deja_omitido_lo_alcanzado_por_un_lead_viejo_de_la_web(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);

        $lead                     = new Lead();
        $lead->contact_name       = 'Lead viejo de la web';
        $lead->phone              = '+549351' . random_int(1000000, 9999999);
        $lead->status             = 'demo_realizada';
        $lead->promoted_client_id = $this->crear_cliente()->id;
        $lead->save();

        /* El primer mensaje es de antes del deploy: se guarda sin pasar por el observer del origen. */
        LeadMessage::withoutEvents(function () use ($lead) {
            $primero                        = new LeadMessage();
            $primero->lead_id               = $lead->id;
            $primero->sender                = 'lead';
            $primero->status                = 'enviado';
            $primero->is_followup           = false;
            $primero->requiere_verificacion = false;
            $primero->content               = LeadOrigenService::FRASE_LANDING;
            $primero->sent_at               = Carbon::now()->subDays(30);
            $primero->save();
        });

        $hace_30_dias = Carbon::now()->subDays(30);
        DB::table('leads')->where('id', $lead->id)->update([
            'origen'              => null,
            'origen_calculado_at' => null,
            'created_at'          => $hace_30_dias,
            'updated_at'          => Carbon::now()->subDay(),
        ]);
        $this->assertSame([], $this->filas_de($lead));

        $this->assertSame(0, Artisan::call('leads:calcular-origen', [
            '--desde'   => $hace_30_dias->format('Y-m-d'),
            '--aplicar' => true,
        ]));

        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->fresh()->origen);
        $this->assertSame([
            ['web', 'Lead', 'cc-lead-' . $lead->id . '-web-lead', LeadEventoMeta::ESTADO_OMITIDO],
            ['web', 'Schedule', 'cc-lead-' . $lead->id . '-web-schedule', LeadEventoMeta::ESTADO_OMITIDO],
            ['web', 'Purchase', 'cc-lead-' . $lead->id . '-web-purchase', LeadEventoMeta::ESTADO_OMITIDO],
        ], $this->filas_de($lead));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);

        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());
        Http::assertNothingSent();
    }

    /**
     * El conciliador registra y encola lo que el observer no vio de un lead de la web (el UPDATE
     * directo de la demo directa), y no toca un lead de la web quieto hace más que la vigencia ni
     * un lead sin canal.
     *
     * @return void
     */
    public function test_el_conciliador_levanta_a_los_leads_de_la_web(): void
    {
        $reciente = $this->crear_lead_de_la_web('nuevo');
        Lead::query()->whereKey($reciente->id)->update(['status' => 'demo_agendada']);
        $this->assertSame([], $this->filas_de($reciente), 'El UPDATE directo no pasa por el observer.');

        $quieto = $this->crear_lead_de_la_web('nuevo');
        DB::table('leads')->where('id', $quieto->id)->update([
            'status'     => 'demo_agendada',
            'updated_at' => Carbon::now()->subDays(LeadEventoMeta::DIAS_DE_VIGENCIA + 2),
        ]);

        $sin_canal = $this->crear_lead_de_la_web('nuevo');
        DB::table('leads')->where('id', $sin_canal->id)->update(['status' => 'demo_agendada', 'origen' => 'desconocido']);

        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertSame(['Lead', 'Schedule'], $this->eventos_de($reciente));
        $this->assertSame([], $this->filas_de($quieto));
        $this->assertSame([], $this->filas_de($sin_canal));

        foreach (LeadEventoMeta::query()->where('lead_id', $reciente->id)->get() as $fila) {
            $this->assertSame('web', $fila->canal);
            Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila) {
                return $this->id_del_job($job) === (int) $fila->id;
            });
        }
    }

    /**
     * El conciliador reencola los `sin_configurar` solo de los canales que ya tienen configuración:
     * con la de la web cargada y la de WhatsApp todavía vacía, sale el de la web y el de WhatsApp
     * espera.
     *
     * @return void
     */
    public function test_el_conciliador_reencola_sin_configurar_solo_del_canal_configurado(): void
    {
        config([
            'services.meta_capi.token'          => '',
            'services.meta_capi.dataset_id'     => '',
            'services.meta_capi.web_token'      => '',
            'services.meta_capi.web_dataset_id' => '',
        ]);
        $this->capturar_el_log();

        $web               = $this->crear_lead_de_la_web('calificado');
        $anuncio            = $this->crear_lead_de_la_web('nuevo');
        $anuncio->origen    = LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP;
        $anuncio->ctwa_clid = self::CLID;
        $anuncio->status    = 'calificado';
        $anuncio->save();

        $fila_web     = LeadEventoMeta::query()->where('lead_id', $web->id)->firstOrFail();
        $fila_anuncio = LeadEventoMeta::query()->where('lead_id', $anuncio->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila_web->estado);
        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila_anuncio->estado);

        config(['services.meta_capi.web_token' => self::WEB_TOKEN, 'services.meta_capi.web_dataset_id' => self::WEB_DATASET]);
        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila_web) {
            return $this->id_del_job($job) === (int) $fila_web->id;
        });
        Queue::assertNotPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($fila_anuncio) {
            return $this->id_del_job($job) === (int) $fila_anuncio->id;
        });
        $this->assertSame(LeadEventoMeta::ESTADO_SIN_CONFIGURAR, $fila_anuncio->fresh()->estado);
    }

    /* ------------------------------------------------------------------------------------------
     | 6. Ningún token a la vista
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Una caída de red cuyo mensaje trae el token de la web no lo deja ni en la fila ni en el log.
     *
     * @return void
     */
    public function test_el_token_de_la_web_no_queda_en_la_fila_ni_en_el_log(): void
    {
        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28: Operation timed out for ' . $request->url() . '?access_token=' . self::WEB_TOKEN);
        });
        $registro = $this->capturar_el_log();

        $lead = $this->crear_lead_de_la_web('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->correr_job($fila);

        $fila->refresh();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, $fila->estado);
        $this->assertStringNotContainsString(self::WEB_TOKEN, (string) $fila->ultimo_error);
        $this->assertStringContainsString('[token]', (string) $fila->ultimo_error);

        $this->assertNotEmpty($registro->lineas);
        $this->assertLogSinLosTokens($registro);
    }

    /* ------------------------------------------------------------------------------------------
     | 7. claude/query
     |----------------------------------------------------------------------------------------- */

    /**
     * `claude/query` expone el canal: como columna y como filtro.
     *
     * @return void
     */
    public function test_claude_query_expone_y_filtra_el_canal(): void
    {
        config(['services.claude_task_ingest.key' => 'clave-de-prueba-conversiones-web']);

        $lead = $this->crear_lead_de_la_web('calificado');
        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();

        $headers = ['X-Claude-Task-Key' => 'clave-de-prueba-conversiones-web', 'Accept' => 'application/json'];

        $web = $this->getJson('/api/claude/query?' . http_build_query([
            'model'   => 'lead_evento_meta',
            'lead_id' => $lead->id,
            'canal'   => 'web',
        ]), $headers);
        $web->assertStatus(200);
        $this->assertContains('canal', $web->json('columnas'));
        $this->assertSame([(int) $fila->id], array_map('intval', array_column($web->json('data'), 'id')));
        $this->assertSame('web', $web->json('data.0.canal'));
        $this->assertSame('Lead', $web->json('data.0.evento'));

        $whatsapp = $this->getJson('/api/claude/query?' . http_build_query([
            'model'   => 'lead_evento_meta',
            'lead_id' => $lead->id,
            'canal'   => 'whatsapp',
        ]), $headers);
        $whatsapp->assertStatus(200);
        $this->assertSame([], $whatsapp->json('data'));
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Lead que vino de la web (origen `landing`, sin ctwa_clid), en el estado pedido.
     *
     * @param string      $status
     * @param string|null $telefono Default: un celular de Córdoba al azar.
     *
     * @return Lead
     */
    private function crear_lead_de_la_web(string $status, ?string $telefono = 'al_azar'): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Lead de la web ' . Str::random(5);
        $lead->phone        = $telefono === 'al_azar' ? '+549351' . random_int(1000000, 9999999) : $telefono;
        $lead->status       = $status;
        $lead->origen       = LeadOrigenService::ORIGEN_LANDING;
        $lead->save();

        return $lead;
    }

    /**
     * Configuración de WhatsApp activa en modo prueba, con el secreto del webhook de Kapso (mismo
     * armado que OrigenDelLeadTest).
     *
     * @return void
     */
    private function configurar_whatsapp(): void
    {
        WhatsappConfig::query()->update(['is_active' => false]);

        $config                     = new WhatsappConfig();
        $config->kapso_api_key      = 'clave-de-prueba';
        $config->phone_number_id    = '1234567890';
        $config->webhook_secret     = self::SECRETO_KAPSO;
        $config->meta_webhook_token = 'token-de-prueba-del-webhook-meta-web-1234567890';
        $config->is_active          = true;
        $config->test_mode          = true;
        $config->save();
    }

    /**
     * Pega al webhook de Kapso con el body firmado igual que lo firma Kapso.
     *
     * @param string $telefono
     * @param string $texto
     * @param string $wamid
     * @param int    $timestamp
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_kapso(string $telefono, string $texto, string $wamid, int $timestamp)
    {
        $body = json_encode([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Lead de la web'],
            'message'      => [
                'id'        => $wamid,
                'from'      => $telefono,
                'type'      => 'text',
                'text'      => ['body' => $texto],
                'timestamp' => (string) $timestamp,
            ],
        ]);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO_KAPSO),
        ], $body);
    }

    /**
     * Primer mensaje entrante del lead, con wamid y momento (para que un referral lo encuentre). El
     * lead ya tiene origen, así que el observer del primer mensaje no lo recalcula.
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return LeadMessage
     */
    private function crear_primer_mensaje(Lead $lead, string $texto): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'lead';
        $mensaje->status                = 'enviado';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = false;
        $mensaje->content               = $texto;
        $mensaje->whatsapp_message_id   = 'wamid.WEB-' . Str::random(16);
        $mensaje->sent_at               = Carbon::now()->subHours(3);
        $mensaje->save();

        return $mensaje;
    }

    /**
     * Filas del lead, en orden: [canal, evento, event_id, estado].
     *
     * @param Lead $lead
     *
     * @return array<int, array<int, string>>
     */
    private function filas_de(Lead $lead): array
    {
        return LeadEventoMeta::query()
            ->where('lead_id', $lead->id)
            ->orderBy('id')
            ->get()
            ->map(function (LeadEventoMeta $fila) {
                return [(string) $fila->canal, (string) $fila->evento, (string) $fila->event_id, (string) $fila->estado];
            })
            ->all();
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

            public function channel($nombre = null)
            {
                return $this;
            }

            public function log($level, $message, array $context = [])
            {
                $this->lineas[] = $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        };

        Log::swap($registro);

        return $registro;
    }

    /**
     * Ninguna línea de log lleva ninguno de los dos tokens.
     *
     * @param AbstractLogger $registro
     *
     * @return void
     */
    private function assertLogSinLosTokens(AbstractLogger $registro): void
    {
        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString(self::TOKEN, $linea, 'Una línea de log lleva el token de WhatsApp.');
            $this->assertStringNotContainsString(self::WEB_TOKEN, $linea, 'Una línea de log lleva el token de la web.');
        }
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
        $cliente->slug            = 'conversiones-web-' . Str::lower(Str::random(10));
        $cliente->api_url         = 'https://ejemplo.test';
        $cliente->api_key         = 'clave-sembrada-' . Str::random(10);
        $cliente->inbound_api_key = 'clave-sembrada-inbound-' . Str::random(10);
        $cliente->is_active       = true;
        $cliente->save();

        return $cliente;
    }
}
