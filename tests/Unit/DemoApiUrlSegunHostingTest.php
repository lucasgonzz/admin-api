<?php

namespace Tests\Unit;

use App\Services\ClientEmpresaApiUrlResolver;
use Tests\TestCase;

/**
 * El sufijo `/public` de la URL de API de una demo depende de dónde vive esa demo.
 *
 * En el hosting compartido el subdominio apunta a la carpeta `api/`, así que hay que entrar por
 * `api/public/`. En el VPS el docroot YA es `public/`, y agregarlo de nuevo da `public/public` →
 * 404 en cada request (§2.1 del informe 20260826-plan-migracion-shared-a-vps.md).
 *
 * Es un valor que queda COMPILADO adentro del bundle del SPA, así que equivocarlo no se ve en
 * ninguna validación de proceso: el build sale bien, el ZIP sube bien, `migrate` da exit 0 y la
 * demo devuelve 404 en todo. Ese incidente ya pasó el 24/7/2026, con `/public/public`.
 */
class DemoApiUrlSegunHostingTest extends TestCase
{
    /**
     * 🔴 Compatibilidad de firma: sin el segundo argumento, el método se comporta exactamente
     * como antes de que existiera. Cualquier llamador viejo sigue agregando /public.
     *
     * @return void
     */
    public function test_sin_indicar_hosting_se_comporta_como_hosting_compartido(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame(
            'https://api-demo3.comerciocity.com/public',
            $resolver->normalize_demo_api_base_url('https://api-demo3.comerciocity.com')
        );
    }

    /**
     * Hosting compartido explícito: mismo resultado.
     *
     * @return void
     */
    public function test_en_hosting_compartido_agrega_public(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame(
            'https://api-demo3.comerciocity.com/public',
            $resolver->normalize_demo_api_base_url('https://api-demo3.comerciocity.com', 'shared_hosting')
        );
    }

    /**
     * 🔴 El caso que este cambio vino a habilitar: en el VPS la URL queda sin /public.
     *
     * @return void
     */
    public function test_en_vps_no_agrega_public(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame(
            'https://api-demo3.comerciocity.com',
            $resolver->normalize_demo_api_base_url('https://api-demo3.comerciocity.com', 'vps')
        );
    }

    /**
     * 🔴 El operador migró la demo al VPS y dejó la URL vieja con /public cargada. Se lo saca:
     * la columna es texto libre y el dato que manda es el hosting, no el sufijo que quedó escrito.
     *
     * @return void
     */
    public function test_en_vps_saca_el_public_que_traiga_la_url_cargada(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame(
            'https://api-demo3.comerciocity.com',
            $resolver->normalize_demo_api_base_url('https://api-demo3.comerciocity.com/public', 'vps')
        );
    }

    /**
     * La idempotencia de siempre, ahora fijada: una URL sucia de una carga vieja no acumula
     * sufijos. Es el bug del 24/7/2026.
     *
     * @return void
     */
    public function test_en_hosting_compartido_nunca_se_duplica_el_public(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame(
            'https://api-demo3.comerciocity.com/public',
            $resolver->normalize_demo_api_base_url('https://api-demo3.comerciocity.com/public/public', 'shared_hosting')
        );
        $this->assertSame(
            'https://api-demo3.comerciocity.com/public',
            $resolver->normalize_demo_api_base_url('https://api-demo3.comerciocity.com/public', 'shared_hosting')
        );
    }

    /**
     * 🔴 La demo local del seeder no es hosting real: se devuelve cruda en los DOS hostings, sin
     * /public y sin esquema. DemoUpdateService::step_verify_demo() usa justamente "esta URL no es
     * absoluta" para reconocerla y saltearse los chequeos HTTP.
     *
     * @return void
     */
    public function test_una_url_local_vuelve_cruda_en_los_dos_hostings(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame(
            'empresa.local:8000',
            $resolver->normalize_demo_api_base_url('empresa.local:8000', 'vps')
        );
        $this->assertSame(
            'empresa.local:8000',
            $resolver->normalize_demo_api_base_url('empresa.local:8000', 'shared_hosting')
        );
    }

    /**
     * Sin URL cargada devuelve cadena vacía, que es lo que los tres llamadores usan para fallar
     * con un mensaje entendible en vez de pegarle a una URL inventada.
     *
     * @return void
     */
    public function test_sin_url_devuelve_vacio_en_los_dos_hostings(): void
    {
        $resolver = new ClientEmpresaApiUrlResolver();

        $this->assertSame('', $resolver->normalize_demo_api_base_url('', 'vps'));
        $this->assertSame('', $resolver->normalize_demo_api_base_url(null, 'shared_hosting'));
    }
}
