<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\EscalationPushNotificationService;
use App\Services\LeadAiService;
use App\Services\LeadSuggestionSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ronda 3 de martin-mensaje-puntos (el Revisor encontró un hallazgo MAYOR en la ronda 2).
 *
 * Lo que pasaba: cuando la sugerencia queda retenida por no tener un mensaje utilizable, `create_message_and_update_lead`
 * crea la tarea «Revisar conversación de X» al GENERAR. Pero el paquete retenido conserva `requiere_intervencion_humana`
 * y `motivo_intervencion`, así que al APROBAR el mensaje de espera `apply_parsed_response()` creaba OTRA tarea idéntica
 * (más su aviso y el escalado). La sonda del revisor: tareas tras generar = 1, tras aprobar = 2.
 *
 * Lo pedido: UNA sola tarea por mensaje retenido, en los dos momentos, con los cuatro marcadores de lugar y con el
 * paquete que ya trae la derivación con el mensaje vacío (como el correctivo de la agenda que no pudo redactar texto), y
 * que lo que avisa a las personas (notificaciones de la tarea y escalado) no se duplique.
 *
 * Se prueba de punta a punta con la IA simulada (`Http::fake`) y la aprobación real de
 * `LeadSuggestionSendService::send_suggestion()`.
 *
 * Hoy están EN ROJO los de «una sola tarea» (la tarea se duplica al aprobar).
 */
class MensajeDePuntosSuspensivosRonda3Test extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    /**
     * Con cada marcador de lugar: tras generar hay UNA tarea del lead, y tras aprobar el mensaje de espera sigue
     * habiendo UNA (no se crea otra), con sus notificaciones sin duplicar y el escalado a lo sumo una vez en total.
     *
     * @dataProvider provider_de_marcadores
     *
     * @param string $marcador Valor de `mensaje_sugerido` que deja el modelo.
     *
     * @return void
     */
    public function test_una_sola_tarea_al_generar_y_al_aprobar(string $marcador): void
    {
        $lead   = $this->lead_en_contactado();
        $espia  = $this->espiar_whatsapp();
        $escala = $this->espiar_el_escalado();

        $this->fakear_ia([$this->paquete_ia($marcador, 'link_enviado')]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(1, $this->tareas_del_lead($lead), 'Tras generar: una sola tarea de revisión.');
        $notificaciones = $this->notificaciones_de_las_tareas($lead);

        (new LeadSuggestionSendService($espia))->send_suggestion($mensaje->fresh());

        $this->assertSame('enviado', (string) $mensaje->fresh()->status, 'Precondición: la aprobación envió el mensaje de espera.');
        $this->assertSame(1, $this->tareas_del_lead($lead), 'Tras aprobar: sigue habiendo una sola tarea.');
        $this->assertSame($notificaciones, $this->notificaciones_de_las_tareas($lead), 'Las notificaciones de la tarea no se duplican.');
        $this->assertLessThanOrEqual(1, $escala->llamadas, 'El escalado por push no sale más de una vez en total.');
        $this->assertTrue((bool) $lead->fresh()->requiere_intervencion_humana, 'El lead sigue marcado para intervención humana.');
    }

    /**
     * Marcadores de lugar que deja el modelo.
     *
     * @return array<string, array{0: string}>
     */
    public function provider_de_marcadores(): array
    {
        return [
            'tres puntos'     => ['...'],
            'caracter puntos' => ['…'],
            'rotulo'          => ['<mensaje>'],
            'vacio'           => [''],
        ];
    }

    /**
     * El paquete que ya trae la derivación a una persona con el mensaje vacío (lo que deja, por ejemplo, el correctivo de
     * la agenda cuando no logra redactar texto): una sola tarea al generar y al aprobar, con el motivo que trajo el
     * paquete a la vista.
     *
     * @return void
     */
    public function test_el_paquete_que_ya_trae_la_derivacion_con_mensaje_vacio_no_duplica_la_tarea(): void
    {
        $lead  = $this->lead_en_contactado();
        $espia = $this->espiar_whatsapp();

        $this->fakear_ia([$this->paquete_ia('', 'solicita_disponibilidad', 'conversacional', [], [
            'requiere_intervencion_humana' => true,
            'motivo_intervencion'          => 'El horario que confirmó la IA no estaba disponible.',
            'requiere_verificacion'        => true,
        ])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(1, $this->tareas_del_lead($lead), 'Tras generar: una sola tarea.');
        $this->assertStringContainsString('El horario que confirmó la IA no estaba disponible.', (string) $mensaje->pending_actions['motivo_intervencion'], 'El motivo que trajo el paquete no se pierde.');

        (new LeadSuggestionSendService($espia))->send_suggestion($mensaje->fresh());

        $this->assertSame(1, $this->tareas_del_lead($lead), 'Tras aprobar: sigue una sola.');
    }

    /**
     * No-regresión: una derivación común (la IA escala con un mensaje real) sigue creando su tarea al aprobar, una vez:
     * la marca de «ya creada» es sólo de los paquetes retenidos por no tener mensaje utilizable. Pasa ya.
     *
     * @return void
     */
    public function test_la_derivacion_comun_sigue_creando_su_tarea_una_vez(): void
    {
        $lead  = $this->lead_en_contactado();

        $this->fakear_ia([$this->paquete_ia('Te paso con una persona del equipo que lo ve.', 'contactado', 'conversacional', [], [
            'requiere_intervencion_humana' => true,
            'motivo_intervencion'          => 'Reclamo que no puede resolver la IA.',
        ])]);

        app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(1, $this->tareas_del_lead($lead), 'La derivación común crea una tarea.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Cantidad de tareas del admin que tiene el lead.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function tareas_del_lead(Lead $lead): int
    {
        return AdminTask::query()->where('lead_id', $lead->id)->count();
    }

    /**
     * Cantidad de notificaciones (in-app/push) de las tareas del lead.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function notificaciones_de_las_tareas(Lead $lead): int
    {
        $ids = AdminTask::query()->where('lead_id', $lead->id)->pluck('id')->toArray();

        return AdminTaskNotification::query()->whereIn('admin_task_id', $ids)->count();
    }

    /**
     * Registra un espía del escalado por push que cuenta las veces que se lo llama y no manda nada.
     *
     * @return EscalationPushNotificationService
     */
    private function espiar_el_escalado(): EscalationPushNotificationService
    {
        $espia = new class extends EscalationPushNotificationService {
            /** @var int Veces que se pidió el escalado de un lead. */
            public $llamadas = 0;

            public function notificar_lead(Lead $lead, string $motivo): array
            {
                $this->llamadas++;

                return ['sin_device' => []];
            }
        };

        $this->app->instance(EscalationPushNotificationService::class, $espia);

        return $espia;
    }

    /**
     * Lead `contactado` que contestó el rubro, con el flujo de entrada apagado (un lead común).
     *
     * @return Lead
     */
    private function lead_en_contactado(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'almacén, estamos con mi señora nomás');

        return $lead->fresh();
    }
}
