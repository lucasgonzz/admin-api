<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión martin-link-directo, CA1, CA2, CA4 y CA5: cuando corresponde el link de la prueba, Martín lo manda; no pregunta
 * si lo quiere.
 *
 * Lo que pasó (ensayo del lead 711): al dar el precio Martín cerró «…sin tarjeta. ¿Te paso el link?» en vez de mandar el
 * link. El bloque del flujo ya lo prohíbe, pero la IA decide contra el texto: hace falta una guarda determinista en
 * `completar_paquete`, igual que la de los puntos debidos.
 */
class LinkDirectoSinPreguntarTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Link textual de la prueba (el de siempre). */
    private const LINK = 'https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin';

    /** La respuesta del ensayo 711 sin la oferta del link. */
    private const RESPUESTA_DEL_ENSAYO = 'Lo más simple es que lo pruebes vos, gratis 7 días, con tu propio negocio y sin tarjeta.';

    /** Nota que deja la guarda en el razonamiento. */
    private const NOTA = 'Flujo de entrada: se reemplazó la oferta del link por el link.';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    /**
     * Lead `contactado` al que ya se le entregó todo salvo el link (no pidió probar: el link no se le debe como punto).
     *
     * @return Lead
     */
    private function lead_sin_puntos_debidos(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, '¡Hola! Quiero más información');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 800 productos');
        $this->mensaje_de_sistema($lead, $this->texto_respuesta_1(), LeadFlujoEntradaService::KIND_DOS_FORMAS);
        $this->mensaje_de_sistema($lead, $this->texto_respuesta_2(), LeadFlujoEntradaService::KIND_PAGINA);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, '¿Cuánto sale?');

        $lead = $lead->fresh();
        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: no se le debe ningún punto.');
        $this->assertTrue(LeadFlujoEntradaService::hay_lugar_en_el_pool(), 'Precondición: hay lugar en el pool.');

        return $lead;
    }

    /**
     * Lead que dijo «quiero probar» en su primer mensaje y contestó el rubro: se le deben las dos formas, la página y el link.
     *
     * @return Lead
     */
    private function lead_con_todos_los_puntos_debidos(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, 'Hola, quiero probar ComercioCity');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 800 productos');

        $lead = $lead->fresh();
        $this->assertSame(['dos_formas', 'pagina', 'link_prueba'], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: se le debe todo.');

        return $lead;
    }

    /**
     * Corre `completar_paquete` con un mensaje.
     *
     * @param Lead   $lead
     * @param string $mensaje Mensaje sugerido por la IA.
     * @param string $estado  Estado sugerido.
     *
     * @return array<string, mixed>
     */
    private function completar(Lead $lead, string $mensaje, string $estado = 'contactado'): array
    {
        return LeadFlujoEntradaService::completar_paquete($lead, $this->paquete_ia($mensaje, $estado), false);
    }

    /**
     * El texto del link de la prueba que agrega el flujo.
     *
     * @return string
     */
    private function texto_del_link(): string
    {
        return LeadFlujoEntradaService::texto_del_punto('link_prueba');
    }

    // ------------------------------------------------------------------------------------------
    // CA1 — el link en vez de ofrecerlo
    // ------------------------------------------------------------------------------------------

    /**
     * CA1. El ensayo 711: la oración «¿Te paso el link?» se reemplaza por el link, como segmento propio.
     *
     * @return void
     */
    public function test_la_oferta_del_link_del_ensayo_se_reemplaza_por_el_link(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        $this->assertStringContainsString(self::LINK, $this->texto_del_link(), 'Precondición: el texto del link lleva el link.');

        $resultado = $this->completar($lead, self::RESPUESTA_DEL_ENSAYO . ' ¿Te paso el link?');

        $this->assertSame(
            self::RESPUESTA_DEL_ENSAYO . "\n---\n" . $this->texto_del_link(),
            trim((string) $resultado['mensaje_sugerido'])
        );
        $this->assertSame('link_enviado', $resultado['estado_sugerido']);
        $this->assertStringContainsString(self::NOTA, (string) $resultado['razonamiento']);
        $this->assertStringContainsString('Razonamiento de prueba.', (string) $resultado['razonamiento'], 'La nota se suma, no pisa.');
    }

    /**
     * CA1. Las formas de ofrecer el link.
     *
     * @dataProvider provider_de_ofertas_del_link
     *
     * @param string $oferta Oración-pregunta que ofrece el link.
     *
     * @return void
     */
    public function test_cada_forma_de_ofrecer_el_link_se_reemplaza(string $oferta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, self::RESPUESTA_DEL_ENSAYO . ' ' . $oferta);

        $this->assertSame(
            self::RESPUESTA_DEL_ENSAYO . "\n---\n" . $this->texto_del_link(),
            trim((string) $resultado['mensaje_sugerido'])
        );
        $this->assertSame('link_enviado', $resultado['estado_sugerido']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_ofertas_del_link(): array
    {
        return [
            'te paso el link'                    => ['¿Te paso el link?'],
            'te mando el enlace'                 => ['¿Te mando el enlace?'],
            'te lo mando'                        => ['¿Te lo mando, el link de la prueba?'],
            'querés que te pase'                 => ['¿Querés que te pase el link de la prueba?'],
            'querés que te envíe'                => ['¿Querés que te envíe el enlace para arrancar?'],
            'te comparto'                        => ['¿Te comparto el link?'],
            'querés el link'                     => ['¿Querés el link?'],
            'con giro antes de la pregunta'      => ['Si te sirve, ¿te paso el link para que arranques?'],
        ];
    }

    /**
     * CA1. La oferta en el medio de un segmento: el link va como segmento propio en ese lugar y lo de después sigue en otro.
     *
     * @return void
     */
    public function test_la_oferta_en_el_medio_parte_el_segmento_y_deja_el_link_en_su_lugar(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, 'Sale $628.400 la implementación. ¿Te paso el link? Cualquier duda me escribís.');

        $this->assertSame(
            "Sale \$628.400 la implementación.\n---\n" . $this->texto_del_link() . "\n---\nCualquier duda me escribís.",
            trim((string) $resultado['mensaje_sugerido'])
        );
    }

    /**
     * CA1. La oferta como segmento propio se reemplaza por el link en el mismo lugar (sin segmentos vacíos).
     *
     * @return void
     */
    public function test_la_oferta_como_segmento_propio_se_reemplaza_en_su_lugar(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, "Sale \$628.400.\n---\n¿Te paso el link?\n---\nCualquier duda me escribís.");

        $this->assertSame(
            "Sale \$628.400.\n---\n" . $this->texto_del_link() . "\n---\nCualquier duda me escribís.",
            trim((string) $resultado['mensaje_sugerido'])
        );
    }

    /**
     * CA1. Lo que habla del link sin ofrecerlo no se toca: una pregunta por el link que ya le pasó (pasado) o una pregunta
     * que no tiene que ver.
     *
     * @dataProvider provider_de_preguntas_que_no_ofrecen
     *
     * @param string $pregunta Oración-pregunta que NO ofrece el link.
     *
     * @return void
     */
    public function test_una_pregunta_que_no_ofrece_el_link_no_se_toca(string $pregunta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $mensaje   = 'Sale $628.400 la implementación. ' . $pregunta;
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']));
        $this->assertSame('contactado', $resultado['estado_sugerido']);
        $this->assertStringNotContainsString(self::NOTA, (string) $resultado['razonamiento']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_preguntas_que_no_ofrecen(): array
    {
        return [
            'pudo abrir el link que le pasó' => ['¿Pudiste abrir el link que te pasé?'],
            'le mandó el link y pregunta'    => ['¿Te llegó el link que te mandé?'],
            'pregunta sin link'              => ['¿Querés que te cuente cómo se cargan los precios?'],
        ];
    }

    /**
     * CA1. Si el mensaje ya trae el link, la oferta no se reemplaza (no se agrega un segundo link).
     *
     * @return void
     */
    public function test_si_el_mensaje_ya_trae_el_link_no_se_toca(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $mensaje   = 'Probalo acá: ' . self::LINK . ' ¿Te paso el link?';
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']));
        $this->assertSame(1, substr_count((string) $resultado['mensaje_sugerido'], self::LINK));
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — sin lugar no se toca
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. Sin lugar en el pool la oferta no se reemplaza.
     *
     * @return void
     */
    public function test_sin_lugar_en_el_pool_no_se_reemplaza(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])->delete();
        $this->assertFalse(LeadFlujoEntradaService::hay_lugar_en_el_pool(), 'Precondición: el pool quedó sin lugar.');

        $mensaje   = self::RESPUESTA_DEL_ENSAYO . ' ¿Te paso el link?';
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']));
        $this->assertSame('contactado', $resultado['estado_sugerido']);
    }

    /**
     * CA2. Con la oferta de la prueba apagada tampoco se reemplaza.
     *
     * @return void
     */
    public function test_con_la_oferta_inactiva_no_se_reemplaza(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        AdminSetting::set(LeadOfertaDePruebaService::KEY_ACTIVA, '0');

        $mensaje   = self::RESPUESTA_DEL_ENSAYO . ' ¿Te paso el link?';
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']));
        $this->assertSame('contactado', $resultado['estado_sugerido']);
    }

    /**
     * CA1/CA2. Las guardas existentes mandan: con el gate de rechazo o con intervención humana el paquete queda intacto.
     *
     * @return void
     */
    public function test_con_gate_o_intervencion_humana_el_paquete_queda_intacto(): void
    {
        $lead    = $this->lead_sin_puntos_debidos();
        $mensaje = self::RESPUESTA_DEL_ENSAYO . ' ¿Te paso el link?';

        $con_gate = LeadFlujoEntradaService::completar_paquete($lead, $this->paquete_ia($mensaje, 'contactado'), true);
        $this->assertSame($mensaje, $con_gate['mensaje_sugerido']);

        $humano = LeadFlujoEntradaService::completar_paquete(
            $lead,
            $this->paquete_ia($mensaje, 'contactado', 'conversacional', [], ['requiere_intervencion_humana' => true]),
            false
        );
        $this->assertSame($mensaje, $humano['mensaje_sugerido']);
    }

    /**
     * Un lead que pidió la demo (el flujo no le oculta la demo) se comporta como hoy.
     *
     * @return void
     */
    public function test_un_lead_que_pidio_la_demo_no_se_toca(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        Lead::query()->where('id', $lead->id)->update(['status' => 'solicita_disponibilidad']);
        $lead = $lead->fresh();
        $this->assertFalse(LeadFlujoEntradaService::oculta_la_demo($lead), 'Precondición: al lead le queda la demo.');

        $mensaje   = self::RESPUESTA_DEL_ENSAYO . ' ¿Te paso el link?';
        $resultado = $this->completar($lead, $mensaje, 'solicita_disponibilidad');

        $this->assertSame($mensaje, $resultado['mensaje_sugerido']);
    }

    /**
     * Fuera del flujo (lead creado antes del encendido) se comporta como hoy.
     *
     * @return void
     */
    public function test_un_lead_fuera_del_flujo_no_se_toca(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        Lead::query()->where('id', $lead->id)->update(['created_at' => '2020-01-01 00:00:00']);
        $lead = $lead->fresh();
        $this->assertFalse(LeadFlujoEntradaService::en_el_flujo($lead), 'Precondición: el lead quedó fuera del flujo.');

        $mensaje   = self::RESPUESTA_DEL_ENSAYO . ' ¿Te paso el link?';
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, $resultado['mensaje_sugerido']);
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — orden y puntos debidos intactos
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. Si el link ya salió por la guarda de CA1, el punto `link_prueba` no se agrega por segunda vez; las dos formas y la
     * página sí se agregan, antes de la pregunta final.
     *
     * @return void
     */
    public function test_el_link_no_se_agrega_dos_veces_y_la_pregunta_final_sigue_ultima(): void
    {
        $lead = $this->lead_con_todos_los_puntos_debidos();

        $resultado = $this->completar(
            $lead,
            self::RESPUESTA_DEL_ENSAYO . " ¿Te paso el link?\n---\n¿Qué rubro trabajás y más o menos cuántos productos manejás?"
        );

        $mensaje   = trim((string) $resultado['mensaje_sugerido']);
        $segmentos = explode("\n---\n", $mensaje);
        $ultimo_segmento = end($segmentos);
        $this->assertSame(1, substr_count($mensaje, self::LINK), 'El link aparece una sola vez.');
        $this->assertStringNotContainsString('¿Te paso el link?', $mensaje);
        $this->assertStringContainsString($this->texto_respuesta_1(), $mensaje, 'Las dos formas se agregan igual.');
        $this->assertStringContainsString($this->texto_respuesta_2(), $mensaje, 'La página se agrega igual.');
        $this->assertSame(
            '¿Qué rubro trabajás y más o menos cuántos productos manejás?',
            trim((string) $ultimo_segmento),
            'La pregunta final sigue siendo lo último que lee el lead.'
        );
        $this->assertStringStartsWith(self::RESPUESTA_DEL_ENSAYO . "\n---\n", $mensaje);
        $this->assertSame('link_enviado', $resultado['estado_sugerido']);
    }

    // ------------------------------------------------------------------------------------------
    // CA5 — texto del bloque
    // ------------------------------------------------------------------------------------------

    /**
     * CA5. Con lugar en el pool, el bloque del agente le dice que nunca pregunte si quiere el link.
     *
     * @return void
     */
    public function test_el_bloque_prohibe_preguntar_si_quiere_el_link_cuando_hay_lugar(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($lead);

        $this->assertStringContainsString(
            'Nunca preguntes si quiere el link («¿te paso el link?»): si corresponde, mandalo directamente.',
            $bloque
        );
    }

    /**
     * CA5. Sin lugar en el pool la línea no está (el bloque ya dice que no se le pase ningún link).
     *
     * @return void
     */
    public function test_el_bloque_sin_lugar_no_lleva_la_linea(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])->delete();

        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($lead);

        $this->assertNotSame('', $bloque, 'Precondición: el lead sigue teniendo bloque.');
        $this->assertStringNotContainsString('Nunca preguntes si quiere el link', $bloque);
    }
}
