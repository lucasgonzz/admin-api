<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Services\LeadFlujoEntradaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión martin-link-directo, CA3 y CA4 (parte de calificación): al lead del flujo de entrada no se le hacen preguntas
 * de calificación (si trabaja solo o con alguien, cuántos son, qué sistema usa, cómo se llama).
 *
 * Lo que pasó (ensayo del lead 711): Martín preguntó «¿la ferretería la manejás solo o hay alguien más con vos?» aunque el
 * bloque del flujo lo prohíbe. La IA decide contra el texto del bloque; la guarda determinista de `completar_paquete`
 * saca esas oraciones-pregunta antes de que el mensaje llegue al lead.
 */
class SinPreguntasDeCalificacionEnElFlujoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** La frase del ensayo 711. */
    private const SEGMENTO_DEL_ENSAYO = 'De paso, hace un rato me quedó la duda: ¿la ferretería la manejás solo o hay alguien más con vos?';

    /** Nota que deja la guarda en el razonamiento. */
    private const NOTA = 'Flujo de entrada: se sacó una pregunta de calificación.';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();
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
     * Lead `contactado` al que ya se le entregó todo (llegada, dos formas y página): no se le debe nada, así el paquete
     * sólo cambia por la guarda de calificación.
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
        $this->mensaje_del_lead($lead, 'Contame más');

        $lead = $lead->fresh();
        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: no se le debe ningún punto.');

        return $lead;
    }

    /**
     * Lead `contactado` que contestó el rubro: se le deben las dos formas y la página.
     *
     * @return Lead
     */
    private function lead_con_las_dos_formas_y_la_pagina_debidas(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, '¡Hola! Quiero más información');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 800 productos');

        $lead = $lead->fresh();
        $this->assertSame(['dos_formas', 'pagina'], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: se le deben las dos formas y la página.');

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
     * CA3. El ensayo 711: el segmento entero con la pregunta de «solo o alguien más» desaparece (incluida la parte anterior al
     * «¿» de la misma oración) y no quedan `---` sueltos.
     *
     * @return void
     */
    public function test_el_segmento_del_ensayo_desaparece_entero(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, "Genial, una ferretería.\n---\n" . self::SEGMENTO_DEL_ENSAYO);

        $this->assertSame('Genial, una ferretería.', trim((string) $resultado['mensaje_sugerido']));
        $this->assertStringContainsString(self::NOTA, (string) $resultado['razonamiento']);
        $this->assertStringContainsString('Razonamiento de prueba.', (string) $resultado['razonamiento'], 'La nota se suma, no pisa.');
    }

    /**
     * CA3. Cada forma de preguntar por calificación se saca.
     *
     * @dataProvider provider_de_preguntas_de_calificacion
     *
     * @param string $pregunta Oración-pregunta de calificación.
     *
     * @return void
     */
    public function test_se_saca_cada_pregunta_de_calificacion(string $pregunta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, 'Genial, una ferretería. ' . $pregunta);

        $this->assertSame('Genial, una ferretería.', trim((string) $resultado['mensaje_sugerido']));
        $this->assertStringContainsString(self::NOTA, (string) $resultado['razonamiento']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_preguntas_de_calificacion(): array
    {
        return [
            'solo o con alguien'    => ['¿Lo manejás solo o con alguien más?'],
            'sos solo'              => ['¿Sos solo en el negocio?'],
            'hay alguien mas'       => ['¿Hay alguien más con vos en el local?'],
            'cuantos trabajan'      => ['¿Cuántos trabajan con vos?'],
            'cuantas personas'      => ['¿Cuántas personas son en el equipo?'],
            'cuantos son'           => ['¿Cuántos son en el equipo?'],
            'tiene empleados'       => ['¿Tenés empleados?'],
            'que sistema usa'       => ['¿Qué sistema usás hoy?'],
            'tiene un sistema'      => ['¿Tenés algún sistema para llevar el stock?'],
            'como te llamas'        => ['¿Cómo te llamás?'],
            'tu nombre'             => ['¿Me pasás tu nombre?'],
        ];
    }

    /**
     * CA3. Lo que NO es calificación se queda: la pregunta del rubro y de la cantidad de productos, y preguntas comunes.
     *
     * @dataProvider provider_de_preguntas_legitimas
     *
     * @param string $pregunta Oración-pregunta que tiene que sobrevivir.
     *
     * @return void
     */
    public function test_no_se_saca_una_pregunta_que_no_es_de_calificacion(string $pregunta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $mensaje   = 'Genial, una ferretería. ' . $pregunta;
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']), 'Una pregunta legítima no se toca.');
        $this->assertStringNotContainsString(self::NOTA, (string) $resultado['razonamiento']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_preguntas_legitimas(): array
    {
        return [
            'rubro y productos'     => ['¿Qué rubro trabajás y más o menos cuántos productos manejás?'],
            'cuantos productos'     => ['¿Cuántos productos tenés cargados en una planilla?'],
            'pregunta de otra cosa' => ['¿Querés que te cuente cómo se cargan los precios?'],
            'listas de precios'     => ['¿Trabajás con más de una lista de precios?'],
        ];
    }

    /**
     * CA3. La detección es por oración: en un mismo segmento sólo se va la oración-pregunta de calificación y se conserva lo
     * demás (antes y después), sin dobles espacios ni separadores sueltos.
     *
     * @return void
     */
    public function test_en_el_medio_del_segmento_se_va_solo_la_oracion(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, 'Genial. De paso, ¿sos solo o tenés gente? Contame el rubro.');

        $this->assertSame('Genial. Contame el rubro.', trim((string) $resultado['mensaje_sugerido']));
    }

    /**
     * CA3. Un segmento intermedio que queda vacío se elimina sin dejar `---` dobles.
     *
     * @return void
     */
    public function test_un_segmento_intermedio_vacio_no_deja_separadores_dobles(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, "Primero.\n---\n¿Cuántos trabajan con vos?\n---\nSegundo.");

        $this->assertSame("Primero.\n---\nSegundo.", trim((string) $resultado['mensaje_sugerido']));
    }

    /**
     * CA4. La limpieza corre ANTES de calcular la pregunta final: si la IA cerraba con la pregunta de calificación, la
     * pregunta final pasa a ser la anterior y los puntos que se agregan van antes de ella.
     *
     * @return void
     */
    public function test_los_puntos_debidos_trabajan_sobre_el_mensaje_ya_limpio(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $resultado = $this->completar(
            $lead,
            "Genial, una ferretería.\n---\n¿Qué rubro trabajás y más o menos cuántos productos manejás?\n---\n¿Cuántos trabajan con vos?"
        );

        $this->assertSame(
            "Genial, una ferretería.\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2()
                . "\n---\n¿Qué rubro trabajás y más o menos cuántos productos manejás?",
            trim((string) $resultado['mensaje_sugerido']),
            'Sin la pregunta de calificación, la pregunta final es la del rubro y los puntos van antes de ella.'
        );
        $this->assertStringContainsString(self::NOTA, (string) $resultado['razonamiento']);
        $this->assertStringContainsString('se agregaron dos_formas, pagina', (string) $resultado['razonamiento']);
    }

    /**
     * CA4. Si la limpieza deja el mensaje vacío y se le deben puntos, el mensaje queda con ellos (nunca vacío).
     *
     * @return void
     */
    public function test_si_todo_era_calificacion_queda_lo_que_se_debe(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $resultado = $this->completar($lead, '¿Cuántos trabajan con vos?');

        $this->assertSame(
            $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2(),
            trim((string) $resultado['mensaje_sugerido'])
        );
    }

    /**
     * CA4. Si la limpieza deja el mensaje vacío y no hay nada que agregar, el paquete queda intacto (no se limpia).
     *
     * @return void
     */
    public function test_si_todo_era_calificacion_y_no_se_debe_nada_queda_intacto(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $paquete   = $this->paquete_ia('¿Cuántos trabajan con vos?', 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame($paquete, $resultado, 'Nunca un mensaje_sugerido vacío por culpa de la limpieza: no se limpia.');
    }

    /**
     * Las guardas existentes mandan: con el gate de rechazo o con intervención humana el paquete queda intacto.
     *
     * @return void
     */
    public function test_con_gate_o_intervencion_humana_el_paquete_queda_intacto(): void
    {
        $lead    = $this->lead_sin_puntos_debidos();
        $mensaje = 'Genial. ' . self::SEGMENTO_DEL_ENSAYO;

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
     * Fuera del flujo (lead creado antes del encendido) `completar_paquete` se comporta como hoy: no saca nada.
     *
     * @return void
     */
    public function test_un_lead_fuera_del_flujo_no_se_toca(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        Lead::query()->where('id', $lead->id)->update(['created_at' => '2020-01-01 00:00:00']);
        $lead = $lead->fresh();
        $this->assertFalse(LeadFlujoEntradaService::en_el_flujo($lead), 'Precondición: el lead quedó fuera del flujo.');

        $mensaje   = 'Genial. ' . self::SEGMENTO_DEL_ENSAYO;
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, $resultado['mensaje_sugerido']);
    }

    /**
     * Un lead que pidió la demo (el flujo no le oculta la demo) no pasa por la guarda: el estado `solicita_disponibilidad`
     * no está en los estados sin demo.
     *
     * @return void
     */
    public function test_un_lead_que_pidio_la_demo_no_se_toca(): void
    {
        $lead = $this->lead_sin_puntos_debidos();
        Lead::query()->where('id', $lead->id)->update(['status' => 'solicita_disponibilidad']);
        $lead = $lead->fresh();
        $this->assertFalse(LeadFlujoEntradaService::oculta_la_demo($lead), 'Precondición: al lead le queda la demo.');

        $mensaje   = 'Genial. ' . self::SEGMENTO_DEL_ENSAYO;
        $resultado = $this->completar($lead, $mensaje, 'solicita_disponibilidad');

        $this->assertSame($mensaje, $resultado['mensaje_sugerido']);
    }
}
