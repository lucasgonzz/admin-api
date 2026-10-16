<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\WhatsappConfig;
use App\Services\AsistenteDemoLeadService;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El correo se pide ANTES de los links (misión mail-demo-turno-y-asistente, 16/10/2026, decisión de
 * Lucas) y el agente solo habla del asistente personal si está disponible.
 *
 * Lo que se prueba es el bloque `DEMO DIRECTA` del contexto del turno, que es la orden que recibe el
 * agente (y la que manda sobre los recursos). Lo que hace el modelo con esa orden no se puede probar
 * acá: se midió aparte, contra el proveedor real (ver el informe de la misión).
 *
 * Cuatro situaciones, que son las cuatro ramas de `build_demo_directa_context()`:
 *
 *   1. **Sin correo y sin demo**: el "sí" pide el mail primero; ni links ni `agendar_demo`.
 *   2. **Con correo y sin demo**: el "sí" asigna ya y avisa a qué casilla va todo.
 *   3. **Demo ya asignada**: no se vuelve a agendar; el correo suelto sigue como siempre.
 *   4. **La línea del asistente**: DISPONIBLE solo con el número listo.
 */
class DemoDirectaCorreoPrimeroTest extends TestCase
{
    use DatabaseTransactions;

    /** Zona de la demo. */
    const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * Reloj fijo (martes 13/10/2026 10:00) y las settings de la reserva de dos horas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', self::TZ));

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * El bloque DEMO DIRECTA del contexto (método privado, por reflexión como los demás tests).
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto(Lead $lead): string
    {
        $metodo = new \ReflectionMethod(LeadAiService::class, 'build_demo_directa_context');
        $metodo->setAccessible(true);

        return (string) $metodo->invoke(new LeadAiService(), $lead);
    }

    /**
     * Una instancia de demo con sus cuatro URLs.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-correo.test';
        $demo->erp_api_url       = 'https://demo-correo-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-correo.test';
        $demo->ecommerce_api_url = 'https://tienda-correo-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead de la dinámica nueva con teléfono, sin demo asignada.
     *
     * @param string|null $email Correo del lead, o null si no lo tenemos.
     *
     * @return Lead
     */
    private function crear_lead(?string $email = null): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '5493511234567';
        $lead->email        = $email;
        $lead->status       = 'calificado';
        $lead->save();

        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Deja el número del asistente listo para atender.
     *
     * @return void
     */
    private function dejar_listo_el_asistente(): void
    {
        $config = new WhatsappConfig();
        $config->forceFill([
            'proposito'       => WhatsappConfig::PROPOSITO_ASISTENTE,
            'kapso_api_key'   => 'clave-kapso-del-asistente',
            'phone_number_id' => '5550001112223',
            'webhook_secret'  => 'secreto-del-asistente',
            'display_phone'   => '+1 201-534-4158',
            'is_active'       => true,
            'test_mode'       => false,
        ]);
        $config->save();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Sin correo y sin demo: el mail va primero
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Sin correo, el "sí" pide el mail PRIMERO: sin `agendar_demo`, sin links y sin hora.
     *
     * @return void
     */
    public function test_sin_correo_el_si_pide_el_mail_antes_de_los_links(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('TODAVIA NO', $bloque);
        $this->assertStringContainsString('SU MAIL y es lo PRIMERO que se pide', $bloque);
        $this->assertStringContainsString('NO devuelvas agendar_demo todavia, NO pases ningun link', $bloque);
        $this->assertStringContainsString('¿a qué mail te mando todo?', $bloque);
        $this->assertStringContainsString('el día y la hora de tu demo', $bloque);
        $this->assertStringContainsString('Email del lead: (no lo tenemos)', $bloque);
    }

    /**
     * 🔴 Y cuando el lead pasa el mail, el paquete lleva `guardar_email` Y `agendar_demo` juntos, con los
     * dos links en el mismo mensaje.
     *
     * @return void
     */
    public function test_con_el_mail_se_devuelven_guardar_email_y_agendar_demo_juntos(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('Cuando acepta hacerla ahora y te pasa su mail', $bloque);
        $this->assertStringContainsString('guardar_email con el correo Y', $bloque);
        $this->assertStringContainsString('agendar_demo: {"ahora": true} EN EL MISMO paquete', $bloque);
        $this->assertStringContainsString('links de arriba (la pagina', $bloque);
        $this->assertStringContainsString('no inventes hora ni fecha, el mail trae las', $bloque);
    }

    /**
     * Si no quiere dar el mail se explica UNA vez para qué sirve y, si insiste, se le pasan los links
     * igual: la demo no se bloquea por el correo.
     *
     * @return void
     */
    public function test_si_se_niega_a_dar_el_mail_se_le_pasan_los_links_igual(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('Si no quiere pasar el mail', $bloque);
        $this->assertStringContainsString('explicale UNA sola vez para que sirve', $bloque);
        $this->assertStringContainsString('devolve agendar_demo: {"ahora": true} y pasale los dos links igual', $bloque);
    }

    /**
     * El pedido del mail no nombra ninguna hora ni promete "dos horas" cuando la ventana va a ser más
     * corta: la hora real recién existe cuando la demo se asigna.
     *
     * @return void
     */
    public function test_el_pedido_del_mail_no_nombra_una_hora(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());
        $regla  = substr($bloque, (int) strpos($bloque, 'TODAVIA NO'));
        $regla  = substr($regla, 0, (int) strpos($regla, 'Para ofrecerla'));

        $this->assertStringContainsString('la tenés reservada dos horas, para que la recorras tranquilo', $regla);
        $this->assertStringNotContainsString('hasta las ', $regla, 'La hora de fin real recién existe al asignar.');
        $this->assertStringContainsString('No digas ninguna hora', $regla);
    }

