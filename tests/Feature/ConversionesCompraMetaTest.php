<?php

namespace Tests\Feature;

use App\Jobs\EnviarEventoMetaJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Services\LeadOrigenService;
use App\Services\MetaConversionesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El `Purchase` de TODO lead que se vuelve cliente (misión purchase-capi-al-promover, 22/10/2026:
 * «que el admin le avise a Meta cuando un lead se convierte en cliente, y que además se informen los
 * leads que YA son clientes»).
 *
 * Hasta el 22/10 solo salía para los leads con canal (anuncio con `ctwa_clid`, o la web). Lo que se
 * protege acá: que la promoción de un lead sin atribución registre UN `Purchase` por la web y nada
 * más; que salga con moneda, valor y los datos de contacto hasheados; que no se duplique (otro save,
 * el conciliador); que un promovido viejo no mande nada al cambiarle el status; que los leads con
 * canal sigan igual; el `fbc` desde el `fbclid` de la landing; y el comando retroactivo, que respeta
 * la ventana de 7 días de Meta y no inventa fechas.
 */
class ConversionesCompraMetaTest extends TestCase
{
    use DatabaseTransactions;

    /** Token de la web. */
    private const WEB_TOKEN = 'EAAG-token-web-compras-SECRETO-1122334455';

    /** Dataset del píxel. */
    private const WEB_DATASET = '1123648997027192';

    /** Click id de un anuncio. */
    private const CLID = 'ARAkLkA-clid-de-prueba-compras';

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
            'services.meta_capi.token'           => 'EAAG-token-wa-compras-SECRETO-99887766',
            'services.meta_capi.dataset_id'      => self::WEB_DATASET,
            'services.meta_capi.web_token'       => self::WEB_TOKEN,
            'services.meta_capi.web_dataset_id'  => self::WEB_DATASET,
            'services.meta_capi.waba_id'         => '1290129236552587',
            'services.meta_capi.graph_version'   => 'v24.0',
            'services.meta_capi.test_event_code' => '',
            'services.meta_capi.timeout'         => 15,
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Al promover
     |----------------------------------------------------------------------------------------- */

