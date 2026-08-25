<?php

namespace Tests\Unit;

use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Services\EcommerceInstallationService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guardas del path de instalación de la tienda: las dos que decide el modelo
 * (ClientEcommerce::normalize_hosting_path()) y la del script de deploy
 * (EcommerceInstallationService::build_spa_atomic_deploy_shell()).
 *
 * POR QUÉ ESTOS TESTS EXISTEN APARTE DE LOS DE FEATURE: los de Feature prueban el camino del
 * modal (PUT del cliente) y necesitan base. Estos prueban las dos guardas que protegen contra el
 * `rm -rf` del swap atómico del deploy, y conviene que sean directos, rápidos y sin base:
 *
 *  1. Una entrada que no tiene FORMA de path de instalación se rechaza entera. El caso real que
 *     lo originó es copiar de una sesión SSH `/home/u123456/domains/comerciocity.store`, o pegar
 *     `comerciocity.store` a secas: con eso guardado, get_spa_docroot() daba
 *     `domains/comerciocity.store` y el deploy borraba el public_html entero de ese dominio, con
 *     todas las otras tiendas que colgaran de ahí.
 *  2. El subpath de la API —que desde la misión ecommerce-paths-subcarpeta sale de un campo de
 *     texto del modal y ya no de un literal del código— nunca se interpola crudo dentro del
 *     script remoto.
 *
 * Estos tests no tocan ni base ni red: el servicio se instancia sin constructor (mismo patrón que
 * tests/Unit/AfipCertSyncCommandTest.php) porque su constructor exige una corrida real.
 */
class GuardasDelPathDeInstalacionDeLaTiendaTest extends TestCase
{
    /**
     * 🔴 EL TEST DE LOS ~40 CLIENTES EN PRODUCCIÓN: los paths derivados de siempre siguen siendo
     * aceptados. Si este test se pone en rojo, la guarda de forma quedó demasiado estricta y se
     * rompen todas las tiendas ya instaladas.
     *
     * @return void
     */
    public function test_los_paths_derivados_de_los_clientes_existentes_siguen_pasando(): void
    {
        $this->assertSame(
            'cliente.com.ar/public_html',
            ClientEcommerce::normalize_hosting_path('cliente.com.ar/public_html')
        );

        $this->assertSame(
            'cliente.com.ar/public_html/api',
            ClientEcommerce::normalize_hosting_path('cliente.com.ar/public_html/api')
        );

        // Y con subdominio, que es la forma habitual de las tiendas.
        $this->assertSame(
            'tienda.cliente.com.ar/public_html',
            ClientEcommerce::normalize_hosting_path('tienda.cliente.com.ar/public_html')
        );

        // La subcarpeta de otro dominio, que es lo que trajo esta misión.
        $this->assertSame(
            'comerciocity.store/public_html/tienda/spa',
            ClientEcommerce::normalize_hosting_path('comerciocity.store/public_html/tienda/spa')
        );
    }

    /**
     * Entradas DEMASIADO CORTAS para ser un path de instalación: un solo segmento nunca lo es,
     * porque el primero es siempre el dominio. Las tres son entradas plausibles de copy/paste.
     *
     * @return void
     */
    public function test_una_entrada_de_un_solo_segmento_se_rechaza_entera(): void
    {
        // Pegar el dominio a secas, olvidándose la cola.
        $this->assertSame('', ClientEcommerce::normalize_hosting_path('comerciocity.store'));

        // Copiar la ruta absoluta de una sesión SSH: normaliza al mismo caso de arriba.
        $this->assertSame(
            '',
            ClientEcommerce::normalize_hosting_path('/home/u123456/domains/comerciocity.store')
        );

        // Con barras de sobra, que es como suele quedar cuando se copia de un prompt.
        $this->assertSame('', ClientEcommerce::normalize_hosting_path('/comerciocity.store/'));
    }

