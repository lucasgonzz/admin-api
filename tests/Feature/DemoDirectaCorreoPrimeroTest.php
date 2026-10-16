<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
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
     * @param string $api_key Clave de la demo, o '' para una instancia sin clave.
     *
     * @return Demo
     */
    private function crear_demo(string $api_key = 'clave-de-la-demo'): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->api_key           = $api_key;
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
     * @param string|null $email    Correo del lead, o null si no lo tenemos.
     * @param string      $telefono Teléfono del lead.
     *
     * @return Lead
     */
    private function crear_lead(?string $email = null, string $telefono = '5493511234567'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = $telefono;
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
        $this->assertStringContainsString('no inventes hora ni fecha, el mail trae las reales', $bloque);
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
    // 5. Lo que encontró el chequeo independiente
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 El pedido del mail devuelve el estado que el lead ya tenía, NO `demo_agendada`: todavía no hay demo
     * asignada, y un `demo_agendada` sin `agendar_demo` retiene el mensaje para revisión humana.
     *
     * @return void
     */
    public function test_el_pedido_del_mail_no_cambia_el_estado_a_demo_agendada(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('estado_sugerido igual al que ya tenia ("calificado"), NO demo_agendada', $bloque);
    }

    /**
     * 🔴 El pedido del mail no pasa NI EL LINK DE LA PÁGINA, aunque la línea de su página diga que va "en el
     * mensaje del sí" (lead de la web).
     *
     * @return void
     */
    public function test_el_pedido_del_mail_pisa_la_regla_de_la_pagina(): void
    {
        $this->crear_demo();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('NO pases ningun link (ni el de', $bloque);
        $this->assertStringContainsString('esto pisa lo que diga la linea de su pagina', $bloque);
    }

    /**
     * 🔴 Sin instancia para asignar no se pide el mail: la reserva no existe, y el lead daría un correo para
     * una demo que no sale.
     *
     * @return void
     */
    public function test_sin_instancia_libre_no_se_pide_el_mail(): void
    {
        // Ninguna demo cargada en ESTE test: el único dato es el que se crea; se la ocupa con otro lead.
        $demo  = $this->crear_demo();
        $otro  = $this->crear_lead(null, '5493511230000');
        $otro->status          = 'demo_agendada';
        $otro->demo_id         = $demo->id;
        $otro->demo_date       = '2026-10-13';
        $otro->demo_start_time = '10:10';
        $otro->demo_end_time   = '12:10';
        $otro->save();

        $bloque = $this->contexto($this->crear_lead());

        $this->assertStringContainsString('es un SI, pero ahora NO se', $bloque);
        $this->assertStringContainsString('no pidas el mail, no pases ningun link y no devuelvas', $bloque);
        $this->assertStringNotContainsString('es lo PRIMERO que se pide', $bloque);
    }

    /**
     * 🔴 Ya se le pidió el correo dos veces y no lo dio: el servidor lo cuenta y la regla pasa a "links igual",
     * sin depender de que el modelo cuente. Con una sola vez, sigue pidiéndolo.
     *
     * @return void
     */
    public function test_a_la_segunda_vez_sin_correo_se_siguen_pasando_los_links(): void
    {
        $this->crear_demo();
        $lead = $this->crear_lead();

        $this->registrar_saliente($lead, 'Genial. ¿A qué mail te mando todo?', 20);
        $this->assertStringContainsString('es lo PRIMERO que se pide', $this->contexto($lead), 'Con UN pedido sin respuesta todavía se vuelve a pedir.');

        $this->registrar_saliente($lead, 'Te lo pido porque ahí te llega el día y la hora. ¿Me pasás tu mail?', 10);
        $bloque = $this->contexto($lead);

        $this->assertStringContainsString('YA LE PEDISTE EL', $bloque);
        $this->assertStringContainsString('2 VECES', $bloque);
        $this->assertStringContainsString('NO se lo pidas de nuevo', $bloque);
        $this->assertStringContainsString('Devolve agendar_demo: {"ahora": true} ahora', $bloque);
        $this->assertStringContainsString('devolve guardar_email con ese correo Y agendar_demo juntos', $bloque, 'Si el mail llega justo después de la explicación, no se pierde.');
        $this->assertStringNotContainsString('es lo PRIMERO que se pide', $bloque);
    }

    /**
     * Una mención del mail que NO pregunta ("te mandé todo por mail") no cuenta como pedido: dos de esas no
     * dejan al agente sin pedir el correo.
     *
     * @return void
     */
    public function test_una_mencion_del_mail_sin_pregunta_no_cuenta_como_pedido(): void
    {
        $this->crear_demo();
        $lead = $this->crear_lead();

        $this->registrar_saliente($lead, 'Ya te mandé la propuesta por mail.', 20);
        $this->registrar_saliente($lead, 'Te mando todo por correo cuando terminemos.', 10);

        $this->assertStringContainsString('es lo PRIMERO que se pide', $this->contexto($lead));
    }

    /**
     * 🔴 Los ejemplos que el agente copia no traen "en diez minutos": el sistema lo toma por un horario concreto
     * y retiene el mensaje del `agendar_demo`.
     *
     * @return void
     */
    public function test_el_mensaje_del_agendar_demo_no_lleva_minutos(): void
    {
        $this->crear_demo();

        foreach ([null, 'guillermo@ejemplo.test'] as $email) {
            $bloque = $this->contexto($this->crear_lead($email));

            $this->assertStringContainsString('NO escribas "en diez minutos" ni "en unos minutos"', $bloque);
        }
    }

    /**
     * 🔴 Si el lead no es argentino, o la demo no tiene clave, el agente no menciona el asistente aunque el
     * número esté listo.
     *
     * @return void
     */
    public function test_el_asistente_no_se_ofrece_a_un_lead_extranjero_ni_sobre_una_demo_sin_clave(): void
    {
        $this->dejar_listo_el_asistente();

        $this->crear_demo();
        $extranjero = $this->contexto($this->crear_lead(null, '12015550123'));
        $this->assertStringContainsString('NO DISPONIBLE todavia', $extranjero);

        Demo::query()->update(['api_key' => '']);
        $sin_clave = $this->contexto($this->crear_lead());
        $this->assertStringContainsString('NO DISPONIBLE todavia', $sin_clave);
    }

    /**
     * Una demo vencida y sin correo también pide el mail primero (la instancia se vuelve a asignar).
     *
     * @return void
     */
    public function test_con_la_demo_vencida_y_sin_correo_se_pide_el_mail_primero(): void
    {
        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $lead->status          = 'demo_agendada';
        $lead->demo_id         = $demo->id;
        $lead->demo_date       = '2026-10-12';
        $lead->demo_start_time = '10:10';
        $lead->demo_end_time   = '12:10';
        $lead->save();

        $bloque = $this->contexto($lead->refresh());

        $this->assertStringContainsString('VENCIÓ', $bloque);
        $this->assertStringContainsString('es lo PRIMERO que se pide', $bloque);
    }

    /**
     * Un mensaje saliente del agente, ya enviado, para que cuente como un pedido del correo.
     *
     * @param Lead   $lead
     * @param string $texto
     * @param int    $hace_minutos
     *
     * @return void
     */
    private function registrar_saliente(Lead $lead, string $texto, int $hace_minutos): void
    {
        LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'content'         => $texto,
            'status'          => 'enviado',
            'is_followup'     => false,
            'is_status_event' => false,
            'sent_at'         => Carbon::now()->subMinutes($hace_minutos),
            'created_at'      => Carbon::now()->subMinutes($hace_minutos),
        ]);
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
