<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\LeadScheduledMessage;
use App\Models\PipelineActivity;
use App\Services\LeadAiService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadSuggestionSendService;
use App\Services\Pipelines\PipelineOpportunityService;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * El «sí» de la charla: el estado `acepto_probar`, la entrada a «Interesados» del tablero de Juan y la tarea (misión
 * leads-charla-antes-de-la-demo, criterios CA5 a CA8, CA10 y CA11 del PLAN).
 *
 * Lo pedido por Lucas: cuando el lead dice que sí a ver la plataforma, tiene que pasar al pipeline «Leads» en
 * «Interesados» para que Juan lo llame; si quiere seguir, Juan lo avanza y la prueba la ofrece Lucas a mano.
 *
 * Lo que se fija acá, siempre con el turno real del agente (`generate_suggestion` con la IA simulada):
 *  - CA5 el sí (`estado_sugerido: acepto_probar`) cambia el lead YA, antes de aprobar el mensaje, crea UNA tarjeta en
 *        «Interesados» con su nota, UNA tarea para Juan y deja el aviso «te va a llamar Juan» en el mensaje.
 *  - CA6 idempotencia y sin retroceso.
 *  - CA7 el primer mensaje del lead (el texto precargado) no es un sí: hacen falta 2 mensajes suyos.
 *  - CA8 pedir la demo ES el sí: no se agenda demo ni se hace la segunda llamada a la IA.
 *  - CA10 cuando Lucas le manda el link a mano pasa a «Quiere probar» sin duplicar ni mover la tarjeta.
 *  - CA11 el catálogo de estados, las tarjetas v2 y el seeder standalone.
 *
 * Extiende la base del tablero de Juan: trae el pipeline «Leads» sembrado, a Juan (perfil Sólo Leads) y el reloj fijo.
 * Los textos y claves del PLAN van como literales (todavía no existen las constantes de producción).
 *
 * Hoy están EN ROJO los que describen lo nuevo; «link_enviado no vuelve a acepto» comparte con hoy que no se crea la
 * tarea, pero el chip del estado sugerido lo pone en rojo.
 */
class CharlaAceptaProbarTest extends BaseDelTableroDeJuan
{
    /** Clave del interruptor de la charla (PLAN §2.1). */
    private const KEY_CHARLA = 'lead_whatsapp_charla_antes_de_la_demo_activa';

    /** Aviso por defecto que se agrega si la IA no nombra a Juan (PLAN §2.5.4, `texto_aviso_juan()`). */
    private const AVISO_DE_JUAN = 'Genial. Te va a llamar Juan, del equipo, para mostrarte la plataforma.';

    /**
     * Pipeline «Leads» con el tablero de Juan, el flujo de entrada y la charla prendidos. El lead nace después del
     * encendido (el sello del encendido se corre un día atrás, como en `EntradaAInteresadosTest`).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();
        $this->preparar_flujo_de_entrada(true, false, true);
        AdminSetting::set(\App\Services\LeadFlujoEntradaService::KEY_ACTIVADO_EN, \Carbon\Carbon::now()->subDay()->toIso8601String());
        $this->espiar_whatsapp();
    }

    // ------------------------------------------------------------------------------------------
    // CA5 — el sí
    // ------------------------------------------------------------------------------------------

    /**
     * CA5. Lead en `contactado` con 2+ mensajes suyos: la IA devuelve «¡Genial! …» con `estado_sugerido: acepto_probar`.
     * El lead queda en `acepto_probar` al crear la sugerencia (sin esperar la aprobación), tiene UNA tarjeta abierta en
     * «Interesados» con la nota «Entró solo: aceptó probar la plataforma en la charla», UNA tarea
     * `embudo_acepto_probar` asignada a Juan y el mensaje nombra a Juan.
     *
     * @return void
     */
    public function test_el_si_entra_a_interesados_con_tarea_y_aviso_de_juan(): void
    {
        $lead = $this->lead_en_charla('contactado');

        $mensaje = $this->turno($lead, $this->paquete_ia('¡Genial! Te va a llamar Juan, del equipo, para mostrarte la plataforma.', 'acepto_probar'));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'El estado se aplica apenas el agente lo sugiere.');

