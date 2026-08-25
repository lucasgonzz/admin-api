<?php

namespace Tests\Feature;

use App\Exceptions\AprobacionEnCursoException;
use App\Exceptions\HorarioYaNoDisponibleException;
use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La oferta que el lead acepta no caduca por el margen mínimo de anticipación.
 *
 * El caso real que originó la misión (lead "Brisa", 25/8/2026): el sistema le ofreció HOY 17:05 en
 * un mensaje enviado 16:57, el lead aceptó 16:58, y el sistema le contestó "uh, justo se ocupó el
 * de las 17:05" — con el horario libre. La causa: la oferta primaria es SIEMPRE el primer slot que
 * sobrevive al margen, así que nace pegada al borde y al turno siguiente el reloj la sacó de la
 * grilla.
 *
 * Lo que estos tests clavan son las cuatro respuestas, que no son la misma:
 *   (a) ofrecido y sólo caído por el margen  → se agenda, sin correctivo y sin reescribir el texto;
 *   (b) ofrecido pero ya pasó                → no se agenda Y NO SE ENVÍA NADA;
 *   (c) ofrecido pero lo tomó otro lead      → igual que (b) — esta es la que prueba que el fix
 *                                              no abre doble-booking;
 *   (d) nunca ofrecido, bajo el margen       → se sigue rechazando como antes.
 *
 * 🔴 En (b) y (c) lo que más importa no es que no se agende: es que el TEXTO que el admin aprobó no
 * se toque ni se envíe (`content` intacto, `pending_actions` puestas, `sent_by_admin_id` en null).
 * Antes de esta misión el sistema pisaba ese texto con un correctivo y lo mandaba igual, firmado
 * "aprobado por <admin>" arriba de algo que ese admin nunca leyó. El mensaje sí cambia de estado, a
 * `rechazado`: es la única forma de que build_user_content() no se lo pase a Claude como si el lead
 * lo hubiera recibido.
 *
 * Y hay un quinto final, que NO es ninguno de los cuatro: un timeout del lock de la instancia es
 * contención transitoria y reintentable, así que se pide reintentar sin castigar la conversación
 * (ver test_un_timeout_del_lock_pide_reintentar_y_no_castiga_la_conversacion).
 */
class OfertaAceptadaNoCaducaPorMargenTest extends TestCase
{
    use DatabaseTransactions;

    /** El día del caso real: martes, laborable. */
    const FECHA = '2026-08-25';