    /**
     * Un lead sin atribución (`desconocido`, sin ctwa_clid) que se promueve registra UN `Purchase`
     * por la web, pendiente, con el event_id del canal, y lo encola por la conexión database. Ni
     * `Lead` ni `Schedule`.
     *
     * @return void
     */
    public function test_promover_un_lead_sin_atribucion_registra_solo_el_purchase_por_la_web(): void
    {
        $lead = $this->crear_lead_sin_atribucion('closer_activo');
        $this->assertSame([], $this->filas_de($lead), 'Antes de promover, nada.');

        $lead->update(['promoted_client_id' => $this->crear_cliente()->id]);

        $this->assertSame([
            ['web', 'Purchase', 'cc-lead-' . $lead->id . '-web-purchase', LeadEventoMeta::ESTADO_PENDIENTE],
        ], $this->filas_de($lead));

        Queue::assertPushed(EnviarEventoMetaJob::class, 1);
        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) {
            return $job->connection === 'database';
        });
    }

    /**
     * El POST exacto: al dataset del píxel, `action_source: chat`, el teléfono en las dos variantes,
     * el país, el id propio, nombre y apellido, y la moneda y el valor del contrato.
     *
     * @return void
     */
    public function test_el_purchase_sin_atribucion_sale_con_el_body_exacto(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'Compra1'], 200)]);

        $numero = (string) random_int(1000000, 9999999);
        $lead   = $this->crear_lead_sin_atribucion('closer_activo', '+549343' . $numero);
        $lead->update([
            'contact_name'             => 'Alina Peña',
            'email'                    => ' Alina@LosPena.com.ar',
            'contract_currency'        => 'USD',
            'contract_precio_licencia' => '1.400',
            'promoted_client_id'       => $this->crear_cliente()->id,
        ]);

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->correr_job($fila);

        $esperado = [
            'data' => [[
                'event_name'    => 'Purchase',
                'event_time'    => $fila->fresh()->event_time->getTimestamp(),
                'event_id'      => 'cc-lead-' . $lead->id . '-web-purchase',
                'action_source' => 'chat',
                'user_data'     => [
                    'ph'          => [hash('sha256', '549343' . $numero), hash('sha256', '54343' . $numero)],
                    'em'          => [hash('sha256', 'alina@lospena.com.ar')],
                    'country'     => [hash('sha256', 'ar')],
                    'external_id' => [hash('sha256', 'cc-lead-' . $lead->id)],
                    'fn'          => [hash('sha256', 'alina')],
                    'ln'          => [hash('sha256', 'peña')],
                ],
                'custom_data'   => ['currency' => 'USD', 'value' => 1400],
            ]],
        ];

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($esperado) {
            return $request->url() === 'https://graph.facebook.com/v24.0/' . self::WEB_DATASET . '/events'
                && $request->hasHeader('Authorization', 'Bearer ' . self::WEB_TOKEN)
                && $request->data() === $esperado;
        });
        $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $fila->fresh()->estado);
    }

    /**
     * Otro save del lead, una segunda vuelta por la promoción y el conciliador no registran ni
     * encolan otro `Purchase`.
     *
     * @return void
     */
    public function test_ni_otro_save_ni_el_conciliador_duplican_la_compra(): void
    {
        $lead = $this->crear_lead_sin_atribucion('closer_activo');
        $lead->update(['promoted_client_id' => $this->crear_cliente()->id]);

        $lead->update(['status' => 'cerrado_ganado', 'notes' => 'Firmó']);
        $lead->update(['promoted_client_id' => $this->crear_cliente()->id]);
        app(MetaConversionesService::class)->registrar_compra($lead->fresh());
        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertSame(['Purchase'], $this->eventos_de($lead));
        Queue::assertPushed(EnviarEventoMetaJob::class, 1);
    }

    /**
     * 🔴 Un lead promovido de antes (nace promovido: no pasa por la promoción de este save) al que le
     * cambian el status no manda un `Purchase` con la fecha de hoy. Eso es del retroactivo.
     *
     * @return void
     */
    public function test_un_promovido_viejo_al_que_le_cambian_el_status_no_manda_nada(): void
    {
        $lead = $this->crear_lead_sin_atribucion('closer_activo', 'al_azar', $this->crear_cliente()->id);
        $this->assertSame([], $this->filas_de($lead));

        $lead->update(['status' => 'cerrado_ganado']);
        $lead->update(['notes' => 'Cliente desde agosto']);
        $this->assertSame(0, Artisan::call('leads:meta-capi-conciliar'));

        $this->assertSame([], $this->filas_de($lead));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
    }

    /**
     * Sin promover, un lead sin atribución sigue sin registrar nada aunque llegue a la demo.
     *
     * @return void
     */
    public function test_sin_promover_un_lead_sin_atribucion_no_registra_nada(): void
    {
        $lead = $this->crear_lead_sin_atribucion('nuevo');
        $lead->update(['status' => 'demo_agendada']);
        $lead->update(['status' => 'closer_activo']);

        $this->assertSame([], $this->filas_de($lead));
        $this->assertNull(MetaConversionesService::canal_de_la_compra($lead));
    }

    /**
     * Los leads con canal siguen exactamente como antes: el de la web registra `Lead`, `Schedule` y
     * `Purchase` en `web`; el de anuncio, los tres de WhatsApp. Ninguna fila de más.
     *
     * @return void
     */
    public function test_los_leads_con_canal_siguen_igual(): void
    {
        $web = $this->crear_lead_sin_atribucion('closer_activo');
        $web->update(['origen' => LeadOrigenService::ORIGEN_LANDING]);
        $web->update(['promoted_client_id' => $this->crear_cliente()->id]);
        $this->assertSame(['Lead', 'Schedule', 'Purchase'], $this->eventos_de($web));
        $this->assertSame(['web'], LeadEventoMeta::query()->where('lead_id', $web->id)->distinct()->pluck('canal')->all());

        $anuncio = $this->crear_lead_sin_atribucion('closer_activo');
        $anuncio->update(['ctwa_clid' => self::CLID, 'origen' => LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP]);
        $anuncio->update(['promoted_client_id' => $this->crear_cliente()->id]);
        $this->assertSame(['LeadSubmitted', 'QualifiedLead', 'Purchase'], $this->eventos_de($anuncio));
        $this->assertSame(['whatsapp'], LeadEventoMeta::query()->where('lead_id', $anuncio->id)->distinct()->pluck('canal')->all());
    }

    /**
     * Sin la configuración de la web, el `Purchase` queda `sin_configurar`, sin encolar ni llamar a
     * Meta. La promoción no se entera.
     *
     * @return void
     */
    public function test_sin_config_queda_sin_configurar_y_no_sale_nada(): void
    {
        config(['services.meta_capi.web_token' => '']);
        Http::fake();

        $lead = $this->crear_lead_sin_atribucion('closer_activo');
        $lead->update(['promoted_client_id' => $cliente_id = $this->crear_cliente()->id]);

        $this->assertSame($cliente_id, (int) $lead->fresh()->promoted_client_id);
        $this->assertSame([
            ['web', 'Purchase', 'cc-lead-' . $lead->id . '-web-purchase', LeadEventoMeta::ESTADO_SIN_CONFIGURAR],
        ], $this->filas_de($lead));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
        Http::assertNothingSent();
    }

    /**
     * Meta rechaza: la fila queda en `error` con un intento, para que el conciliador la reintente.
     *
     * @return void
     */
    public function test_si_meta_rechaza_queda_en_error_para_reintentar(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Caído', 'code' => 2]], 500)]);

        $lead = $this->crear_lead_sin_atribucion('closer_activo');
        $lead->update(['promoted_client_id' => $this->crear_cliente()->id]);

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_ERROR, app(MetaConversionesService::class)->enviar($fila));

        $fila->refresh();
        $this->assertSame(1, $fila->intentos);
        $this->assertStringContainsString('HTTP 500', (string) $fila->ultimo_error);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El fbc de la landing
     |----------------------------------------------------------------------------------------- */

    /**
     * Un lead que llegó por la landing con `fbclid` en su referencia manda `fbc` =
     * `fb.1.{ms de la referencia}.{fbclid}`, sin hash, del contacto más reciente. Un `fbclid` con
     * caracteres raros no se manda.
     *
     * @return void
     */
    public function test_el_fbc_sale_del_fbclid_de_la_referencia_de_la_landing(): void
    {
        $lead = $this->crear_lead_sin_atribucion('closer_activo');
        $this->assertNull(MetaConversionesService::fbc_del_lead($lead));

        $viejo = Carbon::parse('2026-10-15 10:00:00');
        $nuevo = Carbon::parse('2026-10-20 18:30:15');
        $this->crear_contacto_de_la_landing($lead, 'IwAR-viejo_123', $viejo, 100);
        $this->crear_contacto_de_la_landing($lead, 'IwZXh0bgNhZW0BMABh-nuevo', $nuevo, 200);
        $this->crear_contacto_de_la_landing($lead, 'raro<script>', Carbon::parse('2026-10-21 09:00:00'), 300);

        $this->assertSame('fb.1.' . ($nuevo->getTimestamp() * 1000) . '.IwZXh0bgNhZW0BMABh-nuevo', MetaConversionesService::fbc_del_lead($lead));

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $lead->update(['promoted_client_id' => $this->crear_cliente()->id]);
        $this->correr_job(LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail());

        Http::assertSent(function ($request) use ($nuevo) {
            return ($request->data()['data'][0]['user_data']['fbc'] ?? null) === 'fb.1.' . ($nuevo->getTimestamp() * 1000) . '.IwZXh0bgNhZW0BMABh-nuevo';
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El retroactivo
     |----------------------------------------------------------------------------------------- */

    /**
     * Simula por defecto: lista y no escribe nada.
     *
     * @return void
     */
    public function test_el_retroactivo_simula_por_defecto(): void
    {
        $lead = $this->crear_promovido_de_antes(2);

        $this->assertSame(0, Artisan::call('leads:meta-capi-compras'));
        $salida = Artisan::output();

        $this->assertStringContainsString('Simulación', $salida);
        $this->assertMatchesRegularExpression('/\|\s*' . $lead->id . '\s*\|.*\|\s*mandar\s*\|/u', $salida);
        $this->assertSame([], $this->filas_de($lead));
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
    }

    /**
     * 🔴 Con `--enviar --confirmar`: la promoción de hace 2 días sale con SU fecha; la de hace 20 días
     * queda `omitido` (Meta no acepta más de 7) y nunca sale; el que ya tenía su `Purchase` no se
     * toca. Una segunda corrida no registra nada.
     *
     * @return void
     */
    public function test_el_retroactivo_manda_solo_la_ventana_y_deja_omitido_lo_viejo(): void
    {
        $reciente = $this->crear_promovido_de_antes(2);
        $viejo    = $this->crear_promovido_de_antes(20);
        $ya       = $this->crear_promovido_de_antes(1);
        app(MetaConversionesService::class)->registrar_compra($ya, Carbon::now()->subDay(), null, null, false);

        $this->assertSame(0, Artisan::call('leads:meta-capi-compras', ['--enviar' => true, '--confirmar' => true]));

        $fila = LeadEventoMeta::query()->where('lead_id', $reciente->id)->firstOrFail();
        $this->assertSame(['web', 'Purchase', LeadEventoMeta::ESTADO_PENDIENTE], [$fila->canal, $fila->evento, $fila->estado]);
        $this->assertSame(
            Client::query()->find($reciente->promoted_client_id)->created_at->getTimestamp(),
            $fila->event_time->getTimestamp(),
            'El evento sale con la fecha real de la promoción.'
        );

        $omitida = LeadEventoMeta::query()->where('lead_id', $viejo->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $omitida->estado);
        $this->assertSame(LeadEventoMeta::MOTIVO_FUERA_DE_VENTANA, $omitida->ultimo_error);

        $this->assertCount(1, LeadEventoMeta::query()->where('lead_id', $ya->id)->get());

        Queue::assertPushed(EnviarEventoMetaJob::class, 1);

        /* El omitido no lo manda nadie, ni a mano. */
        Http::fake();
        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, app(MetaConversionesService::class)->enviar($omitida));
        Http::assertNothingSent();

        $this->assertSame(0, Artisan::call('leads:meta-capi-compras', ['--enviar' => true, '--confirmar' => true]));
        $this->assertCount(1, LeadEventoMeta::query()->where('lead_id', $reciente->id)->get());
        $this->assertCount(1, LeadEventoMeta::query()->where('lead_id', $viejo->id)->get());
        Queue::assertPushed(EnviarEventoMetaJob::class, 1);
    }

    /**
     * Un cliente anterior al lead (se vinculó uno que ya existía) no da una fecha de promoción
     * confiable: no se manda.
     *
     * @return void
     */
    public function test_el_retroactivo_no_inventa_la_fecha_de_promocion(): void
    {
        $cliente             = $this->crear_cliente();
        $cliente->created_at = Carbon::now()->subDays(30);
        $cliente->save();

        $lead             = $this->nuevo_lead_sin_atribucion('closer_activo', 'al_azar');
        $lead->created_at = Carbon::now()->subDays(3);
        $lead->promoted_client_id = $cliente->id;
        $lead->save();

        $this->assertSame(0, Artisan::call('leads:meta-capi-compras', ['--enviar' => true, '--confirmar' => true]));

        $fila = LeadEventoMeta::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $fila->estado);
        Queue::assertNotPushed(EnviarEventoMetaJob::class);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Lead sin atribución (`desconocido`, sin ctwa_clid), guardado.
     *
     * @param string   $status
     * @param string   $telefono   Default: un celular de Entre Ríos al azar.
     * @param int|null $client_id  Si nace promovido.
     *
     * @return Lead
     */
    private function crear_lead_sin_atribucion(string $status, string $telefono = 'al_azar', ?int $client_id = null): Lead
    {
        $lead                     = $this->nuevo_lead_sin_atribucion($status, $telefono);
        $lead->promoted_client_id = $client_id;
        $lead->save();

        return $lead;
    }

    /**
     * Lead sin atribución sin guardar.
     *
     * @param string $status
     * @param string $telefono
     *
     * @return Lead
     */
    private function nuevo_lead_sin_atribucion(string $status, string $telefono): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Lead sin atribucion ' . Str::random(5);
        $lead->phone        = $telefono === 'al_azar' ? '+549343' . random_int(1000000, 9999999) : $telefono;
        $lead->status       = $status;
        $lead->origen       = LeadOrigenService::ORIGEN_DESCONOCIDO;

        return $lead;
    }

    /**
     * Lead promovido ANTES de esta misión: nace promovido (el observer no lo ve como promoción),
     * con el cliente creado hace `$dias` días y el lead antes.
     *
     * @param int $dias
     *
     * @return Lead
     */
    private function crear_promovido_de_antes(int $dias): Lead
    {
        $cliente             = $this->crear_cliente();
        $cliente->created_at = Carbon::now()->subDays($dias);
        $cliente->save();

        $lead                     = $this->nuevo_lead_sin_atribucion('closer_activo', 'al_azar');
        $lead->created_at         = Carbon::now()->subDays($dias + 10);
        $lead->promoted_client_id = $cliente->id;
        $lead->save();

        return $lead;
    }

    /**
     * Referencia de la landing con su `fbclid`, consumida por un mensaje del lead.
     *
     * @param Lead   $lead
     * @param string $fbclid
     * @param Carbon $emitida
     * @param int    $orden    Para el message_id (el más alto es el más reciente).
     *
     * @return void
     */
    private function crear_contacto_de_la_landing(Lead $lead, string $fbclid, Carbon $emitida, int $orden): void
    {
        $datos = ['ad_id' => '999000001', 'utm_source' => 'facebook', 'fbclid' => $fbclid];

        $referencia_id = DB::table('landing_whatsapp_referencias')->insertGetId([
            'reference_hash'  => hash('sha256', Str::random(32)),
            'datos'           => json_encode($datos),
            'created_at'      => $emitida,
            'expires_at'      => $emitida->copy()->addDays(7),
            'claimed_at'      => $emitida->copy()->addMinute(),
            'claimed_lead_id' => $lead->id,
        ]);

        DB::table('lead_atribucion_contactos')->insert([
            'lead_id'       => $lead->id,
            'message_id'    => $lead->id * 1000 + $orden,
            'referencia_id' => $referencia_id,
            'tipo'          => $orden === 100 ? 'adquisicion' : 'recontacto',
            'evidencia'     => 'referencia_landing',
            'datos'         => json_encode($datos),
            'contacto_at'   => $emitida->copy()->addMinute(),
            'created_at'    => $emitida->copy()->addMinute(),
        ]);
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
     * Cliente mínimo para promover un lead (leads.promoted_client_id tiene FK a clients).
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $cliente                  = new Client();
        $cliente->name            = 'Cliente de prueba ' . Str::random(5);
        $cliente->company_name    = 'Negocio de prueba';
        $cliente->slug            = 'compras-meta-' . Str::lower(Str::random(10));
        $cliente->api_url         = 'https://ejemplo.test';
        $cliente->api_key         = 'clave-sembrada-' . Str::random(10);
        $cliente->inbound_api_key = 'clave-sembrada-inbound-' . Str::random(10);
        $cliente->is_active       = true;
        $cliente->save();

        return $cliente;
    }
}
