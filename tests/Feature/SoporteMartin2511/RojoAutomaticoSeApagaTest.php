<?php

namespace Tests\Feature\SoporteMartin2511;

use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ajuste de la revisión (25/11/2026): el rojo que pone el propio sistema (dos turnos vacíos o el cierre rechazado
 * viejo) no es algo que Lucas tenga que destrabar. Al reabrirse con un entrante nuevo, el claim lo apaga; y el
 * comando `support-codex:limpiar-rojos-automaticos` apaga los que ya quedaron prendidos, sin tocar pausas de Lucas,
 * revisiones pedidas por el agente ni bloqueos técnicos.
 */
class RojoAutomaticoSeApagaTest extends TestCase
{
    use DatabaseTransactions;
    use SoporteMartinHelpers;

    /** Motivo del monitor tras dos turnos vacíos (`soporte.py` MOTIVO_TURNOS_VACIOS). */
    const TURNOS_VACIOS = 'Martín terminó dos turnos seguidos sin registrar respuesta para el mismo pedido; revisar el ticket';

    /** Motivo viejo del cierre rechazado (`SupportCodexService::CONFIRMATION_REVIEW_REASON`). */
    const CIERRE_RECHAZADO = 'El cierre fue rechazado porque el mensaje no confirma el resultado. Revisar sin volver a pedir confirmación.';

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
     * Deja el ticket en revisión manual con el motivo dado y el rojo prendido, como lo deja `release` o `reply`.
     *
     * @param  SupportTicket $ticket
     * @param  string        $motivo
     * @param  int|null      $pedido_por_mensaje `review_requested_message_id` (revisión pedida por el agente).
     * @return SupportCodexCase
     */
    private function en_rojo(SupportTicket $ticket, string $motivo, ?int $pedido_por_mensaje = null): SupportCodexCase
    {
        $ultimo = \App\Models\SupportMessage::where('support_ticket_id', $ticket->id)->max('id');
        $case = SupportCodexCase::firstOrCreate(['support_ticket_id' => $ticket->id]);
        $case->update(['state' => 'manual_review', 'review_reason' => $motivo, 'review_requested_message_id' => $pedido_por_mensaje,
            'expected_message_id' => $ultimo, 'handled_message_id' => $ultimo, 'lease_hash' => null, 'lease_expires_at' => null]);
        $ticket->forceFill(['support_attention_enabled' => true])->save();
        return $case;
    }

    /**
     * needs_human del ticket, como lo ve la bandeja.
     *
     * @param  SupportTicket $ticket
     * @return bool
     */
    private function rojo(SupportTicket $ticket): bool
    {
        return (bool) $ticket->fresh()->load('support_codex_case')->support_attention['needs_human'];
    }

    public function test_el_rojo_de_turnos_vacios_se_apaga_al_tomar_el_entrante_nuevo(): void
    {
        foreach ([self::TURNOS_VACIOS, self::CIERRE_RECHAZADO] as $i => $motivo) {
            $ticket = $this->ticket(['phone' => '+54934177760' . $i]);
            $this->en_rojo($ticket, $motivo);
            $this->assertTrue($this->rojo($ticket));

            $this->inbound($ticket, '¿Alguna novedad con lo que te pedí?');
            $this->claim($ticket);

            $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
            $this->assertNull($case->review_reason, $motivo);
            $this->assertNull($case->review_requested_message_id);
            $this->assertFalse((bool) $ticket->fresh()->support_attention_enabled, $motivo);
            $this->assertFalse($this->rojo($ticket), $motivo);
        }
    }

    public function test_la_revision_pedida_por_el_agente_sigue_en_rojo_al_tomar_el_turno(): void
    {
        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket), ['action' => 'pedir_revision', 'body' => 'Lo reviso.',
            'review_reason' => 'Necesito que cargues el CUIT porque Martín no tiene ese dato.'])->assertOk();
        $this->inbound($ticket, 'Ok, espero.');
        $this->claim($ticket);
        $this->assertTrue($this->rojo($ticket));
        $this->assertSame('Necesito que cargues el CUIT porque Martín no tiene ese dato.',
            SupportCodexCase::where('support_ticket_id', $ticket->id)->value('review_reason'));
    }

    public function test_el_comando_apaga_solo_los_rojos_automaticos_y_es_idempotente(): void
    {
        $vacios = $this->ticket(['phone' => '+5493417770701']);
        $this->en_rojo($vacios, self::TURNOS_VACIOS);
        $cierre = $this->ticket(['phone' => '+5493417770702']);
        $this->en_rojo($cierre, self::CIERRE_RECHAZADO);
        $pausa = $this->ticket(['phone' => '+5493417770703']);
        $this->en_rojo($pausa, SupportCodexCase::HUMAN_REPLY_REVIEW_REASON);
        $agente = $this->ticket(['phone' => '+5493417770704']);
        $this->en_rojo($agente, self::TURNOS_VACIOS . ' (pedido)', (int) \App\Models\SupportMessage::where('support_ticket_id', $agente->id)->max('id'));
        $tecnico = $this->ticket(['phone' => '+5493417770705']);
        $this->en_rojo($tecnico, 'El envío necesita conciliación.');
        $cerrado = $this->ticket(['phone' => '+5493417770706']);
        $this->en_rojo($cerrado, self::TURNOS_VACIOS);
        $cerrado->update(['status' => 'closed']);

        $this->artisan('support-codex:limpiar-rojos-automaticos', ['--dry-run' => true])
            ->expectsOutput('Tickets: ' . $vacios->id . ',' . $cierre->id)
            ->assertExitCode(0);
        $this->assertTrue((bool) $vacios->fresh()->support_attention_enabled);

        $this->artisan('support-codex:limpiar-rojos-automaticos')
            ->expectsOutput('Tickets: ' . $vacios->id . ',' . $cierre->id)
            ->assertExitCode(0);
        $this->assertFalse($this->rojo($vacios));
        $this->assertFalse($this->rojo($cierre));
        foreach ([$pausa, $agente, $tecnico, $cerrado] as $intacto) {
            $this->assertTrue((bool) $intacto->fresh()->support_attention_enabled, 'ticket ' . $intacto->id);
        }

        // El caso queda listo para reabrirse con el próximo entrante del cliente.
        $this->inbound($vacios, 'Sigo esperando.');
        $this->getJson($this->base . '/pending', $this->headers)->assertJsonFragment(['id' => $vacios->id, 'reason' => 'inbound']);

        $this->artisan('support-codex:limpiar-rojos-automaticos')->expectsOutput('Tickets: ninguno')->assertExitCode(0);
    }
}
