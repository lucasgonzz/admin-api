<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pruebas de `RunDemoSetupService::respuestas_para_payload()`: qué claves se le agregan al payload
 * del demo setup según la dinámica de demo del lead. Sin base de datos ni HTTP: el método es puro
 * (solo el lead y `LeadDemoFormMapper`), que es justamente lo que lo hace probable acá.
 */
class RunDemoSetupServiceRespuestasTest extends TestCase
{
    /**
     * El método avisa por `Log::info` cuando el formulario no está completado, así que la facade
     * necesita una raíz. Se le da un logger que descarta todo: no hay app booteada ni se quiere.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('log', new \Illuminate\Log\Logger(new MonologLogger('tests', [new NullHandler()])));

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Un lead con la dinámica actual no recibe ni una clave nueva: su payload tiene que quedar
     * idéntico al de siempre.
     */
    public function test_dinamica_actual_no_agrega_nada(): void
    {
        $lead = new Lead();
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;

        $this->assertSame([], $this->respuestas_para_payload($lead));
    }

    /**
     * Y tampoco la recibe un lead con la columna en null o con un valor inventado: es el fallback
     * duro de `demo_experiencia_efectiva()`. Este es el test que protege a los leads reales de
     * producción mientras la dinámica nueva no esté activada.
     */
    public function test_dinamica_desconocida_o_nula_tampoco_agrega_nada(): void
    {
        $lead_nulo = new Lead();
        $lead_nulo->demo_experiencia = null;
        $this->assertSame([], $this->respuestas_para_payload($lead_nulo));

        $lead_inventado = new Lead();
        $lead_inventado->demo_experiencia = 'una-dinamica-que-no-existe';
        $this->assertSame([], $this->respuestas_para_payload($lead_inventado));

        // Y el caso del lead recién instanciado, sin tocar la columna.
        $this->assertSame([], $this->respuestas_para_payload(new Lead()));
    }

    /**
     * Dinámica nueva con el formulario ya completado: viajan las nueve respuestas del lead,
     * `demo_form_completado => true` y los tres legados recalculados desde esas respuestas.
     */
    public function test_dinamica_nueva_con_formulario_completado_manda_las_respuestas_del_lead(): void
    {
        $lead = $this->lead_con_dinamica_nueva();
        $lead->use_price_lists                = false;
        $lead->use_deposits                   = false;
        $lead->omitir_cuentas_corrientes      = false;
        $lead->costos_en_dolares              = true;
        $lead->descuentos_por_metodo_pago     = false;
        $lead->usa_presupuestos               = true;
        $lead->registra_compras               = false;
        $lead->usa_ecommerce                  = false;
        $lead->usa_cuentas_corrientes_proveedores = false;

        // El formulario contestado: listas de precios, sin ecommerce y sin cuentas corrientes de
        // clientes (las columnas de arriba quedan pisadas por el mapper).
        \App\Services\LeadDemoFormMapper::to_lead($lead, [
            'tipo_precios'                       => 'listas',
            'costos_en_dolares'                  => true,
            'descuentos_por_metodo_pago'         => false,
            'usa_cuentas_corrientes_clientes'    => false,
            'usa_cuentas_corrientes_proveedores' => false,
            'usa_presupuestos'                   => true,
            'registra_compras'                   => false,
            'usa_ecommerce'                      => false,
            'usa_depositos'                      => true,
        ]);
        $this->marcar_formulario_completado($lead);

        $resultado = $this->respuestas_para_payload($lead);

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $resultado['demo_experiencia']);
        $this->assertTrue($resultado['demo_form_completado']);
        $this->assertCount(9, $resultado['respuestas_formulario']);
        $this->assertSame('listas', $resultado['respuestas_formulario']['tipo_precios']);
        $this->assertFalse($resultado['respuestas_formulario']['usa_ecommerce']);

