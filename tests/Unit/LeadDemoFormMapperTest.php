<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Services\LeadDemoFormMapper;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del mapeo entre las nueve respuestas del formulario de la página inmersiva y las
 * columnas de `leads`. Sin base de datos: el lead se instancia en memoria.
 */
class LeadDemoFormMapperTest extends TestCase
{
    /**
     * Un lead que todavía no completó el formulario tiene que recibir los defaults del catálogo
     * (`contexto/demo_catalogo.md` §2), no las columnas apagadas con las que nació.
     */
    public function test_respuestas_efectivas_devuelve_los_defaults_si_el_formulario_no_se_completo(): void
    {
        $lead = new Lead();
        $lead->demo_form_completado_at = null;

        $respuestas = LeadDemoFormMapper::respuestas_efectivas($lead);

        $esperadas = [
            'tipo_precios'                       => 'unico',
            'costos_en_dolares'                  => false,
            'descuentos_por_metodo_pago'         => true,
            'usa_cuentas_corrientes_clientes'    => true,
            'usa_cuentas_corrientes_proveedores' => true,
            'usa_presupuestos'                   => false,
            'registra_compras'                   => true,
            'usa_ecommerce'                      => true,
            'usa_depositos'                      => false,
        ];

        // Se comparan ordenados por clave: importan las nueve claves y sus valores exactos
        // (assertSame conserva la estrictez de tipos), no el orden en que se declararon.
        $ordenadas = $respuestas;
        ksort($esperadas);
        ksort($ordenadas);
        $this->assertSame($esperadas, $ordenadas);

        // Las cuatro que más caro salen si se toman apagadas por error.
        $this->assertTrue($respuestas['descuentos_por_metodo_pago']);
        $this->assertTrue($respuestas['usa_cuentas_corrientes_clientes']);
        $this->assertTrue($respuestas['usa_cuentas_corrientes_proveedores']);
        $this->assertTrue($respuestas['registra_compras']);
        $this->assertTrue($respuestas['usa_ecommerce']);
    }

    /**
     * Un lead que sí completó el formulario tiene que recibir SUS respuestas, incluso cuando
     * apagó algo que en el default está encendido. Si alguien invierte la condición de
     * `demo_form_completado_at`, este test se pone rojo.
     */
    public function test_respuestas_efectivas_devuelve_las_del_lead_si_el_formulario_se_completo(): void
    {
        $respuestas_cargadas = [
            'tipo_precios'                       => 'listas',
            'costos_en_dolares'                  => true,
            'descuentos_por_metodo_pago'         => false,
            'usa_cuentas_corrientes_clientes'    => false,
            'usa_cuentas_corrientes_proveedores' => false,
            'usa_presupuestos'                   => true,
            'registra_compras'                   => false,
            // Encendida en el default del catálogo, apagada por este lead: es la que delata
            // que se estén devolviendo los defaults en vez de las respuestas reales.
            'usa_ecommerce'                      => false,
            'usa_depositos'                      => true,
        ];

        $lead = new Lead();
        LeadDemoFormMapper::to_lead($lead, $respuestas_cargadas);

        // La fecha se escribe cruda y ya como objeto: asignarla por el setter, o dejarla como
        // string, hace que el cast `datetime` le pida el formato de fecha a la conexión, y acá no
        // hay base. Con una instancia de Carbon el cast la devuelve tal cual, sin conexión.
        $lead->setRawAttributes(array_merge(
            $lead->getAttributes(),
            ['demo_form_completado_at' => new Carbon('2026-08-05 10:00:00')]
        ));

        $efectivas = LeadDemoFormMapper::respuestas_efectivas($lead);

        $esperadas = $respuestas_cargadas;
        ksort($esperadas);
        ksort($efectivas);
        $this->assertSame($esperadas, $efectivas);
        $this->assertFalse($efectivas['usa_ecommerce']);
    }

    /**
     * El ida y vuelta de la inversión `usa_cuentas_corrientes_clientes` ↔
     * `omitir_cuentas_corrientes`, que es el error más caro de todo el circuito.
     */
    public function test_la_inversion_de_cuentas_corrientes_de_clientes_va_y_vuelve(): void
    {
        $lead = new Lead();
        LeadDemoFormMapper::to_lead($lead, ['usa_cuentas_corrientes_clientes' => false]);

        $this->assertTrue($lead->omitir_cuentas_corrientes);
        $this->assertFalse(LeadDemoFormMapper::from_lead($lead)['usa_cuentas_corrientes_clientes']);

        LeadDemoFormMapper::to_lead($lead, ['usa_cuentas_corrientes_clientes' => true]);

        $this->assertFalse($lead->omitir_cuentas_corrientes);
        $this->assertTrue(LeadDemoFormMapper::from_lead($lead)['usa_cuentas_corrientes_clientes']);
    }
}