        $tarjetas = $this->tarjetas_del_lead($lead);
        $this->assertCount(1, $tarjetas, 'Una sola tarjeta del lead en el pipeline Leads.');
        $this->assertSame('interesados', $this->clave_de_la_columna($tarjetas[0]), 'La tarjeta entra en «Interesados».');
        $this->assertNull($tarjetas[0]->fresh()->closed_at, 'Y está abierta.');
        $notas = PipelineActivity::query()->where('opportunity_id', $tarjetas[0]->id)->where('type', 'created')->pluck('body')->implode(' | ');
        $this->assertStringContainsString('Entró solo: aceptó probar la plataforma en la charla', $notas);

        $tareas = AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_acepto_probar')->get();
        $this->assertCount(1, $tareas, 'Una sola tarea para Juan.');
        $this->assertStringEndsWith(': aceptó probar la plataforma', (string) $tareas[0]->title);
        $this->assertSame([(int) $this->juan->id], $this->asignados_de($tareas[0]), 'La tarea va al primer admin del embudo (Juan).');

        $this->assertStringContainsString('Juan', (string) $mensaje->content, 'El mensaje al lead dice que lo llama Juan.');
    }

    /**
     * CA5. Si la IA no nombra a Juan, el servidor agrega el aviso por defecto como último segmento del mensaje.
     *
     * @return void
     */
    public function test_agrega_el_aviso_si_la_ia_no_nombra_a_juan(): void
    {
        $lead = $this->lead_en_charla('contactado');

        $mensaje = $this->turno($lead, $this->paquete_ia('¡Genial! Anotado.', 'acepto_probar'));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Precondición: el sí se aplicó.');
        $contenido = trim((string) $mensaje->content);
        $this->assertStringContainsString('¡Genial! Anotado.', $contenido, 'Lo que dijo la IA se conserva.');
        $this->assertStringEndsWith(self::AVISO_DE_JUAN, $contenido, 'El aviso de Juan va como último segmento.');
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — idempotencia y sin retroceso
    // ------------------------------------------------------------------------------------------

    /**
     * CA6. Un segundo turno con `acepto_probar` deja 1 tarjeta y 1 tarea, y no repite el aviso de Juan.
     *
     * @return void
     */
    public function test_dos_si_no_duplican(): void
    {
        $lead = $this->lead_en_charla('contactado');
        $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan, del equipo.', 'acepto_probar'));
        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Precondición: el primer sí se aplicó.');

        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Dale, perfecto');
        $segundo = $this->turno($lead, $this->paquete_ia('Quedamos así, cualquier duda me escribís.', 'acepto_probar'));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status);
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'Sigue habiendo una sola tarjeta.');
        $this->assertSame(1, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_acepto_probar')->count(), 'Y una sola tarea.');
        $this->assertStringNotContainsString('Te va a llamar Juan', (string) $segundo->content, 'El aviso de Juan sólo se agrega al entrar, no en cada turno.');
    }

    /**
     * CA6. Con el lead en `acepto_probar`, una sugerencia `contactado` o `calificado` no lo baja: el estado sigue y la
     * sugerencia no ofrece el cambio de estado.
     *
     * @return void
     */
    public function test_no_retrocede(): void
    {
        $lead = $this->lead_en_charla('contactado');
        $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan, del equipo.', 'acepto_probar'));
        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Precondición: el sí se aplicó.');

        foreach (['contactado', 'calificado'] as $estado) {
            $this->avanzar(60);
            $this->mensaje_del_lead($lead, 'Una duda más: ¿tiene factura electrónica?');
            $mensaje = $this->turno($lead, $this->paquete_ia('Sí, la tiene. Se lo comento a Juan.', $estado));

            $this->assertSame('acepto_probar', (string) $lead->fresh()->status, "Una sugerencia «{$estado}» no baja al lead.");
            $this->assertEmpty((string) $mensaje->fresh()->suggested_lead_status, "No se ofrece el chip de cambio a «{$estado}».");
        }
    }

    /**
     * CA6. Un lead en `link_enviado` (Lucas ya le mandó el link a mano) con una sugerencia `acepto_probar` sigue en
     * `link_enviado`, no se crea la tarea `embudo_acepto_probar` y la sugerencia no ofrece el cambio.
     *
     * @return void
     */
    public function test_link_enviado_no_vuelve_a_acepto(): void
    {
        $lead = $this->lead_en_charla('link_enviado');

        $mensaje = $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan.', 'acepto_probar'));

        $this->assertSame('link_enviado', (string) $lead->fresh()->status);
        $this->assertSame(0, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_acepto_probar')->count(), 'No hay tarea de acepto.');
        $this->assertEmpty((string) $mensaje->fresh()->suggested_lead_status, 'El estado sugerido vuelve al actual: sin chip.');
    }

    // ------------------------------------------------------------------------------------------
    // CA7 — el primer mensaje no es un sí
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. Un lead con UN solo mensaje suyo («Hola, quiero probar ComercioCity», el texto del botón) y una sugerencia
     * `acepto_probar`: no cambia de estado, no hay tarjeta ni tarea por este motivo y el razonamiento explica por qué.
     *
     * @return void
     */
    public function test_el_primer_mensaje_no_es_un_si(): void
    {
        $lead = $this->lead_en_charla('contactado', 1, 'Hola, quiero probar ComercioCity');

        $mensaje = $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan.', 'acepto_probar'));

        $this->assertSame('contactado', (string) $lead->fresh()->status, 'El texto precargado no es un sí: el estado no cambia.');
        $this->assertCount(0, $this->tarjetas_del_lead($lead), 'No hay tarjeta.');
        $this->assertSame(0, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_acepto_probar')->count(), 'No hay tarea.');
        $this->assertStringContainsString('acepto_probar no aplica', (string) $mensaje->fresh()->ai_reasoning);
    }

    // ------------------------------------------------------------------------------------------
    // CA8 — pedir la demo es el sí
    // ------------------------------------------------------------------------------------------

    /**
     * CA8. Con la charla prendida, la IA devuelve `solicita_disponibilidad: true` (el lead pidió la demo) de un lead en
     * `contactado` con 2+ mensajes: queda en `acepto_probar` (no en `solicita_disponibilidad`), no se hace la segunda
     * llamada a la IA (un solo request a Anthropic) y no se agenda demo.
     *
     * @return void
     */
    public function test_pedir_la_demo_es_el_si(): void
    {
        $lead = $this->lead_en_charla('contactado');

        $this->turno($lead, $this->paquete_ia(
            '¡Dale! Te muestro la plataforma.',
            'solicita_disponibilidad',
            'conversacional',
            [],
            ['solicita_disponibilidad' => true]
        ));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Pedir la demo es el sí: acepto_probar, no solicita_disponibilidad.');
        /* Se cuentan los pedidos REALES a Anthropic con `Http::recorded()`: el setUp registra dos veces el `Http::fake` de la IA (la base del
           tablero y `preparar_flujo_de_entrada`) y `$this->pedidos_a_la_ia` cuenta el doble por pedido. */
        $pedidos_reales = 0;
        foreach (\Illuminate\Support\Facades\Http::recorded() as $par) {
            if (strpos((string) $par[0]->url(), 'api.anthropic.com') !== false) {
                $pedidos_reales++;
            }
        }
        $this->assertSame(1, $pedidos_reales, 'No hay segunda llamada a la IA para pedir horarios.');
        $this->assertNull($lead->fresh()->demo_id, 'No se agenda ninguna demo.');
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'Y entra a Interesados como cualquier sí.');
    }

    // ------------------------------------------------------------------------------------------
    // Ronda 2 — toda forma de pedir o agendar la demo es el sí (hallazgo 2 del Revisor)
    // ------------------------------------------------------------------------------------------

    /**
     * Cuántos pedidos REALES se hicieron a Anthropic (`Http::recorded()`; `$this->pedidos_a_la_ia` cuenta el doble en esta clase
     * por el doble `Http::fake` del setUp).
     *
     * @return int
     */
    private function pedidos_reales_a_la_ia(): int
    {
        $total = 0;
        foreach (\Illuminate\Support\Facades\Http::recorded() as $par) {
            if (strpos((string) $par[0]->url(), 'api.anthropic.com') !== false) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Ronda 2, hallazgo 2. Con la charla prendida, el booleano `solicita_disponibilidad: true` junto a `estado_sugerido:
     * acepto_probar` no dispara la segunda llamada de horarios: un solo pedido a la IA, el lead queda en `acepto_probar` y no hay demo.
     *
     * @return void
     */
    public function test_el_booleano_de_disponibilidad_con_acepto_no_hace_la_segunda_llamada(): void
    {
        $lead = $this->lead_en_charla('contactado');

        $this->turno($lead, $this->paquete_ia('¡Dale! Te muestro la plataforma.', 'acepto_probar', 'conversacional', [], ['solicita_disponibilidad' => true]));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status);
        $this->assertSame(1, $this->pedidos_reales_a_la_ia(), 'Una sola llamada a la IA: sin pedido de horarios.');
        $this->assertNull($lead->fresh()->demo_id, 'No se agenda ninguna demo.');
    }

    /**
     * Ronda 2, hallazgo 2. `estado_sugerido: demo_agendada` (la IA confirma una demo) en la charla es el sí: `acepto_probar`, un solo pedido
     * a la IA y ninguna demo asignada.
     *
     * @return void
     */
    public function test_demo_agendada_en_la_charla_es_el_si(): void
    {
        $lead = $this->lead_en_charla('contactado');

        $this->turno($lead, $this->paquete_ia('¡Listo! Quedamos para la demo.', 'demo_agendada'));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Confirmar una demo en la charla es el sí, no una demo agendada.');
        $this->assertSame(1, $this->pedidos_reales_a_la_ia(), 'Una sola llamada a la IA.');
        $this->assertNull($lead->fresh()->demo_id, 'No se agenda ninguna demo.');
        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'Entra a Interesados como cualquier sí.');
    }

    /**
     * Ronda 2, hallazgo 2. Un `agendar_demo` en el paquete (aunque el estado sugerido sea otro) no agenda nada en la charla: el lead pasa a
     * `acepto_probar`, un solo pedido a la IA y sin demo.
     *
     * @return void
     */
    public function test_agendar_demo_en_la_charla_no_agenda_y_es_el_si(): void
    {
        $lead = $this->lead_en_charla('contactado');

        $this->turno($lead, $this->paquete_ia('¡Dale! Te muestro la plataforma.', 'demo_agendada', 'conversacional', [], ['agendar_demo' => ['ahora' => true]]));

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status);
        $this->assertSame(1, $this->pedidos_reales_a_la_ia(), 'Una sola llamada a la IA.');
        $this->assertNull($lead->fresh()->demo_id, 'agendar_demo no asigna ninguna demo en la charla.');
    }

    /**
     * Ronda 2, nota del Revisor. Un `acepto_probar` sugerido a un lead que NO está en la charla (interruptor apagado) no se aplica: el
     * estado sigue y la sugerencia no ofrece el cambio (el camino genérico no validaba los 2 mensajes).
     *
     * @return void
     */
    public function test_acepto_probar_sin_la_charla_no_se_aplica(): void
    {
        AdminSetting::set(self::KEY_CHARLA, '0');
        $lead = $this->lead_en_charla('contactado');

        $mensaje = $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan.', 'acepto_probar'));

        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Sin la charla el sí no existe.');
        $this->assertEmpty((string) $mensaje->fresh()->suggested_lead_status, 'Y no se ofrece el cambio de estado.');
        $this->assertSame(0, AdminTask::query()->where('lead_id', $lead->id)->where('created_via', 'embudo_acepto_probar')->count());
    }

    // ------------------------------------------------------------------------------------------
    // El bloque en acepto_probar
    // ------------------------------------------------------------------------------------------

    /**
     * PLAN §2.4. Con el lead en `acepto_probar` el contexto del turno es el bloque «ESPERANDO A JUAN»: no vuelve a
     * ofrecer, no manda el link ni las dos formas.
     *
     * @return void
     */
    public function test_el_bloque_de_acepto_probar_espera_a_juan(): void
    {
        $lead = $this->lead_en_charla('contactado');
        $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan, del equipo.', 'acepto_probar'));
        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Precondición: el sí se aplicó.');

        $contexto = $this->contexto_del_turno($lead->fresh());

        $this->assertStringContainsString('ESPERANDO A JUAN', $contexto);
        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $contexto);
        $this->assertStringNotContainsString('Tu objetivo es que elija cómo arrancar', $contexto);
    }

    // ------------------------------------------------------------------------------------------
    // CA10 — Lucas ofrece la prueba a mano
    // ------------------------------------------------------------------------------------------

    /**
     * CA10. Lead en `acepto_probar` con su tarjeta ya movida por Juan a «En seguimiento»: Lucas aprueba un mensaje con el
     * link de la prueba (`LeadSuggestionSendService`). El lead pasa a `link_enviado`, se programan los pasos `link_*` y
     * la tarjeta SIGUE en «En seguimiento»: no vuelve a «Interesados» ni se duplica.
     *
     * @return void
     */
    public function test_lucas_manda_el_link_y_pasa_a_quiere_probar(): void
    {
        $lead = $this->lead_en_charla('contactado');
        $this->turno($lead, $this->paquete_ia('¡Genial! Te llama Juan, del equipo.', 'acepto_probar'));
        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Precondición: el sí se aplicó.');

        /* Juan lo llamó y avanzó la tarjeta. */
        $tarjeta = $this->tarjeta_del_lead($lead);
        app(PipelineOpportunityService::class)->mover($tarjeta, ['stage_id' => (int) $this->etapa_por_clave('en_seguimiento')->id], null);
        $this->assertSame('en_seguimiento', $this->clave_de_la_columna($tarjeta), 'Precondición: Juan movió la tarjeta.');

        /* Lucas le ofrece la prueba a mano: el mensaje con el link sale como uno aprobado. */
        $this->avanzar(600);
        $sugerencia = LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'status' => 'sugerido',
            'content' => 'Genial, creá tu prueba acá: ' . LeadOfertaDePruebaService::link(),
            'is_followup' => false, 'is_status_event' => false, 'requiere_verificacion' => false, 'suggested_lead_status' => null,
        ]);
        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($sugerencia);

        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Con el link enviado pasa a «Quiere probar».');

        $pasos = LeadScheduledMessage::query()->where('lead_id', $lead->id)->whereNotNull('paso_embudo')
            ->where('status', LeadScheduledMessage::STATUS_PENDIENTE)->pluck('paso_embudo')->all();
        sort($pasos);
        $this->assertSame(['link_22h', 'link_2h'], $pasos, 'Arrancan los pasos del link.');

        $this->assertCount(1, $this->tarjetas_del_lead($lead), 'No se duplica la tarjeta.');
        $this->assertSame('en_seguimiento', $this->clave_de_la_columna($this->tarjeta_del_lead($lead)), 'La tarjeta sigue donde la dejó Juan.');
    }

    // ------------------------------------------------------------------------------------------
    // CA11 — el catálogo
    // ------------------------------------------------------------------------------------------

    /**
     * CA11. El catálogo trae `acepto_probar` («Aceptó probar», grupo «Calificación»), las tarjetas v2 son 11 y el
     * seeder standalone, corrido dos veces sobre una tabla con los estados de hoy, deja UNA fila con
     * `sort_order = calificado + 1` y corre en 1 a los que venían detrás (`link_enviado` incluido).
     *
     * @return void
     */
    public function test_catalogo_y_seeder(): void
    {
        $this->assertSame('Aceptó probar', LeadPipelineStatus::DEFAULT_STATUSES['acepto_probar'] ?? null, 'Falta el estado en el catálogo por defecto.');
        $this->assertSame('Calificación', LeadPipelineStatus::DEFAULT_STATUS_GROUPS['acepto_probar'] ?? null);
        $this->assertSame('#ff922b', LeadPipelineStatus::DEFAULT_COLORS['acepto_probar'] ?? null);

        $tarjetas = array_keys(LeadPipelineStatus::TARJETAS_V2);
        $this->assertCount(11, $tarjetas, 'Las tarjetas v2 pasan de 10 a 11.');
        $this->assertSame(['nuevo', 'conversando', 'acepto_probar', 'link_enviado'], array_slice($tarjetas, 0, 4));
        $this->assertSame(['Aceptó probar', '#ff922b', ['acepto_probar']], LeadPipelineStatus::TARJETAS_V2['acepto_probar'] ?? null);
        $this->assertNotContains('acepto_probar', LeadPipelineStatus::SLUGS_ARCHIVADOS, 'No va a los archivados: se puede asignar a mano.');

        /* La tabla como está hoy en producción: el catálogo SIN el estado nuevo, con su orden. */
        $clase = 'Database\\Seeders\\LeadPipelineStatusCharlaSeeder';
        $this->assertTrue(class_exists($clase), 'Falta el seeder ' . $clase . ' (CA11).');

        LeadPipelineStatus::query()->delete();
        $orden = 0;
        foreach (LeadPipelineStatus::DEFAULT_STATUSES as $slug => $etiqueta) {
            if ($slug === 'acepto_probar') {
                continue;
            }
            $orden++;
            LeadPipelineStatus::create(['slug' => $slug, 'label' => $etiqueta, 'color' => '#adb5bd', 'sort_order' => $orden]);
        }
        $antes = LeadPipelineStatus::query()->pluck('sort_order', 'slug')->all();

        $this->seed($clase);
        $this->seed($clase);

        $this->assertSame(1, LeadPipelineStatus::query()->where('slug', 'acepto_probar')->count(), 'Corrido dos veces deja una sola fila.');
        $despues = LeadPipelineStatus::query()->pluck('sort_order', 'slug')->all();
        $this->assertSame((int) $antes['calificado'] + 1, (int) $despues['acepto_probar'], 'Va detrás de «calificado».');
        $this->assertSame((int) $antes['link_enviado'] + 1, (int) $despues['link_enviado'], '«link_enviado» corre un lugar.');
        $this->assertSame((int) $antes['nuevo'], (int) $despues['nuevo'], 'Lo que está antes no se mueve.');
        $this->assertSame((int) $antes['calificado'], (int) $despues['calificado']);
        $this->assertSame((int) $antes['en_pausa'] + 1, (int) $despues['en_pausa'], 'Todo lo de atrás corre uno.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Lead del flujo en el estado pedido, con la llegada entregada y `$mensajes_del_lead` mensajes suyos: el primero es
     * el texto precargado del anuncio (o `$primero`) y los demás respuestas, como un lead que ya está charlando.
     *
     * @param string      $estado Estado del lead.
     * @param int         $mensajes_del_lead Cuántos mensajes suyos hay en el hilo (1 = sólo el precargado).
     * @param string|null $primero Texto del primer mensaje (null = el del anuncio de Cecilia).
     *
     * @return Lead
     */
    private function lead_en_charla(string $estado, int $mensajes_del_lead = 2, ?string $primero = null): Lead
    {
        $lead = $this->crear_lead_de_flujo($estado);
        $this->mensaje_del_lead($lead, $primero !== null ? $primero : 'Hola, vi el video de los presupuestos y quiero probar ComercioCity');
        $this->dejar_entregado_el_paso_1($lead);

        $respuestas = ['Almacén y abastecedora', 'Tengo dos negocios y somos cuatro. Me cuesta controlar el stock, sí, me gustaría verlo'];
        for ($i = 1; $i < $mensajes_del_lead; $i++) {
            $this->avanzar(60);
            $this->mensaje_del_lead($lead, $respuestas[($i - 1) % count($respuestas)]);
        }

        return $lead->fresh();
    }

    /**
     * Un turno del agente: la IA simulada devuelve `$paquete` y se genera la sugerencia real.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $paquete Paquete del agente.
     *
     * @return LeadMessage La sugerencia creada.
     */
    private function turno(Lead $lead, array $paquete): LeadMessage
    {
        $this->fakear_ia([$paquete]);

        return app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);
    }

    /**
     * Ids de los admins asignados a la tarea, ordenados.
     *
     * @param AdminTask $tarea
     *
     * @return array<int, int>
     */
    private function asignados_de(AdminTask $tarea): array
    {
        $ids = $tarea->assigned_admins()->pluck('admins.id')->map(function ($id) {
            return (int) $id;
        })->all();
        sort($ids);

        return $ids;
    }
}