    /**
     * El primer segmento tiene que parecer un dominio (tener al menos un punto). El caso real: el
     * File Manager de hPanel, parado ADENTRO del dominio, muestra `public_html/tienda/spa` — sin
     * el dominio adelante. Pegado tal cual quedaba como `domains/public_html/tienda/spa`, una
     * carpeta inventada al lado de los dominios que ningún vhost sirve.
     *
     * @return void
     */
    public function test_un_primer_segmento_que_no_parece_dominio_se_rechaza_entero(): void
    {
        $this->assertSame('', ClientEcommerce::normalize_hosting_path('public_html/tienda/spa'));
        $this->assertSame('', ClientEcommerce::normalize_hosting_path('tienda/spa'));

        // Y con el prefijo domains/ de más, que se saca antes de aplicar la guarda.
        $this->assertSame('', ClientEcommerce::normalize_hosting_path('domains/public_html/tienda/spa'));
    }

    /**
     * Espacios invisibles pegados de una web o de un chat: el `.trim()` del modal los sacaba y el
     * `trim()` de PHP no, así que el hint mostraba la ruta limpia y la columna guardaba la ruta con
     * el carácter invisible — y el deploy creaba una carpeta con ese carácter al final.
     *
     * @return void
     */
    public function test_recorta_los_mismos_espacios_invisibles_que_javascript(): void
    {
        // Espacio duro (U+00A0) al final.
        $this->assertSame(
            'comerciocity.store/public_html/tienda/spa',
            ClientEcommerce::normalize_hosting_path("comerciocity.store/public_html/tienda/spa\xC2\xA0")
        );

        // BOM (U+FEFF) adelante.
        $this->assertSame(
            'comerciocity.store/public_html/tienda/spa',
            ClientEcommerce::normalize_hosting_path("\xEF\xBB\xBFcomerciocity.store/public_html/tienda/spa")
        );

        // Los dos a la vez, más un espacio normal.
        $this->assertSame(
            'comerciocity.store/public_html/tienda/spa',
            ClientEcommerce::normalize_hosting_path("\xEF\xBB\xBF comerciocity.store/public_html/tienda/spa \xC2\xA0")
        );
    }

    /**
     * Un valor que no es escalar no puede ser un path: `(string) []` da literalmente "Array" (con
     * warning) y ese "Array" se terminaba guardando como destino del `rm -rf`.
     *
     * @return void
     */
    public function test_un_valor_que_no_es_escalar_devuelve_vacio(): void
    {
        $this->assertSame('', ClientEcommerce::normalize_hosting_path(['comerciocity.store', 'public_html']));
        $this->assertSame('', ClientEcommerce::normalize_hosting_path(['clave' => 'valor']));
        $this->assertSame('', ClientEcommerce::normalize_hosting_path(null));
        $this->assertSame('', ClientEcommerce::normalize_hosting_path(new \stdClass()));
    }

