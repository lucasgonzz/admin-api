<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Services\AsistenteDemoLeadService;
use App\Services\AsistenteWhatsappService;
use App\Services\DemoDirectaService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * El asistente personal para el LEAD durante su turno de demo (misión mail-demo-turno-y-asistente,
 * 16/10/2026).
 *
 * Lo que estas pruebas cuidan, en el orden en que importa:
 *
 *   1. **Solo entra el lead que está DENTRO de su turno**, con la instancia armada y su teléfono
 *      EXACTO. Cualquier otro (turno vencido, setup pendiente, estado que no ocupa la instancia,
 *      teléfono parecido, otro país) no habla con el asistente de nadie.
 *   2. **A un lead nunca se le abre un hilo de dueño**: no se le crea lead ni ticket y no se toca la
 *      tabla `clients` (la demo no es un cliente).
 *   3. **El dueño de siempre no cambia**: la fila de un dueño no lleva `demo_id` y el job la tramita
 *      como antes (esas pruebas son las del resto de esta carpeta).
 *   4. **La respuesta sale al teléfono del LEAD** y el job le habla a la instancia de la DEMO, con la
 *      clave de la demo.
 */
class AsistenteDeLaDemoParaLeadsTest extends BaseDelCanal
{
    /** Teléfono del lead de prueba, tal como llega en el payload de WhatsApp. */
    const TELEFONO_LEAD = '5493417778899';

    /** Zona de la demo. */
    const TZ = DemoDirectaService::TZ;

    /**
     * Reloj fijo (viernes 16/10/2026 15:30), los dos números activos y la cola/envío fakeados.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-16 15:30:00', self::TZ));
        Cache::flush();

        // Lo que define cuándo vence un turno (una sola definición para el mail, la página y el asistente).
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');

        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Instancia de demo con clave propia y hosting VPS (sin `/public` en la URL).
     *
     * @param string $api_key Clave de la demo; '' = sin clave.
     *
     * @return Demo
     */
    private function crear_demo(string $api_key = 'clave-de-la-demo'): Demo
    {
        $demo                    = new Demo();
        $demo->nombre            = 'Demo 7';
        $demo->erp_spa_url       = 'https://demo7-erp.test';
        $demo->erp_api_url       = 'https://demo7-erp-api.test';
        $demo->erp_hosting_type  = 'vps';
        $demo->ecommerce_spa_url = 'https://demo7-tienda.test';
        $demo->ecommerce_api_url = 'https://demo7-tienda-api.test';
        $demo->api_key           = $api_key;
        $demo->save();

        return $demo;
    }

    /**
     * Lead con su turno de 15:10 a 17:10 de hoy y el setup terminado: el que SÍ puede hablar.
     *
     * @param Demo                 $demo   Instancia asignada.
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return Lead
     */
    private function crear_lead(Demo $demo, array $campos = []): Lead
    {
        $lead = new Lead();
        $lead->forceFill(array_merge([
            'phone'             => '+' . self::TELEFONO_LEAD,
            'contact_name'      => 'Guillermo González',
            'email'             => 'guillermo@ejemplo.test',
            'status'            => 'demo_agendada',
            'demo_id'           => $demo->id,
            'demo_date'         => '2026-10-16',
            'demo_start_time'   => '15:10',
            'demo_end_time'     => '17:10',
            'demo_setup_status' => 'exitoso',
        ], $campos));
        $lead->save();

        return $lead;
    }

