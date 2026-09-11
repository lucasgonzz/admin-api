<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\LeadDemoSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET/PUT settings/lead-demo` — las tres claves del CTA de la página de experiencia (misión
 * experiencia-landing, 11/9/2026): número de WhatsApp del canal de leads, texto prearmado del CTA y
 * minutos hasta el seguimiento de página.
 */
class LeadDemoSettingsCtaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'lead-demo-settings-cta-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Payload PUT completo y válido, con los overrides que cada test necesite.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload_valido(array $overrides = []): array
    {
        return array_merge([
            'duracion_minutos'                    => 60,
            'setup_minutos_antes'                 => 15,
            'gracia_minutos_post'                 => 10,
            'recordatorio_minutos_antes'          => 15,
            'recordatorio_manana_hora'            => '09:00',
            'check_ingreso_minutos_post'           => 10,
            'resumen_minutos_antes_fin'            => 10,
            'duracion_llamada_closer_minutos'      => 30,
            'frecuencia_slots_minutos'             => 30,
            'llamada_debe_terminar_en_horario'     => false,
            'ingreso_timeout_minutos'              => 15,
            'fin_seguimiento_minutos'              => 10,
            'fin_timeout_minutos'                  => 25,
            'pendiente_ingreso_horas_timeout'      => 24,
            'pendiente_terminar_timeout_minutos'   => 120,
        ], $overrides);
    }

    /**
     * (1) GET sin nada guardado: los tres defaults.
     *
     * @return void
     */
    public function test_get_devuelve_los_tres_defaults(): void
    {
        $this->autenticar();

        $this->getJson('/api/admin/settings/lead-demo')
            ->assertStatus(200)
            ->assertJsonPath('whatsapp_numero_leads', '543444544199')
            ->assertJsonPath('cta_whatsapp_texto', 'Hola Martín, quiero hacer la demo de ComercioCity')
            ->assertJsonPath('pagina_seguimiento_minutos', 120);
    }

    /**
     * (2) PUT guarda las tres y las devuelve; el número entra con formato y se persiste como dígitos.
     *     Se relee con un GET aparte para confirmar que quedó en `admin_settings`.
     *
     * @return void
     */
    public function test_put_guarda_y_devuelve_las_tres_claves(): void
    {
        $this->autenticar();

        $respuesta = $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido([
            'whatsapp_numero_leads'      => '+54 9 351 123-4567',
            'cta_whatsapp_texto'         => '  Hola, quiero ver la demo  ',
            'pagina_seguimiento_minutos' => 90,
        ]));

        $respuesta->assertStatus(200)
            ->assertJsonPath('whatsapp_numero_leads', '5493511234567')
            ->assertJsonPath('cta_whatsapp_texto', 'Hola, quiero ver la demo')
            ->assertJsonPath('pagina_seguimiento_minutos', 90);

        $this->assertSame('5493511234567', AdminSetting::get(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS));
        $this->assertSame('Hola, quiero ver la demo', AdminSetting::get(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTO));
        $this->assertSame('90', AdminSetting::get(LeadDemoSettings::KEY_PAGINA_SEGUIMIENTO_MINUTOS));

        $this->getJson('/api/admin/settings/lead-demo')
            ->assertJsonPath('whatsapp_numero_leads', '5493511234567')
            ->assertJsonPath('pagina_seguimiento_minutos', 90);

        $this->assertSame('https://wa.me/5493511234567?text=Hola%2C%20quiero%20ver%20la%20demo', LeadDemoSettings::build_cta_whatsapp_url());
    }

    /**
     * (3) Número inválido (menos de 8 o más de 15 dígitos después de normalizar) → 422 y no se toca
     *     lo guardado. Texto vacío o demasiado largo, y minutos fuera de rango, también 422.
     *
     * @return void
     */
    public function test_valores_invalidos_devuelven_422_sin_tocar_lo_guardado(): void
    {
        $this->autenticar();
        AdminSetting::set(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS, '543444544199');

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['whatsapp_numero_leads' => '12-34']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_numero_leads']);
        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['whatsapp_numero_leads' => '1234567890123456']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_numero_leads']);
        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['whatsapp_numero_leads' => 'sin digitos']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_numero_leads']);

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['cta_whatsapp_texto' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cta_whatsapp_texto']);
        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['cta_whatsapp_texto' => str_repeat('a', 201)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cta_whatsapp_texto']);

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido(['pagina_seguimiento_minutos' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pagina_seguimiento_minutos']);

        $this->assertSame('543444544199', AdminSetting::get(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS));
    }

    /**
     * (4) Sin mandar las claves (SPA viejo): el PUT no falla y los valores guardados no se tocan —
     *     mismo criterio "isset" que el resto de los campos opcionales.
     *
     * @return void
     */
    public function test_put_sin_las_claves_no_rompe_y_no_las_borra(): void
    {
        $this->autenticar();
        AdminSetting::set(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS, '5493511234567');
        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTO, 'Texto propio');
        AdminSetting::set(LeadDemoSettings::KEY_PAGINA_SEGUIMIENTO_MINUTOS, '45');

        $this->putJson('/api/admin/settings/lead-demo', $this->payload_valido())
            ->assertStatus(200)
            ->assertJsonPath('whatsapp_numero_leads', '5493511234567')
            ->assertJsonPath('cta_whatsapp_texto', 'Texto propio')
            ->assertJsonPath('pagina_seguimiento_minutos', 45);

        $this->assertSame('5493511234567', AdminSetting::get(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS));
        $this->assertSame('Texto propio', AdminSetting::get(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTO));
        $this->assertSame('45', AdminSetting::get(LeadDemoSettings::KEY_PAGINA_SEGUIMIENTO_MINUTOS));
    }
}
