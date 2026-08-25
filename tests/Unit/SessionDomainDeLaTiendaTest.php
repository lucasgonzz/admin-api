<?php

namespace Tests\Unit;

use App\Services\EcommerceInstallationService;
use Tests\TestCase;

/**
 * SESSION_DOMAIN del .env de tienda-api, cuando el SPA y la API no viven en el mismo host.
 *
 * Nació de la misión ecommerce-paths-subcarpeta: hasta ahí la API vivía siempre en
 * {spa_url}/api, o sea en el MISMO host que el SPA, y escribir el host del SPA como SESSION_DOMAIN
 * alcanzaba. Con una tienda servida desde tienda.comerciocity.store y su API desde
 * api-tienda.comerciocity.store, la API estaría seteando una cookie para un host HERMANO: el
 * navegador la descarta y la tienda queda sin login de compradores ni carrito, sin ningún error
 * visible en el deploy.
 *
 * derive_session_domain() es estático justamente para poder probarlo acá sin instanciar el
 * servicio (que en su constructor necesita SSH y una corrida real en la base): estos tests no
 * tocan ni base ni red.
 */
class SessionDomainDeLaTiendaTest extends TestCase
{
    /**
     * 🔴 EL TEST DE LOS 40 CLIENTES EN PRODUCCIÓN.
     *
     * Con la API adentro del mismo host que el SPA ({spa_url}/api), el valor tiene que ser
     * exactamente el que escribía el código anterior a la misión: el host del SPA, pelado y sin
     * punto adelante. Si este test se pone en rojo, se les rompe la sesión a todas las tiendas ya
     * instaladas.
     *
     * @return void
     */
    public function test_con_la_api_en_el_mismo_host_devuelve_el_host_del_spa(): void
    {
        $this->assertSame(
            'tienda.cliente.com.ar',
            EcommerceInstallationService::derive_session_domain(
                'https://tienda.cliente.com.ar',
                'https://tienda.cliente.com.ar/api'
            )
        );
    }

    /**
     * El caso que motivó la misión: SPA y API en subdominios HERMANOS del mismo dominio. El valor
     * pasa a ser el padre común con punto adelante, que es lo único que los dos hosts pueden
     * setear y leer.
     *
     * @return void
     */
    public function test_con_la_api_en_un_subdominio_hermano_devuelve_el_dominio_padre(): void
    {
        $this->assertSame(
            '.comerciocity.store',
            EcommerceInstallationService::derive_session_domain(
                'https://tienda.comerciocity.store',
                'https://api-tienda.comerciocity.store'
            )
        );
    }

    /**
     * Mismo escenario pero sobre un dominio de tres labels (.com.ar). Acá el padre común es
     * cliente.com.ar, que SÍ es un dominio registrable: no lo tapa la guarda de sufijos públicos.
     *
     * @return void
     */
    public function test_con_dominio_com_ar_el_padre_comun_de_tres_labels_es_valido(): void
    {
        $this->assertSame(
            '.cliente.com.ar',
            EcommerceInstallationService::derive_session_domain(
                'https://tienda.cliente.com.ar',
                'https://api.cliente.com.ar'
            )
        );
    }

    /**
     * Guarda del sufijo público: dos dominios distintos bajo .com.ar comparten `com.ar` y nada
     * más. Escribir `.com.ar` sería peor que no escribir nada — el navegador rechaza cualquier
     * cookie seteada para un sufijo de la Public Suffix List —, así que se devuelve vacío y el
     * llamador escribe SESSION_DOMAIN=null.
     *
     * @return void
     */
    public function test_dos_dominios_distintos_bajo_com_ar_no_comparten_padre_seguro(): void
    {
        $this->assertSame(
            '',
            EcommerceInstallationService::derive_session_domain(
                'https://tienda.com.ar',
                'https://api.otrodominio.com.ar'
            )
        );
    }

    /**
     * Sin ningún sufijo común (distinto TLD) tampoco hay padre posible.
     *
     * @return void
     */
    public function test_sin_sufijo_comun_devuelve_vacio(): void
    {
        $this->assertSame(
            '',
            EcommerceInstallationService::derive_session_domain(
                'https://tienda.com.ar',
                'https://api.otracosa.com'
            )
        );
    }

    /**
     * Una tienda sin URL de API cargada cae en el camino de siempre: el host del SPA. Es el mismo
     * comportamiento que tenía el código viejo, que ni miraba la api_url.
     *
     * @return void
     */
    public function test_sin_api_url_devuelve_el_host_del_spa(): void
    {
        $this->assertSame(
            'tienda.cliente.com.ar',
            EcommerceInstallationService::derive_session_domain('https://tienda.cliente.com.ar', '')
        );
    }

    /**
     * Los valores del modal son texto libre y pueden venir sin esquema (y con "www." adelante).
     * domain_from_url() ya normaliza las dos cosas, así que el resultado tiene que ser idéntico al
     * de las URLs completas.
     *
     * @return void
     */
    public function test_las_urls_sin_esquema_dan_el_mismo_resultado(): void
    {
        $this->assertSame(
            '.comerciocity.store',
            EcommerceInstallationService::derive_session_domain(
                'tienda.comerciocity.store',
                'api-tienda.comerciocity.store'
            )
        );

        $this->assertSame(
            'tienda.cliente.com.ar',
            EcommerceInstallationService::derive_session_domain(
                'www.tienda.cliente.com.ar',
                'https://tienda.cliente.com.ar/api'
            )
        );
    }
}
