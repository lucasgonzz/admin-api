<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadScheduledMessage;
use App\Services\LeadEmbudoSeguimientosService;
use App\Services\LeadFollowupService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Artisan;

/**
 * Un compromiso vigente SUSPENDE los seguimientos genéricos: criterio CA10 de la etapa E2 de la misión
 * leads-referidos-compromisos.
 *
 * El pedido de Lucas: «si Juan tuvo la llamada un martes y el lead le dijo que lo llame el viernes, que no haya un
 * seguimiento automático para el día jueves, sino que se cree un job para el viernes». Con el motor prendido
 * (`lead_compromisos_activo`) y UN compromiso vigente en el lead, no sale ninguno de los cuatro seguimientos genéricos:
 *  1. el cron viejo (`LeadFollowupService::process_single_lead`) devuelve `compromiso` y no genera nada;
 *  2. el embudo (`LeadEmbudoSeguimientosService::programar_para_estado`) no programa pasos y cancela los pendientes con
 *     `canceled_reason = compromiso_vigente`;
 *  3. el despacho de los pasos ya programados (`leads:send-scheduled-messages`) cancela el paso con ese mismo motivo y no
 *     llama a WhatsApp;
 *  4. el empujón diario de la prueba gratuita (`PruebaWhatsappService::empujar`, decisión P2 de Lucas) devuelve
 *     `compromiso_vigente`, salvo la bienvenida, que sale igual.
 * Lo que decide una persona NO se suspende: un mensaje programado a mano por un operador (sin `paso_embudo`) sale igual.
 *
 * Y con el motor APAGADO todo es exactamente como hoy aunque haya un compromiso guardado: cada test lo prueba con su
 * variante de interruptor apagado, y `test_motor_apagado_todo_igual` repite el ejemplo de Lucas con el motor en '0'.
 *
 * Cada test tiene su CONTROL (un lead igual, sin compromiso, al que el seguimiento le sale): así el test mide lo que
 * dice y no un seguimiento que nunca iba a salir. Los controles pasan hoy; lo que falla hoy es que no hay compromisos
 * (la tabla y la ruta `POST admin/lead/{id}/compromisos` no existen).
 *
 * Fechas: calendario real de 2026 (ver `BaseDeReferidosYCompromisos`). «Hoy» es el martes 24/11 a las 12:00.
 */
class SuspensionDeSeguimientosPorCompromisoTest extends BaseDeReferidosYCompromisos
{
    /** El viernes de la llamada pactada, a las 10:00. */
    private const VIERNES = '2026-11-27 10:00';

    /**
     * Martes 24/11 a las 12:00, con el motor prendido.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ir_a('2026-11-24 12:00:00');
        $this->prender_el_motor();
    }

    /**
     * El valor de `LeadScheduledMessage::CANCELED_COMPROMISO_VIGENTE` (constante nueva de E2: PLAN §2). Si no existe el test
     * falla con un mensaje claro en vez de un «Undefined class constant».
     *
     * @return string
     */
    private function motivo_compromiso_vigente(): string
    {
        $this->assertTrue(
            defined(LeadScheduledMessage::class . '::CANCELED_COMPROMISO_VIGENTE'),
            'Falta la constante LeadScheduledMessage::CANCELED_COMPROMISO_VIGENTE (PLAN §2, suspensión de lo genérico).'
        );
        $this->assertSame('compromiso_vigente', constant(LeadScheduledMessage::class . '::CANCELED_COMPROMISO_VIGENTE'));

        return 'compromiso_vigente';
    }

    /**
     * Deja la regla y la plantilla del cron viejo para el estado `nuevo` (48 horas, un seguimiento).
     *
     * @return void
     */
    private function armar_el_cron_viejo(): void
    {
        FollowupRule::query()->updateOrCreate(['estado' => 'nuevo'], ['horas_espera' => 48, 'max_followups' => 1, 'activa' => true]);
        FollowupTemplate::query()->where('estado', 'nuevo')->update(['activa' => false]);
        FollowupTemplate::create([
            'template_name' => 'cc_seg_prueba_nuevo_d1', 'estado' => 'nuevo', 'dia_numero' => 1, 'language_code' => 'es_AR',
            'body_template' => 'Hola {{1}}! Te escribo de ComercioCity.', 'activa' => true, 'meta_status' => 'APPROVED',
        ]);
    }

