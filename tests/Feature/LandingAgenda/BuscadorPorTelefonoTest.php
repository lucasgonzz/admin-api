<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\BuscadorDeLeadPorTelefono;

/**
 * Segunda vuelta, 🟠1: el número exacto primero. La landing usa SOLO el exacto; el webhook, el exacto y
 * recién si no hay, la tolerancia de siempre por sufijo.
 */
class BuscadorPorTelefonoTest extends BaseDeLaAgendaDeLaLanding
{
    const SECRETO = 'secreto-de-prueba-buscador';

    public function test_desde_la_landing_dos_numeros_que_solo_comparten_el_sufijo_no_se_cruzan(): void
    {
        $cordoba = Lead::create(['phone' => '+5493515551234', 'contact_name' => 'Lead de Córdoba', 'email' => 'cordoba@example.com', 'status' => 'contactado']);
        $leads_antes = Lead::query()->count();

        $respuesta = $this->reservar($this->cuerpo(['whatsapp' => '261-555-1234', 'email' => 'mendoza@example.com']))->assertStatus(201);

        $this->assertSame($leads_antes + 1, Lead::query()->count(), 'Nace un lead nuevo para Mendoza.');
        $respuesta->assertJsonPath('experiencia_url', 'https://admin.test/experiencia/5492615551234');
        $respuesta->assertJsonPath('correo', 'me***@example.com');

        $cordoba = $cordoba->fresh();
        $this->assertSame('contactado', $cordoba->status, 'La ficha de Córdoba no se toca.');
        $this->assertNull($cordoba->demo_id);
        $this->assertNull($cordoba->demo_reservada_desde_landing_at);
        $this->assertSame('demo_agendada', Lead::query()->where('phone', '+5492615551234')->value('status'));
    }

    public function test_el_webhook_elige_el_exacto_cuando_existen_los_dos(): void
    {
        $cordoba = Lead::create(['phone' => '+5493515551234', 'contact_name' => 'Córdoba', 'status' => 'contactado']);
        $mendoza = Lead::create(['phone' => '+5492615551234', 'contact_name' => 'Mendoza', 'status' => 'contactado']);

        $this->assertSame((int) $cordoba->id, (int) BuscadorDeLeadPorTelefono::buscar('+5493515551234')->id, 'Antes ganaba el más reciente por sufijo (Mendoza).');
        $this->assertSame((int) $mendoza->id, (int) BuscadorDeLeadPorTelefono::buscar('5492615551234')->id);

        /* Por el webhook real. */
        WhatsappConfig::query()->update(['is_active' => false]);
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();

        $body = json_encode([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => '+5493515551234', 'contact_name' => 'Córdoba'],
            'message'      => ['id' => 'wamid.BUSCADOR1', 'from' => '+5493515551234', 'type' => 'text', 'text' => ['body' => 'Hola'], 'timestamp' => (string) time()],
        ]);
        $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body)->assertStatus(200);

        $this->assertSame((int) $cordoba->id, (int) LeadMessage::query()->where('whatsapp_message_id', 'wamid.BUSCADOR1')->value('lead_id'));
    }

    public function test_sin_exacto_el_webhook_conserva_la_tolerancia_y_la_landing_no(): void
    {
        /* Ficha vieja con el número a medias: solo coincide por los últimos 8 dígitos. */
        $viejo = Lead::create(['phone' => '15551234', 'contact_name' => 'Ficha vieja', 'status' => 'contactado']);

        $this->assertSame((int) $viejo->id, (int) BuscadorDeLeadPorTelefono::buscar('+5493515551234')->id);
        $this->assertNull(BuscadorDeLeadPorTelefono::buscar_exacto('+5493515551234'));
    }
}
