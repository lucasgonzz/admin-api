<?php

namespace Tests\Unit;

use App\Services\EcommerceDistCustomizer;
use App\Services\SpaRuntimeConfig;
use Tests\TestCase;

/**
 * La personalización del dist de `tienda-spa` que publica GitHub Actions (misión cruzada
 * `versiones-tienda`, 1/10/2026): tokens del contrato, `config.js` y branding.
 *
 * Cada test arma un zip de release de mentira en una carpeta temporal —con los cinco tokens donde
 * el contrato dice que van— y una carpeta de branding con los 17 archivos esperados, y le pasa los
 * dos a `EcommerceDistCustomizer`. Nada de SSH ni de base: es exactamente lo que el pipeline hace
 * en el admin entre que baja el release y que lo sube al hosting.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Que un nombre con comillas, `<`, `&` o acentos no rompa el `<title>`, los atributos
 *     `content="..."` ni el `manifest.json` (que tiene que seguir siendo JSON válido).
 *  2. 🔴 Que si queda un token sin reemplazar, NO se publique (salvo `__CC_CONFIG__`, que es el
 *     nombre de la global de runtime y no un token).
 *  3. Que falte lo que falte (index.html, .htaccess, seo.php o un ícono), la corrida frene.
 *  4. Que `config.js` lleve las claves del contrato y todas como string.
 */
class PersonalizacionDelDistDeLaTiendaTest extends TestCase
{
    /** @var string Carpeta temporal del test. */
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc-dist-tienda-' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->borrar_arbol($this->dir);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * Borra una carpeta entera (los tests escriben zips y carpetas de branding).
     *
     * @param string $ruta
     *
     * @return void
     */
    private function borrar_arbol(string $ruta): void
    {
        if (! is_dir($ruta)) {
            return;
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterador as $archivo) {
            $archivo->isDir() ? @rmdir($archivo->getPathname()) : @unlink($archivo->getPathname());
        }
        @rmdir($ruta);
    }

    /**
     * El `index.html` del release, con los tokens en los lugares del contrato (sección 1.2) y el
     * cargador inline de Google Maps que lee la global de runtime.
     *
     * @return string
     */
    private function index_html(): string
    {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<script src="/config.js"></script>'
            . '<title>__CC_SITE_NAME__</title>'
            . '<meta name="description" content="__CC_SITE_DESCRIPTION__">'
            . '<meta property="og:site_name" content="__CC_SITE_NAME__">'
            . '<meta property="og:title" content="__CC_SITE_NAME__">'
            . '<meta property="og:description" content="__CC_SITE_DESCRIPTION__">'
            . '<meta property="og:url" content="__CC_SITE_URL__">'
            . '<meta property="og:image" content="__CC_SITE_IMAGE__">'
            . '<meta name="twitter:title" content="__CC_SITE_NAME__">'
            . '<meta name="twitter:description" content="__CC_SITE_DESCRIPTION__">'
            . '<meta name="twitter:image" content="__CC_SITE_IMAGE__">'
            . '<meta name="theme-color" content="__CC_THEME_COLOR__">'
            . '<meta name="apple-mobile-web-app-title" content="__CC_SITE_NAME__">'
            . '<link rel="mask-icon" href="/img/icons/safari-pinned-tab.svg" color="__CC_THEME_COLOR__">'
            /* Lo que inyecta el plugin PWA con assetsVersion (la versión del release), igual que en el
               zip real: íconos y manifest con ?v=. Y un ?v= que NO es del plugin, que no se toca. */
            . '<link rel="icon" type="image/png" sizes="32x32" href="/img/icons/favicon-32x32.png?v=1.0.0">'
            . '<link rel="manifest" href="/manifest.json?v=1.0.0">'
            . '<link rel="apple-touch-icon" href="/img/icons/apple-touch-icon-180x180.png?v=1.0.0">'
            . '<meta name="msapplication-TileImage" content="/img/icons/msapplication-icon-144x144.png?v=1.0.0">'
            . '<link rel="stylesheet" href="/css/fuente-externa.css?v=9">'
            . '<script>(function(){var c=window.__CC_CONFIG__||{};var k=c.VUE_APP_GOOGLE_MAPS_API_KEY;})();</script>'
            . '</head><body><noscript>__CC_SITE_NAME__ necesita JavaScript.</noscript><div id="app"></div></body></html>';
    }

