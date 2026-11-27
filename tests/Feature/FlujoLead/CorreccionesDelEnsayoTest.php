<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Models\TrialInstance;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Services\AdminTaskAssignmentResolver;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadTareaVendedorService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\PreciosVigentesSettings;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Correcciones del ensayo del 26/11/2026 contra Martín (leads 705 a 709) con el flujo de entrada nuevo prendido
 * (misión martin-correcciones-ensayo, criterios CA1 a CA6 del PLAN).
 *
 * Qué pasó en el ensayo y qué fija cada grupo de tests:
 *  - CA1: Martín dijo «hasta 100 operaciones» (el tope viejo) con los planes de hoy en 200. El bloque PRECIOS VIGENTES
 *    tiene que decir, con UNA línea generada desde los planes guardados, que esos son los únicos topes.
 *  - CA2 (no-regresión): al lead del flujo que ya dijo su rubro se le pasó el link de la demo vieja (`/experiencia/`).
 *    Eso ya está corregido en master (DemoSoloAPedidoEnElFlujoTest); acá queda el caso exacto del turno de Rubén.
 *  - CA3: al que pidió la carga gratis Martín le ofreció «te doy una mano para subirlo». Con el flujo, la carga la
 *    hace el lead solo (video dentro de la prueba) o la hacemos nosotros como implementación paga.
 *  - CA4: «¿cómo arranco?» recibió comerciocity.com y no el link de la prueba.
 *  - CA5: el simulador del panel (`simulate_inbound_json`) no le pasaba el primer mensaje al onboarding, así que el
 *    lead simulado del flujo recibía el auto genérico y no la presentación del flujo.
 *  - CA6: la tarea del embudo salió con Thomas (vendedor, id 3) como responsable principal y no con Juan (perfil
 *    Sólo Leads, id 7): `for_embudo_task()` ordenaba sólo por id.
 *
 * Armado: el del flujo de entrada (`ArmadoDelFlujoDeEntrada`): reloj fijo, colas y mail falsos, WhatsApp en modo prueba
 * y la IA simulada con `Http::fake`. Ningún envío sale de la máquina.
 *
 * Hoy están EN ROJO CA1, CA3, CA4, CA5 y CA6; CA2 es de no-regresión y pasa ya.
 */
class CorreccionesDelEnsayoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /**
     * Deja el entorno del flujo prendido, el protocolo de leads sembrado y la demo directa ACTIVA en el recurso
     * `v2/demo_agenda` (como en producción: si el lead del flujo no la recibe es porque el sistema la oculta, no
     * porque falte el marcador). Sin roles de admin: cada test crea los suyos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();

        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => LeadAiService::MARCADOR_DEMO_DIRECTA,
            'synced_at' => now(),
        ]);

        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS, json_encode([
            'Hola Martín, quiero hacer la demo de ComercioCity',
            'Hola, quiero hacer la demo de ComercioCity',
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
    // CA1 — la línea de los únicos topes de operaciones
    // ------------------------------------------------------------------------------------------

    /**
     * CA1. Con los planes por defecto (Inicial hasta 200, Intermedio hasta 600) el bloque PRECIOS VIGENTES trae UNA
     * línea que dice que esos son los únicos topes de operaciones y que cualquier otro tope que el agente recuerde es de
     * la política anterior. La línea nombra los topes de los planes y NO el número viejo «100».
     *
     * @return void
     */
    public function test_el_bloque_de_precios_dice_cuales_son_los_unicos_topes(): void
    {
        $linea = $this->linea_de_los_topes(PreciosVigentesSettings::bloque_para_el_agente());

        $this->assertStringContainsString('únicos topes de operaciones', $linea);
        $this->assertStringContainsString('200', $linea, 'La línea nombra el tope del plan Inicial.');
        $this->assertStringContainsString('600', $linea, 'La línea nombra el tope del plan Intermedio.');
        $this->assertDoesNotMatchRegularExpression('/\b100\b/', $linea, 'El número de la política anterior no se nombra: sería sembrarlo.');
    }

    /**
     * CA1 (prueba de que no es texto fijo). Si se guardan otros topes (300 y 700) la línea cambia con ellos y ya no
     * nombra los de antes: los números salen de los planes guardados, no están escritos a mano.
     *
     * @return void
     */
    public function test_la_linea_de_los_topes_sale_de_los_planes_guardados(): void
    {
        $contrato = PreciosVigentesSettings::DEFAULTS;
        $contrato['gestion']['planes'][0]['hasta'] = 300;
        $contrato['gestion']['planes'][1]['desde'] = 301;
        $contrato['gestion']['planes'][1]['hasta'] = 700;
        $contrato['gestion']['planes'][2]['desde'] = 701;
        PreciosVigentesSettings::persist_from_request($contrato, null);

        $linea = $this->linea_de_los_topes(PreciosVigentesSettings::bloque_para_el_agente());

        $this->assertStringContainsString('300', $linea);
        $this->assertStringContainsString('700', $linea);
        $this->assertStringNotContainsString('200', $linea, 'El tope viejo del plan Inicial no puede quedar en la línea.');
        $this->assertStringNotContainsString('600', $linea, 'El tope viejo del plan Intermedio no puede quedar en la línea.');
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — no-regresión: el turno de Rubén no recibe la demo vieja
    // ------------------------------------------------------------------------------------------

    /**
     * CA2 (no-regresión; pasa ya). Lead del flujo en `contactado` que ya dijo su rubro: el contenido de usuario que sale
     * hacia la IA no lleva el link de su página (`/experiencia/`), ni el bloque DEMO DIRECTA, ni el de la página de acceso
     * a la demo; y el bloque del flujo sigue diciendo que no se le pide el nombre.
     *
     * @return void
     */
    public function test_el_turno_del_lead_que_dijo_su_rubro_no_lleva_la_demo_vieja(): void
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Tengo un corralón de materiales, unos 3000 productos');

        $this->fakear_ia([$this->paquete_ia('Genial.', 'contactado')]);
        app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $pedido    = json_decode($this->pedidos_a_la_ia[0], true);
        $contenido = $pedido['messages'][0]['content'];
        $contexto  = is_array($contenido) ? json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $contenido;

        $this->assertStringNotContainsString('/experiencia/', $contexto);
        $this->assertStringNotContainsString('DEMO DIRECTA', $contexto);
        $this->assertStringNotContainsString('PAGINA DE ACCESO A LA DEMO', $contexto);
        $this->assertStringContainsString('FLUJO DE ENTRADA', $contexto, 'Precondición: el turno lleva el bloque del flujo.');
        $this->assertStringContainsString('No pidas el nombre', $contexto);
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — nada de ayuda con la carga
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. Con lugar en el pool el bloque del flujo PROHÍBE ofrecer ayuda con la carga («una mano para subirlo», pedir o
     * recibir su Excel, cargárselo, «te lo subo») y manda a quien pide la carga gratis al link de la prueba textual,
     * diciéndole que adentro hay un video corto que lo guía para subir su Excel él mismo y que la carga hecha por
     * nosotros es la implementación paga (con su precio de PRECIOS VIGENTES).
     *
     * @return void
     */
    public function test_el_bloque_prohibe_ayudar_con_la_carga_y_manda_al_video_de_la_prueba(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando());

        $this->assertStringContainsString('NO le ofrezcas ayuda con la carga', $bloque);
        $this->assertStringContainsString('una mano para subirlo', $bloque, 'La prohibición cita la frase que salió en el ensayo.');
        $this->assertStringContainsString('te lo subo', $bloque);
        $this->assertStringContainsString('NO le pidas ni recibas su Excel', $bloque);

        $punto = $this->punto_con($bloque, 'video corto');
        $this->assertNotNull($punto, 'Falta el punto que manda al lead al video de la prueba.');
        $this->assertStringContainsString(LeadOfertaDePruebaService::link(), $punto, 'Ese punto lleva el link de la prueba textual.');
        $this->assertStringContainsString('implementación', $punto, 'Aclara que la carga hecha por nosotros es la implementación.');
        $this->assertStringContainsString(LeadFlujoEntradaService::precio_de_implementacion(), $punto, 'Y con el precio vigente.');
    }

    /**
     * CA3. Sin lugar en el pool la prohibición de ayudar con la carga está igual (no depende del link), y sigue sin
     * pasarse ningún link (regla vigente: Juan le escribe para darle el acceso).
     *
     * @return void
     */
    public function test_sin_lugar_en_el_pool_la_prohibicion_de_la_carga_sigue(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando(false));

        $this->assertStringContainsString('NO le ofrezcas ayuda con la carga', $bloque);
        $this->assertStringContainsString('una mano para subirlo', $bloque);
        $this->assertStringContainsString('NO le pidas ni recibas su Excel', $bloque);
        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $bloque, 'Sin lugar no se pasa el link.');
        $this->assertStringContainsString('Juan te escribe para darte el acceso', $bloque);
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — «cómo arranco» es el link de la prueba, no comerciocity.com
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. Con lugar en el pool el bloque dice explícito que «cómo arranco / cómo empiezo / me interesa / quiero
     * empezar / cómo hago» es el link de la prueba textual con `estado_sugerido: "link_enviado"`, y que NO se manda
     * comerciocity.com como respuesta a eso.
     *
     * @return void
     */
    public function test_como_arranco_es_el_link_de_la_prueba_y_no_la_pagina(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando());

        $punto = $this->punto_con($bloque, 'cómo arranco');
        $this->assertNotNull($punto, 'Falta el punto de «cómo arranco» en el bloque del flujo.');

        foreach (['cómo empiezo', 'me interesa', 'quiero empezar', 'cómo hago'] as $expresion) {
            $this->assertStringContainsString($expresion, $punto);
        }

        $this->assertStringContainsString(LeadOfertaDePruebaService::link(), $punto, 'Responde con el link de la prueba textual.');
        $this->assertStringContainsString('estado_sugerido: "link_enviado"', $punto);
        $this->assertStringContainsString('NO le mandes comerciocity.com', $punto, 'La página no es la respuesta a «cómo arranco».');
    }

    /**
     * CA4. Sin lugar en el pool el mismo punto existe pero NO manda link ni sugiere `link_enviado` (regla vigente: Juan le
     * escribe), y sigue prohibiendo mandar comerciocity.com como respuesta.
     *
     * @return void
     */
    public function test_sin_lugar_como_arranco_no_manda_link(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando(false));

        $punto = $this->punto_con($bloque, 'cómo arranco');
        $this->assertNotNull($punto, 'Falta el punto de «cómo arranco» también cuando no hay lugar.');

        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $punto, 'Sin lugar no se pasa el link.');
        $this->assertStringNotContainsString('estado_sugerido: "link_enviado"', $punto);
        $this->assertStringContainsString('Juan te escribe para darte el acceso', $punto);
        $this->assertStringContainsString('NO le mandes comerciocity.com', $punto);
    }

    // ------------------------------------------------------------------------------------------
    // CA5 — el simulador le pasa el primer mensaje al onboarding
    // ------------------------------------------------------------------------------------------

    /**
     * CA5 (llamada del onboarding). Un lead nuevo del flujo, sin historia, cuyo primer mensaje NO pregunta, escribe por
     * el simulador del panel: queda programada la presentación del flujo (los dos mensajes de la llegada) y NO sale el
     * auto genérico de siempre.
     *
     * @return void
     */
    public function test_el_simulador_programa_la_presentacion_del_flujo_en_el_primer_mensaje(): void
    {
        $this->actingAs($this->crear_admin_con_roles('Setter', ['es_setter' => true]), 'sanctum');
        $lead = $this->crear_lead_de_flujo('nuevo');

        $this->postJson('/api/admin/lead/' . $lead->id . '/simulate-inbound', ['content' => 'Buenas tardes, tengo una ferretería'])
            ->assertStatus(200);

        $this->verificar_que_salio_la_llegada_del_flujo($lead);
    }

    /**
     * CA5 (llamada de «recuperación»). Un lead `nuevo` del flujo con un mensaje previo suyo y sin ningún auto ni
     * presentación (la simulación anterior no pasó por el onboarding) escribe otro mensaje que no pregunta: la
     * recuperación también le pasa el contenido y programa la presentación del flujo, no el auto genérico.
     *
     * @return void
     */
    public function test_la_recuperacion_del_simulador_tambien_programa_la_presentacion_del_flujo(): void
    {
        $this->actingAs($this->crear_admin_con_roles('Setter', ['es_setter' => true]), 'sanctum');
        $lead = $this->crear_lead_de_flujo('nuevo');
        $this->mensaje_del_lead($lead, 'Buenas');
        $this->avanzar(60);

        $this->postJson('/api/admin/lead/' . $lead->id . '/simulate-inbound', ['content' => 'Tengo una ferretería'])
            ->assertStatus(200);

        $this->verificar_que_salio_la_llegada_del_flujo($lead);
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — Juan (Sólo Leads) antes que Thomas (vendedor)
    // ------------------------------------------------------------------------------------------

    /**
     * CA6. Thomas (`es_vendedor`, id menor) y Juan (`solo_leads`, id mayor): `for_embudo_task()` devuelve primero a Juan.
     * Los consumidores toman `$ids[0]` como responsable principal.
     *
     * @return void
     */
    public function test_juan_va_antes_que_thomas(): void
    {
        $this->limpiar_roles_de_admins();
        $thomas = $this->crear_admin_con_roles('Thomas', ['es_vendedor' => true]);
        $juan   = $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);
        $this->assertLessThan((int) $juan->id, (int) $thomas->id, 'Precondición: Thomas tiene el id menor.');

        $this->assertSame([(int) $juan->id, (int) $thomas->id], AdminTaskAssignmentResolver::for_embudo_task());
    }

    /**
     * CA6. Con varios de cada grupo: primero todos los `solo_leads` por id y después los `es_vendedor` por id. Un admin
     * con las dos marcas cuenta como `solo_leads` y no se repite. El conjunto devuelto es el de siempre.
     *
     * @return void
     */
    public function test_el_orden_es_solo_leads_por_id_y_despues_vendedores_por_id(): void
    {
        $this->limpiar_roles_de_admins();
        $vendedor_1 = $this->crear_admin_con_roles('Vendedor 1', ['es_vendedor' => true]);
        $leads_1    = $this->crear_admin_con_roles('Leads 1', ['solo_leads' => true]);
        $vendedor_2 = $this->crear_admin_con_roles('Vendedor 2', ['es_vendedor' => true]);
        $ambos      = $this->crear_admin_con_roles('Ambos', ['solo_leads' => true, 'es_vendedor' => true]);
        $this->crear_admin_con_roles('Setter', ['es_setter' => true]);

        $ids = AdminTaskAssignmentResolver::for_embudo_task();

        $this->assertSame([(int) $leads_1->id, (int) $ambos->id, (int) $vendedor_1->id, (int) $vendedor_2->id], $ids);

        $ordenado = $ids;
        sort($ordenado);
        $this->assertSame(
            [(int) $vendedor_1->id, (int) $leads_1->id, (int) $vendedor_2->id, (int) $ambos->id],
            $ordenado,
            'El conjunto devuelto no cambia: son los mismos cuatro admins.'
        );
    }

    /**
     * CA6. La tarea del embudo creada con Thomas y Juan queda con Juan como responsable principal
     * (`assigned_admin_id`) y los dos asignados.
     *
     * @return void
     */
    public function test_la_tarea_del_embudo_queda_con_juan_como_responsable_principal(): void
    {
        $this->limpiar_roles_de_admins();
        $thomas = $this->crear_admin_con_roles('Thomas', ['es_vendedor' => true]);
        $juan   = $this->crear_admin_con_roles('Juan', ['solo_leads' => true]);
        $lead   = $this->crear_lead_de_flujo('contactado');

        $tarea = LeadTareaVendedorService::crear($lead, LeadTareaVendedorService::MOTIVO_IMPLEMENTACION_PEDIDA, false);

        $this->assertNotNull($tarea, 'Precondición: la tarea se creó.');
        $this->assertSame((int) $juan->id, (int) $tarea->fresh()->assigned_admin_id, 'El responsable principal es Juan, no Thomas.');

        $asignados = $tarea->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all();
        sort($asignados);
        $this->assertSame([(int) $thomas->id, (int) $juan->id], $asignados, 'Los dos siguen asignados.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Lead del flujo en `contactado` que ya recibió la llegada y dijo su rubro, con o sin lugar en el pool.
     *
     * @param bool $con_lugar false = deja el pool sin instancias libres ni armándose.
     *
     * @return Lead
     */
    private function lead_conversando(bool $con_lugar = true): Lead
    {
        if (! $con_lugar) {
            TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])
                ->update(['estado' => TrialInstance::ESTADO_FALLIDA]);
        }

        $lead = $this->crear_lead_de_flujo('contactado');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 4000');

        $this->assertSame($con_lugar, LeadFlujoEntradaService::hay_lugar_en_el_pool(), 'Precondición: el pool está como pide el test.');

        return $lead->fresh();
    }

    /**
     * La línea del bloque de precios que habla de los topes (la que dice «política anterior»). Falla si no hay
     * exactamente UNA.
     *
     * @param string $bloque Bloque PRECIOS VIGENTES.
     *
     * @return string
     */
    private function linea_de_los_topes(string $bloque): string
    {
        $lineas = [];
        foreach (explode("\n", $bloque) as $linea) {
            if (mb_stripos($linea, 'política anterior') !== false) {
                $lineas[] = $linea;
            }
        }

        $this->assertCount(1, $lineas, 'El bloque tiene que traer UNA línea que hable de la política anterior (los únicos topes).');

        return $lineas[0];
    }

    /**
     * El punto del bloque del flujo (un renglón «  - » con sus líneas de continuación) que contiene el texto dado, sin
     * distinguir mayúsculas; null si ninguno lo trae.
     *
     * @param string $bloque Bloque FLUJO DE ENTRADA.
     * @param string $aguja  Texto a buscar.
     *
     * @return string|null
     */
    private function punto_con(string $bloque, string $aguja): ?string
    {
        foreach (explode("\n  - ", $bloque) as $punto) {
            if (mb_stripos($punto, $aguja) !== false) {
                return $punto;
            }
        }

        return null;
    }

    /**
     * Verifica que el simulador dejó la llegada del flujo y no el auto genérico: ningún mensaje de sistema todavía, un
     * solo job de presentación y, al correrlo, salen los dos mensajes de la llegada (llegada_1 y llegada_2).
     *
     * @param Lead $lead Lead simulado.
     *
     * @return void
     */
    private function verificar_que_salio_la_llegada_del_flujo(Lead $lead): void
    {
        $this->assertSame(
            0,
            LeadMessage::query()->where('lead_id', $lead->id)->where('system_message_kind', LeadWhatsappOnboardingService::KIND_AUTO)->count(),
            'El lead del flujo no recibe el auto genérico: el primer mensaje de Martín es el saludo de la llegada.'
        );

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs, 'Tiene que quedar programada UNA presentación.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());

        $this->assertSame(
            [$this->texto_llegada_1(), $this->texto_llegada_2()],
            $this->cuerpos_enviados($espia),
            'La presentación programada es la del flujo de entrada (los dos mensajes de la llegada).'
        );
    }
}
