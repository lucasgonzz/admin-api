<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadWhatsappOnboardingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Interruptor global de Cuenta (decisión de Lucas, 1/9/2026): con el toggle prendido (default,
 * comportamiento histórico), un lead que entra al tramo solicita_disponibilidad → closer_activo
 * se auto-marca para verificación de mensajes (Lead::booted, prompt 406). Con el toggle apagado,
 * ningún lead se marca solo por el tramo. En ningún caso se toca el toggle manual por-lead: el
 * botón del escudo en la conversación sigue funcionando siempre, apagado o prendido el global.
 *
 * Reversible las veces que haga falta (no es una migración de una sola vía): ver caso (a).
 */
class InterruptorGlobalDeVerificacionDeMensajesTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT_SETTINGS = '/api/admin/settings/lead-whatsapp-onboarding';

    /**
     * Crea un admin para autenticar contra los endpoints.
     *
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Crea un lead mínimo en el estado dado.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Payload completo de los campos existentes del endpoint, con el placeholder {nombre}
     * requerido en las dos variantes "con nombre" (si no, el controller devuelve 422).
     *
     * @param bool $auto_activar
     *
     * @return array<string, mixed>
     */
    private function payload_completo(bool $auto_activar): array
    {
        return [
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes' => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => $auto_activar,
        ];
    }

    /**
     * (a) GET sin fila previa devuelve el default true (prueba el seed). PUT persiste el valor en
     * ambos sentidos, confirmando que el interruptor es reversible las veces que haga falta.
     */
    public function test_get_y_put_incluyen_y_persisten_el_interruptor_global(): void
    {
        $admin = $this->crear_admin('cuenta1@comerciocity.com');

        $this->assertNull(AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));

        $get = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT_SETTINGS);
        $get->assertStatus(200);
        $this->assertTrue($get->json('auto_activar_verificacion_al_solicitar_disponibilidad'));
        $this->assertSame('1', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));

        $apagar = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT_SETTINGS, $this->payload_completo(false));
        $apagar->assertStatus(200);
        $this->assertFalse($apagar->json('auto_activar_verificacion_al_solicitar_disponibilidad'));
        $this->assertSame('0', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));

        $prender = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT_SETTINGS, $this->payload_completo(true));
        $prender->assertStatus(200);
        $this->assertTrue($prender->json('auto_activar_verificacion_al_solicitar_disponibilidad'));
        $this->assertSame('1', AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD));
    }

    /**
     * (b) Global en true (default): el lead que cruza a solicita_disponibilidad queda marcado.
     */
    public function test_global_en_true_el_cruce_a_solicita_disponibilidad_marca_al_lead(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');

        $lead = $this->crear_lead('contactado');
        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes);

        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $lead->refresh();

        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);
    }

    /**
     * (c) Global en false: el mismo cruce NO auto-enciende el flag. Cubre dos estados distintos
     * de la ventana para no depender de uno solo.
     */
    public function test_global_en_false_el_cruce_a_la_ventana_no_marca_al_lead(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $lead_a = $this->crear_lead('contactado');
        $lead_a->status = 'solicita_disponibilidad';
        $lead_a->save();
        $lead_a->refresh();
        $this->assertFalse((bool) $lead_a->requiere_verificacion_mensajes);

        $lead_b = $this->crear_lead('contactado');
        $lead_b->status = 'demo_agendada';
        $lead_b->save();
        $lead_b->refresh();
        $this->assertFalse((bool) $lead_b->requiere_verificacion_mensajes);
    }

    /**
     * (d) Global en false: el toggle manual por-lead sigue funcionando en los dos sentidos,
     * independiente del interruptor.
     */
    public function test_global_en_false_el_toggle_manual_por_lead_sigue_funcionando(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');
        $admin = $this->crear_admin('cuenta2@comerciocity.com');
        $lead  = $this->crear_lead('solicita_disponibilidad');
        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes);

        $endpoint = '/api/admin/lead/' . $lead->id . '/toggle-requiere-verificacion-mensajes';

        $this->actingAs($admin, 'sanctum')->postJson($endpoint)->assertStatus(200);
        $lead->refresh();
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);

        $this->actingAs($admin, 'sanctum')->postJson($endpoint)->assertStatus(200);
        $lead->refresh();
        $this->assertFalse((bool) $lead->requiere_verificacion_mensajes);
    }

    /**
     * (e) Global en false: un lead ya marcado a mano no se apaga solo al cambiar de estado. El
     * latch solo prende, nunca apaga — ni el interruptor global cambia eso.
     */
    public function test_global_en_false_no_apaga_un_lead_ya_marcado_a_mano(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '0');

        $lead = $this->crear_lead('contactado');
        $lead->requiere_verificacion_mensajes = true;
        $lead->save();

        $lead->status = 'solicita_disponibilidad';
        $lead->save();
        $lead->refresh();
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);

        $lead->status = 'demo_agendada';
        $lead->save();
        $lead->status = 'en_pausa';
        $lead->save();
        $lead->refresh();
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);
    }
}