    /** El horario del caso real. */
    const HORA = '17:05';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de este archivo sale a la red: el aviso a la instancia y la API de Claude se
        // sustituyen enteros.
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        // Grilla previsible y de día completo, para que los horarios de los tests existan de
        // verdad en la disponibilidad. El horario del closer va igual de ancho porque la dinámica
        // ACTUAL (caso h) resuelve su grilla contra él y no contra la franja de la demo.
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '5');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');

        /* El margen de la dinámica nueva en 5 minutos: con el reloj a las 17:02 deja afuera las
         * 17:05 por tres minutos, que es exactamente el borde donde nació el bug. */
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '5');
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
    /* Los cuatro casos que Lucas pidió blindados                          */
    /* ------------------------------------------------------------------ */

    /**
     * (a) 🔴 EL CASO DE BRISA. Se le ofreció 17:05 a las 16:57, acepta, y para cuando el admin
     *     aprueba son las 17:02: el margen de 5 minutos ya sacó las 17:05 de la grilla. El turno
     *     está libre, se lo ofrecimos nosotros: se agenda, no sale ningún correctivo y el texto que
     *     el admin leyó sale tal cual.
     *
     * @return void
     */
    public function test_la_oferta_aceptada_no_caduca_por_el_margen(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        $mensaje         = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo  = $mensaje->content;

        /* Pasan cinco minutos entre que el lead acepta y que el admin aprueba. */
        Carbon::setTestNow($this->momento_de_la_aceptacion());

        /* Montaje verificado, y es lo que hace que este test PUEDA fallar: en este instante la
         * grilla normal (con margen) ya no trae las 17:05. Sin el rescate, la validación de la
         * aprobación las lee como "no disponible" y tira. */
        $this->assertNotContains(
            self::HORA,
            $this->slots_de($this->grilla_con_margen($this->service(), $lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:05 siguen en la grilla con margen, así que el test pasaría sin necesidad del rescate.'
        );

        $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));

        $lead->refresh();
        $this->assertSame(self::HORA, $lead->demo_start_time, 'El horario que le ofrecimos y aceptó no se agendó: el margen se lo comió otra vez.');
        $this->assertSame(self::FECHA, $lead->demo_date->format('Y-m-d'));
        $this->assertSame('demo_agendada', $lead->status);

        $this->assertSame(
            $texto_que_leyo,
            $mensaje->fresh()->content,
            'Se reescribió el mensaje que el admin aprobó, aunque el horario estaba libre.'
        );
        $this->assertNoHuboLlamadaCorrectiva();
    }

    /**
     * (a2) El mismo rescate por el camino de GENERACIÓN (descartar_agendamiento_fuera_de_slots):
     *      el paquete que trae Claude confirmando las 17:05 sobrevive, y no queda nota para el
     *      setter.
     *
     * @return void
     */
    public function test_la_oferta_aceptada_tampoco_caduca_en_el_camino_de_generacion(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $service = $this->service();
        /* La MISMA grilla que ve el agente al generar: con el margen puesto, o sea sin las 17:05. */
        $disponibilidad = $this->grilla_con_margen($service, $lead);
        $this->assertNotContains(
            self::HORA,
            $this->slots_de($disponibilidad, $demo->id, self::FECHA),
            'Montaje inválido: las 17:05 siguen en la grilla con margen, así que el test no probaría el rescate.'
        );

        $resultado = $service->descartar($lead, $this->paquete($demo, self::FECHA, self::HORA), $disponibilidad);

        $this->assertNotNull($resultado['agendar_demo'], 'El camino de generación descartó un horario que le habíamos ofrecido al lead.');
        $this->assertSame(self::HORA, $resultado['agendar_demo']['demo_start_time']);
        $this->assertTrue(empty($resultado['nota_para_setter']), 'Se dejó una nota de descarte para el setter sobre un agendamiento válido.');
    }

    /**
     * (b) 🔴 El horario ofrecido YA PASÓ: no se agenda y NO SE ENVÍA NADA. El mensaje aprobado
     *     queda intacto y el admin se entera por el 422 y por el bloque rojo del hilo.
     *
     * @return void
     */
    public function test_un_horario_ofrecido_que_ya_paso_no_se_agenda_y_no_se_envia_nada(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo = $mensaje->content;

        /* El reloj se pasó del turno: 17:06 contra un turno de las 17:05. */
        Carbon::setTestNow(Carbon::parse(self::FECHA . ' 17:06:00', 'America/Argentina/Buenos_Aires'));

        $this->assertSeFrenaYNoSeEnvia($lead, $mensaje, $texto_que_leyo, $demo);
    }

    /**
     * (c) 🔴 El horario ofrecido lo tomó OTRO LEAD entre la oferta y la aprobación: no se agenda y
     *     no se envía nada. Es la prueba de que el rescate del margen NO abre doble-booking — el
     *     turno sigue estando "ofrecido", pero la grilla margen-0 pasa igual por el bloqueo por
     *     demo_id y no lo trae.
     *
     * @return void
     */
    public function test_un_horario_ofrecido_que_ocupo_otro_lead_no_se_agenda_y_no_se_envia_nada(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo = $mensaje->content;

        /* El que se metió en el medio: mismo demo_id, mismo día, arrancando 17:00. Con 60 de
         * duración, 15 de setup y 10 de gracia, bloquea de 16:45 a 18:10 — las 17:05 quedan
         * adentro. */
        $intruso = $this->crear_lead('demo_agendada');
        $intruso->demo_id         = $demo->id;
        $intruso->demo_date       = self::FECHA;
        $intruso->demo_start_time = '17:00';
        $intruso->demo_end_time   = '18:00';
        $intruso->save();

        /* Montaje verificado: acá todavía son las 16:57, o sea que las 17:05 pasan el margen de
         * sobra — si igual no figuran, es porque el intruso las bloqueó, y no por el reloj. Eso es
         * lo que separa este caso del (b) y del (d). */
        $this->assertNotContains(
            self::HORA,
            $this->slots_de($this->grilla_con_margen($this->service(), $lead), $demo->id, self::FECHA),
            'Montaje inválido: el lead intruso no está bloqueando las 17:05, así que este test no probaría el doble-booking.'
        );

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrenaYNoSeEnvia($lead, $mensaje, $texto_que_leyo, $demo);
    }

    /**
     * (d) Un horario que NUNCA se le ofreció y no da el margen se sigue rechazando exactamente
     *     como antes: el rescate no es una amnistía general al margen, es un permiso acotado a lo
     *     que el lead efectivamente recibió.
     *
     * @return void
     */
    public function test_un_horario_que_nunca_se_ofrecio_se_sigue_rechazando(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        /* Se le ofreció OTRA hora: el mensaje existe, pero no cubre las 17:05. */
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, '19:00');
        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo = $mensaje->content;

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrenaYNoSeEnvia($lead, $mensaje, $texto_que_leyo, $demo);
    }

    /**
     * (d, camino de generación) Y en generación el paquete se sigue descartando, con su nota para
     * el setter — el guard anti-alucinación del lead #12 queda intacto.
     *
     * @return void
     */
    public function test_un_horario_que_nunca_se_ofrecio_se_sigue_descartando_en_generacion(): void
    {
        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $service        = $this->service();
        $disponibilidad = $this->grilla_con_margen($service, $lead);

        $resultado = $service->descartar($lead, $this->paquete($demo, self::FECHA, self::HORA), $disponibilidad);

        $this->assertNull($resultado['agendar_demo'], 'Se agendó un horario que el sistema nunca le ofreció al lead.');
        $this->assertFalse(empty($resultado['nota_para_setter']), 'El descarte no le dejó ninguna nota al setter.');
    }

    /* ------------------------------------------------------------------ */
    /* Los límites del permiso                                             */
    /* ------------------------------------------------------------------ */

    /**
     * (e) Sólo cuenta lo que el lead EFECTIVAMENTE recibió: una sugerencia que declara haber
     *     ofrecido las 17:05 pero sigue en `sugerido` (nadie la aprobó, nunca salió) no da permiso
     *     para saltarse el margen.
     *
     * @return void
     */
    public function test_solo_una_oferta_ya_enviada_rescata_el_horario(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $oferta = $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        /* El único cambio contra el caso (a): este mensaje nunca se envió. */
        $oferta->status  = 'sugerido';
        $oferta->sent_at = null;
        $oferta->save();

        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo = $mensaje->content;

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrenaYNoSeEnvia($lead, $mensaje, $texto_que_leyo, $demo);
    }

    /**
     * (f) 🔴 El rescate NO depende del reloj con el que se escribió `sent_at`.
     *
     *     Hasta el 25/8/2026 la consulta de ofertas filtraba por una ventana de 24hs comparando
     *     AppTime::now() (que respeta el RELOJ VIRTUAL con el que Lucas prueba el sistema) contra
     *     `sent_at`, que se escribe con el now() de Laravel (RELOJ REAL). Con el reloj virtual
     *     corrido, la consulta no devolvía nada y el rescate no disparaba NUNCA, sin un solo log
     *     que lo dijera — o sea que el fix entero quedaba mudo justo en el escenario en el que se
     *     lo estaba probando. El filtro de tiempo se sacó por eso, y este test lo clava: una
     *     oferta con un `sent_at` viejo, pero para la fecha que se está confirmando, rescata igual.
     *
     *     Lo que sostiene el permiso no es la antigüedad del mensaje sino la fecha del ítem, que
     *     tiene que coincidir EXACTO — ver el test de abajo.
     *
     * @return void
     */
    public function test_el_rescate_no_depende_del_reloj_con_el_que_se_escribio_sent_at(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $oferta = $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        /* Un `sent_at` de hace 30 horas: es lo que se ve cuando los dos relojes no coinciden. */
        $oferta->sent_at = AppTime::now()->copy()->subHours(30);
        $oferta->save();

        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));

        $lead->refresh();
        $this->assertSame(self::HORA, $lead->demo_start_time, 'El rescate no disparó por un sent_at escrito con otro reloj: es exactamente el bug del reloj virtual.');
    }

    /**
     * (f2) Lo que SÍ acota el permiso, ahora que no hay ventana de tiempo: la fecha del ítem tiene
     *      que coincidir exacto con la que se está confirmando. Una oferta vieja es una oferta para
     *      una fecha vieja, y no rescata nada.
     *
     * @return void
     */
    public function test_una_oferta_para_otra_fecha_no_rescata_nada(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        /* Misma hora, otro día: no cubre el turno de hoy. */
        $this->mensaje_ya_enviado_ofreciendo($lead, '2026-08-24', self::HORA);

        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo = $mensaje->content;

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrenaYNoSeEnvia($lead, $mensaje, $texto_que_leyo, $demo);
    }

    /**
     * (g) 🔴 La guarda del criterio de éxito 5 del grupo 330: la grilla que el agente usa para
     *     OFRECER no cambió. Si el margen 0 se hubiera filtrado a este camino, el agente pasaría a
     *     ofrecer turnos a un minuto vista que la instancia no llega a preparar.
     *
     * @return void
     */
    public function test_la_grilla_de_ofrecer_sigue_respetando_el_margen(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $slots = $this->slots_de($this->grilla_con_margen($this->service(), $lead), $demo->id, self::FECHA);

        /* 16:57 + margen de 5 = 17:02: las 17:00 no se pueden ofrecer, las 17:05 sí. */
        $this->assertNotContains('17:00', $slots, 'La grilla de ofrecer perdió el margen: el agente ofrecería turnos que la instancia no llega a preparar.');
        $this->assertContains('17:05', $slots, 'La grilla de ofrecer dejó de traer el primer horario válido.');
    }

    /**
     * (h) La dinámica ACTUAL queda intacta: el gate por dinámica corta antes de cualquier
     *     recálculo, así que un lead `actual` se sigue comportando igual que antes de la misión —
     *     su margen es el fijo de 30 y su grilla ni siquiera incluye el día de hoy.
     *
     * @return void
     */
    public function test_la_dinamica_actual_queda_intacta(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        /* Un horario a 10 minutos vista, ofrecido y enviado: en la dinámica nueva se rescataría;
         * acá tiene que rechazarse igual que siempre. */
        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, '17:07');
        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, '17:07');
        $texto_que_leyo = $mensaje->content;

        $this->assertSeFrenaYNoSeEnvia($lead, $mensaje, $texto_que_leyo, $demo);
    }

    /* ------------------------------------------------------------------ */
    /* Los dos finales, que NO son el mismo                                */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 Un TIMEOUT DEL LOCK es contención transitoria, no un descarte: se le pide al admin que
     * reintente y no se castiga la conversación.
     *
     * Un block(5) que vence no es "el turno ya pasó" ni "lo ocupó otro lead" — que son los dos
     * únicos motivos por los que un horario se cae de verdad. Si entrara por el mismo freno,
     * cinco segundos de contención quemarían el lead: marcado para intervención humana, tarea
     * abierta, mensaje rechazado y bloque rojo, todo por algo que se arregla apretando aprobar
     * de nuevo. Lo único que comparte con el otro final es que tampoco se envía nada.
     *
     * @return void
     */
    public function test_un_timeout_del_lock_pide_reintentar_y_no_castiga_la_conversacion(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        $mensaje        = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);
        $texto_que_leyo = $mensaje->content;

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        /* El lock de la instancia, tomado por otra aprobación en este mismo instante. Se simula
         * con un doble en vez de tomarlo de verdad porque Illuminate\Cache\Lock::block() mide el
         * tiempo con Carbon::now(), que en este archivo está CONGELADO: el block() real no
         * vencería nunca y giraría para siempre. Y tira LockTimeoutException — no devuelve false,
         * que es justamente lo que hacía inalcanzable esta rama antes de la corrección. */
        $lock = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('block')->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException());
        Cache::partialMock()->shouldReceive('lock')->andReturn($lock);

        $tiro = false;
        try {
            $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));
        } catch (AprobacionEnCursoException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'Un timeout del lock no tiró AprobacionEnCursoException: o siguió adelante, o cayó en el freno definitivo.');

        /* Nada de lo que hace el freno definitivo pasó acá. */
        $lead_fresco = $lead->fresh();
        $this->assertFalse((bool) $lead_fresco->requiere_intervencion_humana, 'Un timeout de 5 segundos marcó el lead para intervención humana.');
        $this->assertTrue((bool) $lead_fresco->claude_auto_reply, 'Un timeout de 5 segundos apagó el agente.');
        $this->assertNull($lead_fresco->demo_start_time, 'Se agendó la demo sin haber tomado el lock de la instancia.');

        $mensaje_fresco = $mensaje->fresh();
        $this->assertSame('sugerido', $mensaje_fresco->status, 'Un timeout reintentable dejó el mensaje rechazado: el admin ya no puede volver a aprobarlo.');
        $this->assertSame($texto_que_leyo, $mensaje_fresco->content, 'Se reescribió el texto que el admin aprobó.');
        $this->assertNull($mensaje_fresco->sent_by_admin_id, 'El mensaje quedó firmado por un admin sin haberse enviado.');

        $this->assertFalse(
            LeadMessage::query()->where('lead_id', $lead->id)->where('is_error', true)->exists(),
            'Un timeout reintentable dejó un bloque rojo permanente en la conversación.'
        );
        $this->assertFalse(
            AdminTask::query()->where('lead_id', $lead->id)->exists(),
            'Un timeout reintentable abrió una tarea en el tablero.'
        );
    }

    /**
     * 🔴 Y el freno LEGÍTIMO sí paga el precio completo, pero sólo el que corresponde: mensaje
     * `rechazado`, lead marcado para intervención humana, tarea abierta — y el agente SIGUE
     * PRENDIDO.
     *
     * Las tres cosas juntas son el contrato de la decisión B, y cada una tapa un agujero distinto:
     * el `rechazado` evita que el texto no enviado se le cuele a Claude como si el lead lo hubiera
     * recibido; la tarea evita que el único aviso sea un toast en el navegador del admin; y no
     * apagar claude_auto_reply evita dejar al lead mudo por algo que Lucas no pidió.
     *
     * @return void
     */
    public function test_el_freno_legitimo_marca_el_lead_abre_tarea_y_no_apaga_el_agente(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA, self::HORA);
        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        /* El turno ya pasó: descarte legítimo y definitivo. */
        Carbon::setTestNow(Carbon::parse(self::FECHA . ' 17:06:00', 'America/Argentina/Buenos_Aires'));

        $tiro = false;
        try {
            $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));
        } catch (HorarioYaNoDisponibleException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'No se frenó una aprobación cuyo horario ya había pasado.');

        $this->assertSame('rechazado', $mensaje->fresh()->status);

        $lead_fresco = $lead->fresh();
        $this->assertTrue((bool) $lead_fresco->requiere_intervencion_humana, 'El lead no quedó marcado para intervención humana.');
        $this->assertTrue((bool) $lead_fresco->claude_auto_reply, 'Se apagó el agente: el lead queda mudo y resolver la intervención no lo vuelve a prender.');

        $tarea = AdminTask::query()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($tarea, 'No se abrió ninguna tarea: nadie fuera del navegador del admin se entera del freno.');
        $this->assertSame('lead_alert', $tarea->created_via);
        $this->assertSame('Revisar conversación de ' . $lead->contact_name, $tarea->title);
    }

    /**
     * La fecha de `horarios_ofrecidos` la escribe el modelo y no siempre viene en Y-m-d pelado. Un
     * "2026-08-25T00:00:00" tiene que rescatar igual: si no, el rescate falla EN SILENCIO — y como
     * el fix acopló dos perillas, el resultado no es volver al comportamiento viejo sino caer en
     * "no se envía nada".
     *
     * @return void
     */
    public function test_una_fecha_ofrecida_en_otro_formato_rescata_igual(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        $this->mensaje_ya_enviado_ofreciendo($lead, self::FECHA . 'T00:00:00', self::HORA);
        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));

        $lead->refresh();
        $this->assertSame(self::HORA, $lead->demo_start_time, 'Una fecha declarada como "Y-m-dT00:00:00" hizo fallar el rescate en silencio.');
    }

    /* ------------------------------------------------------------------ */
    /* Aserciones compartidas                                              */
    /* ------------------------------------------------------------------ */

    /**
     * El paquete completo de la decisión B: se frena, no se agenda nada, no se envía nada, el
     * mensaje que el admin aprobó queda exactamente como estaba y el hilo se lleva un bloque rojo.
     *
     * @param Lead        $lead
     * @param LeadMessage $mensaje
     * @param string      $texto_que_leyo Contenido del mensaje antes de aprobar.
     * @param Demo        $demo
     *
     * @return void
     */
    private function assertSeFrenaYNoSeEnvia(Lead $lead, LeadMessage $mensaje, string $texto_que_leyo, Demo $demo): void
    {
        $agendar = $mensaje->pending_actions['agendar_demo'];

        $tiro = false;
        try {
            $this->service()->apply_pending_actions(
                $mensaje,
                $this->final_actions_del_panel($demo, $agendar['demo_date'], $agendar['demo_start_time'])
            );
        } catch (HorarioYaNoDisponibleException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'No se tiró HorarioYaNoDisponibleException: la aprobación siguió adelante con un horario que ya no estaba.');

        /* La demo no se agendó. */
        $lead_fresco = $lead->fresh();
        $this->assertNull($lead_fresco->demo_start_time, 'Se agendó igual un horario que ya no estaba disponible.');
        $this->assertTrue((bool) $lead_fresco->requiere_intervencion_humana, 'El lead no quedó marcado para intervención humana.');

        /* 🔴 Y el agente NO se apaga. Lucas pidió que el mensaje quede pendiente, que se marque
         * para intervención humana y que el error aparezca en la conversación — nada de eso
         * incluye dejar al lead mudo. Con claude_auto_reply en false, LeadAiSuggestionScheduler
         * corta al toque y el lead puede escribir "¿che, quedó lo de las 17:05?" sin que pase
         * nada; y resolver requiere_intervencion_humana no lo vuelve a prender. */
        $this->assertTrue((bool) $lead_fresco->claude_auto_reply, 'Se apagó claude_auto_reply: el lead queda mudo y resolver la intervención no lo vuelve a prender.');

        /* 🔴 El mensaje que el admin aprobó NO puede quedar en `sugerido`: build_user_content()
         * solo tiene rama especial para `rechazado`, así que un `sugerido` se le manda a Claude
         * como "SISTEMA: <texto que confirma las 17:05>" y la próxima generación cree que al lead
         * se le confirmó un turno que nunca se envió ni existe en la base. */
        $mensaje_fresco = $mensaje->fresh();
        $this->assertSame('rechazado', $mensaje_fresco->status, 'El mensaje frenado quedó en sugerido: se le cuela a Claude como si el lead lo hubiera recibido.');
        $this->assertTrue((bool) $mensaje_fresco->requiere_verificacion, 'El mensaje frenado no quedó marcado para verificación.');

        /* Y sigue sin reescribirse, sin enviarse y sin la firma de nadie. Esto es lo que Lucas
         * pidió literalmente. */
        $this->assertNotNull($mensaje_fresco->pending_actions, 'Se consumieron las pending_actions de un mensaje que no se aplicó.');
        $this->assertSame($texto_que_leyo, $mensaje_fresco->content, 'Se reescribió in-place el texto que el admin aprobó.');
        $this->assertNull($mensaje_fresco->sent_by_admin_id, 'El mensaje quedó firmado por un admin sin haberse enviado.');

        /* Y el admin se entera por los dos canales: bloque rojo en el hilo y tarea en el tablero.
         * El toast del 422 no alcanza — si cerró la pestaña o estaba aprobando en tanda, el lead
         * se enfría sin que nada lo denuncie. */
        $this->assertTrue(
            LeadMessage::query()->where('lead_id', $lead->id)->where('is_error', true)->exists(),
            'No quedó ningún bloque rojo en el hilo: el admin no tiene cómo enterarse de que no se envió nada.'
        );
        $this->assertTrue(
            AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'lead_alert')->exists(),
            'No se creó ninguna AdminTask: el único aviso sería el toast en el navegador del admin que apretó aprobar.'
        );
    }

    /**
     * Ninguna de las llamadas de este flujo salió a la API de Claude: no hubo tercera llamada
     * correctiva. (El aviso a la instancia de demo sí sale, y por eso se filtra por host.)
     *
     * @return void
     */
    private function assertNoHuboLlamadaCorrectiva(): void
    {
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.anthropic.com') !== false;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Montaje                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * 16:57: el instante en que el sistema le manda la oferta al lead.
     *
     * @return Carbon
     */
    private function momento_de_la_oferta(): Carbon
    {
        return Carbon::parse(self::FECHA . ' 16:57:00', 'America/Argentina/Buenos_Aires');
    }

    /**
     * 17:02: el instante en que el admin aprueba. Las 17:05 ya no pasan el margen de 5 minutos,
     * pero el turno sigue libre y sigue siendo el que le ofrecimos.
     *
     * @return Carbon
     */
    private function momento_de_la_aceptacion(): Carbon
    {
        return Carbon::parse(self::FECHA . ' 17:02:00', 'America/Argentina/Buenos_Aires');
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
        $lead->contact_name = 'Brisa de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = $status;
        $lead->save();

        // Después del save: el hook `creating` estampa la dinámica por defecto.
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Instancia del service con los dos métodos protegidos que hacen falta expuestos. La subclase
     * no cambia ninguna lógica.
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
             *
             * @return array<string, mixed>
             */
            public function descartar(Lead $lead, array $parsed, array $availability_data): array
            {
                return $this->descartar_agendamiento_fuera_de_slots($lead, $parsed, $availability_data);
            }
        };
    }

    /**
     * Un mensaje del sistema que el lead YA recibió, declarando qué horarios se le ofrecieron.
     * Es la fuente de verdad del permiso para ignorar el margen.
     *
     * @param Lead        $lead
     * @param string      $fecha
     * @param string      $desde
     * @param string|null $hasta Null = oferta primaria (un punto: desde == hasta).
     *
     * @return LeadMessage
     */
    private function mensaje_ya_enviado_ofreciendo(Lead $lead, string $fecha, string $desde, ?string $hasta = null): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'            => $lead->id,
            'sender'             => 'sistema',
            'content'            => 'Te puedo dar hoy a las ' . $desde . '. ¿Te sirve?',
            'status'             => 'enviado',
            'is_followup'        => false,
            'sent_at'            => AppTime::now(),
            'horarios_ofrecidos' => [
                ['fecha' => $fecha, 'desde' => $desde, 'hasta' => $hasta !== null ? $hasta : $desde],
            ],
        ]);
    }

    /**
     * El mensaje que quedó esperando la aprobación humana, con el paquete de agendamiento adentro.
     * Es el que en producción sale firmado por el admin que aprieta aprobar.
     *
     * @param Lead   $lead
     * @param Demo   $demo
     * @param string $fecha
     * @param string $hora
     *
     * @return LeadMessage
     */
    private function mensaje_pendiente(Lead $lead, Demo $demo, string $fecha, string $hora): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'               => $lead->id,
            /* 'sistema', igual que en producción (create_pending_agendamiento_message()). Importa:
             * la rama de build_user_content() que aísla una sugerencia no enviada exige
             * sender = 'sistema' + status = 'rechazado'. */
            'sender'                => 'sistema',
            'content'               => 'Listo, te confirmo la demo hoy a las ' . $hora . '. Te paso el link apenas la preparo.',
            'status'                => 'sugerido',
            'is_followup'           => false,
            'requiere_verificacion' => true,
            'sent_by_admin_id'      => null,
            'pending_actions'       => $this->paquete($demo, $fecha, $hora),
        ]);
    }

    /**
     * El paquete que devuelve Claude confirmando el turno.
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
            'agendar_demo'     => [
                'demo_id'         => $demo->id,
                'demo_date'       => $fecha,
                'demo_start_time' => $hora,
            ],
        ];
    }

    /**
     * Lo que manda el panel de verificación al aprobar, sin editar nada.
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
     * La grilla tal como la ve el agente para OFRECER: con el margen puesto (sexto argumento en
     * null), que es justo el que borra la oferta primaria al turno siguiente.
     *
     * @param LeadAiService $service
     * @param Lead          $lead
     *
     * @return array<string, mixed>
     */
    private function grilla_con_margen(LeadAiService $service, Lead $lead): array
    {
        $snapshot = null;
        $config   = null;
        $ventanas = null;

        return $service->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            self::FECHA,
            $lead->id,
            true,
            null,
            $config,
            $ventanas
        );
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
}
