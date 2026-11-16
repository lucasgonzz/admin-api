<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Client;
use App\Services\ImplementationActionService;
use App\Services\ImplementationConversationService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSendService;
use Mockery;
use ReflectionMethod;

/**
 * Regla de Lucas del 16/11/2026 aplicada al flujo de IMPLEMENTACIÓN: todo mensaje al cliente saluda por el primer
 * nombre de la PERSONA (`clients.name`, nunca `company_name`) y firma «Martín» (nunca el admin asignado).
 *
 * Cubre la plantilla `cc_implementacion_bienvenida` ({{1}}), su texto equivalente, los textos libres de las etapas
 * (1, 2, 4, 5 y 7) y el nombre que usa el panel en modo manual.
 */
class MensajesDeImplementacionConPrimerNombreYFirmaDeMartinTest extends BaseDeImplementaciones
{
    /**
     * Los envíos de texto libre que hizo el servicio: [teléfono, cuerpo].
     *
     * @var array<int, array>
     */
    private $textos = [];

    /**
     * Las plantillas que mandó: [teléfono, nombre de plantilla, variables].
     *
     * @var array<int, array>
     */
    private $plantillas = [];

    /**
     * @var ImplementationConversationService
     */
    private $servicio;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->textos     = [];
        $this->plantillas = [];

        $envio = Mockery::mock(WhatsappSendService::class);
        $envio->shouldReceive('send_text')->andReturnUsing(function ($telefono, $cuerpo) {
            $this->textos[] = [(string) $telefono, (string) $cuerpo];

            return 'wamid.' . count($this->textos);
        });
        $envio->shouldReceive('send_template')->andReturnUsing(function ($telefono, $plantilla, $variables = []) {
            $this->plantillas[] = [(string) $telefono, (string) $plantilla, $variables];

            return 'wamid.t' . count($this->plantillas);
        });

        $this->servicio = new ImplementationConversationService($envio);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Llama a un método privado.
     *
     * @param object $objeto Servicio.
     * @param string $metodo Método.
     * @param array  $args   Argumentos.
     *
     * @return mixed
     */
    private function privado($objeto, string $metodo, array $args = [])
    {
        $m = new ReflectionMethod($objeto, $metodo);
        $m->setAccessible(true);

        return $m->invokeArgs($objeto, $args);
    }

    /**
     * Cliente con persona y negocio distintos (la persona cargada en mayúsculas, como en producción).
     *
     * @return Client
     */
    private function cliente_de_plazoleta(): Client
    {
        return $this->crear_cliente('SEBASTIÁN Gómez', ['company_name' => 'Ferretería plazoleta']);
    }

    /**
     * @return void
     */
    public function test_la_plantilla_de_bienvenida_lleva_el_primer_nombre_y_no_el_negocio(): void
    {
        $impl = $this->crear_implementacion($this->cliente_de_plazoleta());

        $this->servicio->send_welcome_template($impl);

        $this->assertCount(1, $this->plantillas);
        $this->assertSame('cc_implementacion_bienvenida', $this->plantillas[0][1]);
        $this->assertSame(['Sebastián'], $this->plantillas[0][2]);
    }

    /**
     * @return void
     */
    public function test_el_texto_equivalente_de_la_bienvenida_saluda_por_el_primer_nombre_y_firma_martin(): void
    {
        $impl  = $this->crear_implementacion($this->cliente_de_plazoleta());
        $texto = $this->servicio->build_welcome_body($impl);

        $this->assertStringStartsWith('Hola Sebastián, ¿cómo estás?', $texto);
        $this->assertStringContainsString('Soy Martín', $texto);
        $this->assertStringNotContainsString('plazoleta', mb_strtolower($texto));
    }

    /**
     * Sin nombre de persona nunca se cae al negocio.
     *
     * @return void
     */
    public function test_sin_nombre_de_persona_saluda_al_equipo_y_no_al_negocio(): void
    {
        $client       = $this->cliente_de_plazoleta();
        $client->name = '';
        $client->save();
        $impl = $this->crear_implementacion($client->refresh());

        $this->assertStringStartsWith('Hola equipo, ', $this->servicio->build_welcome_body($impl));

        $this->servicio->send_welcome_template($impl);
        $this->assertSame(['equipo'], $this->plantillas[0][2]);
    }

