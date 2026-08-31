<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadDemoFormMapper;
use App\Services\RunDemoSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El payload del demo-setup, en sus dos formas.
 *
 * `RunDemoSetupService` ahora arma dos payloads distintos para el mismo endpoint:
 *
 *   - `build_payload(Lead)`, el de siempre, que dispara el panel de Leads.
 *   - `payload_de_defaults(Demo)`, el nuevo, que usa el pipeline de instalación de una demo, donde
 *     todavía no hay ningún lead asignado.
 *
 * 🔴 El primero de estos tests es de NO REGRESIÓN y es el criterio de aceptación del cambio: el
 * payload del lead lo consumen leads REALES en producción, y una clave que cambie de valor —o que
 * desaparezca— no falla ruidosamente. La demo se arma distinta y nadie se entera hasta que el lead
 * la tiene delante.
 */
class PayloadDelDemoSetupSinLeadTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Un lead de la dinámica actual, con todas sus columnas en el default de la base, tiene que
     * seguir produciendo exactamente el mismo payload que antes de que existiera
     * payload_de_defaults(): las mismas claves y los mismos valores.
     *
     * @return void
     */
    public function test_el_payload_de_un_lead_no_cambio(): void
    {
        $demo = $this->crear_demo();

        $lead = Lead::create([
            'contact_name'  => 'Juan Pérez',
            'company_name'  => 'Ferretería Pérez',
            'email'         => 'juan@ferreteriaperez.test',
            'doc_number'    => '20304050607',
            'business_type' => 'ferreteria',
            'demo_id'       => $demo->id,
        ]);
        $lead->loadMissing('demo');

        $payload = $this->build_payload($lead);

        // Datos del lead, tal cual.
        $this->assertSame('Juan Pérez', $payload['name']);
        $this->assertSame('Ferretería Pérez', $payload['company_name']);
        $this->assertSame('20304050607', $payload['doc_number']);
        $this->assertSame('juan@ferreteriaperez.test', $payload['email']);
        $this->assertSame('ferreteria', $payload['business_type']);

        // La tienda sale de la demo asignada al lead.
        $this->assertSame($demo->ecommerce_api_url, $payload['online']);

        // Los flags, en el default de las columnas de `leads` (todas false).
        foreach ([
            'iva_included',
            'redondear_centenas_en_vender',
            'ask_amount_in_vender',
            'use_price_lists',
            'ventas_con_fecha_de_entrega',
            'cajas',
            'usar_codigos_de_barra',
            'codigos_de_barra_por_defecto',
            'consultora_de_precios',
            'imagenes',
            'produccion',
        ] as $flag) {
            $this->assertFalse($payload[$flag], "El flag {$flag} cambió de valor por defecto.");
        }

        /* 🔴 `use_deposits` y las tres sucursales NO salen del default de la columna: los fuerza el
         * hook `creating` de Lead ("todo lead nuevo arranca configurado para demo"), que pisa el
         * false de la base. Esto es exactamente lo que el test tiene que congelar — es el tipo de
         * comportamiento que un refactor "limpia" sin darse cuenta y deja a todas las demos de los
         * leads sin depósitos ni sucursales. */
        $this->assertTrue($payload['use_deposits']);
        $this->assertSame('Sucursal 1', $payload['address_1']);
        $this->assertSame('Sucursal 2', $payload['address_2']);
        $this->assertSame('Sucursal 3', $payload['address_3']);

        /* 🔴 La inversión de siempre: el Lead guarda `omitir_cuentas_corrientes` y el helper de
         * empresa-api espera `usan_cuentas_corrientes`. Con la columna en su default (false), esto
         * tiene que viajar en true. */
        $this->assertTrue($payload['usan_cuentas_corrientes']);

        // El juego completo de claves del camino del lead, congelado. Es lo que denuncia tanto una
        // clave que se agrega sin querer como una que se pierde en un refactor.
        $esperadas = [
            'name', 'company_name', 'doc_number', 'email', 'online', 'business_type',
            'iva_included', 'redondear_centenas_en_vender', 'ask_amount_in_vender',
            'usan_cuentas_corrientes', 'use_deposits', 'address_1', 'address_2', 'address_3',
            'use_price_lists', 'price_type_1', 'price_type_2', 'price_type_3',
            'ventas_con_fecha_de_entrega', 'cajas', 'usar_codigos_de_barra',
            'codigos_de_barra_por_defecto', 'consultora_de_precios', 'imagenes', 'produccion',
            'google_cuota', 'demo_ingreso_token', 'demo_ingreso_token_expira_at',
        ];

        /* `google_custom_search_api_key` es la única clave condicional del camino del lead: viaja
         * sólo si está cargada en el admin, y este entorno de testing puede tenerla o no. Se saca
         * de la comparación para que el test no dependa de la configuración del slot. */
        $claves = array_values(array_diff(array_keys($payload), ['google_custom_search_api_key']));

        sort($esperadas);
        sort($claves);
        $this->assertSame($esperadas, $claves);
    }

    /**
     * El payload de defaults trae las URLs de ESA demo y los valores por defecto del catálogo.
     *
     * @return void
     */
    public function test_el_payload_de_defaults_trae_las_urls_de_esa_demo(): void
    {
        $demo = $this->crear_demo([
            'nombre'            => 'Demo Nueve',
            'ecommerce_api_url' => 'https://api-tienda-demo9.comerciocity.com',
        ]);

        $payload = (new RunDemoSetupService())->payload_de_defaults($demo);

        // La tienda del ERP es la de esta demo, no la de ningún lead.
        $this->assertSame('https://api-tienda-demo9.comerciocity.com', $payload['online']);

        // El nombre del comercio sale del catálogo, y es el mismo que usa el pipeline de ecommerce
        // para el APP_NAME de la tienda: el ERP y la tienda de una demo tienen que decir lo mismo.
        $this->assertSame('Demo Nueve', $payload['name']);
        $this->assertSame('Demo Nueve', $payload['company_name']);
        $this->assertSame($demo->display_name(), $payload['name']);

        // Sin lead no hay persona: estas dos van en null, no en cadena vacía ni inventadas.
        $this->assertNull($payload['doc_number']);
        $this->assertNull($payload['email']);

        /* Los tres flags legados se derivan de los defaults del formulario y no se escriben a mano,
         * para que "los defaults del catálogo" tengan UNA sola fuente. Si Lucas cambia un default
         * en LeadDemoFormMapper, esta aserción sigue valiendo sin tocar nada. */
        $respuestas = LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO;
        $this->assertSame($respuestas['tipo_precios'] === 'listas', $payload['use_price_lists']);
        $this->assertSame((bool) $respuestas['usa_depositos'], $payload['use_deposits']);
        $this->assertSame(
            (bool) $respuestas['usa_cuentas_corrientes_clientes'],
            $payload['usan_cuentas_corrientes']
        );

        /* Las tres sucursales van con los mismos nombres que le estampa a un lead el hook
         * `creating` de Lead. Hoy son inertes (DemoSetupHelper sólo crea direcciones si
         * use_deposits viene en true, y el catálogo dice que no), pero si ese default cambia, la
         * demo tiene que nacer con sus sucursales y no con depósitos sin una sola dirección. */
        $this->assertSame('Sucursal 1', $payload['address_1']);
        $this->assertSame('Sucursal 2', $payload['address_2']);
        $this->assertSame('Sucursal 3', $payload['address_3']);
    }

    /**
     * El payload de defaults NO lleva el bloque de la experiencia de un lead.
     *
     * Sin lead no hay página inmersiva, ni roadmap, ni token de ingreso: mandarlos con valores
     * inventados dejaría la demo arrancando con un plan y un link que no son de nadie. Cuando
     * después se le asigne un lead, `run()` dispara el demo-setup de verdad con sus respuestas.
     *
     * @return void
     */
    public function test_el_payload_de_defaults_no_lleva_nada_de_un_lead(): void
    {
        $payload = (new RunDemoSetupService())->payload_de_defaults($this->crear_demo());

        foreach ([
            'demo_experiencia',
            'demo_form_completado',
            'respuestas_formulario',
            'demo_plan',
            'demo_media_urls',
            'demo_eventos_token',
            'demo_eventos_url',
            'demo_ingreso_token',
            'demo_ingreso_token_expira_at',
        ] as $clave_de_lead) {
            $this->assertArrayNotHasKey($clave_de_lead, $payload);
        }
    }

    /**
     * Una demo sin `nombre` cargado cae al slug del subdominio en vez de quedarse sin nombre.
     *
     * Un nombre feo se corrige; un vacío se publica — es el mismo criterio que ya documenta
     * Demo::display_name(), y acá importa porque este valor termina siendo el nombre del comercio
     * que ve el lead adentro del ERP.
     *
     * @return void
     */
    public function test_una_demo_sin_nombre_usa_el_slug_del_subdominio(): void
    {
        $demo = $this->crear_demo([
            'nombre'      => null,
            'erp_spa_url' => 'https://demo9.comerciocity.com',
        ]);

        $payload = (new RunDemoSetupService())->payload_de_defaults($demo);

        $this->assertSame('demo9', $payload['name']);
        $this->assertSame('demo9', $payload['company_name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `build_payload()` es protected: se invoca por reflexión, igual que hace
     * RunDemoSetupServiceRespuestasTest con los otros métodos internos de este service.
     *
     * @param  Lead  $lead
     * @return array<string, mixed>
     */
    private function build_payload(Lead $lead): array
    {
        $metodo = new ReflectionMethod(RunDemoSetupService::class, 'build_payload');
        $metodo->setAccessible(true);

        return $metodo->invoke(new RunDemoSetupService(), $lead);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return Demo
     */
    private function crear_demo(array $atributos = []): Demo
    {
        return Demo::create(array_merge([
            'erp_spa_url'       => 'https://demo-payload-s11.comerciocity.com',
            'erp_api_url'       => 'https://api-demo-payload-s11.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo-payload-s11.comerciocity.com',
            'ecommerce_api_url' => 'https://api-tienda-demo-payload-s11.comerciocity.com',
        ], $atributos));
    }
}
