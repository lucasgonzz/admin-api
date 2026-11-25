<?php

namespace Tests\Feature\SoporteMartin2511;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA8 (lado del monitor) — Verificación de entrega SIN acuerdo de horario (contratos C2 y C5): una misión que
 * quedó instalada por la actualización nocturna (`installed_at` sin `installed_schedule_id`) se le ofrece a
 * Martín como `mission_delivery_verification` sólo si el monitor anuncia `supports_delivery_verification`, y la
 * verificación se registra sin `schedule_id`.
 */
class EntregaPorActualizacionTest extends TestCase
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

    public function test_mision_instalada_por_actualizacion_se_verifica_sin_acuerdo(): void
    {
        $ticket = $this->ticket();
        $mission = $this->mision_con_evidencia($ticket);
        $verificar = $this->base . '/tickets/' . $ticket->id . '/mission-delivery-verified';
        $evidencia = 'Entré con el acceso maestro y la factura B redondea bien.';

        // Todavía no está instalada: no hay nada que verificar.
        $this->postJson($verificar, ['mission_id' => $mission->id, 'evidencia' => $evidencia], $this->headers)->assertStatus(409);

        $mission->update(['installed_at' => now(), 'installed_schedule_id' => null]);

        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->getJson($this->base . '/pending?supports_delivery_verification=1', $this->headers)
            ->assertJsonPath('tickets.0.id', $ticket->id)->assertJsonPath('tickets.0.reason', 'mission_delivery_verification');

        $ultimo = \App\Models\SupportMessage::where('support_ticket_id', $ticket->id)->max('id');
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-lucas', 'expected_message_id' => $ultimo,
        ], $this->headers)->assertStatus(409);
        $claim = $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-lucas', 'expected_message_id' => $ultimo, 'supports_delivery_verification' => true,
        ], $this->headers)->assertOk()->json();
        $this->assertSame('installed_pending_verification', $claim['ticket']['mission_delivery']['status']);
        $this->assertNull($claim['ticket']['mission_delivery']['schedule_id']);
        $this->assertSame('actualizacion', $claim['ticket']['mission_delivery']['installed_via']);

        $this->postJson($verificar, ['mission_id' => $mission->id, 'evidencia' => $evidencia], $this->headers)
            ->assertOk()->assertJsonPath('mission_delivery.status', 'verified')
            ->assertJsonPath('mission_delivery.schedule_id', null)
            ->assertJsonPath('mission_delivery.verification_evidence', $evidencia);
        $this->assertNotNull($mission->fresh()->delivery_verified_at);
    }
}