    /**
     * El `manifest.json` del release, con los tokens en name, short_name y theme_color.
     *
     * @return string
     */
    private function manifest_json(): string
    {
        return json_encode([
            'name'             => '__CC_SITE_NAME__',
            'short_name'       => '__CC_SITE_NAME__',
            'theme_color'      => '__CC_THEME_COLOR__',
            'background_color' => '#FFF',
            'icons'            => [
                ['src' => './img/icons/android-chrome-192x192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Arma el zip del release.
     *
     * @param array<string, string> $entradas Ruta en el zip => contenido. Null para usar el default.
     *
     * @return string Ruta del zip.
     */
    private function zip_de_release(array $entradas = null): string
    {
        if ($entradas === null) {
            $entradas = $this->entradas_de_release();
        }

        $ruta = $this->dir . DIRECTORY_SEPARATOR . 'tienda-spa-v1.0.0-dist.zip';
        $zip  = new \ZipArchive();
        $this->assertTrue($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entradas as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();

        return $ruta;
    }

    /**
     * Las entradas de un release sano: lo que exige la raíz, un `config.js` default vacío, un ícono
     * genérico del repo y un bundle de js.
     *
     * @return array<string, string>
     */
    private function entradas_de_release(): array
    {
        return [
            'index.html'                           => $this->index_html(),
            'manifest.json'                        => $this->manifest_json(),
            '.htaccess'                            => "RewriteEngine On\n",
            'seo.php'                              => "<?php echo 'seo';\n",
            'config.js'                            => "window.__CC_CONFIG__ = window.__CC_CONFIG__ || {};\n",
            'version.json'                         => '{"version":"1.0.0"}',
            'js/app.123.js'                        => 'console.log("app")',
            'img/icons/android-chrome-192x192.png' => 'ICONO-GENERICO-DEL-REPO',
            'favicon.ico'                          => 'FAVICON-GENERICO',
            'service-worker.js'                    => $this->service_worker_js(),
            self::PRECACHE_DEL_RELEASE             => $this->precache_js(),
        ];
    }

    /** Nombre del precache del release de mentira (el real es precache-manifest.<hash>.js). */
    const PRECACHE_DEL_RELEASE = 'precache-manifest.0123456789abcdef0123456789abcdef.js';

    /**
     * `service-worker.js` del release, con el mismo formato que genera Workbox 4 en el zip real.
     *
     * @return string
     */
    private function service_worker_js(): string
    {
        return implode("\n", [
            'importScripts("https://storage.googleapis.com/workbox-cdn/releases/4.3.1/workbox-sw.js");',
            '',
            'importScripts(',
            '  "/' . self::PRECACHE_DEL_RELEASE . '"',
            ');',
            '',
            'workbox.core.skipWaiting();',
            'self.__precacheManifest = [].concat(self.__precacheManifest || []);',
            'workbox.precaching.precacheAndRoute(self.__precacheManifest, {});',
            '',
        ]);
    }

    /**
     * Manifiesto de precache del release: las revision de /index.html y /manifest.json son el md5 del
     * archivo CON TOKENS (como en el zip real), más una entrada que no se toca.
     *
     * @return string
     */
    private function precache_js(): string
    {
        return implode("\n", [
            'self.__precacheManifest = (self.__precacheManifest || []).concat([',
            '  {',
            '    "revision": "' . md5($this->index_html()) . '",',
            '    "url": "/index.html"',
            '  },',
            '  {',
            '    "revision": "' . md5($this->manifest_json()) . '",',
            '    "url": "/manifest.json"',
            '  },',
            '  {',
            '    "revision": "ece64fc03253914f7bda2be4485981b8",',
            '    "url": "/robots.txt"',
            '  }',
            ']);',
        ]);
    }

    /**
     * Carpeta de branding con los 17 archivos esperados (contenido distinto por archivo).
     *
     * @param array<int, string> $sin Rutas relativas a NO crear (para probar el freno).
     *
     * @return string
     */
    private function carpeta_de_branding(array $sin = []): string
    {
        $base = $this->dir . DIRECTORY_SEPARATOR . 'branding';
        foreach (EcommerceDistCustomizer::ARCHIVOS_DE_BRANDING as $relativa) {
            if (in_array($relativa, $sin, true)) {
                continue;
            }
            $local = $base . '/' . $relativa;
            if (! is_dir(dirname($local))) {
                mkdir(dirname($local), 0755, true);
            }
            file_put_contents($local, 'BRANDING-DE-LA-TIENDA:' . $relativa);
        }

        return $base;
    }

    /**
     * Las variables de `config.js` del contrato (sección 1.3), con valores de prueba.
     *
     * @return array<string, mixed>
     */
    private function config_vars(): array
    {
        return [
            'VUE_APP_API_URL'             => 'https://api.mitienda.com.ar/public',
            'VUE_APP_COMMERCE_ID'         => 4200,
            'VUE_APP_APP_URL'             => 'https://mitienda.com.ar',
            'VUE_APP_SITE_NAME'           => 'Ferretería "El <Tornillo> & Cía"',
            'VUE_APP_SITE_DESCRIPTION'    => '',
            'VUE_APP_SITE_IMAGE'          => 'https://mitienda.com.ar/img/og-image.png',
            'VUE_APP_SITE_URL'            => 'https://mitienda.com.ar',
            'VUE_APP_PUSHER_KEY'          => 'key-central',
            'VUE_APP_PUSHER_CLUSTER'      => 'sa1',
            'VUE_APP_GOOGLE_MAPS_API_KEY' => 'maps-key',
            'VUE_APP_FIREBASE_API_KEY'    => 'firebase-key',
        ];
    }

    /**
     * Corre el personalizador y devuelve la ruta del zip resultante.
     *
     * @param string               $zip_release
     * @param array<string,string> $tokens
     * @param string               $branding
     *
     * @return string
     */
    private function personalizar(string $zip_release, array $tokens, string $branding, string $sello = null): string
    {
        $destino = $this->dir . DIRECTORY_SEPARATOR . 'salida' . DIRECTORY_SEPARATOR . 'tienda_dist.zip';
        (new EcommerceDistCustomizer())->customize($zip_release, $destino, $tokens, $this->config_vars(), $branding, $sello);

        return $destino;
    }

    /**
     * Nombres de las entradas de un zip.
     *
     * @param string $zip_path
     *
     * @return array<int, string>
     */
    private function nombres(string $zip_path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $nombres;
    }

    /**
     * El precache que quedó en un zip personalizado: [nombre, contenido].
     *
     * @param string $zip_path
     *
     * @return array{0: string, 1: string}
     */
    private function precache_de(string $zip_path): array
    {
        $precaches = array_values(array_filter($this->nombres($zip_path), function ($nombre) {
            return preg_match('/^precache-manifest\.[0-9a-f]+\.js$/', $nombre) === 1;
        }));
        $this->assertCount(1, $precaches, 'Tiene que quedar exactamente un precache en la raíz.');

        return [$precaches[0], (string) $this->leer($zip_path, $precaches[0])];
    }

    /**
     * Lee una entrada de un zip.
     *
     * @param string $zip_path
     * @param string $nombre
     *
     * @return string|false
     */
    private function leer(string $zip_path, string $nombre)
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);
        $contenido = $zip->getFromName($nombre);
        $zip->close();

        return $contenido;
    }

    /**
     * Tokens de un comercio con todos los caracteres que rompen HTML y JSON.
     *
     * @return array<string, string>
     */
    private function tokens_dificiles(): array
    {
        return EcommerceDistCustomizer::site_tokens(
            'Ferretería "El <Tornillo> & Cía" d\'Ángelo',
            '',
            'https://mitienda.com.ar/',
            '#1a2B3c',
            '#c5111d'
        );
    }

    /* ------------------------------------------------------------------------------------------
     | Valores de los tokens
     |----------------------------------------------------------------------------------------- */

    /** Los cinco valores salen como dice la sección 1.2 del contrato. */
    public function test_los_valores_de_los_tokens_siguen_el_contrato(): void
    {
        $tokens = $this->tokens_dificiles();

        $this->assertSame(
            ['__CC_SITE_NAME__', '__CC_SITE_DESCRIPTION__', '__CC_SITE_URL__', '__CC_SITE_IMAGE__', '__CC_THEME_COLOR__'],
            array_keys($tokens)
        );
        $this->assertSame('https://mitienda.com.ar', $tokens['__CC_SITE_URL__'], 'La URL va sin barra final.');
        $this->assertSame('https://mitienda.com.ar/img/og-image.png', $tokens['__CC_SITE_IMAGE__']);
        $this->assertSame('#1a2B3c', $tokens['__CC_THEME_COLOR__']);
        $this->assertSame(
            'Ferretería "El <Tornillo> & Cía" d\'Ángelo - Tienda online. Comprá online con envío o retiro en el local.',
            $tokens['__CC_SITE_DESCRIPTION__'],
            'Sin meta_description va la descripción por defecto de vue.config.js.'
        );
    }

    /** Una descripción cargada se normaliza (saltos de línea colapsados) y se recorta a 300. */
    public function test_la_descripcion_cargada_se_normaliza_y_se_recorta(): void
    {
        $larga  = "Línea uno\n\tlínea   dos " . str_repeat('x', 400);
        $tokens = EcommerceDistCustomizer::site_tokens('Comercio', $larga, 'https://x.com.ar', '#fff');

        $this->assertStringStartsWith('Línea uno línea dos ', $tokens['__CC_SITE_DESCRIPTION__']);
        $this->assertSame(300, mb_strlen($tokens['__CC_SITE_DESCRIPTION__']));
    }

    /** Un color que no es un hex válido cae al de respaldo, y un respaldo inválido a #c5111d. */
    public function test_un_color_invalido_cae_al_de_respaldo(): void
    {
        $this->assertSame('#00ff00', EcommerceDistCustomizer::normalize_theme_color('rojo', '#00ff00'));
        $this->assertSame('#00ff00', EcommerceDistCustomizer::normalize_theme_color('', '#00ff00'));
        $this->assertSame('#c5111d', EcommerceDistCustomizer::normalize_theme_color('"><script>', 'tampoco'));
        $this->assertSame('#AbC', EcommerceDistCustomizer::normalize_theme_color(' #AbC ', '#000000'));
    }

    /* ------------------------------------------------------------------------------------------
     | El zip personalizado
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Los tokens del index.html se reemplazan escapados como HTML: un nombre con comillas, `<`,
     * `&` y apóstrofe no corta ningún atributo ni abre una etiqueta, y los acentos quedan legibles.
     */
    public function test_index_html_queda_con_los_valores_escapados_como_html(): void
    {
        $zip  = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding());
        $html = (string) $this->leer($zip, 'index.html');

        $this->assertStringNotContainsString('__CC_SITE', $html);
        $this->assertStringNotContainsString('__CC_THEME', $html);
        $this->assertStringContainsString(
            '<title>Ferretería &quot;El &lt;Tornillo&gt; &amp; Cía&quot; d&#039;Ángelo</title>',
            $html
        );
        $this->assertStringContainsString(
            '<meta property="og:title" content="Ferretería &quot;El &lt;Tornillo&gt; &amp; Cía&quot; d&#039;Ángelo">',
            $html
        );
        $this->assertStringContainsString('<meta property="og:url" content="https://mitienda.com.ar">', $html);
        $this->assertStringContainsString(
            '<meta property="og:image" content="https://mitienda.com.ar/img/og-image.png">',
            $html
        );
        $this->assertStringContainsString('<meta name="theme-color" content="#1a2B3c">', $html);
        $this->assertStringContainsString('color="#1a2B3c"', $html);
        $this->assertStringNotContainsString('<Tornillo>', $html, 'El nombre abrió una etiqueta HTML.');

        /* La global de runtime NO es un token: sigue intacta en el cargador de Google Maps. */
        $this->assertStringContainsString('window.__CC_CONFIG__', $html);
    }

    /** 🔴 manifest.json sigue siendo JSON válido y trae los valores SIN escapar como HTML. */
    public function test_manifest_json_sigue_siendo_json_valido_con_los_valores(): void
    {
        $zip      = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding());
        $manifest = json_decode((string) $this->leer($zip, 'manifest.json'), true);

        $this->assertIsArray($manifest, 'manifest.json dejó de ser JSON válido.');
        $this->assertSame('Ferretería "El <Tornillo> & Cía" d\'Ángelo', $manifest['name']);
        $this->assertSame('Ferretería "El <Tornillo> & Cía" d\'Ángelo', $manifest['short_name']);
        $this->assertSame('#1a2B3c', $manifest['theme_color']);
        $this->assertSame('#FFF', $manifest['background_color'], 'Lo que no es token no se toca.');
    }

    /** config.js lleva las once claves del contrato, todas como string, en la forma de SpaRuntimeConfig. */
    public function test_config_js_lleva_las_claves_del_contrato_como_string(): void
    {
        $zip       = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding());
        $config_js = (string) $this->leer($zip, 'config.js');

        $this->assertSame(SpaRuntimeConfig::render($this->config_vars()), $config_js);
        $this->assertStringStartsWith('window.__CC_CONFIG__ = {', $config_js);

        $json = substr($config_js, strlen('window.__CC_CONFIG__ = '), -2);
        $vars = json_decode($json, true);
        $this->assertIsArray($vars);
        $this->assertSame(array_keys($this->config_vars()), array_keys($vars));
        foreach ($vars as $clave => $valor) {
            $this->assertIsString($valor, "La clave {$clave} de config.js no viaja como string.");
        }
        $this->assertSame('4200', $vars['VUE_APP_COMMERCE_ID']);
    }

    /** Los 17 archivos de branding pisan los del release (y lo que no es branding queda igual). */
    public function test_el_branding_pisa_los_iconos_del_release(): void
    {
        $zip = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding());

        foreach (EcommerceDistCustomizer::ARCHIVOS_DE_BRANDING as $relativa) {
            $this->assertSame(
                'BRANDING-DE-LA-TIENDA:' . $relativa,
                $this->leer($zip, $relativa),
                "{$relativa} no quedó con el branding de la tienda."
            );
        }

        $this->assertSame('console.log("app")', $this->leer($zip, 'js/app.123.js'));
        $this->assertSame('{"version":"1.0.0"}', $this->leer($zip, 'version.json'));
        $this->assertSame("RewriteEngine On\n", $this->leer($zip, '.htaccess'));
    }

