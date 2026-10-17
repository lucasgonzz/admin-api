<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\LeadMessage;
use App\Services\DemoHitosService;
use App\Services\LeadAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `confirmar_ingreso` en la dinámica nueva (misión flujo-lead-landing-closer, 17/10/2026): lo que el lead
 * DICE no mueve el estado; lo mueve el evento real `demo.ingreso` de la instancia.
 */
class ConfirmarIngresoSinCambioDeEstadoTest extends TestCase
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
    }

    /**
     * (1) El lead dice que entró: queda el evento en el hilo, pero sigue en demo_agendada y sin
     *     demo_ingreso_confirmado. Después llega el evento real y recién ahí pasa a demo_en_curso.
     *
     * @return void
     */
    public function test_lo_que_dice_el_lead_no_mueve_y_el_evento_real_si(): void
    {
        $lead = $this->crear_lead('demo_agendada');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'  => '¡Genial! Cualquier duda mientras la recorrés, escribime por acá.',
            'estado_sugerido'   => 'demo_agendada',
            'confirmar_ingreso' => true,
        ]);

        $lead->refresh();
        $this->assertSame('demo_agendada', (string) $lead->status);
        $this->assertFalse((bool) $lead->demo_ingreso_confirmado);
        $this->assertNull($msg->suggested_lead_status);
        $this->assertFalse((bool) $msg->marca_demo_ingreso_confirmado);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'         => $lead->id,
            'is_status_event' => true,
            'content'         => 'El lead dice que entró a la demo; el ingreso real lo confirma la instancia.',
        ]);

        $this->postear_ingreso($lead)->assertStatus(200);

        $lead->refresh();
        $this->assertSame('demo_en_curso', (string) $lead->status);
        $this->assertTrue((bool) $lead->demo_ingreso_confirmado);
    }

    /**
     * (2) Si ya está en demo_en_curso, "ya entré" no deja ni el evento: no hay nada que anotar.
     *
     * @return void
     */
    public function test_ya_adentro_no_anota_nada(): void
    {
        $lead = $this->crear_lead('demo_en_curso');

        $this->service()->aplicar($lead, [
            'mensaje_sugerido'  => 'Dale.',
            'estado_sugerido'   => 'demo_en_curso',
            'confirmar_ingreso' => true,
        ]);

        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->where('is_status_event', true)->count());
        $this->assertSame('demo_en_curso', (string) $lead->refresh()->status);
    }

    /**
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Lead de prueba';
        $lead->phone              = '5493519990003';
        $lead->status             = $status;
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 1, 'tipo' => LeadDemoHito::TIPO_INGRESO,
            'seccion' => null, 'clip_id' => null, 'titulo' => 'Entrar a la demo',
            'evento_esperado' => DemoHitosService::EVENTO_INGRESO,
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);

        return $lead->refresh();
    }

    /**
     * Postea el evento `demo.ingreso` con el token del lead (mismo canal que DemoIngresoAutomaticoPorEventoTest).
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_ingreso(Lead $lead)
    {
        return $this->postJson('/api/demo-eventos', [
            'eventos' => [[
                'uuid'        => (string) Str::uuid(),
                'nombre'      => DemoHitosService::EVENTO_INGRESO,
                'clip_id'     => null,
                'ocurrido_at' => now()->toIso8601String(),
                'datos'       => [],
            ]],
        ], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token]);
    }

    /**
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            public function aplicar(Lead $lead, array $parsed): LeadMessage
            {
                return $this->apply_parsed_response($lead, $parsed, false);
            }
        };
    }
}
