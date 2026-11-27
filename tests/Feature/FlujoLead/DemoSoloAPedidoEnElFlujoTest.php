<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La demo sólo a pedido para el lead del flujo de entrada (misión embudo-prueba-martin-precios, criterios CA1 a
 * CA4 del PLAN).
 *
 * Lo que pasó (ensayo 707 del 27/11/2026): el lead del flujo, que tiene que ir a la PRUEBA de 7 días, recibió en el
 * prompt el bloque DEMO DIRECTA (le dicta pasar su página `/experiencia/<tel>` y calificarlo) y la nota del system
 * prompt sobre la dinámica de la demo directa; Martín le mandó la página y le preguntó si usaba algún sistema. Lo
 * pedido: mientras el lead esté en el flujo y en un estado sin demo, ni el link de la página ni el bloque de la
 * demo entran al prompt (entra un bloque corto «DEMO SÓLO A PEDIDO»); la demo vuelve en cuanto pide disponibilidad,
 * y fuera del flujo (apagado, o lead anterior al encendido) nada cambia. El seguimiento «pedí la demo» de
 * `leads:check-pagina-sin-demo` tampoco le llega al lead del flujo.
 *
 * Cómo se prueba: el turno completo con `generate_suggestion` real y la IA simulada (`Http::fake` sobre
 * api.anthropic.com), leyendo del pedido que salió el system prompt y el contenido de usuario. `v2/demo_agenda` se
 * siembra como `SyncedGithubFile` con el marcador `demo_directa`, que es lo que activa la demo directa en producción.
 *
 * Hoy están EN ROJO los que describen lo nuevo (CA1, CA2, CA4 «no llega»); CA3 y CA4 «fuera del flujo sale» son de
 * no-regresión y pasan ya.
 */
class DemoSoloAPedidoEnElFlujoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Frase que identifica el bloque de la demo directa en el contexto de usuario. */
    private const BLOQUE_DEMO_DIRECTA = 'DEMO DIRECTA';

    /** Título del bloque de la página de acceso (demo sin demo directa). */
    private const BLOQUE_PAGINA_DE_ACCESO = 'PAGINA DE ACCESO A LA DEMO';

    /** Línea de la calificación mínima del bloque de la demo directa. */
    private const CALIFICACION_MINIMA = 'Calificacion minima';

    /** Nota del system prompt de la demo directa. */
    private const NOTA_DEL_SYSTEM = 'DINÁMICA DE DEMO DIRECTA';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();

        /* La demo directa se activa por el marcador dentro de `v2/demo_agenda` (como en producción). */
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => LeadAiService::MARCADOR_DEMO_DIRECTA,
            'synced_at' => now(),
        ]);

        /* Frases del botón de la demo como están en producción, para que el entorno no dependa de la base del slot. */
        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS, json_encode([
            'Hola Martín, quiero hacer la demo de ComercioCity',
            'Hola, quiero hacer la demo de ComercioCity',
            'Hola Martín, vengo de Instagram y quiero hacer la demo de ComercioCity',
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // CA1 y CA2 — el lead del flujo no recibe la demo
    // ------------------------------------------------------------------------------------------

    /**
     * CA1. Lead del flujo (creado después del encendido, sin demo) en un estado sin demo: el contenido de usuario
     * que sale hacia la IA NO lleva el link de su página (`/experiencia/`), ni el bloque DEMO DIRECTA, ni el de la
     * página de acceso, ni la calificación mínima; SÍ lleva PRECIOS VIGENTES, el bloque «DEMO SÓLO A PEDIDO» y, donde
     * el flujo da su bloque, FLUJO DE ENTRADA.
     *
     * @dataProvider provider_de_estados_sin_demo
     *
     * @param string $estado Estado del lead.
     * @param bool   $lleva_el_bloque_del_flujo Si en ese estado el bloque FLUJO DE ENTRADA se inyecta hoy
     *                                          (`ESTADOS_CON_EL_BLOQUE` no incluye `implementacion_pedida`).
     *
     * @return void
     */
    public function test_el_lead_del_flujo_no_recibe_la_pagina_ni_la_demo(string $estado, bool $lleva_el_bloque_del_flujo): void
    {
        $lead = $this->lead_del_flujo_con_un_mensaje($estado);

        $contexto = $this->contexto_enviado_a_la_ia($lead);

        $this->assertStringNotContainsString('/experiencia/', $contexto, 'La página del lead no se le pasa a quien está en el flujo.');
        $this->assertStringNotContainsString(self::BLOQUE_DEMO_DIRECTA, $contexto);
        $this->assertStringNotContainsString(self::BLOQUE_PAGINA_DE_ACCESO, $contexto);
        $this->assertStringNotContainsString(self::CALIFICACION_MINIMA, $contexto, 'Con la prueba como camino no se califica con la pregunta del sistema actual.');

        $this->assertStringContainsString('PRECIOS VIGENTES (fuente: admin', $contexto);
        $this->assertStringContainsString('DEMO SÓLO A PEDIDO', $contexto, 'Entra el bloque corto que dice qué hacer si pide la demo con sus palabras.');
        if ($lleva_el_bloque_del_flujo) {
            $this->assertStringContainsString('FLUJO DE ENTRADA', $contexto);
        }
    }

    /**
     * Estados del embudo en los que el lead no tiene demo. `implementacion_pedida` no recibe hoy el bloque del flujo
     * (`ESTADOS_CON_EL_BLOQUE`); ahí se verifica todo lo demás, y el PLAN §6 CA1 lo pide igual: queda anotado en
     * Pendientes.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public function provider_de_estados_sin_demo(): array
    {
        return [
            'nuevo'                 => ['nuevo', true],
            'contactado'            => ['contactado', true],
            'calificado'            => ['calificado', true],
            'link_enviado'          => ['link_enviado', true],
            'implementacion_pedida' => ['implementacion_pedida', false],
        ];
    }

    /**
     * CA2. Mismo lead (`contactado`): el system prompt que sale hacia la IA no lleva la nota de la dinámica de la
     * demo directa.
     *
     * @return void
     */
    public function test_el_system_prompt_no_trae_la_nota_de_la_demo_directa(): void
    {
        $lead = $this->lead_del_flujo_con_un_mensaje('contactado');

        $this->generar_sugerencia($lead);

        $this->assertStringNotContainsString(self::NOTA_DEL_SYSTEM, $this->system_enviado_a_la_ia());
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — la demo sigue para quien la pide y fuera del flujo
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. El mismo lead, en `solicita_disponibilidad` (pidió la demo con sus palabras y el sistema lo pasó de
     * estado), vuelve a recibir el bloque DEMO DIRECTA con el link de su página, y el system prompt la nota de la
     * demo directa. Es de no-regresión: pasa ya y tiene que seguir pasando.
     *
     * @return void
     */
    public function test_con_disponibilidad_pedida_vuelve_la_demo_directa(): void
    {
        $lead = $this->lead_del_flujo_con_un_mensaje('solicita_disponibilidad');

        $contexto = $this->contexto_enviado_a_la_ia($lead);

        $this->assertStringContainsString(self::BLOQUE_DEMO_DIRECTA, $contexto);
        $this->assertStringContainsString('/experiencia/', $contexto);
        $this->assertStringContainsString(self::NOTA_DEL_SYSTEM, $this->system_enviado_a_la_ia());
        $this->assertStringNotContainsString('DEMO SÓLO A PEDIDO', $contexto, 'El bloque corto es sólo para quien todavía no pidió la demo.');
    }

    /**
     * CA3. Con el flujo APAGADO, un lead `contactado` recibe la demo directa como hoy (bloque, página y nota del
     * system), sin el bloque «DEMO SÓLO A PEDIDO». Es de no-regresión: pasa ya.
     *
     * @return void
     */
    public function test_con_el_flujo_apagado_todo_igual(): void
    {
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
        $lead = $this->lead_del_flujo_con_un_mensaje('contactado');

        $contexto = $this->contexto_enviado_a_la_ia($lead);

        $this->assertStringContainsString(self::BLOQUE_DEMO_DIRECTA, $contexto);
        $this->assertStringContainsString('/experiencia/', $contexto);
        $this->assertStringContainsString(self::CALIFICACION_MINIMA, $contexto);
        $this->assertStringContainsString(self::NOTA_DEL_SYSTEM, $this->system_enviado_a_la_ia());
        $this->assertStringNotContainsString('DEMO SÓLO A PEDIDO', $contexto);
    }

    /**
     * CA3. Un lead creado ANTES del encendido del flujo (no está en el flujo) recibe la demo directa como hoy.
     * Es de no-regresión: pasa ya.
     *
     * @return void
     */
    public function test_lead_anterior_al_encendido_todo_igual(): void
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        Lead::query()->where('id', $lead->id)->update(['created_at' => now()->subDays(3)]);
        $this->mensaje_del_lead($lead, 'Hola, quiero más información');

        $contexto = $this->contexto_enviado_a_la_ia($lead->fresh());

        $this->assertStringContainsString(self::BLOQUE_DEMO_DIRECTA, $contexto);
        $this->assertStringContainsString('/experiencia/', $contexto);
        $this->assertStringContainsString(self::NOTA_DEL_SYSTEM, $this->system_enviado_a_la_ia());
        $this->assertStringNotContainsString('DEMO SÓLO A PEDIDO', $contexto);
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — el seguimiento «pedí la demo»
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. `leads:check-pagina-sin-demo` no le manda el «pedí la demo» a un lead del flujo en `contactado` que abrió
     * su página hace más de N minutos (la recibió antes del arreglo, como el 707): no sale nada y no se lo marca.
     *
     * @return void
     */
    public function test_el_seguimiento_de_la_pagina_no_le_llega_al_lead_del_flujo(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->lead_listo_para_el_seguimiento_de_la_pagina();

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(0, $espia->textos, 'Al lead del flujo no se le manda «te preparo la demo».');
        $this->assertNull($lead->fresh()->pagina_seguimiento_enviado_at);
    }

    /**
     * CA4 (control). El mismo lead pero FUERA del flujo (creado antes del encendido) sí recibe el seguimiento, una
     * sola vez, como hoy: prueba que el armado del test es válido y que el comando no se rompió para el resto. Pasa ya.
     *
     * @return void
     */
    public function test_fuera_del_flujo_el_seguimiento_sale(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->lead_listo_para_el_seguimiento_de_la_pagina();
        Lead::query()->where('id', $lead->id)->update(['created_at' => now()->subDays(3)]);

        $this->artisan('leads:check-pagina-sin-demo')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'Fuera del flujo el seguimiento sale igual que hoy.');
        $this->assertNotNull($lead->fresh()->pagina_seguimiento_enviado_at);
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Lead del flujo (creado con el interruptor prendido, sin demo) en el estado pedido y con un mensaje suyo.
     *
     * @param string $estado Estado del lead.
     *
     * @return Lead
     */
    private function lead_del_flujo_con_un_mensaje(string $estado): Lead
    {
        $lead = $this->crear_lead_de_flujo($estado);
        $this->mensaje_del_lead($lead, 'Hola, quiero más información');

        return $lead->fresh();
    }

    /**
     * Corre `generate_suggestion` real con una respuesta simulada de la IA.
     *
     * @param Lead $lead
     *
     * @return LeadMessage
     */
    private function generar_sugerencia(Lead $lead): LeadMessage
    {
        $this->fakear_ia([$this->paquete_ia('Te cuento.', $lead->status)]);

        return app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);
    }

    /**
     * Genera el turno y devuelve el contenido de usuario que salió hacia la IA.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto_enviado_a_la_ia(Lead $lead): string
    {
        $this->generar_sugerencia($lead);

        $pedido = json_decode($this->pedidos_a_la_ia[0], true);
        $this->assertIsArray($pedido, 'Precondición: el pedido a la IA es un JSON.');

        $contenido = $pedido['messages'][0]['content'];

        return is_array($contenido) ? json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $contenido;
    }

    /**
     * El system prompt del primer pedido que salió hacia la IA.
     *
     * @return string
     */
    private function system_enviado_a_la_ia(): string
    {
        $pedido = json_decode($this->pedidos_a_la_ia[0], true);
        $system = $pedido['system'];

        if (is_array($system)) {
            $textos = [];
            foreach ($system as $bloque) {
                $textos[] = isset($bloque['text']) ? (string) $bloque['text'] : '';
            }

            return implode("\n", $textos);
        }

        return (string) $system;
    }

    /**
     * Lead `contactado` del flujo que abrió su página hace 130 minutos, con el último mensaje del hilo nuestro, en
     * silencio, con la ventana de 24 hs abierta y las perillas del comando fijas: exactamente el candidato del
     * seguimiento (mismas condiciones que `CheckPaginaSinDemoTest`).
     *
     * @return Lead
     */
    private function lead_listo_para_el_seguimiento_de_la_pagina(): Lead
    {
        AdminSetting::set(LeadDemoSettings::KEY_PAGINA_SEGUIMIENTO_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_CHECK_INGRESO_SILENCIO_MINUTOS, '10');
        DemoEventoRecibido::query()->where('nombre', 'pagina_abierta_sin_turno')->delete();

        $lead = $this->crear_lead_de_flujo('contactado');
        Lead::query()->where('id', $lead->id)->update([
            'demo_experiencia'              => Lead::EXPERIENCIA_NUEVA,
            'tiene_sugerencia_pendiente'    => false,
            'automatizaciones_demo_activas' => true,
        ]);

        $this->fijar_mensaje($this->mensaje_del_lead($lead, 'Hola, quiero más información'), now()->subMinutes(200));
        $this->fijar_mensaje($this->mensaje_de_sistema($lead, 'Te dejo tu página.'), now()->subMinutes(190));

        DemoEventoRecibido::create([
            'lead_id'     => $lead->id,
            'uuid'        => (string) Str::uuid(),
            'nombre'      => 'pagina_abierta_sin_turno',
            'clip_id'     => null,
            'ocurrido_at' => now()->subMinutes(130),
            'datos'       => null,
        ]);

        return $lead->fresh();
    }

    /**
     * Fija la fecha de creación de un mensaje del hilo.
     *
     * @param LeadMessage $mensaje
     * @param \Carbon\Carbon $cuando
     *
     * @return void
     */
    private function fijar_mensaje(LeadMessage $mensaje, $cuando): void
    {
        LeadMessage::query()->where('id', $mensaje->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);
    }
}
