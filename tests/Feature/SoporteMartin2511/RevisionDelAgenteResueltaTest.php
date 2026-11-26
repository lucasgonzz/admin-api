<?php

namespace Tests\Feature\SoporteMartin2511;

use App\Models\SupportCodexCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA3 — Un ticket en revisión por «dos turnos vacíos» vuelve a la cola cuando el cliente escribe de nuevo.
 * CA4 — `review_resolved: true` (contrato C3) apaga el rojo de una revisión que pidió el propio agente y que
 * ya no aplica; nunca el de un bloqueo técnico, y sólo con responder / solicitar_confirmacion / cerrar.
 */
class RevisionDelAgenteResueltaTest extends TestCase
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

    public function test_revision_por_turnos_vacios_se_reabre_con_un_entrante_nuevo(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/release', [
            'lease_token' => $claim['lease_token'], 'manual_review' => true,
            'reason' => 'Martín terminó dos turnos seguidos sin registrar respuesta para el mismo pedido; revisar el ticket',
        ], $this->headers)->assertOk();
        $this->assertSame('manual_review', SupportCodexCase::where('support_ticket_id', $ticket->id)->value('state'));
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonCount(0, 'tickets');

        $this->inbound($ticket, '¿Alguna novedad?');
        $this->getJson($this->base . '/pending', $this->headers)
            ->assertJsonPath('tickets.0.id', $ticket->id)->assertJsonPath('tickets.0.reason', 'inbound');
    }

    public function test_review_resolved_apaga_el_rojo_de_una_revision_pedida_por_el_agente(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket), ['action' => 'pedir_revision', 'body' => 'Lo reviso y te aviso.',
            'review_reason' => 'Necesito que cargues el punto de venta porque Martín no tiene permiso de AFIP.'])->assertOk();
        $this->assertTrue($ticket->fresh()->load('support_codex_case')->support_attention['needs_human']);

        // El cliente destrabó solo lo que pedía la revisión y sigue con otra consulta (no es una confirmación de cierre).
        $this->inbound($ticket, 'Ya cargué el punto de venta yo. ¿Cómo hago una nota de crédito?');
        $claim = $this->claim($ticket);
        $this->reply($ticket, $claim, ['body' => 'Perfecto. Para la nota de crédito entrá a Ventas y elegí la factura.', 'review_resolved' => true])
            ->assertOk()->assertJsonPath('operation.status', 'sent');

        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertNull($case->review_reason);
        $this->assertNull($case->review_requested_message_id);
        $this->assertFalse((bool) $ticket->fresh()->support_attention_enabled);
        $this->assertFalse($ticket->fresh()->load('support_codex_case')->support_attention['needs_human']);
    }

    public function test_review_resolved_no_toca_un_bloqueo_tecnico(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $motivo = 'No se pudo confirmar la entrega del mensaje; conciliar antes de reenviar.';
        SupportCodexCase::where('support_ticket_id', $ticket->id)->update(['review_reason' => $motivo, 'review_requested_message_id' => null]);
        $ticket->forceFill(['support_attention_enabled' => true])->save();

        $this->reply($ticket, $claim, ['review_resolved' => true])->assertOk()->assertJsonPath('operation.status', 'sent');

        $this->assertSame($motivo, SupportCodexCase::where('support_ticket_id', $ticket->id)->value('review_reason'));
        $this->assertTrue((bool) $ticket->fresh()->support_attention_enabled);
        $this->assertTrue($ticket->fresh()->load('support_codex_case')->support_attention['needs_human']);
    }

    public function test_review_resolved_con_escalar_o_valor_invalido_da_422(): void
    {
        $ticket = $this->ticket();
        $claim = $this->claim($ticket);
        $escalar = $this->reply($ticket, $claim, ['action' => 'escalar', 'mission' => 'Corregir el redondeo.', 'review_resolved' => true])
            ->assertStatus(422);
        $this->assertStringStartsWith('invalid_review_resolution:', $escalar->json('message'));
        $falso = $this->reply($ticket, $claim, ['review_resolved' => false])->assertStatus(422);
        $this->assertStringStartsWith('invalid_review_resolution:', $falso->json('message'));
        $this->assertCount(0, $this->deliveries->bodies);
    }
}
