<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoExperienciaController;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El bloque `cta` del payload de la página de experiencia (misión experiencia-landing, 11/9/2026):
 * el botón "Quiero probarlo" que devuelve al lead a la conversación de WhatsApp con el texto
 * prearmado. Contrato con admin-spa: `cta.whatsapp_url` (string o null) y `cta.texto_boton`.
 */
class DemoExperienciaCtaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * (1) Sin nada configurado, el payload trae la URL de `wa.me` con el número del canal de leads
     *     y el texto por defecto codificado (rawurlencode: espacios como %20, no como +).
     *
     * @return void
     */
    public function test_el_payload_trae_la_url_de_whatsapp_con_el_numero_y_el_texto_por_defecto(): void
    {
        $lead = $this->crear_lead();

        $this->getJson('/api/demo-experiencia/' . $lead->uuid)
            ->assertStatus(200)
            ->assertJsonPath('cta.whatsapp_url', 'https://wa.me/543444544199?text=Hola%20Mart%C3%ADn%2C%20quiero%20hacer%20la%20demo%20de%20ComercioCity')
            ->assertJsonPath('cta.texto_boton', DemoExperienciaController::CTA_TEXTO_BOTON)
            ->assertJsonPath('cta.texto_boton', 'Quiero probarlo');
    }

    /**
     * (2) El número y el texto salen de las settings: un número guardado con formato se usa como
     *     dígitos pelados, y el texto configurado viaja codificado.
     *
     * @return void
     */
    public function test_la_url_sale_de_las_settings_configuradas(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS, '5493511234567');
        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTO, 'Hola, quiero la demo');

        $lead = $this->crear_lead();

        $this->getJson('/api/demo-experiencia/' . $lead->uuid)
            ->assertStatus(200)
            ->assertJsonPath('cta.whatsapp_url', 'https://wa.me/5493511234567?text=Hola%2C%20quiero%20la%20demo');

        $this->assertSame('https://wa.me/5493511234567?text=Hola%2C%20quiero%20la%20demo', LeadDemoSettings::build_cta_whatsapp_url());
    }

    /**
     * (3) Sin número válido configurado, `whatsapp_url` es null (la página no dibuja el botón) y el
     *     bloque `cta` sigue presente con su texto: el contrato no desaparece.
     *
     * @return void
     */
    public function test_sin_numero_valido_la_url_es_null_pero_el_bloque_sigue(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS, 'sin numero');

        $lead = $this->crear_lead();

        $respuesta = $this->getJson('/api/demo-experiencia/' . $lead->uuid)->assertStatus(200);

        $this->assertNull($respuesta->json('cta.whatsapp_url'));
        $this->assertSame('Quiero probarlo', $respuesta->json('cta.texto_boton'));
        $this->assertNull(LeadDemoSettings::build_cta_whatsapp_url());
        $this->assertSame('', LeadDemoSettings::get_whatsapp_numero_leads());
    }

    /**
     * (4) El bloque viaja también con turno asignado: es la página la que decide cuándo dibujar el
     *     botón, no el backend el que lo esconde según el estado.
     *
     * @return void
     */
    public function test_con_turno_el_bloque_cta_viaja_igual(): void
    {
        $lead                  = $this->crear_lead();
        $lead->demo_id         = 1;
        $lead->demo_date       = '2026-09-11';
        $lead->demo_start_time = '10:00';
        $lead->demo_end_time   = '11:00';
        $lead->save();

        $this->getJson('/api/demo-experiencia/' . $lead->uuid)
            ->assertStatus(200)
            ->assertJsonStructure(['cta' => ['whatsapp_url', 'texto_boton']]);
    }

    /**
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Guillermo González';
        $lead->company_name     = 'Ferretería de prueba';
        $lead->phone            = '5493519999999';
        $lead->status           = 'contactado';
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }
}
