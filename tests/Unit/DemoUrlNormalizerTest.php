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
     * Una URL que ya viene absoluta no se toca. Ni siquiera la barra final: por acá pasan también
     * los `video_url` de los tutoriales personalizados, que son URLs arbitrarias.
     *
     * @return void
     */
    public function test_una_url_absoluta_vuelve_intacta(): void
    {
        $this->assertSame(
            'https://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('https://demo3.comerciocity.com')
        );

        $this->assertSame(
            'https://demo3.comerciocity.com/',
            DemoUrlNormalizer::absolute('https://demo3.comerciocity.com/')
        );

        // Un valor cargado con HTTP explícito se respeta: no se fuerza a HTTPS.
        $this->assertSame(
            'http://demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('http://demo3.comerciocity.com')
        );

        // Query string intacta, incluida una barra final que puede ser parte de un valor firmado.
        $this->assertSame(
            'https://www.youtube.com/watch?v=abc123/',
            DemoUrlNormalizer::absolute('https://www.youtube.com/watch?v=abc123/')
        );
    }

    /**
     * `base()` es la variante para concatenarle una ruta: esa sí recorta la barra final.
     *
     * @return void
     */
    public function test_base_recorta_la_barra_final_para_poder_concatenar(): void
    {
        $this->assertSame(
            'https://demo3.comerciocity.com',
            DemoUrlNormalizer::base('https://demo3.comerciocity.com/')
        );

        $this->assertSame('http://empresa.local:8080', DemoUrlNormalizer::base('empresa.local:8080/'));
        $this->assertSame('', DemoUrlNormalizer::base(''));
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
            DemoUrlNormalizer::absolute('  demo3.comerciocity.com:8443  ')
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
     * IP privada de LAN: es como se prueba la demo desde un teléfono contra la máquina de
     * desarrollo. Tampoco hay TLS ahí.
     *
     * @return void
     */
    public function test_las_ips_privadas_de_lan_salen_por_http(): void
    {
        $this->assertSame('http://192.168.0.10:8080', DemoUrlNormalizer::absolute('192.168.0.10:8080'));
        $this->assertSame('http://10.0.0.5:8080', DemoUrlNormalizer::absolute('10.0.0.5:8080'));
        $this->assertSame('http://172.16.0.3:8080', DemoUrlNormalizer::absolute('172.16.0.3:8080'));

        // Una IP pública NO es LAN: si esto se rompe, un host real navega sin TLS.
        $this->assertSame('https://8.8.8.8', DemoUrlNormalizer::absolute('8.8.8.8'));
        $this->assertSame('https://172.32.0.1', DemoUrlNormalizer::absolute('172.32.0.1'));
    }

    /**
     * IPv6 entre corchetes: los dos puntos de adentro no son el separador de puerto.
     *
     * @return void
     */
    public function test_ipv6_local_entre_corchetes(): void
    {
        $this->assertSame('http://[::1]:8080', DemoUrlNormalizer::absolute('[::1]:8080'));
    }

    /**
     * 🔴 Ningún host real puede terminar saliendo por HTTP. Estos son los caminos por los que un
     * parseo flojo de la autoridad lo dejaría pasar: el sufijo reservado aparece en la ruta, en la
     * query o después de un arroba, pero el host es de producción.
     *
     * @return void
     */
    public function test_ningun_host_real_sale_por_http_por_un_sufijo_escondido(): void
    {
        $this->assertSame(
            'https://local.comerciocity.com',
            DemoUrlNormalizer::absolute('local.comerciocity.com')
        );

        $this->assertSame('https://milocal.com.ar', DemoUrlNormalizer::absolute('milocal.com.ar'));

        // En la ruta.
        $this->assertSame(
            'https://demo3.comerciocity.com/algo.local',
            DemoUrlNormalizer::absolute('demo3.comerciocity.com/algo.local')
        );

        // En la query, con un arroba de por medio.
        $this->assertSame(
            'https://demo3.comerciocity.com?a=b@c.local',
            DemoUrlNormalizer::absolute('demo3.comerciocity.com?a=b@c.local')
        );

        // En el fragmento.
        $this->assertSame(
            'https://demo3.comerciocity.com#x.local',
            DemoUrlNormalizer::absolute('demo3.comerciocity.com#x.local')
        );
    }

    /**
     * Credenciales embebidas: el host es lo que va después del arroba.
     *
     * @return void
     */
    public function test_credenciales_embebidas_no_confunden_al_host(): void
    {
        $this->assertSame(
            'http://usuario:clave@empresa.local:8080',
            DemoUrlNormalizer::absolute('usuario:clave@empresa.local:8080')
        );

        $this->assertSame(
            'https://usuario@demo3.comerciocity.com',
            DemoUrlNormalizer::absolute('usuario@demo3.comerciocity.com')
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
        $urls = [
            'demo3.comerciocity.com',
            'empresa.local:8080',
            'https://demo3.comerciocity.com/',
            'usuario@demo3.comerciocity.com',
        ];

        foreach ($urls as $url) {
            $una_vez = DemoUrlNormalizer::absolute($url);
            $this->assertSame($una_vez, DemoUrlNormalizer::absolute($una_vez), 'Falló con: ' . $url);

            $base_una_vez = DemoUrlNormalizer::base($url);
            $this->assertSame($base_una_vez, DemoUrlNormalizer::base($base_una_vez), 'base() falló con: ' . $url);
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