    /**
     * Un lead conversando que está en el embudo, con su último mensaje a esa hora (así tiene la ventana de 24 h abierta).
     * Hay que haber prendido el embudo ANTES (`prender_el_embudo`): sólo están «en el flujo» los leads creados después.
     *
     * @param string $estado Estado del lead.
     *
     * @return Lead
     */
    private function lead_del_embudo(string $estado = 'contactado'): Lead
    {
        $lead = $this->crear_lead_del_tablero($estado, ['contact_name' => 'Ro', 'company_name' => 'Ferretería de Ro']);
        $this->mensaje_del_lead($lead, 'Hola, quiero info');

        return $lead->fresh();
    }

    // ------------------------------------------------------------------------------------------
    // 1. El cron viejo
    // ------------------------------------------------------------------------------------------

    /**
     * CA10. El cron viejo (`leads:check-followups`) devuelve `compromiso` para un lead con compromiso vigente y no genera
     * nada: ni plantilla, ni sugerencia, ni pausa. Control: un lead igual sin compromiso sale por su plantilla.
     * Con el motor APAGADO, un compromiso guardado no frena nada: el lead vuelve a ser del cron como hoy.
     *
     * @return void
     */
    public function test_cron_viejo(): void
    {
        $this->armar_el_cron_viejo();
        $con_compromiso = $this->crear_lead_del_tablero('nuevo', ['phone' => '+5493511110101', 'origen' => 'desconocido']);
        $comun          = $this->crear_lead_del_tablero('nuevo', ['phone' => '+5493511110102', 'origen' => 'desconocido']);
        $apagado        = $this->crear_lead_del_tablero('nuevo', ['phone' => '+5493511110103', 'origen' => 'desconocido']);
        $this->crear_compromiso_por_el_panel($con_compromiso, ['vence_at' => '2026-11-30 10:00']);
        $this->crear_compromiso_por_el_panel($apagado, ['vence_at' => '2026-11-30 10:00']);
        $this->avanzar(72 * 3600);

        $servicio = app(LeadFollowupService::class);

        $this->assertSame('suggestion', $servicio->process_single_lead($comun->fresh()), 'Control: el lead común es del cron.');
        $this->assertCount(1, $this->envios->plantillas, 'Control: al común le sale su plantilla.');

        $this->assertSame('compromiso', $servicio->process_single_lead($con_compromiso->fresh()), 'Con compromiso vigente el cron viejo se corre.');
        $this->assertCount(1, $this->envios->plantillas, 'No sale ninguna plantilla más.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', (int) $con_compromiso->id)->where('is_followup', true)->count(), 'Tampoco queda una sugerencia de seguimiento.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', (int) $con_compromiso->id)->where('is_status_event', true)->count(), 'Ni una pausa en el hilo.');
        $this->assertSame('nuevo', $con_compromiso->fresh()->status, 'El lead queda como estaba.');

        $this->motor_de_compromisos(false);
        $this->assertSame('suggestion', $servicio->process_single_lead($apagado->fresh()), 'Con el motor apagado el cron viejo lleva al lead como hoy.');
        $this->assertCount(2, $this->envios->plantillas);
    }

    // ------------------------------------------------------------------------------------------
    // 2. El embudo
    // ------------------------------------------------------------------------------------------

    /**
     * CA10. Con un compromiso vigente, `programar_para_estado` no crea pasos y cancela los pendientes con
     * `compromiso_vigente`; un inbound del lead (que re-ancla los pasos) tampoco los vuelve a crear. Control: el mismo
     * lead sin compromiso tiene su paso pendiente. Con el motor apagado, el embudo programa como hoy.
     *
     * @return void
     */
    public function test_embudo_no_programa_y_cancela(): void
    {
        $this->prender_el_embudo();
        $con_compromiso = $this->lead_del_embudo();
        $apagado        = $this->lead_del_embudo();
        LeadEmbudoSeguimientosService::programar_para_estado($con_compromiso);
        LeadEmbudoSeguimientosService::programar_para_estado($apagado);
        $pendientes = function (Lead $lead) {
            return LeadScheduledMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('paso_embudo')->where('status', LeadScheduledMessage::STATUS_PENDIENTE)->count();
        };
        $this->assertGreaterThanOrEqual(1, $pendientes($con_compromiso), 'Control: sin compromiso el embudo programa su paso.');
        $antes = $pendientes($con_compromiso);

        $this->crear_compromiso_por_el_panel($con_compromiso);
        $this->crear_compromiso_por_el_panel($apagado);
        LeadEmbudoSeguimientosService::programar_para_estado($con_compromiso->fresh());

        $this->assertSame(0, $pendientes($con_compromiso), 'Con compromiso vigente no queda ningún paso pendiente.');
        $cancelados = LeadScheduledMessage::query()->where('lead_id', (int) $con_compromiso->id)->whereNotNull('paso_embudo')
            ->where('status', LeadScheduledMessage::STATUS_CANCELADO)->where('canceled_reason', $this->motivo_compromiso_vigente())->count();
        $this->assertSame($antes, $cancelados, 'Los pasos que estaban pendientes quedaron cancelados con el motivo compromiso_vigente.');

        $this->avanzar(300);
        $this->mensaje_del_lead($con_compromiso, 'Dale, lo pienso');
        LeadEmbudoSeguimientosService::al_llegar_un_mensaje($con_compromiso->fresh());
        $this->assertSame(0, $pendientes($con_compromiso), 'Un mensaje del lead no vuelve a armar el seguimiento mientras haya compromiso.');

        $this->motor_de_compromisos(false);
        LeadEmbudoSeguimientosService::programar_para_estado($apagado->fresh());
        $this->assertGreaterThanOrEqual(1, $pendientes($apagado), 'Con el motor apagado el embudo programa como hoy.');
    }

    // ------------------------------------------------------------------------------------------
    // 3. El despacho de lo ya programado
    // ------------------------------------------------------------------------------------------

    /**
     * CA10. Un paso del embudo que ya estaba programado y vence con el lead en compromiso se cancela con
     * `compromiso_vigente` y NO llama a WhatsApp. Control: el paso de un lead sin compromiso sale (el del motor apagado
     * va en el test siguiente).
     *
     * @return void
     */
    public function test_despacho_cancela_paso(): void
    {
        $motivo = $this->motivo_compromiso_vigente();
        $this->prender_el_embudo();
        $suspendido = $this->lead_del_embudo('nuevo');
        $libre      = $this->lead_del_embudo('nuevo');
        $paso_suspendido = $this->insertar_paso_del_embudo($suspendido, 'nuevo_3h', 'nuevo', '2026-11-24 15:00:00', 'Seguimiento del lead en compromiso.');
        $paso_libre      = $this->insertar_paso_del_embudo($libre, 'nuevo_3h', 'nuevo', '2026-11-24 15:00:00', 'Seguimiento del lead libre.');
        $this->crear_compromiso_por_el_panel($suspendido, ['vence_at' => '2026-11-30 10:00']);

        $this->ir_a('2026-11-24 15:00:30');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, (string) $paso_libre->fresh()->status, 'Control: el paso del lead sin compromiso sale.');
        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, (string) $paso_suspendido->fresh()->status, 'El paso del lead en compromiso no sale.');
        $this->assertSame($motivo, (string) $paso_suspendido->fresh()->canceled_reason);
        $cuerpos = array_column($this->envios->textos, 'body');
        $this->assertNotContains('Seguimiento del lead en compromiso.', $cuerpos, 'No se llamó a WhatsApp por el lead en compromiso.');
        $this->assertContains('Seguimiento del lead libre.', $cuerpos, 'Control: el del lead libre sí salió.');
        $this->assertSame(0, $this->cantidad_de_salientes($suspendido), 'No quedó ninguna burbuja en su hilo.');
    }

    /**
     * CA10, variante con el motor apagado. Con el motor apagado, un compromiso guardado NO frena el paso ya programado:
     * sale como hoy (nada de `compromiso_vigente`).
     *
     * @return void
     */
    public function test_despacho_con_el_motor_apagado_sale_como_hoy(): void
    {
        $this->prender_el_embudo();
        $lead = $this->lead_del_embudo('nuevo');
        $paso = $this->insertar_paso_del_embudo($lead, 'nuevo_3h', 'nuevo', '2026-11-24 15:00:00', 'Seguimiento con el motor apagado.');
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-30 10:00']);
        $this->motor_de_compromisos(false);

        $this->ir_a('2026-11-24 15:00:30');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, (string) $paso->fresh()->status, 'Con el motor apagado el paso sale como hoy.');
        $this->assertContains('Seguimiento con el motor apagado.', array_column($this->envios->textos, 'body'));
    }

    /**
     * CA10. Un mensaje programado A MANO por un operador (sin `paso_embudo`) es una decisión de una persona: sale igual
     * aunque el lead tenga un compromiso vigente.
     *
     * @return void
     */
    public function test_programado_manual_sale(): void
    {
        $lead = $this->lead_de_ro();
        $this->mensaje_del_lead($lead, 'Hola, quiero info');
        $manual = LeadScheduledMessage::create([
            'lead_id' => (int) $lead->id, 'scheduled_send_at' => \Carbon\Carbon::parse('2026-11-24 15:00:00', self::$ZONA_DEL_TABLERO),
            'mode' => LeadScheduledMessage::MODE_TEXTO_LIBRE, 'content' => 'Te escribo como quedamos, Ro.', 'cancel_if_lead_replies' => false,
            'status' => LeadScheduledMessage::STATUS_PENDIENTE, 'created_by_admin_id' => (int) $this->admin->id,
        ]);
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-30 10:00']);

        $this->ir_a('2026-11-24 15:00:30');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, (string) $manual->fresh()->status, 'Lo programa una persona: el compromiso no lo frena.');
        $this->assertContains('Te escribo como quedamos, Ro.', array_column($this->envios->textos, 'body'));
    }

    // ------------------------------------------------------------------------------------------
    // 4. El empujón de la prueba
    // ------------------------------------------------------------------------------------------

    /**
     * CA10 y decisión P2 de Lucas. Con un compromiso vigente, `PruebaWhatsappService::empujar` devuelve
     * `compromiso_vigente` y no manda la plantilla del hito: el lead que dijo «llamame el viernes» no recibe «¿pudiste
     * cargar tus artículos?» el jueves.
     *
     * @return void
     */
    public function test_empujon_de_la_prueba(): void
    {
        list($negocio, $lead) = $this->negocio_en_prueba_con_bienvenida();
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);

        $resultado = app(PruebaWhatsappService::class)->empujar($negocio, $lead);

        $this->assertSame('compromiso_vigente', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertSame([], $this->envios->plantillas, 'No sale el empujón de la prueba.');
    }

    /**
     * Control del empujón. Con el motor apagado (aunque el compromiso esté guardado) el empujón sale como hoy: una
     * plantilla del primer hito que falta. Es lo que prueba que el escenario de arriba mide lo que dice.
     *
     * @return void
     */
    public function test_empujon_con_el_motor_apagado_sale_como_hoy(): void
    {
        list($negocio, $lead) = $this->negocio_en_prueba_con_bienvenida();
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);
        $this->motor_de_compromisos(false);

        $resultado = app(PruebaWhatsappService::class)->empujar($negocio, $lead);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertCount(1, $this->envios->plantillas, 'Con el motor apagado el empujón sale como siempre.');
    }

    /**
     * CA10 y P2. La BIENVENIDA de la prueba sí sale aunque haya un compromiso vigente: es la primera vez que el negocio
     * sabe quién le escribe y no es un seguimiento.
     *
     * @return void
     */
    public function test_la_bienvenida_de_la_prueba_sale_igual(): void
    {
        list($negocio, $lead) = $this->negocio_en_prueba_con_bienvenida();
        \App\Models\PruebaWhatsappEnvio::query()->where('client_id', (int) $negocio->id)->delete();
        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => self::VIERNES]);

        $resultado = app(PruebaWhatsappService::class)->empujar($negocio, $lead);

        $this->assertNotSame('compromiso_vigente', $resultado['estado'], 'La bienvenida no se suspende.');
        $this->assertSame('bienvenida_enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertCount(1, $this->envios->plantillas, 'Sale la plantilla de la bienvenida.');
    }

    // ------------------------------------------------------------------------------------------
    // El ejemplo de Lucas
    // ------------------------------------------------------------------------------------------

    /**
     * Arma el ejemplo de Lucas hasta el miércoles a la mañana y devuelve el lead: el martes 24/11 el embudo le programó el
     * seguimiento del día siguiente (22 horas después de su último mensaje) y Juan lo llamó: el lead le dijo «llamame el
     * viernes». Con el motor prendido hay un compromiso para el viernes 27/11 10:00; con el apagado, no.
     *
     * @return Lead
     */
    private function armar_el_ejemplo_de_lucas(): Lead
    {
        $this->prender_el_embudo();
        $lead = $this->lead_del_embudo('contactado');
        LeadEmbudoSeguimientosService::programar_para_estado($lead);
        $this->assertGreaterThanOrEqual(
            1,
            LeadScheduledMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('paso_embudo')->where('status', LeadScheduledMessage::STATUS_PENDIENTE)->count(),
            'Precondición: el embudo programó el seguimiento del miércoles.'
        );

        $this->avanzar(600);
        $this->registrar_llamada_por_el_panel($lead, [
            'atendio' => true, 'resultado' => 'volver_a_llamar', 'proxima_llamada_at' => self::VIERNES, 'notas' => 'Me pidió que lo llame el viernes.',
        ])->assertStatus(201);

        return $lead->fresh();
    }

    /**
     * El ejemplo de Lucas, con el motor prendido: llamada el martes, «llamame el viernes». El seguimiento genérico del
     * miércoles no sale, el cron viejo del jueves a las 12:00 no genera nada y el embudo no programa nada nuevo: hasta el
     * viernes el lead no recibe ningún seguimiento automático. Hay un único compromiso vigente, el del viernes.
     *
     * @return void
     */
    public function test_ejemplo_martes_viernes_jueves_nada(): void
    {
        $lead = $this->armar_el_ejemplo_de_lucas();
        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'), 'Hay un compromiso, el del viernes.');

        /* El miércoles a las 10:30 vence el paso del embudo: no sale. */
        $this->ir_a('2026-11-25 10:30:00');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));
        $this->assertSame([], $this->envios->textos, 'El miércoles no sale ningún seguimiento genérico.');
        $this->assertSame(0, $this->cantidad_de_salientes($lead), 'Y no queda nada en el hilo.');

        /* El jueves a las 12:00: ni el cron viejo, ni el embudo, ni el despacho. */
        $this->ir_a('2026-11-26 12:00:00');
        $this->armar_el_cron_viejo();
        $this->assertNotContains(app(LeadFollowupService::class)->process_single_lead($lead->fresh()), ['suggestion', 'paused'], 'El cron viejo no genera nada el jueves.');
        LeadEmbudoSeguimientosService::programar_para_estado($lead->fresh());
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertSame(0, LeadScheduledMessage::query()->where('lead_id', (int) $lead->id)->where('status', LeadScheduledMessage::STATUS_PENDIENTE)->count(), 'El jueves no hay nada programado.');
        $this->assertSame(0, $this->cantidad_de_salientes($lead), 'El jueves a las 12:00 no salió ningún seguimiento.');
        $this->assertSame([], $this->envios->textos);
        $this->assertSame([], $this->envios->plantillas);
        $vigente = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($vigente, 'El compromiso del viernes sigue vigente.');
        $this->assertSame('2026-11-27 10:00:00', $this->fecha_ar($vigente->vence_at));
    }

    /**
     * El mismo ejemplo con el motor APAGADO: todo como hoy. La llamada se registra pero no nace ningún compromiso, y el
     * seguimiento genérico del miércoles SALE a su hora (es lo que hacía el sistema antes de E2).
     *
     * @return void
     */
    public function test_motor_apagado_todo_igual(): void
    {
        $this->motor_de_compromisos(false);
        $lead = $this->armar_el_ejemplo_de_lucas();

        $this->ir_a('2026-11-25 10:30:00');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $paso = LeadScheduledMessage::query()->where('lead_id', (int) $lead->id)->whereNotNull('paso_embudo')->orderBy('id')->first();
        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, (string) $paso->status, 'Como hoy: el seguimiento del miércoles sale.');
        $this->assertCount(1, $this->envios->textos, 'Control: sale el texto del embudo.');

        $this->assertCount(0, $this->compromisos_de($lead), 'Con el motor apagado no nace ningún compromiso.');
    }
}
