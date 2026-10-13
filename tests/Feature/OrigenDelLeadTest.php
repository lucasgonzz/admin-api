<?php

namespace Tests\Feature;

use App\Helpers\WhatsappNormalizer;
use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use App\Models\WhatsappConfig;
use App\Services\LeadOrigenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El origen del lead (misión origen-del-lead-y-conversiones-meta, 13/10/2026): de dónde vino, leído
 * de su PRIMER mensaje entrante, con los dos webhooks reales de por medio (el de Kapso, firmado con
 * HMAC, que crea el lead y su mensaje; y el crudo de Meta, que guarda el referral del anuncio) y en
 * los dos órdenes en que pueden llegar.
 *
 * También se verifica lo que no se ve: que un lead viejo que hoy escribe la frase de la web no se
 * recalcula, que el comando de los leads viejos simula sin escribir, y que el origen sale por
 * claude/leads, claude/query y la definición del panel.
 */
class OrigenDelLeadTest extends TestCase
{
    use DatabaseTransactions;

    /** Secreto con el que se firman los webhooks de Kapso. */
    private const SECRETO = 'secreto-de-prueba-del-webhook-origen';

    /** Token del webhook crudo de Meta. */
    private const TOKEN_META = 'token-de-prueba-del-webhook-meta-origen-1234567890';

    /** Clave de ingesta de claude/*. */
    private const CLAVE = 'clave-de-prueba-claude-origen';

    /** Click id del anuncio de las pruebas. */
    private const CLID = 'ARAkLkA-clid-de-prueba-origen';