    /**
     * Pega al webhook por el número del ASISTENTE con la firma de ese número.
     *
     * @param string $telefono Remitente (formato de WhatsApp, sin +).
     * @param string $texto    Cuerpo.
     * @param string $wamid    ID del mensaje.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function escribir_al_asistente(string $telefono, string $texto = 'Cuánto vendimos hoy?', string $wamid = 'wamid.LEAD1')
    {
        return $this->postear_webhook(
            $this->payload_de_texto($telefono, $texto, $wamid, null, self::PHONE_NUMBER_ID_ASISTENTE),
            self::SECRETO_DEL_ASISTENTE
        )->assertStatus(200);
    }

    // ---------------------------------------------------------------------------------------------
    // 1. El lead dentro de su turno entra al asistente de SU demo
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 El lead en turno deja una fila apuntada a su demo, sin crear lead ni ticket ni tocar `clients`.
     *
     * @return void
     */
    public function test_el_lead_en_turno_habla_con_el_asistente_de_su_demo(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo();
        $lead  = $this->crear_lead($demo);

        $leads_antes    = Lead::count();
        $clientes_antes = Client::count();

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $fila = ClientAssistantMessage::where('demo_id', $demo->id)->first();
        $this->assertNotNull($fila, 'Tiene que quedar la fila entrante del hilo de la demo.');
        $this->assertSame(0, (int) $fila->client_id, 'El hilo de un lead no cuelga de ningún cliente.');
        $this->assertSame((int) $lead->id, (int) $fila->lead_id);
        $this->assertSame(ClientAssistantMessage::DIRECCION_ENTRANTE, $fila->direccion);
        $this->assertSame('Cuánto vendimos hoy?', $fila->texto);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, (string) $fila->phone_number_id);

        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class, 1);
        $this->assertSame($leads_antes, Lead::count(), 'No se crea ningún lead.');
        $this->assertSame($clientes_antes, Client::count(), 'La demo no es un cliente: no se le crea una fila.');
        $this->assertSame(0, SupportTicket::count(), 'No se abre ningún ticket.');
        $this->assertCount(0, $espia->textos, 'En turno no se le manda ningún texto fijo: contesta el asistente.');
    }

    /**
     * La fila de un dueño de cliente NO lleva `demo_id` ni `lead_id`: el camino de siempre.
     *
     * @return void
     */
    public function test_el_dueno_de_un_cliente_no_se_ve_afectado(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $client = $this->crear_cliente('+5493411234567', true);

        $inserts = [];
        DB::listen(function ($consulta) use (&$inserts) {
            if (stripos($consulta->sql, 'insert into `client_assistant_messages`') === 0) {
                $inserts[] = $consulta->sql;
            }
        });

        $this->escribir_al_asistente('5493411234567', 'Hola', 'wamid.DUENO1');

        $this->assertNotEmpty($inserts);
        foreach ($inserts as $sql) {
            $this->assertStringNotContainsString('demo_id', $sql, 'La fila de un dueño no toca las columnas de la demo: con el código nuevo sobre una base sin la migración, su INSERT tiene que seguir andando.');
            $this->assertStringNotContainsString('lead_id', $sql);
        }

        $fila = ClientAssistantMessage::where('client_id', $client->id)->first();
        $this->assertNotNull($fila);
        $this->assertNull($fila->demo_id);
        $this->assertNull($fila->lead_id);
        $this->assertFalse($fila->es_de_demo());
    }

    /**
     * Si el mismo teléfono es de un dueño de cliente Y de un lead con turno, gana el dueño.
     *
     * @return void
     */
    public function test_si_es_dueno_y_lead_a_la_vez_gana_el_dueno(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $client = $this->crear_cliente('+' . self::TELEFONO_LEAD, true);
        $this->crear_lead($this->crear_demo());

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $this->assertSame(1, ClientAssistantMessage::where('client_id', $client->id)->count());
        $this->assertSame(0, ClientAssistantMessage::whereNotNull('demo_id')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Quién NO entra
    // ---------------------------------------------------------------------------------------------

    /**
     * El turno vencido no entra: recibe el texto de "no tenés una demo activa" y nada más, una sola vez.
     *
     * @return void
     */
    public function test_con_el_turno_vencido_recibe_el_texto_y_no_entra(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo();
        // 12:00 a 14:00: vence a las 14:10 (fin + gracia), y ahora son las 15:30.
        $this->crear_lead($demo, ['demo_start_time' => '12:00', 'demo_end_time' => '14:00']);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);
        $this->escribir_al_asistente(self::TELEFONO_LEAD, 'Hola otra vez', 'wamid.LEAD2');

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertCount(1, $espia->textos, 'Una sola respuesta cada seis horas por teléfono.');
        $this->assertStringContainsString('no tenés una demo activa', $espia->textos[0]['body']);
        $this->assertStringContainsString('+54 9 3444544199', $espia->textos[0]['body']);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, (string) $espia->destinos[0], 'Sale por el número por el que entró.');
    }

    /**
     * Con la instancia todavía armándose (setup sin terminar) recibe un texto distinto y no entra.
     *
     * @return void
     */
    public function test_con_el_setup_sin_terminar_recibe_el_texto_de_preparando(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_lead($this->crear_demo(), ['demo_setup_status' => 'ejecutandose']);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertCount(1, $espia->textos);
        $this->assertStringContainsString('se está armando', $espia->textos[0]['body']);
    }

    /**
     * Un lead cuyo estado ya no ocupa la instancia (demo realizada, perdido, etc.) no entra.
     *
     * @return void
     */
    public function test_un_estado_que_no_ocupa_la_instancia_no_entra(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_lead($this->crear_demo(), ['status' => 'demo_realizada']);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        // No es de ningún lead con demo vigente: recibe el texto de siempre de los remitentes ajenos.
        $this->assertCount(1, $espia->textos);
        $this->assertStringContainsString('atiende solo a los dueños', $espia->textos[0]['body']);
    }

    /**
     * 🔴 Un teléfono PARECIDO no abre el asistente de la demo de otro: se compara el número completo, no
     * los últimos ocho dígitos (Rosario y Córdoba comparten los ocho finales).
     *
     * @return void
     */
    public function test_un_telefono_parecido_no_entra_a_la_demo_de_otro(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_lead($this->crear_demo(), ['phone' => '+5493517778899']);

        // Mismos ocho dígitos finales (17778899), otra característica (341 vs 351).
        $this->escribir_al_asistente('5493417778899');

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertStringContainsString('atiende solo a los dueños', $espia->textos[0]['body']);
    }

    /**
     * Un remitente de otro país no recibe respuesta ni entra: el envío normaliza a +54 y mandaría el
     * mensaje a un número que no existe.
     *
     * @return void
     */
    public function test_un_remitente_de_otro_pais_no_entra_ni_recibe_respuesta(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_lead($this->crear_demo(), ['phone' => '+12015550123']);

        $this->escribir_al_asistente('12015550123');

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertCount(0, $espia->textos);
    }

    /**
     * El interruptor propio apaga el canal para leads: el lead en turno recibe el texto de los ajenos.
     *
     * @return void
     */
    public function test_el_interruptor_apagado_deja_al_lead_como_un_ajeno(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $this->crear_lead($this->crear_demo());
        AdminSetting::set(AsistenteDemoLeadService::KEY_ACTIVO, '0');

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertStringContainsString('atiende solo a los dueños', $espia->textos[0]['body']);
    }

    /**
     * El borde de la ventana: 15 minutos antes del inicio ya entra, 16 no; y termina cuando vence el turno
     * para el resto del ciclo (fin 17:10 + gracia de 10 minutos = 17:20), no antes: un lead que todavía
     * puede operar en su demo no se queda sin asistente.
     *
     * @return void
     */
    public function test_los_bordes_de_la_ventana_del_turno(): void
    {
        $this->crear_lead($this->crear_demo());
        $servicio = app(AsistenteDemoLeadService::class);

        $casos = [
            '2026-10-16 14:54:00' => AsistenteDemoLeadService::SITUACION_SIN_TURNO,
            '2026-10-16 14:55:00' => AsistenteDemoLeadService::SITUACION_ACTIVO,
            '2026-10-16 17:20:00' => AsistenteDemoLeadService::SITUACION_ACTIVO,
            '2026-10-16 17:21:00' => AsistenteDemoLeadService::SITUACION_SIN_TURNO,
        ];

        foreach ($casos as $instante => $esperado) {
            $resultado = $servicio->situacion_del_lead(self::TELEFONO_LEAD, Carbon::parse($instante, self::TZ));
            $this->assertSame($esperado, $resultado['situacion'], 'A las ' . $instante);
        }
    }

    /**
     * Dos leads con el mismo teléfono (hay datos reales así): entra el que tiene el turno vigente, aunque el
     * otro sea más nuevo y esté vencido.
     *
     * @return void
     */
    public function test_con_dos_leads_del_mismo_telefono_entra_el_del_turno_vigente(): void
    {
        Queue::fake();
        $this->espiar_sender();
        $demo_vigente = $this->crear_demo();
        $demo_vencida = $this->crear_demo();

        $vigente = $this->crear_lead($demo_vigente);
        // Más nuevo (id mayor) pero con el turno de la mañana, ya vencido.
        $this->crear_lead($demo_vencida, ['demo_start_time' => '09:00', 'demo_end_time' => '11:00']);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $fila = ClientAssistantMessage::first();
        $this->assertNotNull($fila);
        $this->assertSame((int) $demo_vigente->id, (int) $fila->demo_id);
        $this->assertSame((int) $vigente->id, (int) $fila->lead_id);
    }

    /**
     * Un lead cuya demo se borró no abre ningún hilo: recibe el texto de "no disponible".
     *
     * @return void
     */
    public function test_con_la_demo_borrada_el_lead_no_abre_el_hilo(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo();
        $this->crear_lead($demo);
        Demo::where('id', $demo->id)->delete();

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertSame(AsistenteDemoLeadService::texto_sin_endpoint(), $espia->textos[0]['body']);
    }

    /**
     * El número del equipo que dicen los textos sale de la configuración de leads (no está escrito a mano):
     * si Lucas lo cambia, los textos lo siguen.
     *
     * @return void
     */
    public function test_el_numero_del_equipo_en_los_textos_sigue_a_la_configuracion(): void
    {
        $this->assertSame('+54 9 3444544199', AsistenteDemoLeadService::numero_del_equipo_legible(), 'El default de la cuenta, con el 9 de celular.');
        $this->assertStringContainsString('+54 9 3444544199', AsistenteDemoLeadService::texto_sin_endpoint());

        AdminSetting::set(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS, '5491122334455');

        $this->assertSame('+54 9 1122334455', AsistenteDemoLeadService::numero_del_equipo_legible());
        $this->assertStringContainsString('+54 9 1122334455', AsistenteDemoLeadService::texto_para_lead(AsistenteDemoLeadService::SITUACION_SIN_TURNO));
        $this->assertStringContainsString('+54 9 1122334455', AsistenteDemoLeadService::texto_de_disculpa());
        $this->assertStringNotContainsString('{equipo}', AsistenteDemoLeadService::texto_sin_endpoint());
    }

    // ---------------------------------------------------------------------------------------------
    // 3. El viaje: el job le habla a la DEMO y contesta al LEAD
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 El job le pega a la instancia de la DEMO con la clave de la DEMO, y la respuesta sale al
     * teléfono del LEAD por el número del asistente, sin tocar la tabla `clients`.
     *
     * @return void
     */
    public function test_el_job_le_habla_a_la_demo_y_contesta_al_lead(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo('clave-de-la-demo-7');
        $lead  = $this->crear_lead($demo);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);
        $fila = ClientAssistantMessage::where('demo_id', $demo->id)->firstOrFail();

        $this->fakear_http([
            '*/asistente/mensajes'     => Http::response(['ai_conversation_id' => 12, 'ai_message_id' => 77], 202),
            '*/asistente/mensajes/77'  => Http::response(['estado' => 'listo', 'contenido' => 'Hoy vendiste $120.000.', 'ai_conversation_id' => 12], 200),
        ]);

        $clientes_antes = Client::count();
        $job            = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr_job($job, $espia);
        $this->correr_job($job, $espia);

        // La ida: a la API de la demo (VPS: sin /public), con la clave de la demo y sin la promesa de avisos.
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && strpos($request->url(), 'https://demo7-erp-api.test/api/admin-sync/asistente/mensajes') === 0
                && $request->hasHeader('X-Admin-Api-Key', 'clave-de-la-demo-7');
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && strpos($request->body(), 'name="avisos"') === false
                && strpos($request->body(), 'name="spa_url"') !== false
                && strpos($request->body(), 'https://demo7-erp.test') !== false;
        });

        $fila->refresh();
        $this->assertSame(ClientAssistantMessage::ESTADO_RESPONDIDO, $fila->estado);
        $this->assertSame(12, (int) $fila->ai_conversation_id);

        // La vuelta: al teléfono del LEAD, por el número del asistente.
        $this->assertCount(1, $espia->textos);
        $this->assertSame('Hoy vendiste $120.000.', $espia->textos[0]['body']);
        $this->assertSame('+' . self::TELEFONO_LEAD, $espia->textos[0]['to']);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, (string) $espia->destinos[0]);

        // La fila saliente hereda la demo y el lead (para leer el hilo de un vistazo).
        $saliente = ClientAssistantMessage::where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)->first();
        $this->assertNotNull($saliente);
        $this->assertSame((int) $demo->id, (int) $saliente->demo_id);
        $this->assertSame((int) $lead->id, (int) $saliente->lead_id);
        $this->assertSame(0, (int) $saliente->client_id);
        $this->assertSame($clientes_antes, Client::count());
    }

    /**
     * 🔴 Una demo sin clave (`demos.api_key` vacía) no puede atender al asistente: el webhook NO abre el hilo
     * y le dice al lead que no está disponible (no lo manda a un error que además avisa a los admins en
     * cada mensaje).
     *
     * @return void
     */
    public function test_una_demo_sin_clave_no_abre_el_hilo_y_el_lead_recibe_el_texto_de_no_disponible(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo('');
        $this->crear_lead($demo);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);

        $this->assertSame(0, ClientAssistantMessage::count());
        Queue::assertNothingPushed();
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteDemoLeadService::texto_sin_endpoint(), $espia->textos[0]['body']);
    }

    /**
     * Aunque una fila de demo llegara al job con la demo sin clave (se le sacó entre el webhook y el job),
     * el job no le pega a la instancia y cierra con la disculpa PROPIA del lead.
     *
     * @return void
     */
    public function test_una_fila_de_demo_sin_clave_cierra_con_la_disculpa_del_lead(): void
    {
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo('');

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = 0;
        $fila->demo_id             = $demo->id;
        $fila->lead_id             = 1;
        $fila->telefono            = '+' . self::TELEFONO_LEAD;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.SINCLAVE';
        $fila->tipo                = 'text';
        $fila->texto               = 'Hola';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        $this->fakear_http();
        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        Http::assertNothingSent();
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->refresh()->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteDemoLeadService::texto_de_disculpa(), $espia->textos[0]['body']);
        $this->assertStringNotContainsString('chat del sistema', $espia->textos[0]['body']);
    }

    /**
     * Una instancia sin el endpoint (404) degrada con un texto para el LEAD —no el de "tu sistema"— y
     * avisa a los admins una sola vez por demo y por día.
     *
     * @return void
     */
    public function test_el_404_de_la_demo_degrada_con_el_texto_del_lead(): void
    {
        Queue::fake();
        $espia = $this->espiar_sender();
        $demo  = $this->crear_demo();
        $this->crear_lead($demo);

        $this->escribir_al_asistente(self::TELEFONO_LEAD);
        $fila = ClientAssistantMessage::where('demo_id', $demo->id)->firstOrFail();

        $this->fakear_http(['*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $this->assertSame(ClientAssistantMessage::ESTADO_DEGRADADO, $fila->refresh()->estado);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(AsistenteDemoLeadService::texto_sin_endpoint(), $espia->textos[0]['body']);
        $this->assertStringNotContainsString('tu sistema', $espia->textos[0]['body']);

        $asistente = app(AsistenteWhatsappService::class);
        $this->assertFalse($asistente->avisar_demo_sin_endpoint($demo), 'El aviso del día ya salió en el degradado.');
    }

    /**
     * Una fila de demo cuya demo se borró no revienta el job: cierra con error.
     *
     * @return void
     */
    public function test_una_demo_borrada_cierra_con_error(): void
    {
        $espia = $this->espiar_sender();

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = 0;
        $fila->demo_id             = 987654;
        $fila->lead_id             = 1;
        $fila->telefono            = '+' . self::TELEFONO_LEAD;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.HUERFANA';
        $fila->tipo                = 'text';
        $fila->texto               = 'Hola';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        $this->fakear_http();
        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        Http::assertNothingSent();
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->refresh()->estado);
    }

    // ---------------------------------------------------------------------------------------------
    // 4. El cliente virtual nunca toca la base
    // ---------------------------------------------------------------------------------------------

    /**
     * El cliente virtual no se guarda, no cuenta como cliente activo y no recibe avisos.
     *
     * @return void
     */
    public function test_el_cliente_virtual_no_existe_en_la_base_ni_recibe_avisos(): void
    {
        $demo     = $this->crear_demo('clave-de-la-demo-7');
        $servicio = app(AsistenteDemoLeadService::class);
        $antes    = Client::count();

        $virtual = $servicio->cliente_virtual($demo);

        $this->assertFalse($virtual->exists);
        $this->assertSame($antes, Client::count());
        $this->assertFalse((bool) $virtual->is_active, 'Inactivo: ningún barrido de avisos lo recorre.');
        $this->assertTrue((bool) $virtual->asistente_whatsapp_activo);
        $this->assertSame('clave-de-la-demo-7', $virtual->api_key);
        $this->assertFalse(\App\Services\AsistenteAvisosService::cliente_recibe_avisos($virtual));
        $this->assertSame(
            'https://demo7-erp-api.test/api/admin-sync/asistente/mensajes',
            app(\App\Services\ClientEmpresaApiUrlResolver::class)->admin_sync_url($virtual, 'api/admin-sync/asistente/mensajes')
        );
    }
}
