<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Services\LeadFlujoEntradaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ronda 2A de la misión embudo-prueba-martin-precios: la pregunta final es la ORACIÓN que contiene el último «¿»
 * (desde el último límite de oración anterior a ese «¿», o desde el principio del segmento), no sólo lo que sigue
 * a un fin de oración pegado al «¿».
 *
 * Caso del hallazgo: «…agentes de IA. Contame, ¿a qué se dedica tu negocio?» no se separaba porque lo inmediatamente
 * anterior al «¿» era «Contame, ».
 */
class PreguntaFinalConGiroTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

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
     * Casos: [mensaje de la IA, respuesta que queda antes de los puntos ('' si no hay), pregunta que queda al final].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function casos(): array
    {
        return [
            'giro antes del signo' => [
                'Es un sistema para el comercio, con tienda y agentes de IA. Contame, ¿a qué se dedica tu negocio?',
                'Es un sistema para el comercio, con tienda y agentes de IA.',
                'Contame, ¿a qué se dedica tu negocio?',
            ],
            'salto de línea y giro' => [
                "Todo en un solo lugar 🙌\nY vos, ¿a qué te dedicás?",
                'Todo en un solo lugar 🙌',
                'Y vos, ¿a qué te dedicás?',
            ],
            'dos puntos dentro de la oración' => [
                'Mirá: te cuento rápido. Lo usan ferreterías: ¿vendés online?',
                'Mirá: te cuento rápido.',
                'Lo usan ferreterías: ¿vendés online?',
            ],
            'todo el segmento es la pregunta' => [
                'Hola, ¿qué tal?',
                '',
                'Hola, ¿qué tal?',
            ],
            'caso del ensayo 707' => [
                'Te cuento rapidito cómo funciona. Todo en un solo lugar: ventas, stock y caja. ¿A qué se dedica tu negocio?',
                'Te cuento rapidito cómo funciona. Todo en un solo lugar: ventas, stock y caja.',
                '¿A qué se dedica tu negocio?',
            ],
        ];
    }

    /**
     * La pregunta (la oración con el último «¿») queda al final, detrás de los puntos; la respuesta queda antes; y
     * cada parte aparece exactamente una vez.
     *
     * @dataProvider casos
     *
     * @param string $mensaje   Lo que contesta la IA, en un solo segmento.
     * @param string $respuesta Lo que tiene que quedar antes de los puntos ('' si no hay nada).
     * @param string $pregunta  Lo que tiene que quedar al final.
     *
     * @return void
     */
    public function test_la_oracion_con_el_ultimo_signo_de_pregunta_queda_al_final(string $mensaje, string $respuesta, string $pregunta): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia($mensaje, 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);
        $texto     = trim((string) $resultado['mensaje_sugerido']);

        $esperado = ($respuesta === '' ? '' : $respuesta . "\n---\n")
            . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2() . "\n---\n" . $pregunta;
        $this->assertSame($esperado, $texto);

        /* Nada perdido ni duplicado. */
        foreach (array_filter([$respuesta, $pregunta, $this->texto_respuesta_1(), $this->texto_respuesta_2()]) as $parte) {
            $this->assertSame(1, substr_count($texto, $parte), 'Falta o se repite: ' . $parte);
        }
    }

    /**
     * Lead `contactado` al que se le deben las dos formas y la página.
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
        $this->assertSame(['dos_formas', 'pagina'], LeadFlujoEntradaService::puntos_debidos($lead), 'Precondición.');

        return $lead;
    }
}
