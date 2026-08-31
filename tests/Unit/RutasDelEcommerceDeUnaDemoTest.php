<?php

namespace Tests\Unit;

use App\Models\Demo;
use App\Services\DemoPathResolver;
use Tests\TestCase;

/**
 * Rutas del ECOMMERCE de una demo (métodos `ecommerce_*` de DemoPathResolver).
 *
 * Son cálculo puro sobre columnas: se prueba sin base, con modelos sin persistir (mismo patrón que
 * DemoPathResolverTest, que cubre los `erp_*`). Lo que se fija acá es sobre todo lo que NO se ve
 * cuando falla: estos paths terminan siendo el destino del `rm -rf` del swap atómico de
 * `EcommerceInstallationService::build_spa_atomic_deploy_shell()`, así que una ruta mal armada no
 * es un error visible sino un directorio equivocado borrado.
 */
class RutasDelEcommerceDeUnaDemoTest extends TestCase
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
     * El caso normal: hosting compartido, con la misma convención que un cliente
     * ({dominio}/public_html y {dominio}/public_html/api), y RELATIVA a `domains/` porque
     * EcommerceInstallationService le antepone su propio HOSTING_PREFIX.
     *
     * @return void
     */
    public function test_en_hosting_compartido_el_ecommerce_vive_en_su_propio_dominio(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'ecommerce_spa_url' => 'https://tienda-demo3.comerciocity.com',
        ]);

        $this->assertSame('shared_hosting', $resolver->ecommerce_hosting_type($demo));
        $this->assertSame('shared_hosting', $resolver->ecommerce_credential_type($demo));
        $this->assertFalse($resolver->ecommerce_is_vps($demo));
        $this->assertSame('tienda-demo3.comerciocity.com', $resolver->ecommerce_spa_domain($demo));
        $this->assertSame('tienda-demo3.comerciocity.com/public_html', $resolver->ecommerce_spa_path($demo));
        $this->assertSame('tienda-demo3.comerciocity.com/public_html/api', $resolver->ecommerce_api_path($demo));
    }

    /**
     * 🔴 Los métodos de ecommerce NO leen las columnas del ERP, ni al revés. Es el pedido explícito
     * del docblock de DemoPathResolver, y sin este test la única forma de notar la mezcla sería un
     * deploy que sube la tienda arriba del ERP.
     *
     * @return void
     */
    public function test_las_rutas_del_ecommerce_no_se_mezclan_con_las_del_erp(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'erp_spa_url'       => 'https://demo3.comerciocity.com',
            'erp_api_url'       => 'https://api-demo3.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo3.comerciocity.com',
        ]);

        // El ERP sigue siendo una subcarpeta del dominio fijo, con prefijo y todo.
        $this->assertSame('domains/comerciocity.com/public_html/demo3/spa', $resolver->spa_path($demo));
        $this->assertSame('domains/comerciocity.com/public_html/demo3/api', $resolver->api_path($demo));

        // El ecommerce, en cambio, vive en su propio dominio.
        $this->assertSame('tienda-demo3.comerciocity.com/public_html', $resolver->ecommerce_spa_path($demo));
        $this->assertSame('tienda-demo3.comerciocity.com/public_html/api', $resolver->ecommerce_api_path($demo));
    }

    /**
     * Una URL sin esquema (la forma más común de cargarla a mano) y con "www." resuelve igual.
     *
     * @return void
     */
    public function test_la_url_sin_esquema_y_con_www_resuelve_al_mismo_dominio(): void
    {
        $resolver = new DemoPathResolver();

        $this->assertSame(
            'tienda-demo3.comerciocity.com/public_html',
            $resolver->ecommerce_spa_path($this->demo(['ecommerce_spa_url' => 'tienda-demo3.comerciocity.com']))
        );

        $this->assertSame(
            'tienda-demo3.comerciocity.com/public_html',
            $resolver->ecommerce_spa_path($this->demo(['ecommerce_spa_url' => 'https://WWW.Tienda-Demo3.ComercioCity.com/']))
        );
    }

    /**
     * 🔴 VPS todavía no se soporta: los dos paths tiran con el texto exacto acordado, ANTES de que
     * el pipeline se conecte a ningún lado.
     *
     * @return void
     */
    public function test_un_ecommerce_marcado_como_vps_no_resuelve_rutas_y_lo_dice(): void
    {
        $resolver = new DemoPathResolver();
        $demo     = $this->demo([
            'ecommerce_spa_url'      => 'https://tienda-demo3.comerciocity.com',
            'ecommerce_hosting_type' => 'vps',
        ]);

        $this->assertTrue($resolver->ecommerce_is_vps($demo));

        $mensaje_esperado = 'El pipeline de ecommerce todavía solo sabe desplegar en hosting '
            . 'compartido. Esta demo tiene su ecommerce marcado como VPS.';

        try {
            $resolver->ecommerce_spa_path($demo);
            $this->fail('ecommerce_spa_path() tendría que haber tirado con la demo marcada como VPS.');
        } catch (\RuntimeException $e) {
            $this->assertSame($mensaje_esperado, $e->getMessage());
        }

        try {
            $resolver->ecommerce_api_path($demo);
            $this->fail('ecommerce_api_path() tendría que haber tirado con la demo marcada como VPS.');
        } catch (\RuntimeException $e) {
            $this->assertSame($mensaje_esperado, $e->getMessage());
        }
    }

    /**
     * Un valor basura en la columna cae a hosting compartido, nunca a VPS: el dato lo carga a mano
     * un operador en un desplegable de texto libre.
     *
     * @return void
     */
    public function test_un_hosting_type_basura_cae_a_hosting_compartido(): void
    {
        $resolver = new DemoPathResolver();

        foreach (['', 'vpss', 'Shared Hosting', null] as $valor) {
            $demo = $this->demo([
                'ecommerce_spa_url'      => 'https://tienda-demo3.comerciocity.com',
                'ecommerce_hosting_type' => $valor,
            ]);

            $this->assertSame('shared_hosting', $resolver->ecommerce_hosting_type($demo));
        }

        // Pero "VPS" en mayúsculas sí es VPS: la columna es texto libre y nadie la valida.
        $demo_vps = $this->demo([
            'ecommerce_spa_url'      => 'https://tienda-demo3.comerciocity.com',
            'ecommerce_hosting_type' => ' VPS ',
        ]);
        $this->assertTrue($resolver->ecommerce_is_vps($demo_vps));
    }

    /**
     * Sin «Ecommerce SPA URL» no hay dominio, y sin dominio la ruta sería `domains//public_html`:
     * un directorio equivocado, no un error. Tiene que tirar.
     *
     * @return void
     */
    public function test_sin_url_del_spa_no_arma_una_ruta_a_medias(): void
    {
        $resolver = new DemoPathResolver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se pudo determinar el dominio del ecommerce de la demo');

        $resolver->ecommerce_spa_path($this->demo(['ecommerce_spa_url' => '']));
    }

    /**
     * 🔴 La guarda del typo: pegar la «Ecommerce API URL» en el campo del SPA (son contiguos en el
     * modal) haría que el deploy del SPA borre la API de esa misma demo.
     *
     * @return void
     */
    public function test_no_despliega_el_spa_arriba_del_sitio_de_la_api(): void
    {
        $resolver = new DemoPathResolver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('es el sitio de la API de la tienda');

        $resolver->ecommerce_spa_path($this->demo([
            'ecommerce_spa_url' => 'https://api-tienda-demo3.comerciocity.com',
        ]));
    }

    /**
     * El identificador en el VPS existe y está validado, aunque hoy ningún camino llegue a usarlo:
     * es lo que va a hacer falta el día que se soporte VPS, y deja escrito que NO se reutiliza el
     * `vps_slug()` del ERP.
     *
     * @return void
     */
    public function test_el_slug_de_vps_del_ecommerce_sale_de_sus_propias_columnas(): void
    {
        $resolver = new DemoPathResolver();

        // Con la columna cargada, gana la columna.
        $this->assertSame('demo3-tienda', $resolver->ecommerce_vps_slug($this->demo([
            'erp_vps_path'       => 'demo3',
            'ecommerce_vps_path' => 'demo3-tienda',
            'ecommerce_spa_url'  => 'https://tienda-demo3.comerciocity.com',
        ])));

        // Sin la columna, se deduce del subdominio del ECOMMERCE (no del ERP).
        $this->assertSame('tienda-demo3', $resolver->ecommerce_vps_slug($this->demo([
            'erp_spa_url'       => 'https://demo3.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo3.comerciocity.com',
        ])));

        // Un identificador con caracteres que no puede tener el nombre de un sitio se rechaza:
        // termina interpolado en un `cd` remoto.
        $this->expectException(\RuntimeException::class);
        $resolver->ecommerce_vps_slug($this->demo([
            'ecommerce_vps_path' => '../otro-sitio',
            'ecommerce_spa_url'  => 'https://tienda-demo3.comerciocity.com',
        ]));
    }
}
