<?php

namespace Tests\Feature\SoporteMartin2511;

use App\Models\SupportCodexCase;
use App\Models\SupportCodexUpgradeAgreement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA5 — La coordinación de horarios de actualización queda apagada por defecto (contrato C4, Lucas 25/11/2026):
 * el admin no le ofrece a Martín «arreglo listo, coordiná la actualización», las capacidades de coordinación
 * salen en false y una propuesta de horario se rechaza con 409 `upgrade_coordination_disabled` sin crear nada.
 * Con `services.support_codex.upgrade_coordination` en true todo sigue como antes.
 */
class CoordinacionApagadaTest extends TestCase
{
    use DatabaseTransactions;
    use SoporteMartinHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparar_puente();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sin_config_no_hay_oferta_de_actualizacion_ni_propuesta(): void
    {
        $ticket = $this->ticket();
        $this->mision_con_evidencia($ticket);

        $this->getJson($this->base . '/pending?supports_upgrade_agreement=1&supports_mission_progress=1', $this->headers)
            ->assertOk()->assertJsonCount(0, 'tickets');

        $this->inbound($ticket, '¿Cuándo queda arreglado?');
        $pendientes = $this->getJson($this->base . '/pending?supports_upgrade_agreement=1', $this->headers)->assertOk()->json('tickets');
        $this->assertSame('inbound', $pendientes[0]['reason']);
        $claim = $this->claim($ticket, ['supports_upgrade_agreement' => true]);
        $capacidades = $claim['ticket']['capabilities'];
        $this->assertFalse($capacidades['upgrade_coordination']);
        $this->assertFalse($capacidades['coordinated_maintenance']);
        $this->assertFalse($capacidades['overnight_upgrade']);
        $this->assertFalse($capacidades['upgrade_reconfirmation']);
        $this->assertFalse($capacidades['upgrade_window']);
        $this->assertFalse($capacidades['upgrade_agreement']);

        $acuerdos_antes = SupportCodexUpgradeAgreement::count();
        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
        $respuesta = $this->postJson($this->base . '/tickets/' . $ticket->id . '/upgrade-proposal', [
            'lease_token' => $claim['lease_token'], 'expected_message_id' => $claim['ticket']['expected_message_id'],
            'operation_id' => 'propuesta-' . $ticket->id, 'thread_id' => $claim['ticket']['thread_id'],
            'mission_thread_id' => 'tarea-mision-2511', 'mission_hash' => hash('sha256', $case->mission),
            'minimum_minutes' => 60, 'starts_at' => '2026-11-26T02:00:00-03:00', 'ends_at' => '2026-11-26T03:00:00-03:00',
        ], $this->headers)->assertStatus(409);
        $this->assertStringStartsWith('upgrade_coordination_disabled', $respuesta->json('message'));
        $this->assertSame($acuerdos_antes, SupportCodexUpgradeAgreement::count());
        $this->assertCount(1, $this->deliveries->bodies);
    }

    public function test_con_config_la_coordinacion_vuelve_a_ofrecerse(): void
    {
        config(['services.support_codex.upgrade_coordination' => true]);
        $ticket = $this->ticket();
        $this->mision_con_evidencia($ticket);

        $this->getJson($this->base . '/pending?supports_upgrade_agreement=1', $this->headers)
            ->assertOk()->assertJsonPath('tickets.0.reason', 'fix_ready_for_upgrade');
        $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)
            ->assertJsonPath('ticket.capabilities.upgrade_coordination', true)
            ->assertJsonPath('ticket.capabilities.overnight_upgrade', true)
            ->assertJsonPath('ticket.capabilities.upgrade_agreement', true);
    }
}
