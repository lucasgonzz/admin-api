<?php

namespace Tests\Unit;

use App\Models\Demo;
use App\Services\DemoPathResolver;
use Tests\TestCase;

/**
 * DemoPathResolver decide a qué servidor va la actualización de una demo y con qué rutas.
 *
 * Es cálculo puro sobre columnas: se prueba sin base, con modelos sin persistir (mismo patrón que
 * DemoUpdateSpaBuildEnvTest). Lo que se fija acá es sobre todo lo que NO se ve cuando falla: una
 * ruta con un segmento vacío no rompe nada visible, sube el ZIP a un directorio equivocado y
 * después lo vacía con `find -delete`.
 */
class DemoPathResolverTest extends TestCase
{
    /**
     * Demo sin persistir con los atributos que hagan falta.
     *
     * @param  array<string, mixed>  $atributos
     * @return Demo
     */
    private function demo(array $atributos = []): Demo
    {
        $demo = new Demo();
        foreach ($atributos as $clave => $valor) {
            $demo->{$clave} = $valor;
        }

        return $demo;
    }

    /**
     * 🔴 El test de compatibilidad hacia atrás: una demo de hoy —creada antes de que existieran
     * las columnas, o sea con los atributos en null— tiene que resolver EXACTAMENTE a las rutas
     * que el pipeline usaba cableadas.
     *
     * @return void
     */
    public function test_una_demo_sin_los_campos_cargados_sigue_siendo_de_hosting_compartido(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo(['erp_spa_url' => 'https://demo3.comerciocity.com']);

        $this->assertSame('shared_hosting', $resolver->hosting_type($demo));
        $this->assertSame('shared_hosting', $resolver->credential_type($demo));
        $this->assertFalse($resolver->is_vps($demo));
        $this->assertSame('domains/comerciocity.com/public_html/demo3/api', $resolver->api_path($demo));
        $this->assertSame('domains/comerciocity.com/public_html/demo3/spa', $resolver->spa_path($demo));
    }

    /**
     * El mismo resultado con el valor explícito: el default de la columna no cambia nada.
     *
     * @return void
     */
    public function test_shared_hosting_explicito_da_las_mismas_rutas(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'      => 'https://demo3.comerciocity.com',
            'erp_hosting_type' => 'shared_hosting',
        ]);

        $this->assertSame('domains/comerciocity.com/public_html/demo3/api', $resolver->api_path($demo));
        $this->assertSame('domains/comerciocity.com/public_html/demo3/spa', $resolver->spa_path($demo));
    }

    /**
     * Una demo en el VPS con vps_path cargado: rutas absolutas y credencial `vps`, con la misma
     * convención que un cliente (/home/api-{slug}/empresa-api, /home/{slug}/htdocs/{dominio}).
     *
     * @return void
     */
    public function test_una_demo_en_vps_usa_rutas_absolutas_y_la_credencial_del_vps(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'      => 'https://demo3.comerciocity.com',
            'erp_hosting_type' => 'vps',
            'erp_vps_path'     => 'demo3',
        ]);

        $this->assertSame('vps', $resolver->hosting_type($demo));
        $this->assertSame('vps', $resolver->credential_type($demo));
        $this->assertTrue($resolver->is_vps($demo));
        $this->assertSame('/home/api-demo3/empresa-api', $resolver->api_path($demo));
        $this->assertSame('/home/demo3/htdocs/demo3.comerciocity.com', $resolver->spa_path($demo));
    }

    /**
     * 🔴 El fallback al slug (decisión de Lucas, 26/8/2026): sin vps_path cargado, la demo se
     * ubica igual, deduciendo el identificador del subdominio.
     *
     * @return void
     */
    public function test_en_vps_sin_vps_path_el_identificador_sale_del_subdominio(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'      => 'https://demo3.comerciocity.com',
            'erp_hosting_type' => 'vps',
        ]);

        $this->assertSame('/home/api-demo3/empresa-api', $resolver->api_path($demo));
        $this->assertSame('/home/demo3/htdocs/demo3.comerciocity.com', $resolver->spa_path($demo));
    }

    /**
     * 🔴 La trampa de `parse_url()` sin puerto (17/8/2026), ahora también en el camino del VPS:
     * `erp_spa_url` es texto libre y la forma más común de cargarla a mano es sin esquema.
     *
     * @return void
     */
    public function test_una_url_sin_esquema_tambien_ubica_bien_la_demo_en_el_vps(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'      => 'demo3.comerciocity.com',
            'erp_hosting_type' => 'vps',
        ]);

        $this->assertSame('/home/api-demo3/empresa-api', $resolver->api_path($demo));
        $this->assertSame('/home/demo3/htdocs/demo3.comerciocity.com', $resolver->spa_path($demo));
    }

    /**
     * 🔴 Sin URL y sin vps_path no hay ubicación posible, y eso tiene que ser una excepción y no
     * una ruta plausible: `/home/api-/empresa-api` es exactamente la clase de path que después se
     * vacía con `find -delete`.
     *
     * @return void
     */
    public function test_en_vps_sin_vps_path_ni_url_no_se_inventa_una_ruta(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo(['erp_hosting_type' => 'vps']);

        $this->expectException(\RuntimeException::class);
        $resolver->api_path($demo);
    }

    /**
     * Lo mismo del lado del hosting compartido: sin slug no se arma `public_html//api`, se tira.
     *
     * @return void
     */
    public function test_en_hosting_compartido_sin_url_tampoco_se_inventa_una_ruta(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo(['erp_spa_url' => '']);

        $this->expectException(\RuntimeException::class);
        $resolver->api_path($demo);
    }

    /**
     * El vps_path se trimea: un espacio de más al pegarlo en el formulario no puede convertirse en
     * un directorio distinto.
     *
     * @return void
     */
    public function test_el_vps_path_se_trimea(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'      => 'https://demo3.comerciocity.com',
            'erp_hosting_type' => 'vps',
            'erp_vps_path'     => '  demo9  ',
        ]);

        $this->assertSame('/home/api-demo9/empresa-api', $resolver->api_path($demo));
    }

    /**
     * 🔴 Un valor basura en la columna cae a `shared_hosting`, NUNCA a `vps`. El camino nuevo se
     * elige solo cuando alguien lo eligió a propósito.
     *
     * @return void
     */
    public function test_un_valor_desconocido_cae_a_hosting_compartido(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'      => 'https://demo3.comerciocity.com',
            'erp_hosting_type' => 'vpss',
        ]);

        $this->assertSame('shared_hosting', $resolver->hosting_type($demo));
        $this->assertSame('domains/comerciocity.com/public_html/demo3/api', $resolver->api_path($demo));
    }

    /**
     * El hosting del ecommerce NO influye en las rutas del ERP: son dos datos independientes y
     * este resolver lee solo el par `erp_*`.
     *
     * @return void
     */
    public function test_el_hosting_del_ecommerce_no_mueve_las_rutas_del_erp(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'            => 'https://demo3.comerciocity.com',
            'erp_hosting_type'       => 'shared_hosting',
            'ecommerce_hosting_type' => 'vps',
            'ecommerce_vps_path'     => 'demo3-tienda',
        ]);

        $this->assertSame('shared_hosting', $resolver->hosting_type($demo));
        $this->assertSame('domains/comerciocity.com/public_html/demo3/api', $resolver->api_path($demo));
    }
}