    /**
     * El panel en modo manual manda la misma plantilla con el nombre de `resolve_client_name()`.
     *
     * @return void
     */
    public function test_el_panel_manual_pasa_el_primer_nombre_a_la_plantilla(): void
    {
        $impl     = $this->crear_implementacion($this->cliente_de_plazoleta());
        $acciones = new ImplementationActionService($this->servicio);

        $this->assertSame('Sebastián', $this->privado($acciones, 'resolve_client_name', [$impl]));
    }

    /**
     * @return void
     */
    public function test_el_saludo_de_la_etapa_1_firma_martin_aunque_haya_otro_admin_asignado(): void
    {
        $admin       = $this->crear_admin();
        $admin->name = 'Carlos Otro';
        $admin->save();
        $client = $this->cliente_de_plazoleta();
        $impl   = $this->crear_implementacion($client, ['assigned_admin_id' => $admin->id]);

        $saludo = $this->privado($this->servicio, 'build_stage_1_greeting', [$impl, $client]);

        $this->assertStringStartsWith('Hola Sebastián! Soy Martín,', $saludo);
        $this->assertStringNotContainsString('Carlos', $saludo);
    }

    /**
     * Los textos de «te va a responder / va a revisar…» usan `resolve_assigned_admin_name()`.
     *
     * @return void
     */
    public function test_el_nombre_del_admin_asignado_ya_no_se_usa_para_firmar(): void
    {
        $admin       = $this->crear_admin();
        $admin->name = 'Carlos Otro';
        $admin->save();
        $impl = $this->crear_implementacion($this->cliente_de_plazoleta(), ['assigned_admin_id' => $admin->id]);

        $this->assertSame('Martín', $this->privado($this->servicio, 'resolve_assigned_admin_name', [$impl]));

        $sin_asignar = $this->crear_implementacion($this->crear_cliente('Ana Pérez'));
        $this->assertSame('Martín', $this->privado($this->servicio, 'resolve_assigned_admin_name', [$sin_asignar]));
    }

    /**
     * @return void
     */
    public function test_los_pasos_de_afip_saludan_al_responsable_por_su_primer_nombre_y_firman_martin(): void
    {
        $client = $this->cliente_de_plazoleta();
        $impl   = $this->llevar_a_la_etapa($this->crear_implementacion($client), 7);
        $stage  = $impl->stages()->where('stage_number', 7)->first();

        $data = ['afip_contact_name' => 'María Laura Díaz', 'afip_contact_phone' => '+5493415550001'];
        $this->privado($this->servicio, 'execute_afip_steps_sent', [$impl, $stage, $data, '', 'Ferretería plazoleta', 'Martín']);

        $enviados = array_column($this->textos, 1, 0);
        $this->assertArrayHasKey('+5493415550001', $enviados);
        $this->assertStringStartsWith('Hola María! Soy Martín de ComercioCity.', $enviados['+5493415550001']);
    }

    /**
     * El dueño que se hace cargo guarda SU nombre de persona, no el del negocio.
     *
     * @return void
     */
    public function test_el_responsable_que_es_el_dueno_se_guarda_con_su_nombre_de_persona(): void
    {
        $client = $this->cliente_de_plazoleta();

        $this->assertSame('SEBASTIÁN Gómez', $this->privado($this->servicio, 'owner_person_name', [$client, 'x']));
        $this->assertSame('x', $this->privado($this->servicio, 'owner_person_name', [null, 'x']));
    }

    /**
     * Entrega del sistema y checklist de progreso: se le habla a la persona.
     *
     * @return void
     */
    public function test_la_entrega_y_el_progreso_hablan_a_la_persona_y_no_al_negocio(): void
    {
        $impl = $this->llevar_a_la_etapa($this->crear_implementacion($this->cliente_de_plazoleta()), 5);

        $entrega = $this->servicio->build_delivery_body($impl);
        $this->assertStringContainsString('Sebastián, ¡tu sistema ya está listo', $entrega);
        $this->assertStringNotContainsString('Ferretería plazoleta, ¡', $entrega);

        $progreso = $this->servicio->build_progress_body($impl, 1);
        $this->assertStringContainsString('¡Perfecto, Sebastián!', $progreso);
    }

    /**
     * @return void
     */
    public function test_el_primer_nombre_de_un_empleado_sale_del_helper_compartido(): void
    {
        $this->assertSame('Julieta', SupportWhatsappOpenerService::primer_nombre('Julieta Roca'));
        $this->assertSame('equipo', SupportWhatsappOpenerService::primer_nombre(''));
    }
}
