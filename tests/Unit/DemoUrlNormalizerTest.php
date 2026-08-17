<?php

namespace Tests\Unit;

use App\Services\DemoUrlNormalizer;
use Tests\TestCase;

/**
 * La regla de normalización de las URLs de instancia demo, en un solo lugar.
 *
 * Nació del bug del 17/8/2026: `demos.erp_spa_url` es texto libre y se puede guardar sin esquema,
 * y el link de ingreso a la demo la concatenaba cruda. El navegador lee `empresa.local:` como
 * protocolo desconocido y no navega.
 */
class DemoUrlNormalizerTest extends TestCase
{
    /**
     * Una URL que ya viene absoluta no se toca (más allá de la barra final).
     *
     * @return void
     */
    public function test_una_url_absoluta_vuelve_igual(): void
    {
        $this->assertSame(
            'https://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('https://demo3.comerciocity.com')
        );

        $this->assertSame(
            'https://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('https://demo3.comerciocity.com/')
        );

        // Un valor cargado con HTTP explícito se respeta: no se fuerza a HTTPS.
        $this->assertSame(
            'http://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('http://demo3.comerciocity.com')
        );
    }

    /**
     * El caso del bug: host real sin esquema. Se le pone HTTPS.
     *
     * @return void
     */
    public function test_un_host_real_sin_esquema_sale_por_https(): void
    {
        $this->assertSame(
            'https://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('demo3.comerciocity.com')
        );

        // Con puerto, y con espacios de sobra al pegar en el formulario.
        $this->assertSame(
            'https://demo3.comerciocity.com:8443',
            DemoUrlNormalizer::absolute('  demo3.comerciocity.com:8443/  ')
        );
    }

    /**
     * El otro caso del bug, y el motivo por el que la regla no puede ser "siempre HTTPS": los
     * hosts de desarrollo se sirven por HTTP plano, así que `https://empresa.local:8080` está
     * igual de roto que la URL sin esquema.
     *
     * @return void
     */
    public function test_los_hosts_locales_salen_por_http(): void
    {
        $this->assertSame(
            'http://empresa.local:8080',
            DemoUrlNormalizer::absolute('empresa.local:8080')
        );

        $this->assertSame(
            'http://tienda.local:8081',
            DemoUrlNormalizer::absolute('tienda.local:8081')
        );

        $this->assertSame('http://localhost:8000', DemoUrlNormalizer::absolute('localhost:8000'));
        $this->assertSame('http://127.0.0.1:8000', DemoUrlNormalizer::absolute('127.0.0.1:8000'));

        // Mayúsculas: la decisión es por host, sin importar cómo se haya tipeado.
        $this->assertSame('http://EMPRESA.local', DemoUrlNormalizer::absolute('EMPRESA.local'));
    }

    /**
     * Un host que solo *contiene* la palabra local no es local. Si esto se rompe, un cliente real
     * termina navegando por HTTP.
     *
     * @return void
     */
    public function test_un_host_real_parecido_a_local_no_se_confunde(): void
    {
        $this->assertSame(
            'https://local.comerciocity.com',
            DemoUrlNormalizer::absolute('local.comerciocity.com')
        );

        $this->assertSame(
            'https://milocal.com.ar',
            DemoUrlNormalizer::absolute('milocal.com.ar')
        );

        // El sufijo tiene que ser del host, no de la ruta.
        $this->assertSame(
            'https://demo3.comerciocity.com/algo.local',
            DemoUrlNormalizer::absolute('demo3.comerciocity.com/algo.local')
        );
    }

    /**
     * Vacío es vacío: el llamador decide qué hacer (el accessor del lead devuelve null).
     *
     * @return void
     */
    public function test_una_url_vacia_devuelve_cadena_vacia(): void
    {
        $this->assertSame('', DemoUrlNormalizer::absolute(''));
        $this->assertSame('', DemoUrlNormalizer::absolute('   '));
        $this->assertSame('', DemoUrlNormalizer::absolute(null));
    }

    /**
     * Idempotencia: se puede llamar sobre un valor ya normalizado. Importa porque el mismo valor
     * pasa por acá desde el mail, el contexto de la IA, el link de ingreso y el pipeline de
     * actualización de demos.
     *
     * @return void
     */
    public function test_es_idempotente(): void
    {
        $urls = ['demo3.comerciocity.com', 'empresa.local:8080', 'https://demo3.comerciocity.com/'];

        foreach ($urls as $url) {
            $una_vez = DemoUrlNormalizer::absolute($url);
            $this->assertSame($una_vez, DemoUrlNormalizer::absolute($una_vez));
        }
    }

    /**
     * Una URL protocol-relative no puede terminar en `https:///host`.
     *
     * @return void
     */
    public function test_una_url_protocol_relative_no_duplica_barras(): void
    {
        $this->assertSame(
            'https://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('//demo3.comerciocity.com')
        );
    }
}
