<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use App\Models\SupportMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Agrupación de 10 minutos en SOPORTE (corrección de la revisión 1, API-1).
 *
 * En soporte el borrador de Claude no sólo lo borra el sistema al reemplazarlo: también lo borra una persona
 * («Descartar borrador» o contestar a mano, ambos por `clear_ticket_pending_state`). Un ancla borrada por una persona no
 * es «lo reemplazó el sistema»: el borrador nuevo es algo que Lucas no vio y tiene que avisar. Mientras el borrador
 * anterior siga esperando, el nuevo sí se agrupa (regresión de CA9).
 */
class AgrupacionDeSoporteTest extends BaseDePushPorAprobar
{
    /**
     * Cola falsa: crear un borrador de soporte puede encolar trabajos y emitir eventos de tiempo real.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * D1 avisado, una persona lo descarta por el endpoint real (`POST support-message/{id}/discard-ai-draft`) y D2 nace
     * 4 minutos después del aviso de D1: D2 avisa (un push por admin) y su fila queda `sent`, no `grouped`.
     *
     * @return void
     */
    public function test_borrador_descartado_por_una_persona_no_traga_el_aviso_del_siguiente(): void
    {
        $dueno = $this->crear_admin('Dueño de soporte', ['is_default_support_owner' => true]);
        self::registrar_device($dueno);

        $inicio = Carbon::now();
        $ticket = $this->crear_ticket_de_soporte();
        $d1     = $this->crear_borrador_de_soporte($ticket, 'Primer borrador de Claude.');

        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount(1, $this->espia->enviados, 'D1 avisa.');
        $this->assertSame('sent', $this->estado_de('support_message', (int) $d1->id));

        /* Lucas descarta D1 por el endpoint real (3 min después). */
        $this->reloj_desde($inicio, '+3 minutes');
        $this->actingAs($dueno, 'sanctum')
            ->postJson('/api/admin/support-message/' . $d1->id . '/discard-ai-draft')
            ->assertStatus(200);
        $this->assertFalse(SupportMessage::query()->whereKey((int) $d1->id)->exists(), 'El descarte borra el borrador.');

        /* Nace D2 a T+4 min. */
        $this->reloj_desde($inicio, '+4 minutes');
        $d2 = $this->crear_borrador_de_soporte($ticket->fresh(), 'Segundo borrador de Claude.');
        $this->reloj_desde($inicio, '+4 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount(2, $this->espia->enviados, 'D2 avisa: el ancla fue borrada por una persona.');
        $this->assertSame('sent', $this->estado_de('support_message', (int) $d2->id));
        $this->assertNull($this->fila_de('support_message', (int) $d2->id)->grouped_into_id);
    }

    /**
     * Igual pero la persona contesta a mano: el servicio `clear_ticket_pending_state` borra el borrador (es lo que hace
     * la respuesta manual del admin). D2 avisa.
     *
     * @return void
     */
    public function test_respuesta_manual_que_borra_el_borrador_no_traga_el_aviso_del_siguiente(): void
    {
        $dueno = $this->crear_admin('Dueño de soporte', ['is_default_support_owner' => true]);
        self::registrar_device($dueno);

        $inicio = Carbon::now();
        $ticket = $this->crear_ticket_de_soporte();
        $d1     = $this->crear_borrador_de_soporte($ticket, 'Primer borrador de Claude.');

        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount(1, $this->espia->enviados);

        $this->reloj_desde($inicio, '+3 minutes');
        app(\App\Services\SupportAiSuggestionDraftService::class)->clear_ticket_pending_state($ticket->fresh());
        $this->assertFalse(SupportMessage::query()->whereKey((int) $d1->id)->exists());

        $this->reloj_desde($inicio, '+4 minutes');
        $d2 = $this->crear_borrador_de_soporte($ticket->fresh(), 'Segundo borrador de Claude.');
        $this->reloj_desde($inicio, '+4 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount(2, $this->espia->enviados, 'D2 avisa.');
        $this->assertSame('sent', $this->estado_de('support_message', (int) $d2->id));
    }

    /**
     * Regresión de CA9 para soporte: con D1 todavía esperando, D2 a T+3 min se agrupa (no suena). Se crea D2 directo,
     * porque `create_draft` borra el borrador anterior (lo reemplaza el sistema) y entonces ya no habría ancla.
     *
     * @return void
     */
    public function test_con_el_borrador_anterior_pendiente_el_nuevo_sigue_agrupando(): void
    {
        $dueno = $this->crear_admin('Dueño de soporte', ['is_default_support_owner' => true]);
        self::registrar_device($dueno);

        $inicio = Carbon::now();
        $ticket = $this->crear_ticket_de_soporte();
        $d1     = $this->crear_borrador_de_soporte($ticket, 'Primer borrador de Claude.');

        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount(1, $this->espia->enviados, 'D1 avisa.');

        $this->reloj_desde($inicio, '+3 minutes');
        $d2 = SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'sender_admin_id'        => null,
            'kind'                   => 'text',
            'body'                   => 'Otro borrador mientras el primero espera.',
            'is_ai_suggestion_draft' => true,
            'ai_auto_send_at'        => null,
            'ai_generated_at'        => now(),
        ]);
        $this->reloj_desde($inicio, '+3 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount(1, $this->espia->enviados, 'D2 no suena otra vez.');
        $fila_d2 = $this->fila_de('support_message', (int) $d2->id);
        $this->assertSame('grouped', (string) $fila_d2->status);
        $this->assertSame((int) $this->fila_de('support_message', (int) $d1->id)->id, (int) $fila_d2->grouped_into_id);
    }
}