        // Los tres legados, recalculados desde las respuestas y no desde las columnas.
        $this->assertTrue($resultado['use_price_lists']);
        $this->assertTrue($resultado['use_deposits']);
        // Coherencia del legado invertido: si el lead no usa cuentas corrientes de clientes,
        // usan_cuentas_corrientes tiene que viajar en false.
        $this->assertFalse($resultado['usan_cuentas_corrientes']);
    }

    /**
     * Dinámica nueva y formulario sin completar: viajan los defaults del catálogo, no las
     * columnas apagadas con las que nació el lead.
     */
    public function test_dinamica_nueva_sin_formulario_manda_los_defaults_del_catalogo(): void
    {
        $lead = $this->lead_con_dinamica_nueva();

        $resultado = $this->respuestas_para_payload($lead);

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $resultado['demo_experiencia']);
        $this->assertFalse($resultado['demo_form_completado']);

        $respuestas = $resultado['respuestas_formulario'];
        $this->assertCount(9, $respuestas);
        $this->assertSame('unico', $respuestas['tipo_precios']);
        $this->assertFalse($respuestas['costos_en_dolares']);
        $this->assertTrue($respuestas['descuentos_por_metodo_pago']);
        $this->assertTrue($respuestas['usa_cuentas_corrientes_clientes']);
        $this->assertTrue($respuestas['usa_cuentas_corrientes_proveedores']);
        $this->assertFalse($respuestas['usa_presupuestos']);
        $this->assertTrue($respuestas['registra_compras']);
        $this->assertTrue($respuestas['usa_ecommerce']);
        $this->assertFalse($respuestas['usa_depositos']);

        // Los legados salen de los defaults, aunque las columnas del lead digan otra cosa.
        $this->assertFalse($resultado['use_price_lists']);
        $this->assertFalse($resultado['use_deposits']);
        $this->assertTrue($resultado['usan_cuentas_corrientes']);
    }

    /**
     * El requisito duro del grupo, comprobado ejecutando y no leyendo el diff: para un lead con la
     * dinámica actual, el merge que hace `build_payload()` al final deja el payload igual —mismas
     * claves, mismos valores y mismo orden—, sin una sola clave de más.
     *
     * Se usa una muestra del payload real (no se puede armar el de verdad sin base: lee settings y
     * la relación `demo`), porque lo que se está verificando es el efecto del merge, que es lo
     * único que este prompt agregó a ese método.
     */
    public function test_el_merge_deja_intacto_el_payload_de_la_dinamica_actual(): void
    {
        $payload_de_hoy = [
            'name'                    => 'Lucas',
            'company_name'            => 'ComercioCity',
            'business_type'           => 'distribuidora',
            'iva_included'            => true,
            'usan_cuentas_corrientes' => true,
            'use_deposits'            => false,
            'use_price_lists'         => true,
            'cajas'                   => false,
        ];

        $lead = new Lead();
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;

        $mergeado = array_merge($payload_de_hoy, $this->respuestas_para_payload($lead));

        $this->assertSame($payload_de_hoy, $mergeado);
        $this->assertSame(array_keys($payload_de_hoy), array_keys($mergeado));
    }

    /**
     * Lead con la dinámica nueva activada y nada más.
     */
    private function lead_con_dinamica_nueva(): Lead
    {
        $lead = new Lead();
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;

        return $lead;
    }

    /**
     * Marca el formulario como completado sin pasar por el setter: el cast `datetime` le pide el
     * formato de fecha a la conexión, y acá no hay base.
     */
    private function marcar_formulario_completado(Lead $lead): void
    {
        $lead->setRawAttributes(array_merge(
            $lead->getAttributes(),
            ['demo_form_completado_at' => new Carbon('2026-08-05 10:00:00')]
        ));
    }

    /**
     * `respuestas_para_payload()` es `protected` a propósito (no es API del servicio): se alcanza
     * por reflexión en vez de volverlo público sólo para el test.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function respuestas_para_payload(Lead $lead): array
    {
        $metodo = new ReflectionMethod(RunDemoSetupService::class, 'respuestas_para_payload');
        $metodo->setAccessible(true);

        return $metodo->invoke(new RunDemoSetupService(), $lead);
    }
}
