<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\LeadDemoSettings;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadOrigenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El link de la prueba para el lead del anuncio, el cierre de los mensajes con la pregunta al final y el bloque del
 * flujo para quien pregunta precios (misión embudo-prueba-martin-precios, criterios CA5 a CA8 del PLAN).
 *
 * Lo que pasó: los cuatro textos precargados de los anuncios («Hola, vi el video de los presupuestos y quiero probar
 * ComercioCity», etc.) no contienen la frase contigua de la web («Hola, quiero probar ComercioCity»), así que el lead
 * del anuncio quedaba con origen desconocido y NUNCA recibía el link de la prueba aunque su primer mensaje dijera
 * «quiero probar». Además, cuando la IA cerraba con una pregunta de calificación, el flujo pegaba las dos formas y la
 * página DETRÁS de la pregunta.
 *
 * Lo pedido:
 *  - CA5 el primer mensaje que dice «quiero probar» (los cuatro textos) hace que al contestar el rubro se le deban
 *        las dos formas, la página y el link, y que el link salga por el camino real del webhook.
 *  - CA6 lo que no cambia: «¡Hola! Quiero más información» (el texto por defecto de Meta) no debe el link, y sin lugar
 *        en el pool nadie lo debe.
 *  - CA7 los puntos que completa el flujo van ANTES de la pregunta final de la IA.
 *  - CA8 el bloque del flujo dice qué hacer ante la pregunta por precios (link de la prueba) y no pide datos.
 *
 * Hoy están EN ROJO los que describen lo nuevo (CA5 link, CA7 pregunta al final, CA8); los de no-regresión (CA5
 * «no es pregunta ni demo», CA6, CA7 «sin pregunta final») pasan ya.
 */
