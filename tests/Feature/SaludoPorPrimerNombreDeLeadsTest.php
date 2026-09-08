<?php

namespace Tests\Feature;

use App\Http\Controllers\LeadController;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El lead se saluda por el primer nombre, no por el nombre completo con apellido (decisión de
 * Lucas, 8/9/2026): "Hola Guillermo" en vez de "Hola Guillermo González".
 *
 * Cubre a fondo el accessor centralizado `Lead::getContactFirstNameAttribute()` (misión
 * saludo-primer-nombre-leads) y, de punta a punta, uno de los call sites reales que lo consume
 * (el botón manual "check de fin de demo" de LeadController, que espeja al scheduler automático
 * CheckDemoFin y crea el mensaje sugerido que revisa el setter antes de que salga).
 *
 * 🔴 NO cubre `LeadFollowupService::send_followup_via_template()` ni `LeadSuggestionSendService`
 * (el envío automático de seguimientos y el que se aprueba desde el panel tras supervisión de
 * agendamiento). Esos dos arman el `{{1}}` de la plantilla a través de
 * `LeadFollowupService::resolve_contact_name_variable()`, un método centralizado que ya existía
 * antes de esta misión (introducido el 27/8/2026 para el bug de Meta #131008 — ver
 * SeguimientoConVariableVaciaTest) y que hoy sigue leyendo `contact_name` completo, no
 * `contact_first_name`. La misión encontró que el plan original asumía un patrón inline
 * (`$contact_name = $lead->contact_name ?? '';`) que ya no existe en ese archivo — el código
 * evolucionó a este método compartido después de que se escribiera el plan — y quedó reportado
 * como hallazgo en vez de tocado a ciegas.
 */
class SaludoPorPrimerNombreDeLeadsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead de prueba con el contact_name que pida cada caso.
     *
     * @param string|null $contact_name Nombre del contacto (null, '' o '   ' para los casos límite).
     *
     * @return Lead
     */
    private function crear_lead(?string $contact_name): Lead
    {
        $lead                  = new Lead();
        $lead->phone           = '+5493417778899';
        $lead->contact_name    = $contact_name;
        $lead->status          = 'demo_agendada';
        $lead->demo_start_time = '15:00';
        $lead->save();

        return $lead;
    }

    /**
     * Caso normal: nombre y apellido, se queda solo con la primera palabra.
     *
     * @return void
     */
    public function test_nombre_y_apellido_devuelve_solo_el_primer_nombre()
    {
        $lead = $this->crear_lead('Guillermo González');

        $this->assertSame('Guillermo', $lead->contact_first_name);
    }

    /**
     * Nombre de una sola palabra: se devuelve tal cual, sin romper.
     *
     * @return void
     */
    public function test_una_sola_palabra_se_devuelve_tal_cual()
    {
        $lead = $this->crear_lead('Guillermo');

        $this->assertSame('Guillermo', $lead->contact_first_name);
    }

    /**
     * String vacío: el accessor devuelve '', no null ni el genérico de ningún call site (eso lo
     * decide cada fallback `?? 'X'` existente, no el accessor).
     *
     * @return void
     */
    public function test_nombre_vacio_devuelve_vacio()
    {
        $lead = $this->crear_lead('');

        $this->assertSame('', $lead->contact_first_name);
    }

    /**
     * Null: el accessor devuelve null, preservando la nulabilidad de contact_name para que los
     * `?? 'fallback'` de cada call site sigan funcionando igual que con contact_name.
     *
     * @return void
     */
    public function test_nombre_null_devuelve_null()
    {
        $lead = $this->crear_lead(null);

        $this->assertNull($lead->contact_first_name);
    }

    /**
     * Nombre compuesto: "María José García" saluda como "María", que es un saludo aceptable
     * (decisión de enfoque del plan: split simple, sin IA).
     *
     * @return void
     */
    public function test_nombre_compuesto_devuelve_solo_la_primera_palabra()
    {
        $lead = $this->crear_lead('María José García');

        $this->assertSame('María', $lead->contact_first_name);
    }

    /**
     * Nombre de puros espacios: tan inutilizable como vacío, y el accessor lo trata igual (''),
     * no como si fuera un nombre real de una palabra rara.
     *
     * @return void
     */
    public function test_nombre_de_puros_espacios_devuelve_vacio()
    {
        $lead = $this->crear_lead('   ');

        $this->assertSame('', $lead->contact_first_name);
    }

    /**
     * Caso de uso real de punta a punta: el botón manual "check de fin de demo" tiene que guardar
     * en el hilo un saludo con el primer nombre, no el completo.
     *
     * @return void
     */
    public function test_el_check_de_fin_de_demo_manual_saluda_por_el_primer_nombre()
    {
        $lead = $this->crear_lead('Guillermo González');

        app(LeadController::class)->check_demo_fin_json($lead->id);

        $mensaje = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('status', 'sugerido')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($mensaje, 'El check de fin de demo tenía que crear un mensaje sugerido.');
        $this->assertStringContainsString('¡Hola Guillermo!', $mensaje->content);
        $this->assertStringNotContainsString('González', $mensaje->content);
    }
}
