<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\WhatsappSessionWindowService;

/**
 * CA1 y CA2 — El tercer propósito `personal` en `WhatsappConfig`, y que sumarlo no cambia nada de lo que
 * ya andaba (principal y asistente).
 */
class ConfigYNoRegresionTest extends BaseDelNumeroPersonal
{
    /**
     * CA1 — Con la fila personal completa, el principal sigue siendo «el activo» y el asistente no cambia.
     *
     * @return void
     */
    public function test_con_fila_personal_el_principal_sigue_siendo_el_activo(): void
    {
        $this->crear_principal();
        $asistente = new WhatsappConfig();
        $asistente->forceFill([
            'proposito'       => 'asistente',
            'kapso_api_key'   => self::CLAVE_KAPSO,
            'phone_number_id' => '5550001112223',
            'webhook_secret'  => 'secreto-asistente',
            'display_phone'   => '+1 201-586-6682',
            'is_active'       => true,
            'test_mode'       => false,
        ])->save();
        $personal = $this->crear_personal();

        $this->assertSame('personal', WhatsappConfig::PROPOSITO_PERSONAL);
        $this->assertSame(self::PNI_PRINCIPAL, WhatsappConfig::getActive()->phone_number_id);
        $this->assertSame('5550001112223', WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE)->phone_number_id);
        $this->assertSame(WhatsappConfig::PROPOSITO_ASISTENTE, WhatsappConfig::propositoEfectivoDelAsistente());
        $this->assertSame($personal->id, WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL)->id);
    }

    /**
     * CA1 — `canalListo('personal')` exige fila activa con `phone_number_id`, clave y secreto; no pide
     * `display_phone`. Y el asistente sigue pidiéndolo.
     *
     * @return void
     */
    public function test_canal_listo_personal_exige_los_tres_campos(): void
    {
        $this->crear_principal();

        // Sin fila.
        $this->assertNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL));

        // Inactiva.
        $personal = $this->crear_personal(['is_active' => false]);
        $this->assertNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL));

        // Sin secreto del webhook.
        $personal->forceFill(['is_active' => true, 'webhook_secret' => ''])->save();
        $this->assertNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL));
        $this->assertContains('webhook_secret', $personal->fresh()->loQueLeFaltaParaAtender());

        // Sin clave de Kapso.
        $personal->forceFill(['webhook_secret' => 'x', 'kapso_api_key' => ''])->save();
        $this->assertNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL));
        $this->assertContains('kapso_api_key', $personal->fresh()->loQueLeFaltaParaAtender());

        // Sin phone_number_id.
        $personal->forceFill(['kapso_api_key' => 'k', 'phone_number_id' => ''])->save();
        $this->assertNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL));
        $this->assertContains('phone_number_id', $personal->fresh()->loQueLeFaltaParaAtender());

        // Completa y SIN display_phone: lista (para el personal es informativo).
        $personal->forceFill(['phone_number_id' => self::PNI_PERSONAL, 'display_phone' => null])->save();
        $listo = WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL);
        $this->assertNotNull($listo);
        $this->assertSame([], $listo->loQueLeFaltaParaAtender());

        // El asistente sigue exigiendo el número visible.
        $asistente = new WhatsappConfig();
        $asistente->forceFill([
            'proposito' => 'asistente', 'kapso_api_key' => 'k', 'phone_number_id' => '555', 'webhook_secret' => 's',
            'display_phone' => null, 'is_active' => true, 'test_mode' => false,
        ])->save();
        $this->assertSame(['display_phone'], $asistente->fresh()->loQueLeFaltaParaAtender());
    }

    /**
     * CA2 (no regresión, pasa antes y después) — Sin fila personal, un payload con un `phone_number_id`
     * desconocido sigue cayendo al principal y creando el lead, como hoy.
     *
     * @return void
     */
    public function test_sin_fila_personal_phone_number_id_desconocido_cae_al_principal(): void
    {
        $this->crear_principal();

        $leads_antes = Lead::count();
        $payload     = $this->entrante('5493415559999', 'Hola, quiero info del sistema', 'wamid.NOREG1');
        $payload['phone_number_id']                  = '999000999000';
        $payload['conversation']['phone_number_id']  = '999000999000';

        $this->postear_al_webhook($payload, self::SECRETO_PRINCIPAL)->assertStatus(200);

        $this->assertSame($leads_antes + 1, Lead::count(), 'Un desconocido sigue entrando al pipeline por el principal.');
    }

    /**
     * CA2 + CA10 — La ventana de `null`, `principal` y `asistente` no cambia, y `personal` NO hereda la de
     * leads/soporte: un entrante de lead al mismo teléfono no abre la ventana del número personal.
     *
     * @return void
     */
    public function test_ventana_de_principal_y_asistente_no_cambia(): void
    {
        $this->crear_principal();

        $lead = Lead::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_name' => 'Lead', 'phone' => self::TEL_CLIENTE]);
        LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'lead', 'content' => 'hola', 'status' => 'enviado']);

        $ventana = new WhatsappSessionWindowService();

        $this->assertTrue($ventana->window_state(self::TEL_CLIENTE)['open']);
        $this->assertSame('leads', $ventana->window_state(self::TEL_CLIENTE)['origin']);
        $this->assertTrue($ventana->window_state(self::TEL_CLIENTE, 'principal')['open']);
        $this->assertFalse($ventana->window_state(self::TEL_CLIENTE, 'asistente')['open'], 'Sin asistente no hay ventana propia.');

        // El personal tiene su propia ventana: un lead no la abre.
        $this->assertFalse(
            (new WhatsappSessionWindowService())->window_state(self::TEL_CLIENTE, 'personal')['open'],
            'La ventana del personal no puede salir de lead_messages.'
        );
    }
}
