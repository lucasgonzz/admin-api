<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadFlujoEntradaService;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ronda 2 de la misión embudo-prueba-martin-precios: el orden del mensaje cuando el flujo agrega puntos y la
 * promesa del bloque «DEMO SÓLO A PEDIDO» para el lead en `implementacion_pedida`.
 *
 * Hallazgo C1 (ensayo 707): la IA contesta en UN solo segmento («Te cuento rapidito… Todo en un solo lugar…
 * ¿a qué se dedica tu negocio?») y los puntos que agrega el flujo tienen que quedar entre su respuesta y la
 * pregunta final, no detrás de todo el texto de la IA.
 *
 * Hallazgo B nota 1: a un lead en `implementacion_pedida` la guarda de «no retroceder de tramo» no lo deja pasar a
 * `solicita_disponibilidad` (su `sort_order` es mayor), así que el sistema NUNCA le da el bloque de la demo en el
 * turno siguiente: el bloque no puede prometérselo; la demo se la muestra Juan en la llamada de la implementación.
 */
class OrdenDeLaPreguntaFinalTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Respuesta real del ensayo 707, en una sola burbuja y terminada en la pregunta de calificación. */
    private const RESPUESTA_DE_LA_IA = 'Te cuento rapidito cómo funciona. Todo en un solo lugar: ventas, stock y caja. ¿A qué se dedica tu negocio?';

    /** Lo que la IA contesta antes de la pregunta (la respuesta sin la pregunta final). */
    private const RESPUESTA_SIN_LA_PREGUNTA = 'Te cuento rapidito cómo funciona. Todo en un solo lugar: ventas, stock y caja.';

    /** La pregunta final. */
    private const PREGUNTA_FINAL = '¿A qué se dedica tu negocio?';

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
    // C1 — la pregunta final sigue siendo lo último
    // ------------------------------------------------------------------------------------------

    /**
     * C1. La IA responde en UN solo segmento que termina en pregunta: la respuesta va primero, después los puntos que
     * faltan y la pregunta final al final.
     *
     * @return void
     */
    public function test_un_solo_segmento_con_pregunta_deja_la_pregunta_al_final(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia(self::RESPUESTA_DE_LA_IA, 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame(
            self::RESPUESTA_SIN_LA_PREGUNTA . "\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2()
                . "\n---\n" . self::PREGUNTA_FINAL,
            trim((string) $resultado['mensaje_sugerido']),
            'La respuesta de la IA va primero y sólo la pregunta final queda detrás de los puntos.'
        );
    }

    /**
     * C1. La misma respuesta en dos segmentos (`---`): ya quedaba bien, se fija que siga así.
     *
     * @return void
     */
    public function test_dos_segmentos_con_pregunta_deja_la_pregunta_al_final(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia(self::RESPUESTA_SIN_LA_PREGUNTA . "\n---\n" . self::PREGUNTA_FINAL, 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame(
            self::RESPUESTA_SIN_LA_PREGUNTA . "\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2()
                . "\n---\n" . self::PREGUNTA_FINAL,
            trim((string) $resultado['mensaje_sugerido'])
        );
    }

    /**
     * C1. Si el segmento entero es una sola pregunta corta no se puede aislar nada: el orden es el de antes
     * (segmento de la IA y después los puntos).
     *
     * @return void
     */
    public function test_si_todo_el_segmento_es_la_pregunta_el_orden_es_el_de_antes(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia(self::PREGUNTA_FINAL, 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame(
            $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2() . "\n---\n" . self::PREGUNTA_FINAL,
            trim((string) $resultado['mensaje_sugerido']),
            'Sin texto antes de la pregunta, los puntos van primero y la pregunta al final (comportamiento previo).'
        );
    }

    /**
     * C1. Un salto de línea también corta la oración: la pregunta es lo que sigue al último fin de oración o salto.
     *
     * @return void
     */
    public function test_el_salto_de_linea_tambien_separa_la_pregunta(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia("Te cuento cómo funciona\n¿A qué se dedica tu negocio?", 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame(
            "Te cuento cómo funciona\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2()
                . "\n---\n¿A qué se dedica tu negocio?",
            trim((string) $resultado['mensaje_sugerido'])
        );
    }

    // ------------------------------------------------------------------------------------------
    // B nota 1 — la demo del lead en implementacion_pedida
    // ------------------------------------------------------------------------------------------

    /**
     * B nota 1. El lead del flujo en `implementacion_pedida` no recibe la promesa de «en el turno siguiente el sistema
     * te da el bloque de la demo» (esa guarda lo traba) y sí la indicación de que la demo se la muestra Juan en la
     * llamada de la implementación, sin agendar ni prometer una demo automática.
     *
     * @return void
     */
    public function test_el_lead_en_implementacion_pedida_no_recibe_la_promesa_de_la_demo_automatica(): void
    {
        $lead = $this->crear_lead_de_flujo('implementacion_pedida');
        $this->mensaje_del_lead($lead, 'Quiero ver una demo');

        $contexto = $this->contexto_enviado_a_la_ia($lead->fresh());

        $this->assertStringContainsString('DEMO SÓLO A PEDIDO', $contexto);
        $this->assertStringNotContainsString('el sistema te da el bloque de la demo', $contexto);
        $this->assertStringContainsString('Juan', $contexto, 'La demo se la muestra Juan.');
        $this->assertStringContainsString('llamada de la implementación', $contexto);
    }

    /**
     * B nota 1. Para los demás estados sin demo el bloque sigue igual: promete el bloque de la demo en el turno
     * siguiente.
     *
     * @return void
     */
    public function test_el_lead_contactado_sigue_con_la_promesa_de_siempre(): void
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, 'Quiero ver una demo');

        $contexto = $this->contexto_enviado_a_la_ia($lead->fresh());

        $this->assertStringContainsString('el sistema te da el bloque de la demo', $contexto);
        $this->assertStringNotContainsString('llamada de la implementación', $contexto);
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Lead `contactado` con el paso 1 entregado y una respuesta suya: se le deben las dos formas y la página.
     *
     * @return Lead
     */
    private function lead_con_las_dos_formas_y_la_pagina_debidas(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, '¡Hola! Quiero más información');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Almacén, unos 800 productos');

        $lead = $lead->fresh();
        $this->assertSame(['dos_formas', 'pagina'], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: se le deben las dos formas y la página.');

        return $lead;
    }

    /**
     * Genera el turno con una respuesta simulada de la IA y devuelve el contenido de usuario que salió hacia ella.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto_enviado_a_la_ia(Lead $lead): string
    {
        $this->fakear_ia([$this->paquete_ia('Te cuento.', $lead->status)]);
        app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $pedido = json_decode($this->pedidos_a_la_ia[0], true);
        $this->assertIsArray($pedido, 'Precondición: el pedido a la IA es un JSON.');

        $contenido = $pedido['messages'][0]['content'];

        return is_array($contenido) ? json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $contenido;
    }
}
