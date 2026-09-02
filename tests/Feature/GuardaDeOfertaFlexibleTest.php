<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La guarda de `horarios_ofrecidos` frente a la APERTURA FLEXIBLE.
 *
 * La guarda nació por el lead 30 (4/8/2026): un mensaje que no declara horarios es invisible para
 * la revalidación previa al envío, así que es exactamente el mensaje mal formado que ningún control
 * mira. Desde entonces, con oferta primaria disponible y sin `horarios_ofrecidos`, el mensaje queda
 * retenido para revisión humana.
 *
 * Una oferta flexible, por definición, no declara horarios: si la guarda no supiera distinguirla,
 * TODAS las aperturas flexibles quedarían trabadas y la misión entera no serviría de nada.
 *
 * 🔴 La forma de la excepción es lo que este archivo clava, y no es "si dice flexible, pasa":
 *
 *     `oferta_flexible: true` es una AFIRMACIÓN del modelo, y el servidor la VERIFICA contra el
 *     texto. Si el mensaje igual nombra una hora, la credencial se pierde y el mensaje se frena
 *     lo mismo que antes — con otra nota, porque ese caso ("el modelo pelea con el guion") no es
 *     el mismo que "el modelo se olvidó de declarar".
 *
 * En todos los casos el interruptor global de verificación va apagado a propósito: si quedara
 * prendido, TODOS los mensajes del tramo de agenda se retendrían por ese otro motivo y este archivo
 * daría verde midiendo la cosa equivocada.
 */
class GuardaDeOfertaFlexibleTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de todos los casos. */
    const HOY = '2026-09-07';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HOY . ' 09:00:00', 'America/Argentina/Buenos_Aires'));

        Queue::fake();

        /* 🔴 Aislar la guarda del interruptor global: con el interruptor prendido, el mensaje se
         * retiene por pertenecer al tramo de agenda y no por lo que este archivo mide. */
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $this->sembrar_agenda_y_prompt();
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
     * (8) La mina desactivada: una apertura flexible declarada, con un texto que no nombra ninguna
     *     hora, SALE. Es el criterio de aceptación A2 de la misión.
     *
     * @return void
     */
    public function test_una_oferta_flexible_declarada_no_queda_trabada(): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Si querés te la dejo lista ahora mismo, o para el horario que te quede cómodo — vos decime.',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'oferta_flexible'    => true,
            'horarios_ofrecidos' => [],
        ]);

        $this->assertFalse(
            (bool) $msg->requiere_verificacion,
            'La apertura flexible quedó retenida: es exactamente lo que esta misión vino a destrabar.'
        );
        $this->assertEmpty($msg->pending_actions, 'La apertura flexible quedó diferida en vez de aplicarse.');
    }

    /**
     * (9) El caso del lead 30 sigue trabado: sin horarios y sin declararse flexible, la guarda
     *     retiene igual que antes de la misión y con la misma nota.
     *
     * @return void
     */
    public function test_un_mensaje_sin_horarios_y_sin_declarar_flexible_sigue_trabado(): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Puede ser hoy a la tarde o mañana, como te quede mejor.',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'horarios_ofrecidos' => [],
        ]);

        $this->assertTrue((bool) $msg->requiere_verificacion, 'Se destrabó el caso del lead 30: la guarda dejó de proteger lo que protegía.');
        $this->assertStringContainsString(
            'El mensaje no ofrece un horario concreto pese a que el sistema',
            (string) $msg->pending_actions['nota_para_setter']
        );
    }

    /**
     * (10) 🔴 EL TEST CENTRAL. Declararse flexible NO es un salvoconducto: si el texto nombra una
     *      hora, el mensaje se frena igual — y con la nota que dice el problema real, porque es la
     *      señal de que el modelo está peleando con el guion y hay que poder medirlo.
     *
     * @return void
     */
    public function test_declarar_flexible_no_alcanza_si_el_texto_nombra_una_hora(): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Te la dejo lista para las 12:30, ¿te sirve?',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'oferta_flexible'    => true,
            'horarios_ofrecidos' => [],
        ]);

        $this->assertTrue(
            (bool) $msg->requiere_verificacion,
            'El modelo se declaró flexible, nombró una hora igual y el mensaje salió solo: la guarda quedó aflojada.'
        );
        $this->assertStringContainsString(
            'se declaró como oferta flexible (sin horario) pero el texto nombra una hora',
            (string) $msg->pending_actions['nota_para_setter']
        );
    }

    /**
     * (11) Con el contrato apagado (el `.md` viejo todavía vivo), la marca `oferta_flexible` se
     *      ignora por completo y la guarda se comporta como antes de la misión. Es el estado
     *      intermedio del despliegue: código nuevo, `.md` viejo.
     *
     * @return void
     */
    public function test_con_el_contrato_apagado_la_marca_flexible_se_ignora(): void
    {
        $msg = $this->generar(false, [
            'mensaje_sugerido'   => 'Si querés te la dejo lista ahora mismo, o cuando te quede cómodo.',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'oferta_flexible'    => true,
            'horarios_ofrecidos' => [],
        ]);

        $this->assertTrue(
            (bool) $msg->requiere_verificacion,
            'Con el .md viejo vivo, el campo oferta_flexible ya destrababa mensajes: el interruptor de contrato no está funcionando.'
        );
        $this->assertStringContainsString(
            'El mensaje no ofrece un horario concreto pese a que el sistema',
            (string) $msg->pending_actions['nota_para_setter']
        );
    }

    /**
     * (12) Y el camino de siempre no cambió: una oferta CON hora, declarada en `horarios_ofrecidos`,
     *      pasa como pasaba. La guarda nunca miró ese caso.
     *
     * @return void
     */
    public function test_una_oferta_con_hora_declarada_pasa_como_siempre(): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Te la dejo lista para hoy a las 10:00, ¿te sirve?',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'horarios_ofrecidos' => [
                ['fecha' => self::HOY, 'desde' => '10:00', 'hasta' => '10:00'],
            ],
        ]);

        $this->assertFalse((bool) $msg->requiere_verificacion, 'Se trabó una oferta con hora declarada: la guarda se puso más dura de lo que era.');
        /* assertEquals y no assertSame: el ítem va y vuelve por JSON, y el orden de las claves no
         * sobrevive el viaje. Lo que importa es que las tres claves lleguen con su valor. */
        $this->assertEquals(
            [['fecha' => self::HOY, 'desde' => '10:00', 'hasta' => '10:00']],
            $msg->horarios_ofrecidos,
            'Se pisaron los horarios que el modelo sí declaró.'
        );
    }

    /**
     * (13) 🔴 La oferta flexible persiste `horarios_ofrecidos` como `[]`, NO como `null`, y el `[]`
     *      lo escribe PHP.
     *
     *      No es un detalle de forma: río abajo, el permiso para saltarse el margen de la cadena
     *      flexible (LeadMessage::ultima_oferta_fue_flexible()) lee justamente `[] estricto` de un
     *      mensaje enviado, y el cast `array` distingue `[]` de `null`. Si dependiera de que el
     *      modelo mandara el campo, el permiso existiría o no según el humor de la generación.
     *
     * @return void
     */
    public function test_la_oferta_flexible_persiste_horarios_ofrecidos_vacio(): void
    {
        /* El modelo NO manda `horarios_ofrecidos` en absoluto: es el caso hostil. */
        $msg = $this->generar(true, [
            'mensaje_sugerido' => 'Si querés te la dejo lista ahora mismo, o para el horario que te quede cómodo.',
            'estado_sugerido'  => 'solicita_disponibilidad',
            'razonamiento'     => '',
            'oferta_flexible'  => true,
        ]);

        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->assertNotNull($msg->horarios_ofrecidos, 'Quedó en null: el permiso de la cadena flexible no lo va a reconocer nunca.');
        $this->assertSame([], $msg->horarios_ofrecidos, 'No quedó como array vacío explícito.');
    }

    /**
     * (19) 🔴 `"false"` es un STRING no vacío, y hasta la revisión de esta rama prendía la
     *      credencial: `! empty("false")` es true. O sea que el valor decía exactamente lo contrario
     *      de lo que el sistema entendía — y devolver los booleanos como string es de las cosas que
     *      un modelo de lenguaje hace seguido. Lo mismo con un array, con un objeto o con "no".
     *
     *      Sólo un true de verdad (bool true, 1, "1", "true") vale. Todo lo demás deja la guarda
     *      exactamente como estaba antes de la misión.
     *
     * @dataProvider marcas_que_no_son_un_true
     *
     * @param mixed $marca Valor crudo de `oferta_flexible` que devuelve el modelo.
     *
     * @return void
     */
    public function test_solo_un_true_de_verdad_prende_la_credencial_flexible($marca): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Si querés te la dejo lista ahora mismo, o para el horario que te quede cómodo.',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'oferta_flexible'    => $marca,
            'horarios_ofrecidos' => [],
        ]);

        $this->assertTrue(
            (bool) $msg->requiere_verificacion,
            'Un valor que no es un true de verdad prendió la credencial: la guarda se saltea con cualquier cosa no vacía.'
        );
        $this->assertStringContainsString(
            'El mensaje no ofrece un horario concreto pese a que el sistema',
            (string) $msg->pending_actions['nota_para_setter'],
            'Se tomó como "declaró flexible" algo que no lo es: la nota tiene que ser la de siempre.'
        );
    }

    /**
     * Valores de `oferta_flexible` que NO son un true. `null` viaja como clave presente en null, que
     * es distinto de que la clave no venga.
     *
     * @return array<string, array<int, mixed>>
     */
    public function marcas_que_no_son_un_true(): array
    {
        return [
            'string false' => ['false'],
            'string cero'  => ['0'],
            'entero cero'  => [0],
            'array vacio'  => [[]],
            'null'         => [null],
            'objeto'       => [['valor' => true]],
        ];
    }

    /**
     * (20) 🔴 EL FAIL-SAFE, en la dirección correcta. Un `mensaje_sugerido` vacío o ilegible no se
     *      puede verificar contra nada, y una afirmación que no se puede verificar no vale.
     *
     *      Antes de la revisión pasaba lo contrario: el texto caía a '', '' no nombra ninguna hora,
     *      y el mensaje MENOS legible del sistema era justo el que se salteaba la guarda.
     *
     * @return void
     */
    public function test_un_texto_ilegible_no_es_una_oferta_flexible_creible(): void
    {
        $msg = $this->generar(true, [
            /* El flag `solicita_disponibilidad` es lo único que deja pasar un mensaje vacío por la
             * validación de forma; sin él el paquete se rechaza antes y no se mediría la guarda. */
            'mensaje_sugerido'        => '   ',
            'estado_sugerido'         => 'solicita_disponibilidad',
            'solicita_disponibilidad' => true,
            'razonamiento'            => '',
            'oferta_flexible'         => true,
            'horarios_ofrecidos'      => [],
        ]);

        $this->assertTrue(
            (bool) $msg->requiere_verificacion,
            'Un texto ilegible se llevó la credencial flexible: el fail-safe está al revés.'
        );
        $this->assertStringContainsString(
            'llegó vacío o ilegible',
            (string) $msg->pending_actions['nota_para_setter']
        );
    }

    /**
     * (21) 🔴 EL LEAD 30, EN VERSIÓN NUEVA. La credencial vale sólo en la APERTURA.
     *
     *      Sin esta condición alcanzaba con que el modelo mandara `oferta_flexible: true` en
     *      CUALQUIER mensaje del tramo. El escenario real: el lead contesta "dale, ahora mismo", el
     *      modelo responde "genial, ahora mismo te la preparo y te paso el link" con la marca
     *      flexible y SIN `agendar_demo` — sale solo, no se agenda nada, no hay link, y el lead se
     *      queda esperando. Es textualmente el caso que la guarda existe para atrapar.
     *
     *      Acá el lead ya está en `solicita_disponibilidad`, o sea que este mensaje no puede ser una
     *      apertura.
     *
     * @return void
     */
    public function test_la_credencial_flexible_no_vale_fuera_de_la_apertura(): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Genial, te la dejo lista y te aviso.',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'oferta_flexible'    => true,
            'horarios_ofrecidos' => [],
        ], 'solicita_disponibilidad');

        $this->assertTrue(
            (bool) $msg->requiere_verificacion,
            'La marca flexible destrabó un mensaje del medio del tramo: el bug del lead 30 volvió por la ventana.'
        );
        $this->assertStringContainsString(
            'ya estaba en el tramo de agenda',
            (string) $msg->pending_actions['nota_para_setter']
        );
    }

    /**
     * (22) La otra mitad del criterio: un texto que AFIRMA que la instancia ya se está armando no es
     *      una oferta, es un aviso — y un aviso sin `agendar_demo` es el lead esperando un link que
     *      no llega.
     *
     *      🔴 El criterio mira gerundios y formas ya-hechas, nunca el presente simple: "te la dejo
     *      lista ahora mismo" es cómo se OFRECE algo en rioplatense y es literalmente lo que el
     *      prompt le dicta al modelo. El test 8 de este mismo archivo es el que cuida ese lado.
     *
     * @return void
     */
    public function test_un_texto_que_afirma_que_ya_esta_armando_la_demo_no_es_una_apertura(): void
    {
        $msg = $this->generar(true, [
            'mensaje_sugerido'   => 'Genial, ya la estoy preparando y te paso el link en un rato.',
            'estado_sugerido'    => 'solicita_disponibilidad',
            'razonamiento'       => '',
            'oferta_flexible'    => true,
            'horarios_ofrecidos' => [],
        ]);

        $this->assertTrue(
            (bool) $msg->requiere_verificacion,
            'Un mensaje que afirma trabajo en curso se llevó la credencial de "apertura flexible".'
        );
        $this->assertStringContainsString(
            'ya se está preparando',
            (string) $msg->pending_actions['nota_para_setter']
        );
    }

    /* ------------------------------------------------------------------ */
    /* Montaje                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Corre la segunda llamada con la respuesta del modelo que se le pase y devuelve el mensaje
     * creado.
     *
     * @param bool                 $contrato_activo true = el `.md` trae el marcador del contrato.
     * @param array<string, mixed> $respuesta       JSON que devuelve el modelo.
     * @param string               $estado_del_lead Estado del lead ANTES del mensaje. El default
     *                                              (`calificado`) es el de una apertura real.
     *
     * @return LeadMessage
     */
    private function generar(bool $contrato_activo, array $respuesta, string $estado_del_lead = 'calificado'): LeadMessage
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => $contrato_activo
                ? "# Agenda de la demo\n\n## APERTURA FLEXIBLE\n\nDevolvé el campo oferta_flexible en true.\n"
                : "# Agenda de la demo\n\nOfrecele el primer horario disponible del JSON, uno solo y con la hora.\n",
            'synced_at' => AppTime::now(),
        ]);

        $this->crear_demo();

        return $this->service()->segunda_llamada($this->crear_lead($estado_del_lead));
    }

    /**
     * Instancia del service con la segunda llamada expuesta. La subclase no cambia ninguna lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param Lead $lead
             *
             * @return LeadMessage
             */
            public function segunda_llamada(Lead $lead): LeadMessage
            {
                return $this->generate_suggestion_with_availability($lead, false, null, true);
            }
        };
    }

    /**
     * Grilla ancha y previsible + prompt base: todo lo que hace falta para que haya oferta primaria
     * disponible y la segunda llamada llegue hasta el final.
     *
     * @return void
     */
    private function sembrar_agenda_y_prompt(): void
    {
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
     * Lead de la dinámica nueva, sin turno.
     *
     * @param string $status Estado del lead antes de generar el mensaje.
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'calificado'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = $status;
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
