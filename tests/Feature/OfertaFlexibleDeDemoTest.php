<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La OFERTA PRIMARIA y su apertura FLEXIBLE.
 *
 * Este archivo tiene dos mitades y el orden importa:
 *
 *   1) Los casos 1 a 4 son de CARACTERIZACIÓN: clavan lo que `resolve_oferta_primaria()`,
 *      `primer_slot_disponible()` y `texto_referencia_oferta()` hacen HOY. No había un solo test
 *      sobre ninguno de los tres, así que el bloque `OFERTA PRIMARIA` del prompt se podía reescribir
 *      sin que nada avisara si la resolución del slot cambiaba de paso. Se escribieron ANTES de
 *      tocar el prompt, contra el código tal cual estaba.
 *
 *   2) Los casos 5 a 7 son del cambio: la apertura flexible que se enciende con el marcador del
 *      `.md`. El 5 es el que afirma que, con el `.md` viejo todavía vivo, el prompt sale byte a byte
 *      igual que antes — o sea que el estado intermedio del despliegue (código nuevo, `.md` viejo)
 *      es inerte de verdad y no un "casi".
 *
 * Los tres métodos que se ejercitan son privados o protegidos; se llegan por el mismo patrón que ya
 * usa OfertaAceptadaNoCaducaPorMargenTest: una subclase anónima que los expone sin cambiar ninguna
 * lógica. `primer_slot_disponible()` y `texto_referencia_oferta()` son `private`, así que no se
 * pueden exponer directo: se ejercitan A TRAVÉS de `resolve_oferta_primaria()`, que es justamente
 * como los usa producción.
 */
class OfertaFlexibleDeDemoTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de todos los casos. */
    const HOY = '2026-09-07';

    /** El "mañana" de todos los casos. */
    const MANANA = '2026-09-08';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HOY . ' 09:00:00', 'America/Argentina/Buenos_Aires'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* 1 a 4 — caracterización de lo que ya hace el sistema                 */
    /* ------------------------------------------------------------------ */

    /**
     * (1) La oferta primaria es el slot MÁS TEMPRANO de todas las demos y de todas las fechas, con
     *     su fecha, su hora y el demo_id de la instancia que lo tiene. Se cruzan dos demos y tres
     *     fechas a propósito, y la más temprana no es la primera de la lista: si la resolución se
     *     volviera "la primera que encuentro", este caso se pone rojo.
     *
     * @return void
     */
    public function test_la_oferta_primaria_resuelve_el_primer_slot_real(): void
    {
        $datos = ['demos' => [
            7 => [
                'lunes ' . self::HOY    => ['14:00', '11:30'],
                'martes ' . self::MANANA => ['08:00'],
            ],
            9 => [
                'lunes ' . self::HOY => ['10:15', '16:00'],
            ],
        ]];

        $oferta = $this->service()->oferta_primaria($datos, true);

        $this->assertTrue($oferta['hay_disponibilidad']);
        $this->assertTrue($oferta['es_hoy']);
        $this->assertSame(self::HOY, $oferta['fecha']);
        $this->assertSame('10:15', $oferta['hora'], 'La oferta primaria no es el slot más temprano de todas las demos.');
        $this->assertSame(9, $oferta['demo_id'], 'El demo_id no es el de la instancia que tiene el slot más temprano.');
        $this->assertSame('lunes ' . self::HOY, $oferta['dia_label']);
        $this->assertSame('hoy a las 10:15', $oferta['texto_referencia']);
    }

    /**
     * (2) Sin disponibilidad no se inventa nada: el array trae `hay_disponibilidad: false` y NINGUNA
     *     otra clave. Importa que sea exactamente eso y no un array con claves vacías: el bloque del
     *     prompt lee `texto_referencia` sin preguntar, y una clave presente con valor vacío haría
     *     que el agente reciba "Ofrecé ESTE momento: " sin momento.
     *
     * @return void
     */
    public function test_la_oferta_primaria_sin_disponibilidad_no_inventa_nada(): void
    {
        $service = $this->service();

        $this->assertSame(['hay_disponibilidad' => false], $service->oferta_primaria(['demos' => []], true));
        $this->assertSame(['hay_disponibilidad' => false], $service->oferta_primaria([], true));

        /* Fechas presentes pero sin un solo slot: mismo resultado. */
        $this->assertSame(
            ['hay_disponibilidad' => false],
            $service->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => []]]], true)
        );

        /* Y el resguardo por dinámica: un lead de la dinámica actual nunca tiene oferta primaria,
         * aunque el JSON venga lleno. */
        $this->assertSame(
            ['hay_disponibilidad' => false],
            $service->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => ['10:00']]]], false)
        );
    }

    /**
     * (3) `oferta_manana` es el primer slot en una fecha POSTERIOR A HOY, que no es necesariamente
     *     mañana: si mañana está lleno, es el próximo día con lugar. Acá mañana viene sin slots a
     *     propósito.
     *
     * @return void
     */
    public function test_la_oferta_de_manana_saltea_hoy_y_agarra_el_proximo_dia_con_lugar(): void
    {
        $pasado = '2026-09-09';

        $datos = ['demos' => [
            7 => [
                'lunes ' . self::HOY     => ['10:15'],
                'martes ' . self::MANANA => [],
                'miercoles ' . $pasado   => ['09:45', '18:00'],
            ],
        ]];

        $oferta = $this->service()->oferta_primaria($datos, true);

        $this->assertSame(self::HOY, $oferta['fecha'], 'La oferta primaria dejó de ser la de hoy.');

        $this->assertTrue($oferta['oferta_manana']['hay_disponibilidad']);
        $this->assertFalse($oferta['oferta_manana']['es_hoy']);
        $this->assertSame($pasado, $oferta['oferta_manana']['fecha'], 'La oferta del turno siguiente no salteó el día sin lugar.');
        $this->assertSame('09:45', $oferta['oferta_manana']['hora']);
        $this->assertSame('el ' . $pasado . ' a las 09:45', $oferta['oferta_manana']['texto_referencia']);

        /* Y si HOY es lo único que hay, no hay oferta para el turno siguiente. */
        $solo_hoy = $this->service()->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => ['10:15']]]], true);
        $this->assertSame(['hay_disponibilidad' => false], $solo_hoy['oferta_manana']);
    }

    /**
     * (4) El texto de referencia dice "hoy", "mañana" o la fecha pelada, según corresponda. Son los
     *     tres casos del helper, y el tercero es el que importa cuidar: es el que ve el agente
     *     cuando la primera disponibilidad está a varios días.
     *
     * @return void
     */
    public function test_el_texto_de_referencia_dice_hoy_manana_o_la_fecha(): void
    {
        $service = $this->service();

        $hoy = $service->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => ['10:15']]]], true);
        $this->assertSame('hoy a las 10:15', $hoy['texto_referencia']);

        $manana = $service->oferta_primaria(['demos' => [7 => ['martes ' . self::MANANA => ['08:30']]]], true);
        $this->assertSame('mañana a las 08:30', $manana['texto_referencia']);

        $lejos = $service->oferta_primaria(['demos' => [7 => ['viernes 2026-09-11' => ['16:00']]]], true);
        $this->assertSame('el 2026-09-11 a las 16:00', $lejos['texto_referencia']);
    }

    /* ------------------------------------------------------------------ */
    /* 5 a 7 — la apertura flexible y su interruptor                        */
    /* ------------------------------------------------------------------ */

    /**
     * (5) 🔴 EL TEST DEL ESTADO INTERMEDIO DEL DESPLIEGUE. Las dos mitades de este cambio llegan a
     *     producción a destiempo: primero el código, después el `.md`. En esa ventana el `.md` viejo
     *     todavía le manda al agente nombrar la hora, así que el prompt TIENE que salir igual que
     *     antes de la misión — no "parecido". Si esta afirmación fuera falsa, el paso 2 del
     *     despliegue le cambiaría el guion al agente sin que nadie lo haya pedido.
     *
     * @return void
     */
    public function test_con_el_contrato_apagado_el_bloque_sale_igual_que_hoy(): void
    {
        $this->sembrar_entorno_del_agente();
        $this->sembrar_recurso_demo_agenda_v2($this->md_sin_marcador());

        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $this->crear_demo();

        $prompt = $this->generar_y_capturar_el_prompt($lead);

        $this->assertStringContainsString('OFERTA PRIMARIA (resuelta por el sistema — es LA que tenés que ofrecer, con la hora exacta):', $prompt);
        $this->assertStringContainsString('El mensaje TIENE que decir esa hora.', $prompt);
        $this->assertStringContainsString('Ofrecé LA OFERTA PRIMARIA nombrando la hora', $prompt);
        $this->assertStringContainsString('PROHIBIDO ofrecer franjas del día.', $prompt);

        $this->assertStringNotContainsString('MATERIAL DE RESPALDO', $prompt, 'Con el .md viejo vivo, el prompt ya ordenaba la apertura flexible.');
        $this->assertStringNotContainsString('La apertura de este mensaje es FLEXIBLE', $prompt);
        $this->assertStringNotContainsString('oferta_flexible', $prompt, 'Se le pidió al agente un campo que el .md viejo no le explica.');
    }

    /**
     * (6) El corazón del cambio: con el marcador vivo en el `.md`, el bloque pasa a ordenar la
     *     apertura flexible, deja la oferta primaria como material de respaldo para el turno
     *     siguiente y le pide el campo `oferta_flexible`. Y las órdenes viejas —la hora obligatoria,
     *     la prohibición de la pregunta abierta— desaparecen: si convivieran, el modelo recibiría
     *     dos instrucciones opuestas en el mismo prompt.
     *
     * @return void
     */
    public function test_con_el_contrato_prendido_el_bloque_ordena_la_apertura_flexible(): void
    {
        $this->sembrar_entorno_del_agente();
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());

        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $this->crear_demo();

        $prompt = $this->generar_y_capturar_el_prompt($lead);

        $this->assertStringContainsString('OFERTA PRIMARIA (resuelta por el sistema — MATERIAL DE RESPALDO, no la nombres en este mensaje):', $prompt);
        $this->assertStringContainsString('La apertura de este mensaje es FLEXIBLE', $prompt);
        $this->assertStringContainsString('PROHIBIDO nombrar una hora puntual en esta apertura', $prompt);
        $this->assertStringContainsString('devolvé "oferta_flexible": true y "horarios_ofrecidos": []', $prompt);
        $this->assertStringContainsString('Ofrecé la apertura FLEXIBLE del bloque de arriba', $prompt);
        $this->assertStringContainsString('Recién si el lead pide ver opciones', $prompt);
        $this->assertStringContainsString('si declarás flexible y el texto igual nombra una hora, el mensaje se frena para revisión humana', $prompt);

        /* El material de respaldo sigue estando: el agente tiene que poder nombrar la hora en el
         * turno siguiente sin volver a consultar nada. */
        $this->assertStringContainsString('El primer momento disponible real es', $prompt);

        /* Y las órdenes incompatibles ya no están. */
        $this->assertStringNotContainsString('El mensaje TIENE que decir esa hora.', $prompt);
        $this->assertStringNotContainsString('Ofrecé LA OFERTA PRIMARIA nombrando la hora', $prompt);
        $this->assertStringNotContainsString('PROHIBIDO devolver la pregunta abierta sin haber ofrecido nada', $prompt);
    }

    /**
     * (7) Un lead de la dinámica ACTUAL no ve nada de esto, ni siquiera con el marcador sembrado en
     *     los dos recursos. El gate que lo corta es el de la dinámica, no la ausencia del `.md`: por
     *     eso acá el marcador está puesto en la v1 y en la v2.
     *
     * @return void
     */
    public function test_la_dinamica_actual_no_ve_nada_de_esto(): void
    {
        $this->sembrar_entorno_del_agente();
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());
        $this->sembrar_recurso_demo_agenda_v1($this->md_con_marcador());

        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();
        $this->crear_demo();

        $prompt = $this->generar_y_capturar_el_prompt($lead->refresh());

        $this->assertStringNotContainsString('OFERTA PRIMARIA', $prompt, 'La dinámica actual nunca tuvo bloque de oferta primaria.');
        $this->assertStringNotContainsString('MATERIAL DE RESPALDO', $prompt);
        $this->assertStringNotContainsString('oferta_flexible', $prompt);
        $this->assertStringNotContainsString('La apertura de este mensaje es FLEXIBLE', $prompt);

        /* Y lo que sí tiene sigue estando, byte a byte. */
        $this->assertStringContainsString('DISPONIBILIDAD EN RANGOS LEGIBLES (usar ESTO para ofrecer horarios', $prompt);
    }

    /* ------------------------------------------------------------------ */
    /* Montaje                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Instancia del service con `resolve_oferta_primaria()` y la segunda llamada expuestos. La
     * subclase no cambia ninguna lógica: solo abre la puerta.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param array<string, mixed> $availability_data
             * @param bool                 $usa_experiencia_nueva
             *
             * @return array<string, mixed>
             */
            public function oferta_primaria(array $availability_data, bool $usa_experiencia_nueva): array
            {
                return $this->resolve_oferta_primaria($availability_data, $usa_experiencia_nueva);
            }

            /**
             * @param Lead $lead
             *
             * @return \App\Models\LeadMessage
             */
            public function segunda_llamada(Lead $lead): \App\Models\LeadMessage
            {
                return $this->generate_suggestion_with_availability($lead, false, null, true);
            }
        };
    }

    /**
     * Corre la segunda llamada (la que arma el bloque de disponibilidad) y devuelve el texto exacto
     * que se le mandó al modelo como contenido de usuario. Es donde vive `$availability_context`.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function generar_y_capturar_el_prompt(Lead $lead): string
    {
        $this->service()->segunda_llamada($lead);

        $prompt = '';
        Http::assertSent(function ($request) use (&$prompt) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }

            $data   = $request->data();
            $prompt = isset($data['messages'][0]['content']) ? (string) $data['messages'][0]['content'] : '';

            return true;
        });

        $this->assertNotSame('', $prompt, 'No se capturó ningún prompt: la segunda llamada no salió a la API.');

        return $prompt;
    }

    /**
     * Settings de agenda anchas + fake de la API + cola fakeada + prompt base. Todo lo que
     * `generate_suggestion_with_availability()` necesita para llegar hasta la llamada a Claude sin
     * salir a ningún lado.
     *
     * @return void
     */
    private function sembrar_entorno_del_agente(): void
    {
        Queue::fake();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'mensaje_sugerido'   => 'Si querés te la dejo lista ahora mismo, o cuando te quede cómodo.',
                        'estado_sugerido'    => 'solicita_disponibilidad',
                        'razonamiento'       => '',
                        'oferta_flexible'    => true,
                        'horarios_ofrecidos' => [],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        /* Grilla ancha y previsible: con el reloj a las 09:00 y el día entero abierto, siempre hay
         * oferta primaria disponible. */
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '5');

        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'comercial/agente_leads/system_base.md',
            'content'   => 'System base de prueba.',
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * El recurso `demo_agenda` de la dinámica nueva, que es donde vive el interruptor.
     *
     * @param string $contenido
     *
     * @return void
     */
    private function sembrar_recurso_demo_agenda_v2(string $contenido): void
    {
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => $contenido,
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * El recurso `demo_agenda` vigente (v1), el de la dinámica actual.
     *
     * @param string $contenido
     *
     * @return void
     */
    private function sembrar_recurso_demo_agenda_v1(string $contenido): void
    {
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/demo_agenda.md',
            'content'   => $contenido,
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * El `.md` viejo: explica la agenda pero no nombra el contrato en ningún lado.
     *
     * @return string
     */
    private function md_sin_marcador(): string
    {
        return "# Agenda de la demo\n\nOfrecele el primer horario disponible del JSON, uno solo y con la hora.\n";
    }

    /**
     * El `.md` nuevo: el token del interruptor aparece literal, igual que en el bloque
     * "APERTURA FLEXIBLE" del documento real.
     *
     * @return string
     */
    private function md_con_marcador(): string
    {
        return "# Agenda de la demo\n\n## APERTURA FLEXIBLE\n\nAbrí sin nombrar hora y devolvé el campo oferta_flexible en true.\n";
    }

    /**
     * Lead de la dinámica nueva, sin turno.
     *
     * @return Lead
     */
    private function crear_lead_de_la_dinamica_nueva(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'calificado';
        $lead->save();

        /* Después del save: el hook `creating` estampa la dinámica por defecto. */
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Una instancia de demo, para que la grilla tenga dónde ofrecer.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }
}
