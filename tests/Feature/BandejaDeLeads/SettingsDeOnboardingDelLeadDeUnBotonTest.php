<?php

namespace Tests\Feature\BandejaDeLeads;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\LeadWhatsappOnboardingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El PUT de la pantalla de Cuenta (`PUT admin/settings/lead-whatsapp-onboarding`) con las dos claves
 * del lead de un botón (misión flujo-lead-landing-closer, 17/10/2026): `auto_message_landing` y
 * `welcome_delay_seconds_landing`.
 *
 * Son OPCIONALES en el PUT: el SPA que está en producción no las manda, y un `required` le daría 422 a
 * cada guardado hasta que se despliegue el SPA nuevo. Cuando vienen, se validan (texto ≤ 4000; demora
 * entera entre 0 y 3600) y se persisten; cuando no vienen, lo demás se guarda como siempre y las dos
 * quedan como estaban.
 */
class SettingsDeOnboardingDelLeadDeUnBotonTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/admin/settings/lead-whatsapp-onboarding';

    /**
     * Admin autenticado.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        return Admin::create([
            'name'     => 'Admin de prueba',
            'email'    => 'onboarding-landing-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * El payload que el SPA de hoy manda (sin las dos claves nuevas).
     *
     * @return array<string, mixed>
     */
    private function payload_de_hoy(): array
    {
        return [
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes' => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => true,
        ];
    }

    /**
     * Sin las dos claves, el PUT de hoy sigue dando 200 y no toca lo que ya había guardado para ellas.
     *
     * @return void
     */
    public function test_sin_las_claves_nuevas_el_put_de_hoy_sigue_andando(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING, 'Texto previo');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING, '25');

        $response = $this->actingAs($this->crear_admin(), 'sanctum')->putJson(self::ENDPOINT, $this->payload_de_hoy());
        $response->assertStatus(200);

        $this->assertSame('Texto previo', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING));
        $this->assertSame('25', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING));
        $this->assertSame('Texto previo', $response->json('auto_message_landing'));
        $this->assertSame(25, $response->json('welcome_delay_seconds_landing'));
    }

    /**
     * Con las dos claves, se validan y se persisten, y el GET las devuelve.
     *
     * @return void
     */
    public function test_con_las_claves_nuevas_se_persisten_y_el_get_las_devuelve(): void
    {
        $admin   = $this->crear_admin();
        $payload = array_merge($this->payload_de_hoy(), [
            'auto_message_landing'          => '¡Hola! Enseguida Martín se suma.',
            'welcome_delay_seconds_landing' => 12,
        ]);

        $put = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, $payload);
        $put->assertStatus(200);

        $this->assertSame('¡Hola! Enseguida Martín se suma.', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING));
        $this->assertSame('12', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING));

        $get = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT);
        $get->assertStatus(200);
        $this->assertSame('¡Hola! Enseguida Martín se suma.', $get->json('auto_message_landing'));
        $this->assertSame(12, $get->json('welcome_delay_seconds_landing'));
    }

    /**
     * Fuera de rango o del tipo equivocado: 422 con el campo señalado, y nada se escribe.
     *
     * @return void
     */
    public function test_valores_invalidos_dan_422_sin_escribir_nada(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['welcome_delay_seconds_landing' => 5000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['welcome_delay_seconds_landing']);

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['welcome_delay_seconds_landing' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['welcome_delay_seconds_landing']);

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['welcome_delay_seconds_landing' => 'diez']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['welcome_delay_seconds_landing']);

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['auto_message_landing' => str_repeat('a', 4001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['auto_message_landing']);

        $this->assertNull(AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING));
        $this->assertNull(AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING));
    }

    /**
     * Los bordes del rango (0 y 3600) entran.
     *
     * @return void
     */
    public function test_los_bordes_del_rango_de_la_demora_entran(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['welcome_delay_seconds_landing' => 0]))
            ->assertStatus(200);
        $this->assertSame('0', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING));

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['welcome_delay_seconds_landing' => 3600]))
            ->assertStatus(200);
        $this->assertSame('3600', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING));
    }
}
