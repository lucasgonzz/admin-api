<?php

namespace Tests\Feature;

use App\Exceptions\HorarioYaNoDisponibleException;
use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reagendado automático al próximo slot cuando la oferta que el lead acepta YA ARRANCÓ.
 *
 * El caso que originó la misión (25/8/2026): el sistema ofreció HOY 17:05, el lead contestó "dale"
 * a las 17:10 — cuando ese horario ya había empezado — y el sistema le devolvía la pelota ("¿cuál
 * te sirve?"), que es la peor respuesta posible para alguien que acaba de decir que sí. Encima el
 * texto correctivo inventaba la causa ("uh, justo se ocupó"), porque el prompt le afirmaba al
 * modelo un hecho negativo sin darle el motivo.
 *
 * Lo que este archivo clava son tres cosas distintas:
 *
 *   1. Que el reagendado OCURRA y deje el paquete coherente: el turno nuevo, el texto nuevo y —lo
 *      más frágil— `horarios_ofrecidos` reescrito. Si ese último queda con el horario viejo, la
 *      revalidación previa al envío marca el mensaje como caducado y lo destruye antes de mandarlo.
 *   2. Que el permiso SOBREVIVA a la aprobación (`reagendado_desde`). Es el punto más importante
 *      del diseño: sin la marca, el reagendado se autodestruye justo en la ventana en la que el
 *      admin aprueba (los minutos previos al slot nuevo). Y que la salvaguarda funcione en los dos
 *      sentidos: si el admin movió la hora a mano, la marca NO viaja; si el panel sólo hace eco de
 *      la hora en otro formato ("17:15:00"), SÍ viaja.
 *   3. Que NO se reagende cuando no corresponde, y que ahí el correctivo lleve el motivo REAL.
 *
 * 🔴 Todos los casos negativos traen su control positivo en el mismo test: se corre el MISMO
 * escenario cambiando sólo la condición bajo prueba, y ahí el reagendado sí ocurre. Sin ese
 * control, un test verde no distingue "la condición frena el reagendado" de "el escenario estaba
 * mal armado y no se reagendaba por cualquier otra cosa" — la clase registrada el 25/8/2026 del
 * comando de detección que devuelve OK sin haber podido fallar.
 */
class ReagendadoAlProximoSlotTest extends TestCase
{
    use DatabaseTransactions;

    /** El día del caso real: martes, laborable. */
    const FECHA = '2026-08-25';

    /** El horario que el sistema le ofreció al lead y que el lead aceptó tarde. */
    const HORA_OFRECIDA = '17:05';

    /** El próximo slot de la grilla cuando el lead acepta 17:10 con el margen en 5. */
    const HORA_REAGENDADA = '17:15';

    /**
     * Cuando está en true, la llamada del reagendado devuelve contenido estructurado (que el
     * service trata como fallo). Es la perilla del caso (q).
     *
     * @var bool
     */
    private $reagendado_devuelve_estructura = false;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* Ningún test de este archivo sale a la red. La API de Claude se responde con un doble que
         * distingue las DOS llamadas aisladas que pueden salir en este flujo —la del reagendado y
         * la correctiva— por el texto del prompt, que es lo único que las separa: las dos pegan al
         * mismo endpoint. */
        Http::fake([
            'api.anthropic.com/*' => function ($request) {
                return $this->responder_como_claude($request);
            },
            '*' => Http::response(['ok' => true], 200),
        ]);

        /* Grilla previsible y de día completo, igual que en OfertaAceptadaNoCaducaPorMargenTest:
         * los horarios de los tests tienen que existir de verdad en la disponibilidad. El horario
         * del closer va igual de ancho porque la dinámica ACTUAL resuelve su grilla contra él. */
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '5');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '5');

        /* 🔴 A diferencia del test hermano, ACÁ las llamadas aisladas al modelo tienen que llegar a
         * salir: la del reagendado es la que decide si se reagenda o no. Y build_system_prompt()
         * TIRA si no hay un system prompt activo y un system base sincronizado en la base — no
         * devuelve vacío, tira, y el `try/catch` de la llamada lo traduce a "el modelo no redactó"
         * y a un reagendado que nunca ocurre. O sea que sin estas dos filas el archivo entero da
         * rojo por configuración, no por el código bajo prueba. */
        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire en los tests.',
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
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* (a) El caso de Lucas                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 EL CASO DE LUCAS. Ofrecido hoy 17:05 (mensaje enviado 17:00), el lead contesta "dale" a
     * las 17:10 — cuando ese horario ya arrancó. No se le devuelve la pelota: se le agenda directo
     * las 17:15 y el mensaje se reescribe para confirmárselo con el motivo real.
     *
     * @return void
     */
    public function test_el_horario_que_ya_arranco_se_reagenda_al_proximo_slot(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $paquete_original = $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA);

        /* Montaje verificado, y es lo que hace que este test PUEDA fallar: a las 17:10 el horario
         * aceptado ya no está en la grilla —ni siquiera es cuestión del margen, YA ARRANCÓ— y el
         * próximo que sí está es 17:15. Sin el reagendado, el paquete se cae entero acá. */
        $slots = $this->slots_de($this->grilla($lead), $demo->id, self::FECHA);
        $this->assertNotContains(self::HORA_OFRECIDA, $slots, 'Montaje inválido: las 17:05 siguen en la grilla, así que no hay nada que reagendar.');
        $this->assertContains(self::HORA_REAGENDADA, $slots, 'Montaje inválido: las 17:15 no están libres, así que el reagendado no tendría a dónde correr el turno.');

        $resultado = $this->generar($lead, $paquete_original);

        /* La acción quedó con el slot nuevo. */
        $this->assertNotNull($resultado['agendar_demo'], 'El paquete se descartó entero: se le devolvió la pelota a un lead que ya había dicho que sí.');
        $this->assertSame(self::HORA_REAGENDADA, $resultado['agendar_demo']['demo_start_time'], 'No se corrió el turno al próximo slot del día.');
        $this->assertSame(self::FECHA, $resultado['agendar_demo']['demo_date'], 'El reagendado le cambió el DÍA al lead: tiene que ser siempre dentro de hoy.');
        $this->assertSame($demo->id, (int) $resultado['agendar_demo']['demo_id']);

        /* El permiso que viaja hasta la aprobación, y que es el horario VIEJO. */
        $this->assertArrayHasKey('reagendado_desde', $resultado['agendar_demo'], 'El paquete salió sin la marca del reagendado: en la aprobación se frena solo.');
        $this->assertSame(self::HORA_OFRECIDA, $resultado['agendar_demo']['reagendado_desde']);

        /* El texto cambió: el del modelo confirmaba las 17:05 y mandarlo así sería mentir. */
        $this->assertNotSame(
            $paquete_original['mensaje_sugerido'],
            $resultado['mensaje_sugerido'],
            'Se corrió el turno pero se dejó el texto del modelo: el mensaje le confirma al lead un horario distinto del que se agendó.'
        );
        $this->assertStringContainsString(self::HORA_REAGENDADA, $resultado['mensaje_sugerido'], 'El mensaje nuevo no nombra el horario que efectivamente se agendó.');
        $this->assertStringNotContainsString(
            'te confirmo la demo hoy a las ' . self::HORA_OFRECIDA,
            $resultado['mensaje_sugerido'],
            'El mensaje sigue confirmando el horario viejo.'
        );

        /* 🔴 El más frágil de todos: horarios_ofrecidos reescrito con el slot nuevo. */
        $this->assertSame(
            [['fecha' => self::FECHA, 'desde' => self::HORA_REAGENDADA, 'hasta' => self::HORA_REAGENDADA]],
            $resultado['horarios_ofrecidos'],
            'horarios_ofrecidos quedó sin reescribir: la revalidación previa al envío marca el mensaje como caducado y lo tumba antes de mandarlo.'
        );

        /* El resto del paquete: sigue siendo una demo agendada, con nota y razonamiento anexado. */
        $this->assertSame('demo_agendada', $resultado['estado_sugerido'], 'El reagendado bajó el estado: sí se agenda, el estado no cambia.');
        $this->assertFalse(empty($resultado['nota_para_setter']), 'El admin no tiene ninguna nota que le explique por qué el texto no es el que pidió el modelo.');
        $this->assertStringContainsString(
            $paquete_original['razonamiento'],
            $resultado['razonamiento'],
            'El razonamiento del modelo se pisó en vez de anexarse.'
        );
        $this->assertStringContainsString('[sistema]', $resultado['razonamiento'], 'El razonamiento no menciona el reagendado: el panel muestra "confirmo las 17:05" arriba de un texto que dice 17:15.');
        $this->assertStringContainsString(self::HORA_REAGENDADA, $resultado['razonamiento']);
    }

    /**
     * 🔴 Y la consecuencia concreta de reescribir `horarios_ofrecidos`: el paquete reagendado pasa
     * la revalidación previa al envío, y el del modelo (con el horario viejo) no.
     *
     * Es la misma función que corre `LeadSuggestionSendService::send_suggestion()` ANTES de aplicar
     * las acciones. Si devuelve algo, el mensaje se marca rechazado, se abre bloque rojo y se
     * regenera otra sugerencia — o sea, el reagendado nunca llega a enviarse.
     *
     * @return void
     */
    public function test_los_horarios_ofrecidos_reescritos_pasan_la_revalidacion_previa_al_envio(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $service = $this->service();

        /* El control que hace que este test pueda fallar: con el horario VIEJO, la revalidación lo
         * declara caducado. Ese es exactamente el mecanismo que tumbaría el mensaje reagendado. */
        $caducados_viejo = $service->revalidar_horarios_ofrecidos($lead, [
            ['fecha' => self::FECHA, 'desde' => self::HORA_OFRECIDA, 'hasta' => self::HORA_OFRECIDA],
        ]);
        $this->assertNotEmpty($caducados_viejo, 'Montaje inválido: la revalidación previa al envío no marca caducado el horario viejo, así que este test no probaría nada.');

        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));

        $caducados_nuevo = $service->revalidar_horarios_ofrecidos($lead, $resultado['horarios_ofrecidos']);
        $this->assertSame([], $caducados_nuevo, 'La revalidación previa al envío marcó caducado el paquete reagendado: el mensaje se destruye antes de llegar al lead.');
    }

    /**
     * (b) El gate de verificación humana se conserva: el paquete reagendado sigue trayendo
     * `agendar_demo`, así que el mensaje se difiere y espera aprobación. El reagendado corrige un
     * borrador, no se auto-aprueba nada.
     *
     * @return void
     */
    public function test_el_gate_de_verificacion_humana_se_conserva(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $service   = $this->service();
        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));

        $this->assertNotNull($resultado['agendar_demo'], 'Montaje inválido: no hubo reagendado, así que el gate no se está evaluando sobre el paquete reagendado.');
        $this->assertTrue(
            $service->gate($lead, $resultado),
            'El paquete reagendado dejó de requerir verificación humana: se enviaría solo, sin que ningún admin lea el texto que PHP reescribió.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* (c)-(e) El permiso que viaja hasta la aprobación                    */
    /* ------------------------------------------------------------------ */

    /**
     * (c) 🔴 EL PUNTO MÁS IMPORTANTE DEL DISEÑO. El admin aprueba el turno reagendado a las 17:12,
     *     con el slot nuevo (17:15) a tres minutos — o sea por debajo del margen de anticipación.
     *     Sin la marca `reagendado_desde`, la validación de la aprobación lo lee como "no
     *     disponible" y frena: el reagendado se autodestruye justo en la ventana en la que el admin
     *     aprueba de verdad.
     *
     * @return void
     */
    public function test_la_aprobacion_del_turno_reagendado_no_se_frena_por_el_margen(): void
    {
        list($lead, $demo, $mensaje) = $this->escenario_reagendado_pendiente();
        $texto_que_leyo = $mensaje->content;

        Carbon::setTestNow($this->momento('17:12'));

        /* Montaje verificado: en este instante 17:15 ya NO pasa el margen, así que la aprobación
         * sólo puede prosperar por el permiso que viaja en el paquete. */
        $this->assertNotContains(
            self::HORA_REAGENDADA,
            $this->slots_de($this->grilla($lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:15 todavía pasan el margen, así que la aprobación pasaría sin necesidad de la marca.'
        );

        /* El panel devuelve la marca tal cual vino (caso "el SPA la conoce"). */
        $final_actions = $this->final_actions_del_panel($demo, self::FECHA, self::HORA_REAGENDADA);
        $final_actions['agendar_demo']['reagendado_desde'] = self::HORA_OFRECIDA;

        $this->service()->apply_pending_actions($mensaje, $final_actions);

        $lead->refresh();
        $this->assertSame(self::HORA_REAGENDADA, $lead->demo_start_time, 'El turno que el sistema corrió se frenó solo al aprobarlo: el permiso no viajó.');
        $this->assertSame(self::FECHA, $lead->demo_date->format('Y-m-d'));
        $this->assertSame('demo_agendada', $lead->status);
        $this->assertSame($texto_que_leyo, $mensaje->fresh()->content, 'Se reescribió el texto que el admin aprobó.');
    }

    /**
     * (d) 🔴 Y la marca sobrevive al panel REAL, que reconstruye `agendar_demo` clave por clave y
     *     no manda `reagendado_desde` porque no la conoce. Es la prueba de la tercera preservación
     *     de la serie (`ventana_extendida`, `ventana_hasta`, `reagendado_desde`).
     *
     * @return void
     */
    public function test_la_marca_del_reagendado_sobrevive_a_la_reconstruccion_del_panel(): void
    {
        list($lead, $demo, $mensaje) = $this->escenario_reagendado_pendiente();

        Carbon::setTestNow($this->momento('17:12'));

        $this->assertNotContains(
            self::HORA_REAGENDADA,
            $this->slots_de($this->grilla($lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:15 todavía pasan el margen, así que la aprobación pasaría sin necesidad de la marca.'
        );

        /* Exactamente lo que manda el SPA: sin `reagendado_desde`. */
        $final_actions = $this->final_actions_del_panel($demo, self::FECHA, self::HORA_REAGENDADA);
        $this->assertArrayNotHasKey('reagendado_desde', $final_actions['agendar_demo'], 'Montaje inválido: el panel simulado está mandando la marca, así que no se prueba la preservación.');

        $this->service()->apply_pending_actions($mensaje, $final_actions);

        $this->assertSame(
            self::HORA_REAGENDADA,
            $lead->fresh()->demo_start_time,
            'La marca se perdió al aprobar: el panel reconstruye agendar_demo clave por clave y el turno reagendado se frena solo.'
        );
    }

    /**
     * (d2) Y tolera el FORMATO. Si el panel hace eco de la hora como "17:15:00" en vez de "17:15",
     *      el admin no movió nada — es el mismo horario. Con una comparación estricta sobre el
     *      string crudo la marca se tiraba en silencio y el reagendado se frenaba solo, que es
     *      exactamente el modo de falla que la marca existe para evitar.
     *
     * @return void
     */
    public function test_la_marca_del_reagendado_tolera_que_el_panel_devuelva_la_hora_con_segundos(): void
    {
        list($lead, $demo, $mensaje) = $this->escenario_reagendado_pendiente();

        Carbon::setTestNow($this->momento('17:12'));

        $this->assertNotContains(
            self::HORA_REAGENDADA,
            $this->slots_de($this->grilla($lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:15 todavía pasan el margen, así que la aprobación pasaría sin necesidad de la marca.'
        );

        /* Mismo horario, otra forma de escribirlo.
         *
         * ⚠️ La FECHA va en Y-m-d pelado a propósito, aunque la salvaguarda también la compara
         * normalizada. Medido el 25/8/2026: si el panel mandara `demo_date` como
         * "2026-08-25T00:00:00", la marca se preservaría bien pero la aprobación se caería igual —
         * `apply_parsed_response()` normaliza `demo_start_time` a HH:MM y NO normaliza `demo_date`,
         * así que la fecha con hora pegada no matchea ninguna clave de la grilla. Es un límite
         * PREEXISTENTE del camino de aprobación, ajeno a esta misión, y meterlo acá haría que este
         * test diera rojo por otra cosa. Queda anotado como hallazgo. */
        $final_actions = $this->final_actions_del_panel($demo, self::FECHA, self::HORA_REAGENDADA . ':00');

        $this->service()->apply_pending_actions($mensaje, $final_actions);

        $this->assertSame(
            self::HORA_REAGENDADA,
            $lead->fresh()->demo_start_time,
            'Un panel que devuelve "17:15:00" tiró la marca en silencio: el mismo horario escrito distinto no es una edición del admin.'
        );
    }

    /**
     * (e) 🔴 La salvaguarda, del otro lado: si el admin MOVIÓ la hora a mano, el reagendado del
     *     sistema ya no aplica y el permiso no puede viajar con una hora que nadie eligió. Ese
     *     horario se comporta como cualquier otro que nunca se le ofreció al lead.
     *
     * @return void
     */
    public function test_la_marca_del_reagendado_no_sobrevive_si_el_admin_movio_el_horario(): void
    {
        /* El margen se sube a 15 SÓLO para el instante de la aprobación, y es deliberado: con el
         * margen en 5 y la frecuencia en 5, el único slot que queda por debajo del margen es el que
         * el sistema eligió, así que cualquier hora que el admin elija estaría disponible por sí
         * sola y el test no distinguiría nada. Con 15, tanto 17:15 (la del sistema) como 17:20 (la
         * del admin) quedan bajo el margen: la única diferencia entre las dos es el permiso. */
        list($lead_control, $demo_control, $mensaje_control) = $this->escenario_reagendado_pendiente();
        list($lead, $demo, $mensaje)                         = $this->escenario_reagendado_pendiente();

        Carbon::setTestNow($this->momento('17:12'));
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '15');

        $slots = $this->slots_de($this->grilla($lead), $demo->id, self::FECHA);
        $this->assertNotContains(self::HORA_REAGENDADA, $slots, 'Montaje inválido: 17:15 pasa el margen, así que el control no prueba el permiso.');
        $this->assertNotContains('17:20', $slots, 'Montaje inválido: 17:20 pasa el margen por sí sola, así que agendarla no probaría que el permiso viajó.');

        /* CONTROL: el panel no toca la hora → la marca viaja y el turno se agenda. */
        $this->service()->apply_pending_actions(
            $mensaje_control,
            $this->final_actions_del_panel($demo_control, self::FECHA, self::HORA_REAGENDADA)
        );
        $this->assertSame(
            self::HORA_REAGENDADA,
            $lead_control->fresh()->demo_start_time,
            'Montaje inválido: ni siquiera el caso sin edición del admin agenda, así que el test no puede atribuirle nada a la edición.'
        );

        /* El caso: el admin movió la hora a las 17:20, que nunca se le ofreció al lead. */
        $tiro = false;
        try {
            $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, '17:20'));
        } catch (HorarioYaNoDisponibleException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'El permiso del reagendado viajó con una hora que el admin cambió a mano: la marca se convirtió en una amnistía general al margen.');
        $this->assertNull($lead->fresh()->demo_start_time, 'Se agendó un horario que nadie le ofreció al lead, con el permiso de un reagendado que ya no aplica.');
    }

    /**
     * (e2) 🔴 Y la marca NO es una llave que el modelo se pueda firmar solo. `agendar_demo` lo
     *      escribe el modelo y viaja crudo hasta la aprobación, así que un `reagendado_desde`
     *      inventado sería una exención del margen auto-otorgada: nombrar un horario que sí se le
     *      ofreció al lead para colar otro que no. La marca se honra sólo si es coherente con un
     *      reagendado real (anterior al que se agenda, ya arrancado, y hace poco).
     *
     * @return void
     */
    public function test_una_marca_de_reagendado_inventada_por_el_modelo_no_otorga_ningun_permiso(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        /* Lo que el lead REALMENTE recibió: las 17:30. */
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, '17:30');

        /* El paquete del modelo: agenda 17:15 —que nunca se ofreció— y se auto-firma el permiso
         * nombrando las 17:30, que sí figuran como ofrecidas. */
        $paquete = $this->paquete($demo, self::FECHA, self::HORA_REAGENDADA);
        $paquete['agendar_demo']['reagendado_desde'] = '17:30';
        $mensaje = $this->mensaje_pendiente($lead, $paquete);

        Carbon::setTestNow($this->momento('17:12'));

        /* Montaje verificado, en las tres patas: 17:15 no pasa el margen (o sea que sólo se puede
         * agendar con un permiso), no se le ofreció nunca, y el horario que la marca nombra sí se
         * le ofreció — que es lo que haría funcionar la exención si la marca se honrara a ciegas. */
        $this->assertNotContains(
            self::HORA_REAGENDADA,
            $this->slots_de($this->grilla($lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:15 pasan el margen, así que se agendarían sin necesidad de ningún permiso.'
        );
        $this->assertFalse(LeadMessage::horario_figura_como_ofrecido((int) $lead->id, self::FECHA, self::HORA_REAGENDADA));
        $this->assertTrue(
            LeadMessage::horario_figura_como_ofrecido((int) $lead->id, self::FECHA, '17:30'),
            'Montaje inválido: el horario que la marca inventa no figura como ofrecido, así que ni honrándola se rescataría nada.'
        );

        $tiro = false;
        try {
            $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA_REAGENDADA));
        } catch (HorarioYaNoDisponibleException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'Una marca `reagendado_desde` inventada por el modelo le abrió la puerta al margen: la exención se firma sola.');
        $this->assertNull($lead->fresh()->demo_start_time, 'Se agendó un horario que nunca se le ofreció al lead, con un permiso que el modelo se escribió a sí mismo.');
    }

    /**
     * (f) El slot nuevo se pasó mientras esperaba aprobación: no se agenda y NO SE ENVÍA NADA. El
     *     permiso no es una amnistía — la grilla margen-0 sigue exigiendo que el turno no haya
     *     arrancado. En ningún caso se re-reagenda: el texto ya lo firmó un admin.
     *
     * @return void
     */
    public function test_si_el_slot_nuevo_se_paso_esperando_aprobacion_no_se_envia_nada(): void
    {
        list($lead, $demo, $mensaje) = $this->escenario_reagendado_pendiente();
        $texto_que_leyo = $mensaje->content;

        Carbon::setTestNow($this->momento('17:20'));

        /* Montaje verificado: el permiso SIGUE existiendo (17:05 figura como ofrecido en un mensaje
         * enviado) y aun así tiene que frenar. Lo que cambió es que 17:15 ya arrancó. */
        $this->assertTrue(
            LeadMessage::horario_figura_como_ofrecido((int) $lead->id, self::FECHA, self::HORA_OFRECIDA),
            'Montaje inválido: el permiso no existe, así que el freno no prueba que el permiso no alcanza.'
        );

        $tiro = false;
        try {
            $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA_REAGENDADA));
        } catch (HorarioYaNoDisponibleException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'Se agendó un turno reagendado que ya había arrancado: el permiso se volvió una amnistía.');

        $lead_fresco = $lead->fresh();
        $this->assertNull($lead_fresco->demo_start_time, 'Se agendó igual un horario que ya arrancó.');
        $this->assertTrue((bool) $lead_fresco->requiere_intervencion_humana);

        $mensaje_fresco = $mensaje->fresh();
        $this->assertSame('rechazado', $mensaje_fresco->status, 'El mensaje frenado quedó en sugerido: se le cuela a Claude como si el lead lo hubiera recibido.');
        $this->assertSame($texto_que_leyo, $mensaje_fresco->content, 'Se reescribió in-place el texto que el admin aprobó.');
        $this->assertNull($mensaje_fresco->sent_by_admin_id, 'El mensaje quedó firmado por un admin sin haberse enviado.');
    }

    /* ------------------------------------------------------------------ */
    /* Cuándo NO se reagenda (cada uno con su control positivo)            */
    /* ------------------------------------------------------------------ */

    /**
     * (g) Pasados más de 60 minutos no se reagenda: un lead que contesta "dale" una hora y cuarto
     *     después no está aceptando un turno, está contestando tarde. Cae al correctivo, con el
     *     motivo real, y decide él.
     *
     * @return void
     */
    public function test_pasados_mas_de_sesenta_minutos_no_se_reagenda(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('18:10'));

        /* Montaje verificado: TODAS las demás condiciones del reagendado se cumplen —el horario se
         * le ofreció, es de hoy, y hay slots más tarde para correrle el turno—. Lo único que falla
         * es la ventana de los 60 minutos; el par diferencial exacto está en el test del borde. */
        $this->assertTrue(LeadMessage::horario_figura_como_ofrecido((int) $lead->id, self::FECHA, self::HORA_OFRECIDA));
        $this->assertContains('18:15', $this->slots_de($this->grilla($lead), $demo->id, self::FECHA), 'Montaje inválido: no queda ningún slot hoy, así que tampoco se reagendaría dentro de la ventana.');

        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));

        $this->assertNull($resultado['agendar_demo'], 'Se reagendó un horario que había arrancado hacía 65 minutos.');
        $this->assertSame('solicita_disponibilidad', $resultado['estado_sugerido']);
        $this->assertFalse(empty($resultado['nota_para_setter']), 'El descarte no le dejó ninguna nota al setter.');
    }

    /**
     * (h) El borde exacto de la ventana, en un solo par diferencial: 60 minutos justos reagenda, 61
     *     no. Es el test que le da sentido al de arriba — sin él, "no se reagenda a los 65 minutos"
     *     podría ser cualquier otra cosa.
     *
     * @return void
     */
    public function test_el_borde_exacto_de_los_sesenta_minutos(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_justo = $this->crear_demo();
        $lead_justo = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_justo, self::FECHA, self::HORA_OFRECIDA);

        $demo_pasado = $this->crear_demo();
        $lead_pasado = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_pasado, self::FECHA, self::HORA_OFRECIDA);

        /* 18:05 = 60 minutos exactos después de las 17:05. */
        Carbon::setTestNow($this->momento('18:05'));
        $adentro = $this->generar($lead_justo, $this->paquete($demo_justo, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($adentro['agendar_demo'], 'A los 60 minutos exactos no se reagendó: la ventana se está evaluando como estrictamente menor.');
        $this->assertSame('18:10', $adentro['agendar_demo']['demo_start_time']);

        /* 18:06 = 61 minutos: un minuto afuera. */
        Carbon::setTestNow($this->momento('18:06'));
        $afuera = $this->generar($lead_pasado, $this->paquete($demo_pasado, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNull($afuera['agendar_demo'], 'A los 61 minutos se reagendó igual: la ventana no está acotando nada.');
    }

    /**
     * (i) Un horario pasado que el lead se inventó (nunca se le ofreció) no se reagenda: el guard
     *     anti-alucinación queda intacto. El reagendado es un permiso acotado a lo que el lead
     *     efectivamente recibió, no una amnistía a cualquier horario que nombre.
     *
     * @return void
     */
    public function test_un_horario_que_nunca_se_ofrecio_no_se_reagenda(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_control = $this->crear_demo();
        $lead_control = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_control, self::FECHA, self::HORA_OFRECIDA);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        /* El único cambio contra el control: se le ofreció OTRA hora. */
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, '19:00');

        Carbon::setTestNow($this->momento('17:10'));

        $control = $this->generar($lead_control, $this->paquete($demo_control, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($control['agendar_demo'], 'Montaje inválido: el escenario no reagenda ni cuando el horario SÍ se ofreció.');

        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNull($resultado['agendar_demo'], 'Se reagendó un horario que el sistema nunca le ofreció al lead.');
        $this->assertFalse(empty($resultado['nota_para_setter']));
    }

    /**
     * (j) Si el horario TODAVÍA NO ARRANCÓ, el caso no es de esta misión: lo resuelve el rescate
     *     del margen de la misión hermana, que agenda el horario ofrecido tal cual y no toca el
     *     texto. Este test blinda que el reagendado no le pisó ese caso.
     *
     * @return void
     */
    public function test_un_horario_que_todavia_no_arranco_sigue_yendo_por_el_rescate_del_margen(): void
    {
        Carbon::setTestNow($this->momento('16:57'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        /* 17:02: las 17:05 ya no pasan el margen de 5 minutos, pero todavía no arrancaron. */
        Carbon::setTestNow($this->momento('17:02'));

        $this->assertNotContains(
            self::HORA_OFRECIDA,
            $this->slots_de($this->grilla($lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:05 siguen en la grilla, así que ni el rescate ni el reagendado se están ejercitando.'
        );

        $paquete   = $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA);
        $resultado = $this->generar($lead, $paquete);

        $this->assertNotNull($resultado['agendar_demo'], 'El rescate del margen de la misión anterior dejó de funcionar.');
        $this->assertSame(self::HORA_OFRECIDA, $resultado['agendar_demo']['demo_start_time'], 'Se le corrió el turno a un horario que todavía no había arrancado.');
        $this->assertArrayNotHasKey('reagendado_desde', $resultado['agendar_demo'], 'Se marcó como reagendado un paquete que no se reagendó.');
        $this->assertSame($paquete['mensaje_sugerido'], $resultado['mensaje_sugerido'], 'Se reescribió el texto de un paquete que no se reagendó.');
    }

    /**
     * (k) Si no queda ningún slot hoy no hay a dónde correr el turno: no se reagenda y cae al
     *     correctivo. El control cambia UNA sola cosa —la franja horaria de la demo— para que el
     *     mismo escenario sí tenga próximo slot.
     *
     * @return void
     */
    public function test_si_no_queda_ningun_slot_hoy_no_se_reagenda(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_control = $this->crear_demo();
        $lead_control = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_control, self::FECHA, self::HORA_OFRECIDA);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        /* CONTROL, con el día entero abierto: se reagenda. */
        $control = $this->generar($lead_control, $this->paquete($demo_control, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($control['agendar_demo'], 'Montaje inválido: el escenario no reagenda ni con el día entero disponible.');

        /* El caso: la demo sólo atiende hasta el mediodía, así que después de las 17:05 no queda
         * absolutamente nada hoy. */
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-12:00');

        $this->assertSame([], $this->slots_de($this->grilla($lead), $demo->id, self::FECHA), 'Montaje inválido: todavía quedan slots hoy, así que sí habría a dónde reagendar.');

        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));

        $this->assertNull($resultado['agendar_demo'], 'Se reagendó a un slot que no existe.');
        $this->assertStringContainsString(
            'ese horario ya arrancó',
            $this->ultimo_prompt_correctivo(),
            'El correctivo salió sin el motivo real.'
        );
    }

    /**
     * (l) El próximo slot puede ser de OTRA instancia, y el `demo_id` cambia. El lead nunca ve el
     *     demo_id y el link de la experiencia sale de su propio uuid: quedarse pegado a la
     *     instancia original le costaría al lead una hora larga sin comprar nada.
     *
     * @return void
     */
    public function test_el_proximo_slot_puede_ser_de_otra_instancia(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_del_lead = $this->crear_demo();
        $demo_libre    = $this->crear_demo();

        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        /* Un lead intruso ocupa la instancia del lead alrededor del horario nuevo. */
        $intruso = $this->crear_lead('demo_agendada');
        $intruso->demo_id         = $demo_del_lead->id;
        $intruso->demo_date       = self::FECHA;
        $intruso->demo_start_time = '17:20';
        $intruso->demo_end_time   = '18:20';
        $intruso->save();

        Carbon::setTestNow($this->momento('17:10'));

        /* Montaje verificado: 17:15 está libre en la otra instancia y NO en la del lead. Sin esto,
         * el test no distinguiría "eligió la otra instancia" de "la original también servía". */
        $grilla = $this->grilla($lead);
        $this->assertNotContains(self::HORA_REAGENDADA, $this->slots_de($grilla, $demo_del_lead->id, self::FECHA), 'Montaje inválido: la instancia del lead tiene libre las 17:15.');
        $this->assertContains(self::HORA_REAGENDADA, $this->slots_de($grilla, $demo_libre->id, self::FECHA), 'Montaje inválido: la otra instancia no tiene libre las 17:15.');

        $resultado = $this->generar($lead, $this->paquete($demo_del_lead, self::FECHA, self::HORA_OFRECIDA));

        $this->assertNotNull($resultado['agendar_demo'], 'No se reagendó teniendo otra instancia libre en el próximo slot.');
        $this->assertSame(self::HORA_REAGENDADA, $resultado['agendar_demo']['demo_start_time'], 'Se corrió el turno más lejos de lo necesario por quedarse pegado a la instancia original.');
        $this->assertSame($demo_libre->id, (int) $resultado['agendar_demo']['demo_id'], 'No se cambió de instancia: el corrimiento dejó de ser el mínimo.');
    }

    /**
     * (m) Ante EMPATE de horario gana la instancia original: mover la instancia porque sí es ruido
     *     en los logs y en el panel.
     *
     * @return void
     */
    public function test_ante_empate_de_horario_gana_la_instancia_original(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        /* La otra instancia se crea PRIMERO a propósito: queda con el id más chico, así que es la
         * que ganaría si el desempate no existiera y el recorrido se quedara con la primera. */
        $demo_otra     = $this->crear_demo();
        $demo_del_lead = $this->crear_demo();
        $this->assertLessThan($demo_del_lead->id, $demo_otra->id, 'Montaje inválido: la instancia original no es la de id más alto, así que el empate no prueba el desempate.');

        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $grilla = $this->grilla($lead);
        $this->assertContains(self::HORA_REAGENDADA, $this->slots_de($grilla, $demo_del_lead->id, self::FECHA), 'Montaje inválido: la instancia original no tiene libre las 17:15.');
        $this->assertContains(self::HORA_REAGENDADA, $this->slots_de($grilla, $demo_otra->id, self::FECHA), 'Montaje inválido: la otra instancia no tiene libre las 17:15, así que no hay empate.');

        $resultado = $this->generar($lead, $this->paquete($demo_del_lead, self::FECHA, self::HORA_OFRECIDA));

        $this->assertNotNull($resultado['agendar_demo']);
        $this->assertSame(self::HORA_REAGENDADA, $resultado['agendar_demo']['demo_start_time']);
        $this->assertSame($demo_del_lead->id, (int) $resultado['agendar_demo']['demo_id'], 'Con las dos instancias libres en el mismo horario se cambió de instancia igual.');
    }

    /**
     * (n) Un paquete que pide VENTANA EXTENDIDA no se reagenda: la franja es un trato negociado con
     *     el lead ("te la dejo de 20 a 23:59") y moverle el inicio cambia el trato que el mensaje ya
     *     describía. El control es el mismo paquete sin la ventana.
     *
     * @return void
     */
    public function test_un_paquete_con_ventana_extendida_no_se_reagenda(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_control = $this->crear_demo();
        $lead_control = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_control, self::FECHA, self::HORA_OFRECIDA);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $control = $this->generar($lead_control, $this->paquete($demo_control, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($control['agendar_demo'], 'Montaje inválido: el escenario no reagenda ni sin ventana extendida.');

        /* El único cambio: el paquete pide la franja extendida sobre el mismo horario pasado. */
        $paquete = $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA);
        $paquete['agendar_demo']['ventana_extendida'] = true;

        $resultado = $this->generar($lead, $paquete);

        $this->assertNull($resultado['agendar_demo'], 'Se reagendó una ventana extendida: se le movió el inicio a una franja que el mensaje ya le había prometido al lead.');
    }

    /* ------------------------------------------------------------------ */
    /* La dinámica actual y el motivo real del correctivo                  */
    /* ------------------------------------------------------------------ */

    /**
     * (o) La dinámica ACTUAL queda intacta: el gate por dinámica corta antes de todo, así que un
     *     lead `actual` se sigue comportando igual que antes de la misión.
     *
     * @return void
     */
    public function test_la_dinamica_actual_no_se_reagenda(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_control = $this->crear_demo();
        $lead_control = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_control, self::FECHA, self::HORA_OFRECIDA);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        /* El único cambio contra el control: la dinámica. */
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $control = $this->generar($lead_control, $this->paquete($demo_control, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($control['agendar_demo'], 'Montaje inválido: el escenario no reagenda ni en la dinámica nueva.');

        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));

        $this->assertNull($resultado['agendar_demo'], 'Se reagendó un lead de la dinámica actual: se le cambió el comportamiento a un flujo que nadie pidió tocar.');
    }

    /**
     * (o2) Y el prompt correctivo de la dinámica ACTUAL sigue ofreciendo la LISTA de alternativas,
     *      exactamente como hasta hoy. Los dos textos no se unifican: el protocolo v2 de la
     *      dinámica nueva dice "el mensaje ofrece UN momento, no una lista", y la actual ofrece
     *      rangos. Unificarlos rompe uno de los dos.
     *
     * @return void
     */
    public function test_el_correctivo_de_la_dinamica_actual_sigue_llevando_la_lista(): void
    {
        Carbon::setTestNow($this->momento('17:10'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        /* Mañana (miércoles laborable) y una hora que no cae en la frecuencia de 5 minutos: el
         * descarte clásico por hora inválida, con la fecha adentro de la ventana para que el
         * correctivo tenga alternativas reales que enumerar. */
        $manana = '2026-08-26';

        $this->assertNotEmpty(
            $this->slots_de($this->grilla($lead, $manana), $demo->id, $manana),
            'Montaje inválido: no hay slots para mañana, así que el correctivo iría por la rama de "sin alternativas" y no probaría la lista.'
        );

        $resultado = $this->generar($lead, $this->paquete($demo, $manana, '17:07'), $manana);
        $this->assertNull($resultado['agendar_demo'], 'Montaje inválido: el horario inventado no se descartó, así que no hubo correctivo.');

        $prompt = $this->ultimo_prompt_correctivo();
        $this->assertStringContainsString('Los próximos horarios disponibles son:', $prompt, 'La dinámica actual perdió la lista de alternativas del correctivo.');
        $this->assertStringNotContainsString('Ofrecele ESE, uno solo', $prompt, 'Se le aplicó a la dinámica actual la instrucción de un solo horario, que es del protocolo v2.');
    }

    /**
     * (p) 🔴 EL BUG QUE REPORTÓ LUCAS. Cuando NO se reagenda, el prompt correctivo tiene que llevar
     *     la CAUSA REAL. El modelo no deja huecos: si se le afirma "ese horario ya no está
     *     disponible" sin decirle por qué, escribe la explicación más creíble que se le ocurre —"uh,
     *     justo se ocupó"— y esa invención sale firmada por el sistema y le llega al lead como un
     *     hecho.
     *
     * ⚠️ Sobre la aserción de "se ocupó": el prompt nuevo NOMBRA esa frase, pero para PROHIBIRLA
     * ("NO digas que se ocupó"). Buscar el string pelado en el cuerpo entero daría rojo por la
     * prohibición misma, así que la aserción se hace por línea, salteando la de la prohibición: lo
     * que no puede pasar es que el prompt le AFIRME al modelo esa causa.
     *
     * @return void
     */
    public function test_el_correctivo_lleva_el_motivo_real_y_un_solo_horario(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        /* Fuera de la ventana de los 60 minutos: no se reagenda, así que sale el correctivo — que
         * es el que tiene que decir la verdad. */
        Carbon::setTestNow($this->momento('18:10'));

        $resultado = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNull($resultado['agendar_demo'], 'Montaje inválido: hubo reagendado, así que no salió ningún correctivo que inspeccionar.');

        $encontrado = false;
        Http::assertSent(function ($request) use (&$encontrado) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }
            $datos = $request->data();
            $texto = isset($datos['messages'][0]['content']) ? (string) $datos['messages'][0]['content'] : '';
            if (strpos($texto, 'no se pudo confirmar') === false) {
                return false;
            }
            $encontrado = true;

            /* El motivo real, textual. */
            $this->assertStringContainsString(
                'MOTIVO REAL, y es el único que podés dar: ese horario ya arrancó',
                $texto,
                'El prompt correctivo salió sin la causa real: el modelo la va a inventar.'
            );

            /* 🔴 Y en ninguna línea que no sea la prohibición se le afirma la causa inventada. */
            foreach (explode("\n", $texto) as $linea) {
                if (strpos($linea, 'PROHIBIDO inventar otra causa') !== false) {
                    continue;
                }
                $this->assertStringNotContainsString('se ocup', $linea, 'El prompt le afirma al modelo que el horario "se ocupó": es la causa falsa que reportó Lucas.');
            }

            /* Un solo horario: el protocolo v2 no enumera. */
            $this->assertStringContainsString('El próximo horario disponible es: 18:15', $texto, 'La dinámica nueva no recibió el horario único del protocolo v2.');
            $this->assertStringNotContainsString('Los próximos horarios disponibles son:', $texto, 'La dinámica nueva recibió la lista entera: lo que está en el prompt, se enumera.');

            return true;
        });

        $this->assertTrue($encontrado, 'No salió ninguna llamada correctiva: no hay prompt que inspeccionar.');
    }

    /**
     * (q) Si el modelo no redacta el texto del reagendado, NO se reagenda y no queda nada a medias.
     *     Por eso el orden es primero el texto y después la mutación: sin estado escrito, no hay
     *     nada que revertir. Y no se inventa un texto fijo de PHP en la voz del agente.
     *
     * @return void
     */
    public function test_si_el_modelo_no_redacta_no_se_reagenda_y_no_queda_nada_a_medias(): void
    {
        Carbon::setTestNow($this->momento('17:00'));

        $demo_control = $this->crear_demo();
        $lead_control = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead_control, self::FECHA, self::HORA_OFRECIDA);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $control = $this->generar($lead_control, $this->paquete($demo_control, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($control['agendar_demo'], 'Montaje inválido: el escenario no reagenda ni con el modelo respondiendo bien.');

        /* El único cambio: la llamada del reagendado devuelve una estructura en vez de texto, que
         * el service trata como fallo. */
        $this->reagendado_devuelve_estructura = true;

        $paquete   = $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA);
        $resultado = $this->generar($lead, $paquete);

        $this->assertNull($resultado['agendar_demo'], 'Se reagendó sin haber conseguido el texto: el mensaje le confirmaría al lead un horario que nadie le explicó.');
        $this->assertArrayNotHasKey('horarios_ofrecidos', $resultado, 'Quedó escrito un horarios_ofrecidos de un reagendado que nunca ocurrió: estado a medias.');
        $this->assertStringNotContainsString(self::HORA_REAGENDADA, (string) $resultado['nota_para_setter'], 'La nota para el setter anuncia un reagendado que no pasó.');
    }

    /* ------------------------------------------------------------------ */
    /* Montaje                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * El escenario completo hasta el mensaje esperando aprobación: se ofrece 17:05 a las 17:00, el
     * lead acepta 17:10 y el sistema reagenda a 17:15. El paquete pendiente NO se escribe a mano:
     * sale de correr la generación de verdad, que es lo que en producción alimenta
     * `create_pending_agendamiento_message()`.
     *
     * @return array{0: Lead, 1: Demo, 2: LeadMessage}
     */
    private function escenario_reagendado_pendiente(): array
    {
        $reloj_previo = Carbon::getTestNow();

        Carbon::setTestNow($this->momento('17:00'));

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA_OFRECIDA);

        Carbon::setTestNow($this->momento('17:10'));

        $paquete = $this->generar($lead, $this->paquete($demo, self::FECHA, self::HORA_OFRECIDA));
        $this->assertNotNull($paquete['agendar_demo'], 'Montaje inválido: no hubo reagendado, así que no hay paquete reagendado que aprobar.');
        $this->assertSame(self::HORA_REAGENDADA, $paquete['agendar_demo']['demo_start_time']);

        $mensaje = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $paquete['mensaje_sugerido'],
            'status'                => 'sugerido',
            'is_followup'           => false,
            'requiere_verificacion' => true,
            'sent_by_admin_id'      => null,
            'pending_actions'       => $paquete,
            'horarios_ofrecidos'    => $paquete['horarios_ofrecidos'],
        ]);

        if ($reloj_previo !== null) {
            Carbon::setTestNow($reloj_previo);
        }

        return [$lead, $demo, $mensaje];
    }

    /**
     * Un mensaje esperando aprobación humana con el paquete que se le pase, tal cual. Es el que en
     * producción sale firmado por el admin que aprieta aprobar.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $paquete
     *
     * @return LeadMessage
     */
    private function mensaje_pendiente(Lead $lead, array $paquete): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => $paquete['mensaje_sugerido'],
            'status'                => 'sugerido',
            'is_followup'           => false,
            'requiere_verificacion' => true,
            'sent_by_admin_id'      => null,
            'pending_actions'       => $paquete,
        ]);
    }

    /**
     * Corre el camino de GENERACIÓN completo: arma la grilla que el modelo tuvo delante y se la
     * pasa al guard que decide si el agendamiento sobrevive.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $paquete
     * @param string|null          $fecha   Fecha específica de la grilla (default: la del caso).
     *
     * @return array<string, mixed>
     */
    private function generar(Lead $lead, array $paquete, ?string $fecha = null): array
    {
        $service  = $this->service();
        $ventanas = null;
        $grilla   = $this->grilla($lead, $fecha, $service, $ventanas);

        return $service->descartar($lead, $paquete, $grilla, is_array($ventanas) ? $ventanas : []);
    }

    /**
     * La grilla tal como la ve el agente para OFRECER: con el margen puesto (sexto argumento en
     * null). Es la misma que después recibe descartar_agendamiento_fuera_de_slots().
     *
     * @param Lead               $lead
     * @param string|null        $fecha
     * @param LeadAiService|null $service
     * @param array|null         $ventanas Referencia de salida con las ventanas extendidas.
     *
     * @return array<string, mixed>
     */
    private function grilla(Lead $lead, ?string $fecha = null, ?LeadAiService $service = null, &$ventanas = null): array
    {
        $service  = $service !== null ? $service : $this->service();
        $snapshot = null;
        $config   = null;

        return $service->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            $fecha !== null ? $fecha : self::FECHA,
            $lead->id,
            $lead->usa_experiencia_demo_nueva(),
            null,
            $config,
            $ventanas
        );
    }

    /**
     * Instancia del service con los métodos protegidos que hacen falta expuestos. La subclase no
     * cambia ninguna lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param Lead                 $lead
             * @param array<string, mixed> $parsed
             * @param array<string, mixed> $availability_data
             * @param array<string, mixed> $ventanas_extendidas
             *
             * @return array<string, mixed>
             */
            public function descartar(Lead $lead, array $parsed, array $availability_data, array $ventanas_extendidas = []): array
            {
                return $this->descartar_agendamiento_fuera_de_slots($lead, $parsed, $availability_data, $ventanas_extendidas);
            }

            /**
             * @param Lead                 $lead
             * @param array<string, mixed> $parsed
             *
             * @return bool
             */
            public function gate(Lead $lead, array $parsed): bool
            {
                return $this->requires_agendamiento_verification_gate($lead, $parsed);
            }

            /**
             * @param Lead $lead
             *
             * @return string
             */
            public function historial(Lead $lead): string
            {
                return $this->build_user_content($lead, false);
            }
        };
    }

    /**
     * Doble de la API de Claude. Distingue las dos llamadas aisladas por el texto del prompt, que
     * es lo único que las separa: las dos pegan al mismo endpoint.
     *
     * @param \Illuminate\Http\Client\Request $request
     *
     * @return \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
     */
    private function responder_como_claude($request)
    {
        $datos  = $request->data();
        $prompt = isset($datos['messages'][0]['content']) ? (string) $datos['messages'][0]['content'] : '';

        if (strpos($prompt, 'YA LE DEJAMOS AGENDADA') !== false) {
            if ($this->reagendado_devuelve_estructura) {
                /* Respuesta estructurada: el service la trata como fallo y no reagenda. */
                return Http::response(['content' => [['type' => 'text', 'text' => '{"mensaje_sugerido":"no"}']]], 200);
            }

            /* Se responde con el horario que el propio prompt declara agendado, para que el texto
             * del doble no pueda quedar desalineado con lo que el service decidió. */
            $slot = '';
            if (preg_match('/YA LE DEJAMOS AGENDADA la demo para HOY a las (\d{1,2}:\d{2})/u', $prompt, $m)) {
                $slot = $m[1];
            }

            return Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => 'Uy, la de recién ya había arrancado y necesitamos unos minutos para dejarte todo listo, '
                        . 'así que te la dejé agendada para hoy a las ' . $slot . '. Entrás por acá cuando llegue la hora.',
                ]],
            ], 200);
        }

        return Http::response([
            'content' => [['type' => 'text', 'text' => 'Perdón, ese horario no lo pude confirmar. Te paso otro.']],
        ], 200);
    }

    /**
     * El cuerpo del último prompt correctivo que salió a la API.
     *
     * @return string
     */
    private function ultimo_prompt_correctivo(): string
    {
        $ultimo = '';

        foreach (Http::recorded() as $par) {
            $request = $par[0];
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                continue;
            }
            $datos = $request->data();
            $texto = isset($datos['messages'][0]['content']) ? (string) $datos['messages'][0]['content'] : '';
            if (strpos($texto, 'no se pudo confirmar') !== false) {
                $ultimo = $texto;
            }
        }

        $this->assertNotSame('', $ultimo, 'No salió ninguna llamada correctiva: no hay prompt que inspeccionar.');

        return $ultimo;
    }

    /**
     * Un instante del día del caso, en hora de Argentina.
     *
     * @param string $hhmm
     *
     * @return Carbon
     */
    private function momento(string $hhmm): Carbon
    {
        return Carbon::parse(self::FECHA . ' ' . $hhmm . ':00', 'America/Argentina/Buenos_Aires');
    }

    /**
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

    /**
     * Lead de la dinámica nueva, sin turno.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'calificado'): Lead
    {
        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = $status;
        $lead->save();

        // Después del save: el hook `creating` estampa la dinámica por defecto.
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Un mensaje del sistema que el lead YA recibió, declarando qué horarios se le ofrecieron. Es
     * la fuente de verdad del permiso, tanto del rescate del margen como del reagendado.
     *
     * @param Lead   $lead
     * @param string $fecha
     * @param string $desde
     *
     * @return LeadMessage
     */
    private function mensaje_ya_enviado_ofreciendo(Lead $lead, string $fecha, string $desde): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'            => $lead->id,
            'sender'             => 'sistema',
            'content'            => 'Te puedo dar hoy a las ' . $desde . '. ¿Te sirve?',
            'status'             => 'enviado',
            'is_followup'        => false,
            'sent_at'            => AppTime::now(),
            'horarios_ofrecidos' => [
                ['fecha' => $fecha, 'desde' => $desde, 'hasta' => $desde],
            ],
        ]);
    }

    /**
     * El paquete que devuelve el modelo confirmando el turno que el lead aceptó.
     *
     * @param Demo   $demo
     * @param string $fecha
     * @param string $hora
     *
     * @return array<string, mixed>
     */
    private function paquete(Demo $demo, string $fecha, string $hora): array
    {
        return [
            'mensaje_sugerido' => 'Listo, te confirmo la demo hoy a las ' . $hora . '. Te paso el link apenas la preparo.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => 'El lead aceptó el horario que le ofrecimos.',
            'agendar_demo'     => [
                'demo_id'         => $demo->id,
                'demo_date'       => $fecha,
                'demo_start_time' => $hora,
            ],
        ];
    }

    /**
     * Lo que manda el panel de verificación al aprobar. Reconstruye `agendar_demo` clave por clave,
     * igual que el SPA: las claves que no conoce no viajan.
     *
     * @param Demo   $demo
     * @param string $fecha
     * @param string $hora
     *
     * @return array<string, mixed>
     */
    private function final_actions_del_panel(Demo $demo, string $fecha, string $hora): array
    {
        return [
            'estado_sugerido' => 'demo_agendada',
            'agendar_demo'    => [
                'demo_id'         => $demo->id,
                'demo_date'       => $fecha,
                'demo_start_time' => $hora,
            ],
            'forzar_slot' => false,
        ];
    }

    /**
     * Slots de una demo en una fecha, buscando la clave que termina en ese Y-m-d.
     *
     * @param array<string, mixed> $datos
     * @param int                  $demo_id
     * @param string               $fecha
     *
     * @return array<int, string>
     */
    private function slots_de(array $datos, int $demo_id, string $fecha): array
    {
        $por_fecha = isset($datos['demos'][$demo_id]) ? $datos['demos'][$demo_id] : [];
        foreach ($por_fecha as $label => $slots) {
            if (substr((string) $label, -strlen($fecha)) === $fecha) {
                return array_map('strval', $slots);
            }
        }

        return [];
    }

    /**
     * Los bloques rojos de error NO entran al historial que lee el agente.
     *
     * 🔴 Es el filtro más transversal de la misión —aplica a TODA conversación de TODO lead— y el
     * único que no quedaba clavado por ningún test. Sin él, el agente lee el bloque de error del
     * sistema como si fuera una línea de la conversación con el lead, y puede repetirle una causa
     * que nadie le dijo: es la puerta por la que volvía el "se ocupó" que reportó Lucas, después de
     * haber blindado los dos prompts aislados (que no llevan historial).
     *
     * El control positivo va en el mismo test: un mensaje normal del sistema, creado igual, SÍ
     * tiene que estar en el historial. Sin eso, un `build_user_content()` que devolviera vacío por
     * cualquier motivo haría pasar la aserción negativa sin probar nada.
     *
     * @return void
     */
    public function test_los_bloques_de_error_no_entran_al_historial_que_lee_el_agente(): void
    {
        $lead = $this->crear_lead();

        /* Mensaje normal del sistema: el lead SÍ lo recibió. */
        $enviado = new LeadMessage();
        $enviado->lead_id = $lead->id;
        $enviado->sender  = 'sistema';
        $enviado->status  = 'enviado';
        $enviado->content = 'Te la dejo lista para hoy a las 17:05.';
        $enviado->save();

        /* Bloque rojo: lo escribe el sistema para el admin, nunca salió al lead. */
        (new \App\Services\LeadConversationErrorLogger())->log(
            (int) $lead->id,
            'No se envió: el horario ya no está disponible',
            'El turno dejó de estar disponible mientras esperaba aprobación.'
        );

        /* Montaje: el bloque rojo existe de verdad y está marcado como error. */
        $bloque = LeadMessage::query()->where('lead_id', $lead->id)->where('is_error', true)->first();
        $this->assertNotNull($bloque, 'El montaje falló: no se creó el bloque de error.');

        $historial = $this->service()->historial($lead->refresh());

        $this->assertStringContainsString(
            'Te la dejo lista para hoy a las 17:05.',
            $historial,
            'El control positivo falló: un mensaje enviado normal tiene que estar en el historial.'
        );
        $this->assertStringNotContainsString(
            'dejó de estar disponible mientras esperaba aprobación',
            $historial,
            'El bloque rojo de error entró al historial que lee el agente.'
        );
    }
}