    /**
     * Cerca de la medianoche la reserva queda recortada y el pedido del mail no promete "dos horas".
     *
     * @return void
     */
    public function test_cerca_de_la_medianoche_el_pedido_del_mail_no_promete_dos_horas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 22:30:00', self::TZ));
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());
        $regla  = substr($bloque, (int) strpos($bloque, 'TODAVIA NO'));
        $regla  = substr($regla, 0, (int) strpos($regla, 'Para ofrecerla'));

        $this->assertStringNotContainsString('reservada dos horas', $regla);
        $this->assertStringContainsString('dura menos de una hora, para que la recorras tranquilo', $regla);
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Con correo y sin demo: se asigna ya
    // ---------------------------------------------------------------------------------------------

    /**
     * Con el correo ya cargado no se lo vuelve a pedir: se asigna y se le dice a qué casilla va todo.
     *
     * @return void
     */
    public function test_con_correo_el_si_asigna_ya_y_nombra_la_casilla(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead('guillermo@ejemplo.test'));

        $this->assertStringNotContainsString('TODAVIA NO', $bloque);
        $this->assertStringNotContainsString('es lo PRIMERO que se pide', $bloque);
        $this->assertStringContainsString('Ya tenemos', $bloque);
        $this->assertStringContainsString('su mail (guillermo@ejemplo.test)', $bloque);
        $this->assertStringContainsString('le mandas todo a ese mail (si es otro, que te avise)', $bloque);
        $this->assertStringContainsString('Cuando acepta hacerla ahora: devolve agendar_demo', $bloque);
        $this->assertStringContainsString('No le pidas el mail: ya lo tenemos', $bloque);
    }

    // ---------------------------------------------------------------------------------------------
    // 3. Demo ya asignada
    // ---------------------------------------------------------------------------------------------

    /**
     * Con la demo YA asignada no hay nada que aceptar: no se vuelve a agendar ni se pide el mail
     * "primero", y el correo suelto sigue como siempre.
     *
     * @return void
     */
    public function test_con_la_demo_ya_asignada_no_se_vuelve_a_agendar(): void
    {
        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $lead->status          = 'demo_agendada';
        $lead->demo_id         = $demo->id;
        $lead->demo_date       = '2026-10-13';
        $lead->demo_start_time = '10:10';
        $lead->demo_end_time   = '12:10';
        $lead->save();

        $bloque = $this->contexto($lead->refresh());

        $this->assertStringContainsString('YA ASIGNADA', $bloque);
        $this->assertStringNotContainsString('es lo PRIMERO que se pide', $bloque);
        $this->assertStringContainsString('no la vuelvas a agendar', $bloque);
        $this->assertStringContainsString('hasta las 12:10', $bloque);
        $this->assertStringContainsString('Cuando el lead pasa su mail con la demo YA asignada', $bloque);
    }

    /**
     * La regla del correo suelto y lo que el mail trae están siempre presentes, en las tres ramas.
     *
     * @return void
     */
    public function test_el_agente_sabe_que_trae_el_mail(): void
    {
        $this->crear_demo();

        foreach ([null, 'guillermo@ejemplo.test'] as $email) {
            $bloque = $this->contexto($this->crear_lead($email));

            $this->assertStringContainsString('trae el DIA Y LA HORA reales de su demo', $bloque);
            $this->assertStringContainsString('una instancia completa con el sistema de gestion, la tienda online y el sistema de agentes', $bloque);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 4. El asistente personal
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Sin el número listo, el agente NO puede prometer el asistente: la línea dice NO DISPONIBLE y
     * le prohíbe nombrarlo. Es el estado de HOY.
     *
     * @return void
     */
    public function test_sin_el_numero_listo_el_agente_no_menciona_el_asistente(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('Asistente personal para probar durante la demo: NO DISPONIBLE todavia', $bloque);
        $this->assertStringContainsString('No lo menciones ni prometas un numero', $bloque);
        $this->assertStringNotContainsString('DISPONIBLE (', $bloque);
        $this->assertStringNotContainsString('+1 201', $bloque);
    }

    /**
     * Con el número listo la línea dice DISPONIBLE, con el número, y limita lo que se puede decir.
     *
     * @return void
     */
    public function test_con_el_numero_listo_el_agente_sabe_que_existe(): void
    {
        $this->dejar_listo_el_asistente();
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('Asistente personal para probar durante la demo: DISPONIBLE (+1 201-534-4158)', $bloque);
        $this->assertStringContainsString('desde este', $bloque);
        $this->assertStringContainsString('No lo ofrezcas', $bloque);
        $this->assertStringNotContainsString('NO DISPONIBLE', $bloque);
    }

    /**
     * El interruptor propio apaga la línea aunque el número esté listo.
     *
     * @return void
     */
    public function test_el_interruptor_apagado_vuelve_a_no_disponible(): void
    {
        $this->dejar_listo_el_asistente();
        AdminSetting::set(AsistenteDemoLeadService::KEY_ACTIVO, 'off');
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('NO DISPONIBLE todavia', $bloque);
    }
}
