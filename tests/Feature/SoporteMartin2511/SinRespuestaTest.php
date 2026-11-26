<?php

namespace Tests\Feature\SoporteMartin2511;

use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA1 y CA10 — Carril «no necesita respuesta» (contrato C1) y capacidades nuevas del contexto (C4).
 *
 * Martín puede marcar un entrante como atendido sin responder (un acuse, un «ok»): queda registrado con su
 * motivo, se suelta la reserva y el ticket NO se pone en rojo ni se vuelve a disparar hasta que el cliente
 * escriba algo nuevo.
 */
class SinRespuestaTest extends TestCase
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

    /**
     * Pide el no-respuesta con la reserva del claim.
     *
     * @param  SupportTicket        $ticket
     * @param  array<string, mixed> $claim
     * @param  array<string, mixed> $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function no_reply(SupportTicket $ticket, array $claim, array $extra = [])
    {
        return $this->postJson($this->base . '/tickets/' . $ticket->id . '/no-reply', array_merge([
            'lease_token' => $claim['lease_token'],
            'expected_message_id' => $claim['ticket']['expected_message_id'],
            'reason' => 'Es un acuse, no pide nada',
            'execution_id' => 'ejecucion-2511',
            'thread_id' => 'thread-ticket-' . $ticket->id,
        ], $extra), $this->headers);
    }

    public function test_registra_el_no_respuesta_sin_rojo_y_se_reabre_con_un_entrante_nuevo(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket))->assertOk();
        $ack = $this->inbound($ticket, 'Ok');
        $atencion_antes = $ticket->fresh()->support_attention_enabled;
        $claim = $this->claim($ticket);

        $this->no_reply($ticket, $claim)->assertOk()
            ->assertJsonPath('registered', true)->assertJsonPath('replayed', false)
            ->assertJsonPath('ticket.no_reply.message_id', $ack->id)
            ->assertJsonPath('ticket.no_reply.reason', 'Es un acuse, no pide nada');

        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame($ack->id, (int) $case->handled_message_id);
        $this->assertNull($case->lease_hash);
        $this->assertNull($case->lease_expires_at);
        $this->assertSame('ready', $case->state);
        $this->assertSame($atencion_antes, $ticket->fresh()->support_attention_enabled);
        $this->assertFalse($ticket->fresh()->load('support_codex_case')->support_attention['needs_human']);
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');
        $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)
            ->assertJsonPath('ticket.no_reply.reason', 'Es un acuse, no pide nada');
        $this->assertCount(1, $this->deliveries->bodies);

        // Repetir el mismo pedido (el monitor reintenta tras un corte) no cambia nada.
        $this->no_reply($ticket, $claim)->assertOk()->assertJsonPath('registered', true)->assertJsonPath('replayed', true);

        $this->inbound($ticket, 'Ahora no me deja cobrar.');
        $this->getJson($this->base . '/pending', $this->headers)
            ->assertJsonPath('tickets.0.id', $ticket->id)->assertJsonPath('tickets.0.reason', 'inbound');
    }

    public function test_rechaza_entrante_viejo_revision_manual_reserva_vencida_y_motivo_corto(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);

        $this->no_reply($ticket, $claim, ['reason' => 'corto'])->assertStatus(422);

        $this->inbound($ticket, 'Y además otra cosa.');
        $stale = $this->no_reply($ticket, $claim)->assertStatus(409);
        $this->assertStringStartsWith('stale_message:', $stale->json('message'));

        $otro = $this->ticket(['phone' => '+5493417772222']);
        $claim_otro = $this->claim($otro);
        SupportCodexCase::where('support_ticket_id', $otro->id)->update(['state' => 'manual_review', 'review_reason' => 'El envío necesita conciliación.']);
        $revision = $this->no_reply($otro, $claim_otro)->assertStatus(409);
        $this->assertStringStartsWith('manual_review:', $revision->json('message'));

        $tercero = $this->ticket(['phone' => '+5493417773333']);
        $claim_tercero = $this->claim($tercero);
        Carbon::setTestNow(now()->addMinutes(20));
        $this->no_reply($tercero, $claim_tercero)->assertStatus(423);
        $this->assertNull(SupportCodexCase::where('support_ticket_id', $tercero->id)->value('no_reply_message_id'));
    }

    public function test_sin_motivo_de_entrante_pendiente_devuelve_not_pending(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        // El mensaje ya quedó atendido por otra vía (sin que cambie la conversación): no hay entrante que soltar.
        SupportCodexCase::where('support_ticket_id', $ticket->id)->update(['handled_message_id' => $claim['ticket']['expected_message_id']]);
        $respuesta = $this->no_reply($ticket, $claim)->assertStatus(409);
        $this->assertStringStartsWith('not_pending:', $respuesta->json('message'));
    }

    public function test_contexto_anuncia_las_capacidades_nuevas(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        foreach (['no_reply', 'agent_review_resolution', 'delivery_without_schedule', 'ticket_description_every_reply'] as $capacidad) {
            $this->assertArrayHasKey($capacidad, $claim['ticket']['capabilities'], $capacidad);
            $this->assertTrue($claim['ticket']['capabilities'][$capacidad], $capacidad);
        }
        $this->assertArrayHasKey('no_reply', $claim['ticket']);
        $this->assertNull($claim['ticket']['no_reply']);
    }
}