class LinkDeLaPruebaAlLeadDelAnuncioTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Los cuatro textos precargados que se cargan en `lead_whatsapp_flujo_textos_precargados` y en los anuncios de Meta. */
    private const TEXTOS_PRECARGADOS = [
        'Hola, vi el video de los presupuestos y quiero probar ComercioCity',
        'Hola, vi el video de la factura por foto y quiero probar ComercioCity',
        'Hola, vi el video de la atención por WhatsApp y quiero probar ComercioCity',
        'Hola, vi el video y quiero probar ComercioCity',
    ];

    /** Link textual de la prueba (el mismo que arma `LeadOfertaDePruebaService::link()` por defecto). */
    private const LINK_DE_LA_PRUEBA = 'https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        AdminSetting::set('lead_whatsapp_flujo_textos_precargados', implode("\n", self::TEXTOS_PRECARGADOS));

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
    // CA5 — el lead del anuncio que dice «quiero probar»
    // ------------------------------------------------------------------------------------------

    /**
     * CA5. Cada uno de los cuatro textos precargados: `tiene_pregunta` es false (con y sin cargarlos en la perilla) y
     * no es el «sí» a la demo (`linea_del_si_explicito` vacía con las frases CTA de producción). Es de no-regresión:
     * pasa ya.
     *
     * @dataProvider provider_de_los_textos_precargados
     *
     * @param string $texto Primer mensaje del lead.
     *
     * @return void
     */
    public function test_texto_precargado_no_es_pregunta_ni_demo(string $texto): void
    {
        $this->assertFalse(LeadFlujoEntradaService::tiene_pregunta($texto), 'Cargado en la perilla no es una pregunta.');

        AdminSetting::set('lead_whatsapp_flujo_textos_precargados', '');
        $this->assertFalse(LeadFlujoEntradaService::tiene_pregunta($texto), 'Sin cargar tampoco: no lleva «?» ni una expresión de pregunta.');

        $lead = $this->crear_lead_de_flujo('nuevo');
        $this->mensaje_del_lead($lead, $texto);
        $this->assertSame('', LeadOrigenService::linea_del_si_explicito($lead->fresh()), 'No es el «sí» a la demo: las frases CTA son otras.');
    }

    /**
     * CA5. Por el camino real del webhook: el lead escribe el texto precargado, recibe la llegada (dos mensajes),
     * contesta «Almacén, unos 800 productos» y se le deben, en orden, las dos formas, la página y el link de la
     * prueba. Al correr el job salen los tres textos (el último con el link textual) y el lead queda `link_enviado`.
     * Hoy falta el link: el lead del anuncio no cuenta como lead de la web.
     *
     * @dataProvider provider_de_los_textos_precargados
     *
     * @param string $texto Primer mensaje del lead.
     *
     * @return void
     */
    public function test_al_contestar_el_rubro_sale_el_link(string $texto): void
    {
        [$telefono, $lead] = $this->llegar_y_contestar_el_rubro($texto);

        $this->assertSame(
            ['dos_formas', 'pagina', 'link_prueba'],
            LeadFlujoEntradaService::puntos_debidos($lead->fresh()),
            'Quien dice «quiero probar» en su primer mensaje debe recibir también el link.'
        );

        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'Precondición: contestar sin pregunta encola el job de las dos formas.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());
        $cuerpos = $this->cuerpos_enviados($espia);

        $this->assertCount(3, $cuerpos, 'Salen las dos formas, la página y el link.');
        $this->assertSame($this->texto_respuesta_1(), $cuerpos[0]);
        $this->assertSame($this->texto_respuesta_2(), $cuerpos[1]);
        $this->assertStringContainsString(self::LINK_DE_LA_PRUEBA, $cuerpos[2]);
        $this->assertSame(self::LINK_DE_LA_PRUEBA, LeadOfertaDePruebaService::link(), 'Precondición: el link textual es el de siempre.');
        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Al salir el link el lead pasa a link_enviado.');
        $this->assertNotEmpty($telefono);
    }

    /**
     * Los cuatro textos precargados.
     *
     * @return array<string, array{0: string}>
     */
    public function provider_de_los_textos_precargados(): array
    {
        $casos = [];
        foreach (self::TEXTOS_PRECARGADOS as $indice => $texto) {
            $casos['precargado ' . ($indice + 1)] = [$texto];
        }

        return $casos;
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — lo que no cambia
    // ------------------------------------------------------------------------------------------

    /**
     * CA6. El texto por defecto de Meta («¡Hola! Quiero más información», el del ensayo 707) no dice «quiero probar»:
     * al contestar el rubro se le deben sólo las dos formas y la página, sin el link (el link lo pide él o va tras la
     * pregunta de precio). Es de no-regresión: pasa ya.
     *
     * @return void
     */
    public function test_quiero_mas_informacion_no_debe_el_link(): void
    {
        [, $lead] = $this->llegar_y_contestar_el_rubro('¡Hola! Quiero más información');

        $this->assertSame(['dos_formas', 'pagina'], LeadFlujoEntradaService::puntos_debidos($lead->fresh()));
    }

    /**
     * CA6. Con el pool de pruebas sin lugar ningún lead debe el link: ni el del anuncio que dijo «quiero probar» ni el
     * de la web. Es de no-regresión: pasa ya (hoy ninguno lo debe; mañana el del anuncio lo deberá sólo con lugar).
     *
     * @return void
     */
    public function test_sin_lugar_en_el_pool_no_debe_el_link(): void
    {
        $textos = [self::TEXTOS_PRECARGADOS[3], LeadOrigenService::FRASE_LANDING_PRUEBA];

        foreach ($textos as $texto) {
            [, $lead] = $this->llegar_y_contestar_el_rubro($texto);

            /* Sin ninguna instancia libre ni creándose, `hay_lugar_en_el_pool()` es false. */
            TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])->delete();
            $this->assertFalse(LeadFlujoEntradaService::hay_lugar_en_el_pool(), 'Precondición: el pool quedó sin lugar.');

            $this->assertSame(
                ['dos_formas', 'pagina'],
                LeadFlujoEntradaService::puntos_debidos($lead->fresh()),
                "Sin lugar en el pool no se debe el link (primer mensaje: «{$texto}»)."
            );

            /* El pool vuelve a tener lugar para la vuelta siguiente. */
            TrialInstance::create([
                'nombre' => 'fe-sin-lugar-' . $lead->id, 'estado' => TrialInstance::ESTADO_LIBRE,
                'erp_vps_path' => 'prueba-sintetica', 'ecommerce_vps_path' => 'tienda-sintetica',
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA7 — la pregunta va al final
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. La IA cierra con una pregunta de calificación y se deben las dos formas y la página: los puntos que agrega
     * el flujo van ANTES de esa pregunta, para que la pregunta sea lo último que lee el lead.
     *
     * @return void
     */
    public function test_los_puntos_van_antes_de_la_pregunta_final(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia("Te cuento cómo funciona.\n---\n¿A qué se dedica tu negocio?", 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame(
            "Te cuento cómo funciona.\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2() . "\n---\n¿A qué se dedica tu negocio?",
            trim((string) $resultado['mensaje_sugerido']),
            'Los puntos agregados van antes de la pregunta final.'
        );
        $this->assertStringContainsString('Flujo de entrada: se agregaron dos_formas, pagina', (string) $resultado['razonamiento']);
    }

    /**
     * CA7. Sin pregunta final el orden es el de siempre: el texto de la IA y, después, los puntos agregados. Es de
     * no-regresión: pasa ya (los CA30 de `PuntosObligatoriosDelFlujoTest` lo cubren también).
     *
     * @return void
     */
    public function test_sin_pregunta_final_el_orden_de_siempre(): void
    {
        $lead = $this->lead_con_las_dos_formas_y_la_pagina_debidas();

        $paquete   = $this->paquete_ia('Te cuento cómo funciona.', 'contactado');
        $resultado = LeadFlujoEntradaService::completar_paquete($lead, $paquete, false);

        $this->assertSame(
            "Te cuento cómo funciona.\n---\n" . $this->texto_respuesta_1() . "\n---\n" . $this->texto_respuesta_2(),
            trim((string) $resultado['mensaje_sugerido'])
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA8 — el bloque del flujo
    // ------------------------------------------------------------------------------------------

    /**
     * CA8. El bloque del agente de un lead `contactado` con lugar en el pool dice, en el renglón de la pregunta por
     * precios, que se conteste con PRECIOS VIGENTES y se cierre con el link textual de la prueba; y dice que no se le
     * pide el nombre. Hoy el renglón de precios sólo dice «dale los de PRECIOS VIGENTES» y el bloque no habla del nombre.
     *
     * @return void
     */
    public function test_el_bloque_manda_el_link_tras_el_precio_y_no_pide_datos(): void
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->mensaje_del_lead($lead, 'Hola, vi el video y quiero probar ComercioCity');
        $this->dejar_entregado_el_paso_1($lead);
        $this->assertTrue(LeadFlujoEntradaService::hay_lugar_en_el_pool(), 'Precondición: hay lugar en el pool.');

        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($lead->fresh());

        $this->assertStringContainsString(self::LINK_DE_LA_PRUEBA, $bloque, 'Precondición: el bloque trae el link textual.');
        $this->assertSame(
            1,
            preg_match('/Si pregunta precios[^\n]*\n?[^\n]*link de la prueba/u', $bloque),
            'El renglón de la pregunta por precios tiene que mandar a cerrar con el link de la prueba.'
        );
        $this->assertStringContainsString('PRECIOS VIGENTES', $bloque);
        $this->assertStringContainsString('No pidas el nombre', $bloque, 'Con el rubro alcanza: no se pide nombre, ni cuántos trabajan, ni qué sistema usa.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Lleva a un lead nuevo por el webhook real hasta que contesta el rubro: escribe `$primer_mensaje`, recibe la
     * llegada (corre el job de la presentación), pasa un rato y contesta «Almacén, unos 800 productos» (sin pregunta).
     * Deja encolado el job de las dos formas.
     *
     * @param string $primer_mensaje Primer mensaje del lead.
     *
     * @return array{0: string, 1: Lead} Teléfono y lead.
     */
    private function llegar_y_contestar_el_rubro(string $primer_mensaje): array
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, $primer_mensaje)->assertStatus(200);
        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        /* La cola falsa acumula los de los leads anteriores del test: se corre el último, que es el de este lead. */
        $this->assertGreaterThanOrEqual(1, $jobs->count(), 'Precondición: tiene que haber un job de la llegada.');
        $this->espiar_whatsapp();
        $this->correr_job($jobs->last());
        $this->assertCount(2, $this->mensajes_de_sistema($lead), 'Precondición: salieron los dos mensajes de la llegada.');

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Almacén, unos 800 productos')->assertStatus(200);

        return [$telefono, $lead->fresh()];
    }

    /**
     * Lead `contactado` con la llegada entregada que ya contestó: se le deben las dos formas y la página (y, como su
     * primer mensaje es el texto por defecto de Meta, no el link).
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
}
