<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Services\LeadFlujoEntradaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión martin-link-directo, ronda 2 (rechazo de la revisión): las guardas de preguntas de `completar_paquete` eran
 * demasiado anchas. Estos casos fijan lo que NO se toca (preguntas legítimas de las dos formas de arrancar, ofertas de otros
 * links, estados que no son del camino de la prueba) y los casos nuevos que sí.
 *
 *  1. «solo o» / «alguien más» / «tu nombre» sólo son calificación en su forma de calificar.
 *  2. Sólo es oferta del link de la PRUEBA la que nombra la prueba o la que no lleva destino.
 *  3. Con un estado_sugerido de otro camino (implementación pedida, demo) no se reemplaza la oferta ni se pisa el estado.
 *  4. Si al sacar la pregunta queda un arranque que termina en «:», se saca también.
 */
class AjustesDeLasGuardasDePreguntaTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Respuesta de relleno que precede a la pregunta en los casos. */
    private const BASE = 'Sale $628.400 la implementación.';

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
     * Lead al que ya se le entregó todo (no se le debe ningún punto), en el estado pedido.
     *
     * @param string $estado Estado del lead.
     *
     * @return Lead
     */
    private function lead_sin_puntos_debidos(string $estado = 'contactado'): Lead
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
        if ($estado !== 'contactado') {
            Lead::query()->where('id', $lead->id)->update(['status' => $estado]);
        }

        $lead = $lead->fresh();
        $this->assertSame([], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición: no se le debe ningún punto.');

        return $lead;
    }

    /**
     * @param Lead   $lead
     * @param string $mensaje
     * @param string $estado
     *
     * @return array<string, mixed>
     */
    private function completar(Lead $lead, string $mensaje, string $estado = 'contactado'): array
    {
        return LeadFlujoEntradaService::completar_paquete($lead, $this->paquete_ia($mensaje, $estado), false);
    }

    /**
     * Ítem 1. Preguntas legítimas que NO se tocan.
     *
     * @dataProvider provider_de_preguntas_que_no_son_calificacion
     *
     * @param string $pregunta Oración-pregunta que tiene que sobrevivir.
     *
     * @return void
     */
    public function test_la_pregunta_legitima_no_se_toca(string $pregunta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $mensaje   = self::BASE . ' ' . $pregunta;
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']));
        $this->assertSame('contactado', $resultado['estado_sugerido']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_preguntas_que_no_son_calificacion(): array
    {
        return [
            'solo o con nuestra ayuda'   => ['¿Preferís arrancarlo solo o con nuestra ayuda?'],
            'solo o con nosotros'        => ['¿Lo querés armar solo o con nosotros?'],
            'solo o con ayuda nuestra'   => ['¿Lo cargás vos solo o con ayuda nuestra?'],
            'alguien mas del equipo'     => ['¿Querés que alguien más del equipo te escriba?'],
            'tienda con tu nombre'       => ['¿La tienda sale con tu nombre o con el del negocio?'],
        ];
    }

    /**
     * Ítem 1. Formas nuevas de calificar que sí se sacan.
     *
     * @dataProvider provider_de_preguntas_de_calificacion_nuevas
     *
     * @param string $pregunta Oración-pregunta de calificación.
     *
     * @return void
     */
    public function test_la_pregunta_de_calificacion_se_saca(string $pregunta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, self::BASE . ' ' . $pregunta);

        $this->assertSame(self::BASE, trim((string) $resultado['mensaje_sugerido']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_preguntas_de_calificacion_nuevas(): array
    {
        return [
            'solo o hay'            => ['¿Lo manejás solo o hay alguien más?'],
            'solo o con empleados'  => ['¿Lo manejás solo o con empleados?'],
            'solo o con gente'      => ['¿Lo manejás solo o con gente?'],
            'solo o tenes gente'    => ['¿Lo manejás solo o tenés gente?'],
            'trabajas solo'         => ['¿Trabajás solo?'],
            'alguien mas con vos'   => ['¿Hay alguien más con vos?'],
            'alguien mas atiende'   => ['¿Atiende alguien más el local?'],
            'cual es tu nombre'     => ['¿Cuál es tu nombre?'],
        ];
    }

    /**
     * Ítem 2. Ofertas de OTROS links: no se reemplazan por el link de la prueba.
     *
     * @dataProvider provider_de_ofertas_de_otros_links
     *
     * @param string $oferta Oración-pregunta que ofrece un link que no es el de la prueba.
     *
     * @return void
     */
    public function test_la_oferta_de_otro_link_no_se_reemplaza(string $oferta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $mensaje   = self::BASE . ' ' . $oferta;
        $resultado = $this->completar($lead, $mensaje);

        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']));
        $this->assertSame('contactado', $resultado['estado_sugerido']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_ofertas_de_otros_links(): array
    {
        return [
            'link de la pagina'      => ['¿Te mando el link de la página?'],
            'link de nuestra pagina' => ['¿Te paso el link de nuestra página para que veas los testimonios?'],
            'link del video'         => ['¿Querés que te pase el link del video que te guía para subir el Excel?'],
            'link para pagar'        => ['¿Querés que te mande el link para pagar la implementación?'],
        ];
    }

    /**
     * Ítem 2. Las ofertas que sí son del link de la prueba (la nombran o no llevan destino) se reemplazan.
     *
     * @dataProvider provider_de_ofertas_de_la_prueba
     *
     * @param string $oferta Oración-pregunta que ofrece el link de la prueba.
     *
     * @return void
     */
    public function test_la_oferta_del_link_de_la_prueba_se_reemplaza(string $oferta): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, self::BASE . ' ' . $oferta);

        $this->assertSame(
            self::BASE . "\n---\n" . LeadFlujoEntradaService::texto_del_punto('link_prueba'),
            trim((string) $resultado['mensaje_sugerido'])
        );
        $this->assertSame('link_enviado', $resultado['estado_sugerido']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_ofertas_de_la_prueba(): array
    {
        return [
            'sin destino'          => ['¿Te paso el link?'],
            'querés el link'       => ['¿Querés el link?'],
            'con así arrancás'     => ['¿Te mando el link así arrancás?'],
            'nombra la prueba'     => ['¿Te paso el link de la prueba?'],
            'nombra probarlo'      => ['¿Querés que te mande el link para probarlo?'],
        ];
    }

    /**
     * Ítem 3. Con el estado sugerido de otro camino la oferta no se reemplaza y el estado no se pisa.
     *
     * @dataProvider provider_de_estados_de_otro_camino
     *
     * @param string $estado_sugerido Estado que sugirió la IA.
     *
     * @return void
     */
    public function test_un_estado_de_otro_camino_no_se_pisa(string $estado_sugerido): void
    {
        $lead = $this->lead_sin_puntos_debidos('calificado');

        $mensaje   = 'Perfecto, te llama Juan hoy. ¿Te paso el link de la prueba?';
        $resultado = $this->completar($lead, $mensaje, $estado_sugerido);

        $this->assertSame($estado_sugerido, $resultado['estado_sugerido'], 'El estado de la IA no se pisa.');
        $this->assertSame($mensaje, trim((string) $resultado['mensaje_sugerido']), 'La guarda no agrega el link.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_estados_de_otro_camino(): array
    {
        return [
            'implementacion pedida'    => ['implementacion_pedida'],
            'solicita disponibilidad'  => ['solicita_disponibilidad'],
        ];
    }

    /**
     * Ítem 3. Con el estado vacío o de la prueba (incluido `link_enviado`) la oferta sí se reemplaza.
     *
     * @dataProvider provider_de_estados_del_camino_de_la_prueba
     *
     * @param string $estado_sugerido Estado que sugirió la IA.
     *
     * @return void
     */
    public function test_un_estado_del_camino_de_la_prueba_si_se_reemplaza(string $estado_sugerido): void
    {
        $lead = $this->lead_sin_puntos_debidos('calificado');

        $resultado = $this->completar($lead, self::BASE . ' ¿Te paso el link?', $estado_sugerido);

        $this->assertSame(
            self::BASE . "\n---\n" . LeadFlujoEntradaService::texto_del_punto('link_prueba'),
            trim((string) $resultado['mensaje_sugerido'])
        );
        $this->assertSame('link_enviado', $resultado['estado_sugerido']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function provider_de_estados_del_camino_de_la_prueba(): array
    {
        return [
            'vacio'        => [''],
            'calificado'   => ['calificado'],
            'link enviado' => ['link_enviado'],
        ];
    }

    /**
     * Ítem 4. Si al sacar la pregunta queda un arranque que termina en «:», se saca también.
     *
     * @return void
     */
    public function test_un_arranque_con_dos_puntos_huerfano_se_saca(): void
    {
        $lead = $this->lead_sin_puntos_debidos();

        $resultado = $this->completar($lead, "Genial, una ferretería. Contame:\n¿Cuántos trabajan con vos?");

        $this->assertSame('Genial, una ferretería.', trim((string) $resultado['mensaje_sugerido']));
    }
}