    /** El zip de origen no se toca: el personalizado es una copia. */
    public function test_el_zip_del_release_no_se_modifica(): void
    {
        $release = $this->zip_de_release();
        $antes   = md5_file($release);

        $this->personalizar($release, $this->tokens_dificiles(), $this->carpeta_de_branding());

        $this->assertSame($antes, md5_file($release));
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos
     |----------------------------------------------------------------------------------------- */

    /** 🔴 Un token que el contrato no conoce queda sin reemplazar: no se publica, y no queda zip. */
    public function test_un_token_sin_reemplazar_frena_y_no_deja_zip(): void
    {
        $entradas               = $this->entradas_de_release();
        $entradas['index.html'] = str_replace('</head>', '<meta name="author" content="__CC_SITE_AUTHOR__"></head>', $entradas['index.html']);

        $destino = $this->dir . DIRECTORY_SEPARATOR . 'salida' . DIRECTORY_SEPARATOR . 'tienda_dist.zip';

        try {
            (new EcommerceDistCustomizer())->customize(
                $this->zip_de_release($entradas),
                $destino,
                $this->tokens_dificiles(),
                $this->config_vars(),
                $this->carpeta_de_branding()
            );
            $this->fail('Se personalizó un index.html con un token sin reemplazar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('__CC_SITE_AUTHOR__', $e->getMessage());
            $this->assertStringContainsString('index.html', $e->getMessage());
        }

        $this->assertFileDoesNotExist($destino, 'Quedó un zip a medio personalizar que podría pasar por bueno.');
    }

    /** Un token roto en el manifest también frena. */
    public function test_un_resto_de_token_en_el_manifest_frena(): void
    {
        $entradas                  = $this->entradas_de_release();
        $entradas['manifest.json'] = json_encode(['name' => '__CC_SITE_NAME__', 'lang' => '__CC_LANG']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('__CC_LANG');

        $this->personalizar($this->zip_de_release($entradas), $this->tokens_dificiles(), $this->carpeta_de_branding());
    }

    /** Sin un token reemplazable (tokens vacíos), lo que quede en el HTML frena. */
    public function test_sin_valores_los_tokens_quedan_y_frena(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('__CC_SITE_NAME__');

        $this->personalizar($this->zip_de_release(), [], $this->carpeta_de_branding());
    }

    /**
     * Falta cada uno de los archivos obligatorios de la raíz: frena nombrándolo.
     *
     * @return void
     */
    public function test_si_falta_un_archivo_de_la_raiz_frena(): void
    {
        foreach (['index.html', '.htaccess', 'seo.php', 'manifest.json'] as $archivo) {
            $entradas = $this->entradas_de_release();
            unset($entradas[$archivo]);

            try {
                $this->personalizar($this->zip_de_release($entradas), $this->tokens_dificiles(), $this->carpeta_de_branding());
                $this->fail("Se personalizó un release sin {$archivo}.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($archivo, $e->getMessage());
            }
        }
    }

    /** Un release mal empaquetado (todo adentro de dist/) frena: index.html no está en la raíz. */
    public function test_un_release_con_todo_adentro_de_dist_frena(): void
    {
        $entradas = [];
        foreach ($this->entradas_de_release() as $nombre => $contenido) {
            $entradas['dist/' . $nombre] = $contenido;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('index.html');

        $this->personalizar($this->zip_de_release($entradas), $this->tokens_dificiles(), $this->carpeta_de_branding());
    }

    /** Falta un ícono (o el favicon, o la og:image): frena nombrándolo. */
    public function test_si_falta_un_archivo_de_branding_frena(): void
    {
        foreach (['img/icons/apple-touch-icon-180x180.png', 'favicon.ico', 'img/og-image.png', 'img/icons/safari-pinned-tab.svg'] as $faltante) {
            $this->borrar_arbol($this->dir . DIRECTORY_SEPARATOR . 'branding');

            try {
                $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding([$faltante]));
                $this->fail("Se personalizó sin {$faltante}.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($faltante, $e->getMessage());
            }
        }
    }

    /** Un JSON que deja de ser JSON después del reemplazo frena (token fuera de un string). */
    public function test_un_manifest_que_queda_roto_frena(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON');

        EcommerceDistCustomizer::replace_tokens_in_json('{"name": __CC_SITE_NAME__}', ['__CC_SITE_NAME__' => 'X']);
    }

    /* ------------------------------------------------------------------------------------------
     | El sello de deploy y el service worker (chequeo del 2/10/2026)
     |----------------------------------------------------------------------------------------- */

    /**
     * a) El sello va al ?v= de los íconos y del manifest que inyecta el plugin PWA, y a ningún otro
     * ?v= (una hoja de estilos externa con ?v= queda como vino).
     */
    public function test_el_sello_va_en_el_v_de_iconos_y_manifest_y_en_nada_mas(): void
    {
        $zip  = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding(), '20261002101500');
        $html = (string) $this->leer($zip, 'index.html');

        $this->assertStringContainsString('href="/img/icons/favicon-32x32.png?v=1.0.0.20261002101500"', $html);
        $this->assertStringContainsString('href="/manifest.json?v=1.0.0.20261002101500"', $html);
        $this->assertStringContainsString('href="/img/icons/apple-touch-icon-180x180.png?v=1.0.0.20261002101500"', $html);
        $this->assertStringContainsString('content="/img/icons/msapplication-icon-144x144.png?v=1.0.0.20261002101500"', $html);
        $this->assertStringContainsString('href="/css/fuente-externa.css?v=9"', $html, 'Se tocó un ?v= que no es del plugin PWA.');
        $this->assertSame(4, substr_count($html, '.20261002101500"'));
    }

    /**
     * 🔴 b) Las revision de /index.html y /manifest.json en el precache son el md5 del contenido FINAL,
     * el precache cambia de nombre (y el viejo no queda), y lo que no es index ni manifest no se toca.
     */
    public function test_el_precache_queda_con_el_md5_del_contenido_final_y_con_nombre_nuevo(): void
    {
        $zip = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding(), '20261002101500');

        list($nombre, $precache) = $this->precache_de($zip);
        $revisiones = EcommerceDistCustomizer::precache_revisions($precache);

        $this->assertSame(md5((string) $this->leer($zip, 'index.html')), $revisiones['/index.html']);
        $this->assertSame(md5((string) $this->leer($zip, 'manifest.json')), $revisiones['/manifest.json']);
        $this->assertNotSame(md5($this->index_html()), $revisiones['/index.html'], 'Quedó la revision del index.html CON tokens.');
        $this->assertSame('ece64fc03253914f7bda2be4485981b8', $revisiones['/robots.txt']);

        $this->assertNotSame(self::PRECACHE_DEL_RELEASE, $nombre, 'El precache no cambió de nombre.');
        $this->assertSame('precache-manifest.' . md5($precache) . '.js', $nombre);
        $this->assertNotContains(self::PRECACHE_DEL_RELEASE, $this->nombres($zip), 'Quedó el precache viejo en el zip.');
    }

    /** c) service-worker.js queda sellado y apuntando al precache nuevo. */
    public function test_el_service_worker_queda_sellado_e_importa_el_precache_nuevo(): void
    {
        $zip = $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding(), '20261002101500');

        list($nombre) = $this->precache_de($zip);
        $sw = (string) $this->leer($zip, 'service-worker.js');

        $this->assertStringEndsWith("\n// cc-deploy 20261002101500\n", $sw);
        $this->assertStringContainsString('"/' . $nombre . '"', $sw);
        $this->assertStringNotContainsString(self::PRECACHE_DEL_RELEASE, $sw);
        $this->assertStringContainsString('workbox.precaching.precacheAndRoute', $sw, 'Se rompió el resto del service worker.');
    }

    /**
     * 🔴 El caso que motivó el sello: la MISMA versión desplegada dos veces con el mismo branding
     * cambia igual el service worker, el precache y el index.html, así el navegador detecta un SW
     * nuevo en cada deploy (como pasaba en la vía vieja con VUE_APP_ICONS_VERSION).
     */
    public function test_dos_deploys_de_la_misma_version_cambian_el_service_worker(): void
    {
        $release = $this->zip_de_release();
        $tokens  = $this->tokens_dificiles();

        $primero = $this->personalizar($release, $tokens, $this->carpeta_de_branding(), '20261002101500');
        $sw_1    = $this->leer($primero, 'service-worker.js');
        $html_1  = $this->leer($primero, 'index.html');
        list($precache_1) = $this->precache_de($primero);

        $segundo = $this->personalizar($release, $tokens, $this->carpeta_de_branding(), '20261002101600');

        $this->assertNotSame($sw_1, $this->leer($segundo, 'service-worker.js'));
        $this->assertNotSame($html_1, $this->leer($segundo, 'index.html'));
        list($precache_2) = $this->precache_de($segundo);
        $this->assertNotSame($precache_1, $precache_2);
    }

    /** Sin service-worker.js, sin precache o con dos precache: frena nombrando qué falta. */
    public function test_sin_service_worker_o_precache_frena(): void
    {
        $casos = [
            'service-worker.js' => function (array $entradas) {
                unset($entradas['service-worker.js']);

                return $entradas;
            },
            'precache-manifest' => function (array $entradas) {
                unset($entradas[self::PRECACHE_DEL_RELEASE]);

                return $entradas;
            },
            'trae 2' => function (array $entradas) {
                $entradas['precache-manifest.ffff.js'] = $entradas[self::PRECACHE_DEL_RELEASE];

                return $entradas;
            },
        ];

        foreach ($casos as $esperado => $armar) {
            try {
                $this->personalizar($this->zip_de_release($armar($this->entradas_de_release())), $this->tokens_dificiles(), $this->carpeta_de_branding());
                $this->fail('Se personalizó un release con problema de service worker: ' . $esperado);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($esperado, $e->getMessage());
            }
        }
    }

    /** Un precache sin entrada para /index.html frena: no se podría renovar. */
    public function test_un_precache_sin_index_html_frena(): void
    {
        $entradas = $this->entradas_de_release();
        $entradas[self::PRECACHE_DEL_RELEASE] = str_replace('"/index.html"', '"/otra.html"', $entradas[self::PRECACHE_DEL_RELEASE]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('/index.html');

        $this->personalizar($this->zip_de_release($entradas), $this->tokens_dificiles(), $this->carpeta_de_branding());
    }

    /** Un sello con caracteres que habría que escapar se rechaza. */
    public function test_un_sello_invalido_frena(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sello');

        $this->personalizar($this->zip_de_release(), $this->tokens_dificiles(), $this->carpeta_de_branding(), '2026"><script>');
    }

    /** El revision se encuentra aunque el bloque traiga "url" antes que "revision". */
    public function test_la_revision_se_reemplaza_sin_depender_del_orden_de_las_claves(): void
    {
        $js = 'x([{"url": "/index.html", "revision": "viejo"}, {"revision": "otro", "url": "/a.js"}]);';

        $nuevo = EcommerceDistCustomizer::update_precache_revisions($js, ['/index.html' => 'nuevo']);

        $this->assertSame(['/index.html' => 'nuevo', '/a.js' => 'otro'], EcommerceDistCustomizer::precache_revisions($nuevo));
    }
}