    /**
     * Sin red, con la cola falseada y una configuración de WhatsApp activa en modo prueba que acepta
     * los dos webhooks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
        config(['services.meta_capi.token' => '', 'services.meta_capi.dataset_id' => '']);

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                     = new WhatsappConfig();
        $config->kapso_api_key      = 'clave-de-prueba';
        $config->phone_number_id    = '1234567890';
        $config->webhook_secret     = self::SECRETO;
        $config->meta_webhook_token = self::TOKEN_META;
        $config->is_active          = true;
        $config->test_mode          = true;
        $config->save();
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Teléfono al azar en el formato en que lo manda Kapso (+549...), para que ningún lead de la
     * base de testing lo comparta (ni por sufijo, que es como busca el webhook).
     *
     * @return string
     */
    private function telefono_al_azar(): string
    {
        return '+54911' . random_int(10000000, 99999999);
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
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Lead de prueba'],
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
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body);
    }

    /**
     * Pega al webhook crudo de Meta con un mensaje que trae el bloque `referral`.
     *
     * @param string $telefono
     * @param string $wamid
     * @param int    $timestamp
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_referral(string $telefono, string $wamid, int $timestamp)
    {
        $sin_mas = ltrim($telefono, '+');
        $body    = json_encode([
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => '102030405060708',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata'          => ['display_phone_number' => '5493410000000', 'phone_number_id' => '1234567890'],
                        'contacts'          => [['profile' => ['name' => 'Lead de prueba'], 'wa_id' => $sin_mas]],
                        'messages'          => [[
                            'from'      => $sin_mas,
                            'id'        => $wamid,
                            'timestamp' => (string) $timestamp,
                            'type'      => 'text',
                            'text'      => ['body' => 'Hola, vi el anuncio'],
                            'referral'  => [
                                'source_url'  => 'https://fb.me/anuncio-de-prueba',
                                'source_id'   => '120210000000000000',
                                'source_type' => 'ad',
                                'headline'    => 'Ordená tu negocio',
                                'ctwa_clid'   => self::CLID,
                            ],
                        ]],
                    ],
                ]],
            ]],
        ]);

        return $this->call('POST', '/api/webhook/meta-raw/' . self::TOKEN_META, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * Lead del teléfono, recién leído de la base.
     *
     * @param string $telefono
     *
     * @return Lead|null
     */
    private function lead_del_telefono(string $telefono): ?Lead
    {
        return Lead::query()->where('phone', WhatsappNormalizer::normalize($telefono))->orderByDesc('id')->first();
    }

    /**
     * Headers de claude/*.
     *
     * @return array<string, string>
     */
    private function headers_claude(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Lead con el origen ya escrito (o NULL), sin pasar por ningún webhook.
     *
     * @param string|null $origen
     *
     * @return Lead
     */
    private function crear_lead_con_origen(?string $origen): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Lead ' . Str::random(6);
        $lead->phone        = WhatsappNormalizer::normalize($this->telefono_al_azar());
        $lead->status       = 'contactado';
        $lead->origen       = $origen;
        $lead->save();

        return $lead;
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El anuncio, en los dos órdenes
     |----------------------------------------------------------------------------------------- */

    /**
     * El referral llega ANTES que el webhook de Kapso y engancha por wamid exacto.
     *
     * @return void
     */
    public function test_referral_antes_del_primer_mensaje_por_wamid(): void
    {
        $telefono = $this->telefono_al_azar();
        $momento  = time() - 120;

        $this->postear_referral($telefono, 'wamid.ORIGEN-ANTES-1', $momento)->assertStatus(200);
        $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.ORIGEN-ANTES-1', $momento)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead, 'El webhook de Kapso tenía que crear el lead.');
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $lead->origen);
        $this->assertSame(self::CLID, $lead->ctwa_clid);
        $this->assertNotNull($lead->origen_calculado_at);
    }

    /**
     * El referral llega ANTES con otro wamid: engancha por teléfono y por estar a menos de 30 s.
     *
     * @return void
     */
    public function test_referral_antes_del_primer_mensaje_por_telefono_a_menos_de_30_segundos(): void
    {
        $telefono = $this->telefono_al_azar();
        $momento  = time() - 120;

        $this->postear_referral($telefono, 'wamid.ORIGEN-REF-2', $momento)->assertStatus(200);
        $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.ORIGEN-MSG-2', $momento + 20)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $lead->origen);
        $this->assertSame(self::CLID, $lead->ctwa_clid);
    }

    /**
     * El referral llega DESPUÉS del webhook de Kapso: el lead ya tenía origen por la frase y pasa a
     * anuncio, porque el anuncio gana.
     *
     * @return void
     */
    public function test_referral_despues_del_primer_mensaje_pasa_a_anuncio(): void
    {
        $telefono = $this->telefono_al_azar();
        $momento  = time() - 120;

        $this->postear_kapso($telefono, LeadOrigenService::FRASE_LANDING, 'wamid.ORIGEN-DESPUES-3', $momento)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen, 'Antes del referral, el origen sale de la frase.');

        $this->postear_referral($telefono, 'wamid.ORIGEN-DESPUES-3', $momento)->assertStatus(200);

        $lead->refresh();
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $lead->origen);
        $this->assertSame(self::CLID, $lead->ctwa_clid);
    }

    /**
     * A 31 segundos ya no es el mismo mensaje.
     *
     * @return void
     */
    public function test_referral_a_31_segundos_no_engancha(): void
    {
        $telefono = $this->telefono_al_azar();
        $momento  = time() - 120;

        $this->postear_referral($telefono, 'wamid.ORIGEN-REF-4', $momento)->assertStatus(200);
        $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.ORIGEN-MSG-4', $momento + 31)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame(LeadOrigenService::ORIGEN_DESCONOCIDO, $lead->origen);
        $this->assertNull($lead->ctwa_clid);
    }

    /**
     * Un referral de OTRO teléfono en el mismo segundo no es de este lead.
     *
     * @return void
     */
    public function test_referral_de_otro_telefono_no_engancha(): void
    {
        $telefono = $this->telefono_al_azar();
        $otro     = $this->telefono_al_azar();
        $momento  = time() - 120;

        $this->postear_referral($otro, 'wamid.ORIGEN-REF-5', $momento)->assertStatus(200);
        $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.ORIGEN-MSG-5', $momento)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame(LeadOrigenService::ORIGEN_DESCONOCIDO, $lead->origen);
        $this->assertNull($lead->ctwa_clid);
    }

    /**
     * El referral llega DESPUÉS con un wamid DISTINTO al del mensaje: engancha por teléfono y ±30 s.
     * Es el camino real si el `message.id` de Kapso no fuera el wamid de Meta.
     *
     * @return void
     */
    public function test_referral_despues_con_otro_wamid_engancha_por_telefono(): void
    {
        $telefono = $this->telefono_al_azar();
        $momento  = time() - 120;

        $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.ORIGEN-MSG-6', $momento)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame(LeadOrigenService::ORIGEN_DESCONOCIDO, $lead->origen, 'Antes del referral no hay señal.');

        $this->postear_referral($telefono, 'wamid.ORIGEN-REF-6', $momento + 20)->assertStatus(200);

        $lead->refresh();
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $lead->origen);
        $this->assertSame(self::CLID, $lead->ctwa_clid);
    }

    /**
     * El borde exacto de la tolerancia, en los dos órdenes de llegada y siempre con wamids distintos
     * (o sea, solo por teléfono y tiempo): a 30 s engancha, a 31 s no.
     *
     * @return array<string, array<int, mixed>>
     */
    public function bordes_de_la_tolerancia(): array
    {
        return [
            'referral antes, a 30 s'   => ['antes', 30, LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP],
            'referral antes, a 31 s'   => ['antes', 31, LeadOrigenService::ORIGEN_DESCONOCIDO],
            'referral después, a 30 s' => ['despues', 30, LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP],
            'referral después, a 31 s' => ['despues', 31, LeadOrigenService::ORIGEN_DESCONOCIDO],
        ];
    }

    /**
     * @dataProvider bordes_de_la_tolerancia
     *
     * @param string $orden    `antes`: el referral llega primero; `despues`: llega después del mensaje.
     * @param int    $segundos Distancia entre el `received_at` del referral y el `sent_at` del mensaje.
     * @param string $esperado
     *
     * @return void
     */
    public function test_el_borde_de_los_30_segundos_en_los_dos_ordenes(string $orden, int $segundos, string $esperado): void
    {
        $telefono = $this->telefono_al_azar();
        $momento  = time() - 300;
        $sufijo   = Str::random(8);

        if ($orden === 'antes') {
            /* El mensaje sale $segundos DESPUÉS del clic. */
            $this->postear_referral($telefono, 'wamid.BORDE-REF-' . $sufijo, $momento)->assertStatus(200);
            $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.BORDE-MSG-' . $sufijo, $momento + $segundos)->assertStatus(200);
        } else {
            /* El referral dice $segundos más que el mensaje, y llega después. */
            $this->postear_kapso($telefono, 'Hola, quiero saber más', 'wamid.BORDE-MSG-' . $sufijo, $momento)->assertStatus(200);
            $this->postear_referral($telefono, 'wamid.BORDE-REF-' . $sufijo, $momento + $segundos)->assertStatus(200);
        }

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame($esperado, $lead->origen);
        if ($esperado === LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP) {
            $this->assertSame(self::CLID, $lead->ctwa_clid);
        } else {
            $this->assertNull($lead->ctwa_clid);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Las frases
     |----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, array<int, string>>
     */
    public function frases_del_primer_mensaje(): array
    {
        return [
            'frase nueva de la web'               => ['Hola, quiero hacer la demo de ComercioCity', 'landing'],
            'frase nueva en mayúsculas'           => ['HOLA, QUIERO HACER LA DEMO DE COMERCIOCITY', 'landing'],
            'frase nueva sin signos'              => ['hola quiero hacer la demo de comerciocity', 'landing'],
            'frase nueva con un saludo delante'   => ['Buenas! Hola, quiero hacer la demo de ComercioCity', 'landing'],
            'frase vieja de la web'               => ['Hola Martín, quiero hacer la demo de ComercioCity', 'landing'],
            'frase de Instagram'                  => ['Hola Martín, vengo de Instagram y quiero hacer la demo de ComercioCity', 'instagram'],
            'cualquier otro texto'                => ['Hola, quiero info del sistema para mi ferretería', 'desconocido'],
            'la frase cortada no alcanza'         => ['Hola, quiero hacer la demo', 'desconocido'],
        ];
    }

    /**
     * El primer mensaje decide el origen por la frase, normalizada.
     *
     * @dataProvider frases_del_primer_mensaje
     *
     * @param string $texto
     * @param string $esperado
     *
     * @return void
     */
    public function test_la_frase_del_primer_mensaje_decide_el_origen(string $texto, string $esperado): void
    {
        $telefono = $this->telefono_al_azar();

        $this->postear_kapso($telefono, $texto, 'wamid.ORIGEN-FRASE-' . Str::random(10), time() - 60)->assertStatus(200);

        $lead = $this->lead_del_telefono($telefono);
        $this->assertNotNull($lead);
        $this->assertSame($esperado, $lead->origen);
        $this->assertNull($lead->ctwa_clid);
    }

    /**
     * 🔴 Un lead viejo (con mensajes anteriores y sin origen) que HOY escribe la frase de la web no
     * se recalcula: ese mensaje no es el primero.
     *
     * @return void
     */
    public function test_un_lead_viejo_que_escribe_la_frase_hoy_no_se_recalcula(): void
    {
        $telefono = $this->telefono_al_azar();

        $lead               = new Lead();
        $lead->contact_name = 'Lead viejo';
        $lead->phone        = WhatsappNormalizer::normalize($telefono);
        $lead->status       = 'contactado';
        $lead->save();

        $viejo                        = new LeadMessage();
        $viejo->lead_id               = $lead->id;
        $viejo->sender                = 'lead';
        $viejo->status                = 'enviado';
        $viejo->is_followup           = false;
        $viejo->requiere_verificacion = false;
        $viejo->content               = 'Hola, pasame precios';
        $viejo->sent_at               = Carbon::now()->subDays(30);
        $viejo->save();

        /* Lead anterior al deploy: sin origen, aunque el observer ya lo haya calculado al crear el
           mensaje de arriba. */
        DB::table('leads')->where('id', $lead->id)->update(['origen' => null, 'origen_calculado_at' => null]);

        $this->postear_kapso($telefono, LeadOrigenService::FRASE_LANDING, 'wamid.ORIGEN-VIEJO-' . Str::random(8), time() - 60)
            ->assertStatus(200);

        $this->assertSame(2, LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->count(), 'El mensaje nuevo tenía que caer en el lead viejo.');
        $this->assertNull($lead->fresh()->origen);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El comando de los leads viejos
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin --aplicar simula y no escribe; con --aplicar escribe, con la misma regla (acá, el anuncio
     * por wamid, con su ctwa_clid).
     *
     * @return void
     */
    public function test_calcular_origen_simula_sin_escribir_y_con_aplicar_escribe(): void
    {
        $telefono = WhatsappNormalizer::normalize($this->telefono_al_azar());

        $de_anuncio               = new Lead();
        $de_anuncio->contact_name = 'Lead viejo de anuncio';
        $de_anuncio->phone        = $telefono;
        $de_anuncio->status       = 'calificado';
        $de_anuncio->save();

        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $de_anuncio->id;
        $mensaje->sender                = 'lead';
        $mensaje->status                = 'enviado';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = false;
        $mensaje->content               = 'Hola, vi el anuncio';
        $mensaje->whatsapp_message_id   = 'wamid.ORIGEN-COMANDO-' . Str::random(8);
        $mensaje->sent_at               = Carbon::now()->subDays(20);
        $mensaje->save();

        $de_la_web               = new Lead();
        $de_la_web->contact_name = 'Lead viejo de la web';
        $de_la_web->phone        = WhatsappNormalizer::normalize($this->telefono_al_azar());
        $de_la_web->status       = 'contactado';
        $de_la_web->save();

        $mensaje_web                        = new LeadMessage();
        $mensaje_web->lead_id               = $de_la_web->id;
        $mensaje_web->sender                = 'lead';
        $mensaje_web->status                = 'enviado';
        $mensaje_web->is_followup           = false;
        $mensaje_web->requiere_verificacion = false;
        $mensaje_web->content               = LeadOrigenService::FRASE_LANDING_VIEJA;
        $mensaje_web->sent_at               = Carbon::now()->subDays(20);
        $mensaje_web->save();

        /* El referral llega después de que los dos leads ya existen, pero se guarda sin pasar por el
           observer, como si fuera anterior al deploy. */
        WhatsappAdReferral::withoutEvents(function () use ($telefono, $mensaje) {
            WhatsappAdReferral::create([
                'phone'       => $telefono,
                'ctwa_clid'   => self::CLID,
                'wamid'       => $mensaje->whatsapp_message_id,
                'received_at' => $mensaje->sent_at,
            ]);
        });

        DB::table('leads')->whereIn('id', [$de_anuncio->id, $de_la_web->id])
            ->update(['origen' => null, 'ctwa_clid' => null, 'origen_calculado_at' => null]);

        $hoy = Carbon::now()->format('Y-m-d');

        $this->assertSame(0, Artisan::call('leads:calcular-origen', ['--desde' => $hoy]));
        $this->assertNull($de_anuncio->fresh()->origen, 'Sin --aplicar no se escribe nada.');
        $this->assertNull($de_la_web->fresh()->origen, 'Sin --aplicar no se escribe nada.');

        $this->assertSame(0, Artisan::call('leads:calcular-origen', ['--desde' => $hoy, '--aplicar' => true]));

        $de_anuncio->refresh();
        $this->assertSame(LeadOrigenService::ORIGEN_ANUNCIO_WHATSAPP, $de_anuncio->origen);
        $this->assertSame(self::CLID, $de_anuncio->ctwa_clid);
        $this->assertNotNull($de_anuncio->origen_calculado_at);
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $de_la_web->fresh()->origen);

        /* Una fecha mal escrita no corre nada. */
        $this->assertSame(1, Artisan::call('leads:calcular-origen', ['--desde' => 'ayer']));
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Por dónde se ve
     |----------------------------------------------------------------------------------------- */

    /**
     * claude/leads devuelve `origen` sin pedirlo y filtra por una lista; desconocido incluye los
     * NULL; un valor inválido es 422 con los válidos.
     *
     * @return void
     */
    public function test_claude_leads_devuelve_y_filtra_por_origen(): void
    {
        $landing     = $this->crear_lead_con_origen('landing');
        $instagram   = $this->crear_lead_con_origen('instagram');
        $anuncio     = $this->crear_lead_con_origen('anuncio_whatsapp');
        $desconocido = $this->crear_lead_con_origen('desconocido');
        $sin_calculo = $this->crear_lead_con_origen(null);

        $ids = implode(',', [$landing->id, $instagram->id, $anuncio->id, $desconocido->id, $sin_calculo->id]);

        $todos = $this->getJson('/api/claude/leads?lead_ids=' . $ids, $this->headers_claude());
        $todos->assertStatus(200);
        $por_id = [];
        foreach ($todos->json('data') as $fila) {
            $this->assertArrayHasKey('origen', $fila, 'origen tiene que viajar en la proyección por defecto.');
            $this->assertArrayNotHasKey('ctwa_clid', $fila, 'El ctwa_clid no viaja por claude/leads.');
            $por_id[$fila['id']] = $fila['origen'];
        }
        $this->assertSame('landing', $por_id[$landing->id]);
        $this->assertNull($por_id[$sin_calculo->id]);

        $filtrados = $this->getJson('/api/claude/leads?lead_ids=' . $ids . '&origen=landing,instagram', $this->headers_claude());
        $filtrados->assertStatus(200);
        $ids_filtrados = array_column($filtrados->json('data'), 'id');
        sort($ids_filtrados);
        $esperados = [(int) $landing->id, (int) $instagram->id];
        sort($esperados);
        $this->assertSame($esperados, $ids_filtrados);

        $sin_dato = $this->getJson('/api/claude/leads?lead_ids=' . $ids . '&origen=desconocido', $this->headers_claude());
        $sin_dato->assertStatus(200);
        $ids_sin_dato = array_column($sin_dato->json('data'), 'id');
        sort($ids_sin_dato);
        $esperados_sin_dato = [(int) $desconocido->id, (int) $sin_calculo->id];
        sort($esperados_sin_dato);
        $this->assertSame($esperados_sin_dato, $ids_sin_dato, 'desconocido incluye los leads sin origen calculado.');

        $this->getJson('/api/claude/leads?origen=facebook', $this->headers_claude())
            ->assertStatus(422)
            ->assertJsonPath('origenes_validos', LeadOrigenService::ORIGENES);
    }

    /**
     * claude/query?model=lead&origen=landing filtra, devuelve las columnas nuevas y deja el
     * ctwa_clid detrás del include `atribucion`.
     *
     * @return void
     */
    public function test_claude_query_filtra_por_origen(): void
    {
        $landing = $this->crear_lead_con_origen('landing');
        $otro    = $this->crear_lead_con_origen('instagram');
        DB::table('leads')->where('id', $landing->id)->update(['ctwa_clid' => 'clid-que-no-viaja-sin-include']);

        $respuesta = $this->getJson('/api/claude/query?' . http_build_query([
            'model'  => 'lead',
            'origen' => 'landing',
            'ids'    => $landing->id . ',' . $otro->id,
        ]), $this->headers_claude());

        $respuesta->assertStatus(200);
        $filas = $respuesta->json('data');
        $this->assertCount(1, $filas);
        $this->assertSame((int) $landing->id, (int) $filas[0]['id']);
        $this->assertSame('landing', $filas[0]['origen']);
        $this->assertArrayHasKey('origen_calculado_at', $filas[0]);
        $this->assertArrayNotHasKey('ctwa_clid', $filas[0]);

        $con_atribucion = $this->getJson('/api/claude/query?' . http_build_query([
            'model'   => 'lead',
            'ids'     => (string) $landing->id,
            'include' => 'atribucion',
        ]), $this->headers_claude());
        $con_atribucion->assertStatus(200);
        $this->assertSame('clid-que-no-viaja-sin-include', $con_atribucion->json('data.0.ctwa_clid'));

        $this->getJson('/api/claude/query?model=lead&origen=facebook', $this->headers_claude())->assertStatus(422);
    }

    /**
     * El panel recibe la propiedad `origen` de solo lectura, con sus cuatro opciones.
     *
     * @return void
     */
    public function test_meta_lead_trae_la_propiedad_origen_con_sus_opciones(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'origen-del-lead-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        Sanctum::actingAs($admin);

        $respuesta = $this->getJson('/api/admin/meta/lead');
        $respuesta->assertStatus(200);

        $propiedad = null;
        foreach ($respuesta->json('properties') as $candidata) {
            if (isset($candidata['key']) && $candidata['key'] === 'origen') {
                $propiedad = $candidata;
            }
        }

        $this->assertNotNull($propiedad, 'meta/lead tiene que traer la propiedad origen.');
        $this->assertSame('pipeline_status', $propiedad['type']);
        $this->assertTrue($propiedad['only_show']);
        $this->assertTrue($propiedad['exclude_on_update']);
        $this->assertTrue($propiedad['use_to_filter_in_search']);
        $this->assertSame(LeadOrigenService::ORIGENES, array_column($propiedad['options'], 'value'));
        $this->assertSame(['Anuncio de WhatsApp', 'Web', 'Instagram', 'Sin dato'], array_column($propiedad['options'], 'text'));
        foreach ($propiedad['options'] as $opcion) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $opcion['color']);
        }
    }

    /**
     * El filtro del panel: lo que manda el SPA al filtrar la columna Origen (convierte el tipo
     * `pipeline_status` a `select`) por `POST admin/search/lead/null/1` trae solo los de ese origen.
     *
     * @return void
     */
    public function test_el_filtro_del_panel_por_origen_trae_solo_ese_origen(): void
    {
        $landing_1   = $this->crear_lead_con_origen('landing');
        $landing_2   = $this->crear_lead_con_origen('landing');
        $instagram   = $this->crear_lead_con_origen('instagram');
        $sin_calculo = $this->crear_lead_con_origen(null);

        $admin = Admin::create([
            'name'     => 'Operador de prueba',
            'email'    => 'origen-filtro-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/search/lead/null/1?page=1', [
                'filters'  => [
                    ['key' => 'origen', 'type' => 'select', 'igual_que' => 'landing'],
                ],
                'per_page' => 200,
            ]);
        $respuesta->assertStatus(200);

        $filas = $respuesta->json('data');
        $this->assertNotEmpty($filas);
        foreach ($filas as $fila) {
            $this->assertSame('landing', $fila['origen'], 'El filtro trajo un lead de otro origen.');
        }

        $ids = array_map('intval', array_column($filas, 'id'));
        $this->assertContains((int) $landing_1->id, $ids);
        $this->assertContains((int) $landing_2->id, $ids);
        $this->assertNotContains((int) $instagram->id, $ids);
        $this->assertNotContains((int) $sin_calculo->id, $ids);
    }
}
