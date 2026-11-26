<?php

namespace Tests\Feature\SoporteMartin2511;

use App\Models\SupportCodexCase;
use App\Models\SupportCodexOperation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA2 — Un cierre que el cliente no confirmó es un rechazo corregible: 422 `confirmation_required` SIN tocar el
 * caso (ni revisión manual, ni reserva borrada, ni rojo), y Martín responde con la misma reserva.
 *
 * El cambio del servicio lo trae la misión `ticket-168-tour-y-cierre` (decisión del Coordinador, 25/11/2026):
 * esta misión no toca el bloque `cerrar` de `reply()`. El test queda saltado hasta que esa rama esté en la base;
 * al mergearla se quita el `markTestSkipped` y tiene que pasar sin cambiar ninguna aserción.
 */
class CierreNoConfirmadoCorregibleTest extends TestCase
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

    public function test_cierre_no_confirmado_conserva_la_reserva_y_no_pone_rojo(): void
    {
        $this->markTestSkipped('lo trae ticket-168-tour-y-cierre');

        $ticket = $this->ticket();
        $this->reply($ticket, $this->claim($ticket))->assertOk();
        $mensaje = $this->inbound($ticket, 'No, sigue igual, gracias');
        $claim = $this->claim($ticket);
        $atencion_antes = $ticket->fresh()->support_attention_enabled;
        $operaciones_antes = SupportCodexOperation::where('support_ticket_id', $ticket->id)->count();

        $rechazo = $this->reply($ticket, $claim, ['action' => 'cerrar', 'confirmation_message_id' => $mensaje->id, 'body' => 'De nada!'])
            ->assertStatus(422);
        $this->assertStringStartsWith('confirmation_required:', $rechazo->json('message'));

        $case = SupportCodexCase::where('support_ticket_id', $ticket->id)->firstOrFail();
        $this->assertNotSame('manual_review', $case->state);
        $this->assertNotNull($case->lease_expires_at);
        $this->assertSame($atencion_antes, $ticket->fresh()->support_attention_enabled);
        $this->assertSame($operaciones_antes, SupportCodexOperation::where('support_ticket_id', $ticket->id)->count());

        $this->reply($ticket, $claim, ['operation_id' => 'op-reintento-' . $ticket->id, 'body' => 'Revisemos juntos: ¿qué error te aparece?'])
            ->assertOk()->assertJsonPath('operation.status', 'sent');
    }
}
