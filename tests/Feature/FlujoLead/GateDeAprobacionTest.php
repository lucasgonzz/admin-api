<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadWhatsappOnboardingSettings;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El gate de aprobación después de D3 (misión flujo-lead-landing-closer, 17/10/2026): hasta
 * `solicita_disponibilidad` la respuesta es automática. El mensaje que lleva a un lead de `calificado`
 * a `solicita_disponibilidad` SALE SOLO (ni el gate ni el respaldo de apply_parsed_response lo
 * retienen); un `agendar_demo` se retiene; un lead que YA está en el tramo se retiene como siempre; y
 * el escudo por lead sigue ganando.
 */
class GateDeAprobacionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        /* El catálogo de estados con su orden real: la guardia "no retroceder de tramo" compara
         * sort_order, y en una base de testing vacía ensure_exists() los numera en el orden en que
         * los va creando (solicita_disponibilidad podría quedar ANTES que calificado). */
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);

        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');
    }

    /**
     * (1) Las decisiones del gate, con el interruptor prendido.
     *
     * @return void
     */
    public function test_el_gate_deja_pasar_el_estado_al_tramo_y_retiene_las_acciones_y_el_tramo(): void
    {
        $service = $this->service();

        $this->assertFalse(
            $service->gate($this->crear_lead('calificado'), ['estado_sugerido' => 'solicita_disponibilidad']),
            'El estado sugerido que entra al tramo ya no retiene (D3).'
        );
        $this->assertFalse(
            $service->gate($this->crear_lead('contactado'), ['estado_sugerido' => 'demo_agendada']),
            'Tampoco demo_agendada como estado suelto: sin agendar_demo no hay nada que aprobar.'
        );
        $this->assertTrue(
            $service->gate($this->crear_lead('calificado'), ['agendar_demo' => ['ahora' => true]]),
            'agendar_demo sigue esperando el ok.'
        );
        $this->assertTrue(
            $service->gate($this->crear_lead('calificado'), ['agendar_demo' => ['dia' => 'manana', 'hora' => '10:00']]),
            'agendar_demo con día y hora también.'
        );
        /* 22/10/2026 (decisión de Lucas, lead Christian): estar en el tramo ya no retiene por sí mismo; el
         * gate no mira el estado de un lead que está adentro. Quien lo retiene es su ESCUDO (que el
         * latch de Lead::booted() prendió al crearlo adentro), y ese operando vive en el llamador:
         * lo cubre el test (3). Con el escudo apagado a mano sale solo (VerificacionSoloPorEscudoDelLeadTest). */
        $this->assertFalse(
            $service->gate($this->crear_lead('solicita_disponibilidad'), ['estado_sugerido' => 'solicita_disponibilidad']),
            'Un lead que ya está en el tramo no se retiene por el estado: lo retiene su escudo, no el gate.'
        );
    }

    /**
     * (2) 🔴 De punta a punta: el paquete que lleva a `solicita_disponibilidad` se aplica en el acto,
     *     sin pending_actions ni requiere_verificacion (ni por el gate ni por el respaldo del final).
     *
     * @return void
     */
    public function test_el_mensaje_que_lleva_a_solicita_disponibilidad_sale_solo(): void
    {
        $lead = $this->crear_lead('calificado');

        $msg = $this->service()->crear($lead, [
            'mensaje_sugerido' => 'Genial. ¿A qué mail te mando todo?',
            'estado_sugerido'  => 'solicita_disponibilidad',
            'razonamiento'     => 'El lead dijo que sí a la demo.',
        ]);

        $this->assertFalse((bool) $msg->requiere_verificacion);
        $this->assertNull($msg->pending_actions);
        $this->assertSame('solicita_disponibilidad', $msg->suggested_lead_status);
        $this->assertSame('sugerido', (string) $msg->status);
    }

    /**
     * (3) Con el lead ya en `solicita_disponibilidad`, el siguiente paquete queda diferido (pending_actions
     *     con el parsed crudo, requiere_verificacion), exactamente como hoy.
     *
     * @return void
     */
    public function test_con_el_lead_ya_en_el_tramo_el_paquete_queda_diferido(): void
    {
        $lead = $this->crear_lead('solicita_disponibilidad');

        $msg = $this->service()->crear($lead, [
            'mensaje_sugerido' => 'Listo, te la preparo.',
            'estado_sugerido'  => 'solicita_disponibilidad',
        ]);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertNotNull($msg->pending_actions);
    }

    /**
     * (4) El escudo por lead gana aunque el lead esté antes del tramo.
     *
     * @return void
     */
    public function test_el_escudo_por_lead_sigue_reteniendo_antes_del_tramo(): void
    {
        $lead                                  = $this->crear_lead('calificado');
        $lead->requiere_verificacion_mensajes  = true;
        $lead->save();

        $msg = $this->service()->crear($lead, [
            'mensaje_sugerido' => 'Genial. ¿A qué mail te mando todo?',
            'estado_sugerido'  => 'solicita_disponibilidad',
        ]);

        $this->assertTrue((bool) $msg->requiere_verificacion);
        $this->assertNotNull($msg->pending_actions);
    }

    /**
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->phone        = '5493519990002';
        $lead->status       = $status;
        $lead->save();

        $inbound              = new LeadMessage();
        $inbound->lead_id     = $lead->id;
        $inbound->sender      = 'lead';
        $inbound->status      = 'enviado';
        $inbound->is_followup = false;
        $inbound->content     = 'Dale, quiero hacer la demo.';
        $inbound->save();

        return $lead->refresh();
    }

    /**
     * Instancia de LeadAiService con requires_agendamiento_verification_gate() y
     * create_message_and_update_lead() expuestos (son protected). La subclase no cambia lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            public function gate(Lead $lead, array $parsed): bool
            {
                return $this->requires_agendamiento_verification_gate($lead, $parsed);
            }

            public function crear(Lead $lead, array $parsed): LeadMessage
            {
                return $this->create_message_and_update_lead($lead, $parsed, false);
            }
        };
    }
}
