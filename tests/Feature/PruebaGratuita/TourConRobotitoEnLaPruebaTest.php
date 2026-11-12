<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\SupportCodexSettings;
use App\Services\SupportTourService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * El tour con Robotito en la prueba gratuita (misión cruzada prueba-e2e-campana-imperio, 11/11/2026; defecto medido
 * en el ticket 101): un ticket nacido por la plantilla de WhatsApp y continuado por el dueño en el chat de su sistema.
 * Martín ofreció el tour con una pregunta escrita a mano, el dueño contestó «sí dale mostrame con el robotito ese…» y
 * al preparar el tour el admin devolvió `tour_consent_required`.
 *
 * Lo que protege:
 *  1. Del lado del admin ese ticket SÍ tiene Robotito disponible cuando el sistema del negocio manda la marca de
 *     sesión (`robotito_support_version`); sin ella, no (y queda escrito de qué depende).
 *  2. Una invitación a Robotito escrita a mano por el agente (sin `tour_requested`) queda registrada igual.
 *  3. Un «sí» que nombra a Robotito con palabras propias se reconoce como aceptación de esa invitación, y solo de esa.
 */
class TourConRobotitoEnLaPruebaTest extends BaseDeLaPrueba
{
    const CODEX = '/api/claude/support-codex';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
    }

    /**
     * El ticket «Prueba gratuita» que abre la plantilla de bienvenida, de un negocio en prueba en 4.3.51.
     *
     * @return array{0: Client, 1: SupportTicket}
     */
    private function ticket_de_la_bienvenida(): array
    {
        $this->crear_config_whatsapp();
        $admin  = $this->admin();
        $client = $this->cliente_en_prueba();
        $client->inbound_api_key = 'firma-del-tour-de-la-prueba';
        $client->save();
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA, 'version' => '4.3.51',
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'user_id' => (int) $client->user_id, 'alias_slug' => 'ferreteria-tour',
        ]);

        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'status' => 'open', 'source' => 'whatsapp',
            'whatsapp_phone' => (string) $client->phone, 'opened_at' => now(), 'name' => 'Prueba gratuita',
        ]);
        SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'sender_admin_id' => $admin->id, 'kind' => 'text',
            'body' => 'Hola Marcela, soy Martín, del equipo de ComercioCity.', 'delivered_at' => now(), 'whatsapp_message_id' => 'wamid.bienvenida',
        ]);

        return [$client, $ticket];
    }

    /**
     * El dueño escribe en el chat de Soporte de su sistema, como lo reenvía empresa-api.
     *
     * @param array<string, mixed> $extra Para sacar o pisar campos del reenvío.
     */
    private function escribe_en_el_sistema(Client $client, SupportTicket $ticket, string $texto, array $extra = []): SupportMessage
    {
        $datos = array_filter($extra + [
            'agentes_version' => 1, 'es_dueno' => 1, 'client_uuid' => $client->uuid, 'ticket_uuid' => $ticket->uuid,
            'client_user_id' => $client->user_id, 'sender_user_id' => $client->user_id, 'sender_type' => 'user',
            'message_uuid' => (string) Str::uuid(), 'kind' => 'text', 'body' => $texto,
            'robotito_support_version' => 1, 'traspasar_a_sistema' => 1,
        ], function ($valor) {
            return $valor !== null;
        });

        $respuesta = $this->postJson('/api/inbound/support/messages', $datos, ['X-Admin-Api-Key' => (string) $client->inbound_api_key]);
        $respuesta->assertSuccessful();

        return SupportMessage::findOrFail($respuesta->json('model.id'));
    }

    /**
     * Martín toma el ticket y contesta.
     *
     * @param array<string, mixed> $respuesta Campos de `reply` además de la reserva.
     *
     * @return SupportMessage El mensaje que quedó en el hilo.
     */
    private function martin_contesta(SupportTicket $ticket, SupportMessage $entrante, array $respuesta): SupportMessage
    {
        $ruta    = self::CODEX . '/tickets/' . $ticket->id;
        $reserva = $this->postJson($ruta . '/claim', ['worker_id' => 'martin-tour', 'expected_message_id' => $entrante->id], $this->headers_de_claude())->assertOk();

        $this->postJson($ruta . '/reply', $respuesta + [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $entrante->id,
            'operation_id' => 'tour-prueba-' . Str::random(12), 'action' => 'responder',
        ], $this->headers_de_claude())->assertOk()->assertJsonPath('operation.status', 'sent');

        return SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->orderByDesc('id')->firstOrFail();
    }

    /** @test */
    public function el_ticket_de_la_bienvenida_continuado_en_el_sistema_tiene_robotito_si_el_sistema_manda_la_marca_de_sesion(): void
    {
        [$client, $ticket] = $this->ticket_de_la_bienvenida();
        $entrante = $this->escribe_en_el_sistema($client, $ticket, '¿Cómo cambio el precio de un artículo?');

        $contexto = $this->getJson(self::CODEX . '/tickets/' . $ticket->id, $this->headers_de_claude())->assertOk();
        $contexto->assertJsonPath('ticket.source', 'erp')
            ->assertJsonPath('ticket.expected_message_id', (int) $entrante->id)
            ->assertJsonPath('ticket.robotito_available', true)
            ->assertJsonPath('ticket.capabilities.support_tour_offer', true)
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', true)
            ->assertJsonPath('ticket.capabilities.support_tour_consent', true)
            ->assertJsonPath('ticket.accepted_tour_request', false);

        /* Sin `robotito_support_version` en el reenvío (lo pone empresa-api cuando la sesión tiene el chat de soporte),
           el admin no puede dar por disponible a Robotito: la oferta sigue, la guía dinámica no. */
        $sin_marca = $this->escribe_en_el_sistema($client, $ticket->fresh(), '¿Y cómo cargo una compra?', ['robotito_support_version' => null, 'traspasar_a_sistema' => null]);
        $this->getJson(self::CODEX . '/tickets/' . $ticket->id, $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.expected_message_id', (int) $sin_marca->id)
            ->assertJsonPath('ticket.robotito_available', false)
            ->assertJsonPath('ticket.capabilities.support_tour_offer', true)
            ->assertJsonPath('ticket.capabilities.support_dynamic_tour', false);
    }

    /** @test */
    public function la_invitacion_escrita_a_mano_queda_registrada_y_el_si_con_palabras_propias_se_reconoce(): void
    {
        [$client, $ticket] = $this->ticket_de_la_bienvenida();
        $this->fakear_http(['*/api/admin-sync/support/messages' => Http::response(['model' => ['id' => 1]], 201)]);
        $consulta = $this->escribe_en_el_sistema($client, $ticket, 'Hola, quiero cambiar unos precios');

        /* Martín ofrece el tour en texto libre: la pregunta pegada al final del párrafo y sin `tour_requested`. */
        $invitacion = $this->martin_contesta($ticket, $consulta, [
            'body' => 'Para cambiar un precio entrá a Artículos, abrí el artículo y editá el costo o el margen. ¿Querés que te prepare un tour con Robotito?',
        ]);

        $this->assertTrue($invitacion->erp_auto_reply['tour_requested'] ?? false, 'La invitación quedó registrada aunque se escribió a mano.');
        $this->assertTrue(SupportTourService::tiene_invitacion($invitacion->body), 'Y quedó con la pregunta en su renglón final.');
        $this->assertStringContainsString('editá el costo o el margen.', $invitacion->body);
        $this->assertSame(1, substr_count($invitacion->body, 'tour con Robotito'), 'Una sola vez.');
        $this->assertNotNull($invitacion->synced_to_client_at);

        /* El dueño acepta con sus palabras. */
        $acepta = $this->escribe_en_el_sistema($client, $ticket->fresh(), 'sí dale mostrame con el robotito ese…', ['traspasar_a_sistema' => null]);

        $ruta    = self::CODEX . '/tickets/' . $ticket->id;
        $reserva = $this->postJson($ruta . '/claim', ['worker_id' => 'martin-tour', 'expected_message_id' => $acepta->id], $this->headers_de_claude())
            ->assertOk()
            ->assertJsonPath('ticket.robotito_available', true)
            ->assertJsonPath('ticket.accepted_tour_request', true);

        /* Al preparar el tour ya no responde `tour_consent_required` (acá no hay planificador: falla más adelante). */
        $preparar = $this->postJson($ruta . '/tour/prepare', [
            'lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $acepta->id,
        ], $this->headers_de_claude());
        $this->assertStringNotContainsString('tour_consent_required', (string) $preparar->getContent());
    }

    /** @test */
    public function sin_una_invitacion_registrada_un_si_no_alcanza_y_un_no_tampoco_se_toma_como_si(): void
    {
        [$client, $ticket] = $this->ticket_de_la_bienvenida();
        $this->fakear_http(['*/api/admin-sync/support/messages' => Http::response(['model' => ['id' => 1]], 201)]);
        $consulta = $this->escribe_en_el_sistema($client, $ticket, 'Hola, quiero cambiar unos precios');

        /* Una respuesta común, sin invitación: el «sí… robotito» de después no es consentimiento de nada. */
        $respuesta = $this->martin_contesta($ticket, $consulta, ['body' => 'Buenísimo. Contame qué artículos querés actualizar y lo vemos.']);
        $this->assertFalse($respuesta->erp_auto_reply['tour_requested'] ?? false);

        $pide = $this->escribe_en_el_sistema($client, $ticket->fresh(), 'sí dale mostrame con el robotito ese', ['traspasar_a_sistema' => null]);
        $this->getJson(self::CODEX . '/tickets/' . $ticket->id, $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.expected_message_id', (int) $pide->id)
            ->assertJsonPath('ticket.accepted_tour_request', false);

        /* Con invitación registrada, un «no» que nombra a Robotito no es un sí. */
        $invitacion = $this->martin_contesta($ticket, $pide, [
            'body' => "Entrá a Artículos, abrí el artículo y editá el precio.\n\n¿Querés que te prepare un tour con Robotito?",
        ]);
        $this->assertTrue($invitacion->erp_auto_reply['tour_requested'] ?? false);

        $rechaza = $this->escribe_en_el_sistema($client, $ticket->fresh(), 'dale, pero no me mandes el robotito', ['traspasar_a_sistema' => null]);
        $this->getJson(self::CODEX . '/tickets/' . $ticket->id, $this->headers_de_claude())->assertOk()
            ->assertJsonPath('ticket.expected_message_id', (int) $rechaza->id)
            ->assertJsonPath('ticket.accepted_tour_request', false);
    }

    /** @test */
    public function la_invitacion_a_mano_se_reconoce_en_sus_formas_y_no_en_un_texto_que_solo_nombra_a_robotito(): void
    {
        $canonica = "Entrá a Artículos.\n\n¿Querés que te prepare un tour con Robotito?";
        $this->assertSame($canonica, SupportTourService::invitacion_escrita_a_mano($canonica));

        $en_linea = SupportTourService::invitacion_escrita_a_mano('Entrá a Artículos y editá el precio. ¿Te gustaría que te lo muestre Robotito en tu pantalla?');
        $this->assertSame("Entrá a Artículos y editá el precio.\n\n" . SupportTourService::INVITACION, $en_linea);

        $this->assertNull(SupportTourService::invitacion_escrita_a_mano('Robotito es la guía que aparece en la barra de navegación.'));
        /* La pregunta suelta, sin ninguna explicación, no es una oferta: `preparar_oferta()` la descarta. */
        $this->assertFalse(SupportTourService::respuesta_admite_oferta(new SupportMessage(['body' => '¿Cómo cambio un precio?']), '¿Querés que te prepare un tour con Robotito?', true));
        $this->assertNull(SupportTourService::invitacion_escrita_a_mano('Te lo muestro. ¿Te parece que te arme un video?'), 'Sin Robotito no es la invitación.');
    }
}
