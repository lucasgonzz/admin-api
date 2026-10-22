<?php

namespace Tests\Feature\AgentesIa;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\ClientAssistantMessage;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Misión `agentes-ia-extension-unica` (22/10/2026): el 403 de un cliente al que le falta la extensión se sigue
 * entendiendo (es el mismo código HTTP que antes), pero el motivo que queda en la fila ahora nombra «Agentes de IA»
 * y, por compatibilidad con empresa vieja, también el slug anterior `asistente_ia`.
 */
class ElMensajeDelAsistenteDiceAgentesIaTest extends BaseDelCanal
{
    /**
     * @return void
     */
    public function test_el_403_nombra_agentes_de_ia_y_sigue_cerrando_con_error(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.AGENTESIA1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Cuánto vendí ayer?';
        $fila->estado              = ClientAssistantMessage::ESTADO_RECIBIDO;
        $fila->save();

        $this->fakear_http(['*' => Http::response(['message' => 'Forbidden'], 403)]);

        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);

        $fila->refresh();

        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->estado);
        $this->assertStringContainsString('Agentes de IA', (string) $fila->error);
        $this->assertStringContainsString('agentes_ia', (string) $fila->error);
    }
}
