<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\LeadWhatsappOnboardingSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * "Al lead se lo trata por su primer nombre, nunca por nombre y apellido" (decisiones de Lucas del
 * 8/9 y del 11/9/2026, misión experiencia-landing): la regla vive en `Lead::primer_nombre_de()` y la
 * comparten el accessor `contact_first_name` y el onboarding de WhatsApp.
 */
class LeadPrimerNombreTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * (1) Primera palabra por espacios, con la misma nulabilidad que la entrada.
     *
     * @return void
     */
    public function test_primer_nombre_de_devuelve_la_primera_palabra_y_respeta_la_nulabilidad(): void
    {
        $this->assertSame('Juan', Lead::primer_nombre_de('Juan Pérez'));
        $this->assertSame('Juan', Lead::primer_nombre_de('Juan'));
        $this->assertSame('María', Lead::primer_nombre_de('  María  José López '));
        $this->assertSame('Guillermo', Lead::primer_nombre_de("Guillermo\tGonzález"));
        $this->assertNull(Lead::primer_nombre_de(null));
        $this->assertSame('', Lead::primer_nombre_de(''));
        $this->assertSame('', Lead::primer_nombre_de('   '));
    }

    /**
     * (2) El accessor delega en la misma regla.
     *
     * @return void
     */
    public function test_el_accessor_contact_first_name_delega_en_la_regla(): void
    {
        $lead               = new Lead();
        $lead->contact_name = 'Juan Pérez';
        $this->assertSame('Juan', $lead->contact_first_name);

        $lead->contact_name = null;
        $this->assertNull($lead->contact_first_name);

        $lead->contact_name = '';
        $this->assertSame('', $lead->contact_first_name);
    }

    /**
     * (3) El onboarding recibe el nombre completo del perfil de WhatsApp y saluda con el primer
     *     nombre: mensaje automático inmediato y presentación de Martín. Sin nombre, la variante
     *     sin nombre de siempre.
     *
     * @return void
     */
    public function test_el_saludo_del_onboarding_lleva_el_primer_nombre(): void
    {
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_WITH_NAME, '¡Hola {nombre}! Ya te atendemos.');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_WITHOUT_NAME, '¡Hola! Ya te atendemos.');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_WITH_NAME, '¡Hola {nombre}! Soy Martín, del equipo de ComercioCity.');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_WITHOUT_NAME, '¡Hola! Soy Martín, del equipo de ComercioCity.');

        $servicio = new LeadWhatsappOnboardingService();

        $this->assertSame('¡Hola Juan! Ya te atendemos.', $servicio->build_auto_message_body('Juan Pérez'));
        $this->assertSame('¡Hola Juan! Soy Martín, del equipo de ComercioCity.', $servicio->build_welcome_message_body('Juan Pérez'));
        $this->assertSame('¡Hola Juan! Soy Martín, del equipo de ComercioCity.', $servicio->build_presentation_message_body('Juan Pérez'));

        $this->assertSame('¡Hola! Ya te atendemos.', $servicio->build_auto_message_body(null));
        $this->assertSame('¡Hola! Soy Martín, del equipo de ComercioCity.', $servicio->build_welcome_message_body('   '));

        /* El punto único: la plantilla de una variante A/B pasa por acá con el nombre completo. */
        $this->assertSame('Hola Juan, ¿cómo va?', LeadWhatsappOnboardingSettings::apply_nombre_placeholder('Hola {nombre}, ¿cómo va?', 'Juan Pérez'));
    }
}