    /**
     * Script de despliegue atómico del SPA para una tienda dada, sin tocar base ni SSH.
     *
     * @param string $spa_path Path del SPA (valor crudo de la columna).
     * @param string $api_path Path de la API (valor crudo de la columna).
     *
     * @return string Script bash que se le mandaría al hosting.
     */
    private function script_de_deploy(string $spa_path, string $api_path): string
    {
        $ecommerce           = new ClientEcommerce();
        $ecommerce->spa_path = $spa_path;
        $ecommerce->api_path = $api_path;

        $installation       = new ClientEcommerceInstallation();
        $installation->uuid = 'uuid-de-prueba';

        $reflexion = new ReflectionClass(EcommerceInstallationService::class);
        $servicio  = $reflexion->newInstanceWithoutConstructor();

        $propiedad_instalacion = $reflexion->getProperty('installation');
        $propiedad_instalacion->setAccessible(true);
        $propiedad_instalacion->setValue($servicio, $installation);

        $propiedad_tienda = $reflexion->getProperty('ecommerce');
        $propiedad_tienda->setAccessible(true);
        $propiedad_tienda->setValue($servicio, $ecommerce);

        $metodo = $reflexion->getMethod('build_spa_atomic_deploy_shell');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            $servicio,
            'domains/' . $spa_path,
            'domains/' . dirname($spa_path) . '/dist_uuid-de-prueba.zip'
        );
    }

    /**
     * 🔴 El subpath de la API NUNCA se interpola crudo en el script remoto.
     *
     * Hasta el chequeo de esta misión, `$entry` se pegaba tal cual adentro de comillas dobles en
     * cinco lugares y el escapeshellarg() se usaba solo en el `echo`. Mientras el valor solo podía
     * ser `api` o `.well-known` (dos literales del código) no pasaba nada; desde que sale del campo
     * de texto del modal, `ecommerce_api_path = comerciocity.store/public_html/tienda/a";id;"b`
     * hacía que el hosting ejecutara `id`.
     *
     * @return void
     */
    public function test_el_subpath_de_la_api_no_se_interpola_crudo_en_el_script(): void
    {
        $subpath_malicioso = 'a";id;"b';

        $script = $this->script_de_deploy(
            'comerciocity.store/public_html/tienda',
            'comerciocity.store/public_html/tienda/' . $subpath_malicioso
        );

        // Ninguna de las cinco interpolaciones puede llevar el valor pegado.
        $this->assertStringNotContainsString(
            '$OLD/' . $subpath_malicioso,
            $script,
            'El subpath se está interpolando crudo dentro del script remoto.'
        );
        $this->assertStringNotContainsString('$DOCROOT/' . $subpath_malicioso, $script);

        // Tiene que ir por una variable de shell asignada una sola vez con escapeshellarg().
        $this->assertStringContainsString('ENTRY=' . escapeshellarg($subpath_malicioso) . '; ', $script);
        $this->assertStringContainsString('mv "$OLD/$ENTRY" "$DOCROOT/$ENTRY"; ', $script);
    }

    /**
     * La versión NO maliciosa, que es la que más importa: una carpeta con un `$` o una comilla en
     * el nombre rompía el script DESPUÉS del `mv` del docroot, o sea con la tienda ya swapeada y
     * el contenido viejo todavía sin rescatar.
     *
     * @return void
     */
    public function test_un_subpath_con_caracteres_raros_va_escapado(): void
    {
        $subpath = 'api $HOME';

        $script = $this->script_de_deploy(
            'comerciocity.store/public_html/tienda',
            'comerciocity.store/public_html/tienda/' . $subpath
        );

        $this->assertStringNotContainsString('$OLD/' . $subpath, $script);
        $this->assertStringContainsString('ENTRY=' . escapeshellarg($subpath) . '; ', $script);
    }

    /**
     * Guarda de no-regresión del formato: el caso normal (API anidada en `api`) sigue armando el
     * mismo bloque de preservación y el mismo `echo SPA_PRESERVED <entrada>` de siempre. Nada lee
     * hoy esa salida (se verificó por grep en todo admin-api), pero queda en el log de la corrida
     * y es lo que se mira cuando un deploy sale raro.
     *
     * @return void
     */
    public function test_el_caso_normal_preserva_la_api_y_el_well_known_como_siempre(): void
    {
        $script = $this->script_de_deploy(
            'cliente.com.ar/public_html',
            'cliente.com.ar/public_html/api'
        );

        $this->assertStringContainsString('ENTRY=' . escapeshellarg('api') . '; ', $script);
        $this->assertStringContainsString('echo SPA_PRESERVED ' . escapeshellarg('api') . '; ', $script);

        $this->assertStringContainsString('ENTRY=' . escapeshellarg('.well-known') . '; ', $script);
        $this->assertStringContainsString('echo SPA_PRESERVED ' . escapeshellarg('.well-known') . '; ', $script);

        // Y el resto del script sigue intacto.
        $this->assertStringContainsString('if [ -e "$OLD/$ENTRY" ] && [ ! -e "$DOCROOT/$ENTRY" ]; then ', $script);
        $this->assertStringContainsString('echo SPA_DEPLOY_OK', $script);
    }
}
