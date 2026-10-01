<?php

namespace App\Services;

/**
 * Personaliza, para UNA tienda, el dist de `tienda-spa` que GitHub Actions publica una sola vez por
 * versión (misión cruzada `versiones-tienda`, 1/10/2026, secciones 1.2 y 1.3 del plan).
 *
 * El build del release es UNO para todos los clientes. Lo que antes se cocinaba por cliente al
 * compilar en el VPS de builds (`.env` + `sed` sobre `vue.config.js`) viaja ahora así:
 *
 *  1. Cinco TOKENS literales en `index.html` y `manifest.json`, que acá se reemplazan:
 *     `__CC_SITE_NAME__`, `__CC_SITE_DESCRIPTION__`, `__CC_SITE_URL__`, `__CC_SITE_IMAGE__` y
 *     `__CC_THEME_COLOR__`. En `index.html` van escapados como HTML (`htmlspecialchars`,
 *     ENT_QUOTES); en `manifest.json`, como string JSON, y el JSON se vuelve a parsear después.
 *  2. `config.js` (`window.__CC_CONFIG__ = {...};`, armado por `SpaRuntimeConfig::render`), que la
 *     SPA lee en runtime con las mismas claves `VUE_APP_*` que antes leía de `process.env`.
 *  3. Los archivos de branding (íconos PWA, `favicon.ico`, `safari-pinned-tab.svg` y
 *     `img/og-image.png`), que generan los mismos scripts node de siempre
 *     (`deploy/tienda/generate_pwa_icons.js` y `generate_og_image.js`) y se pisan en el zip.
 *
 * 🔴 ES UNA CLASE PURA A PROPÓSITO: sin SSH, sin base, sin config. Recibe un zip local, los valores
 * ya resueltos y una carpeta local, y devuelve otro zip local. Así se prueba entera en un test con
 * un zip armado a mano, que es la única forma de enterarse ANTES de producción de que un nombre con
 * comillas rompió el `<title>` o el `manifest.json`.
 *
 * 🔴 LOS FRENOS, y por qué ninguno es opcional:
 *  - Si después de reemplazar queda un `__CC_` en `index.html` o en `manifest.json`, FALLA. Una
 *    tienda publicada con `__CC_SITE_NAME__` en el título (y en la vista previa de WhatsApp de cada
 *    link que comparte) es peor que una corrida fallida y visible.
 *    ⚠️ ÚNICA EXCEPCIÓN: `__CC_CONFIG__`, que no es un token sino el NOMBRE de la global de runtime
 *    (`SpaRuntimeConfig::VARIABLE_GLOBAL`). El cargador inline de Google Maps de `index.html` la lee
 *    (plan, sección 2.1 punto 4), así que un `index.html` sano la trae. Tratarla como token sin
 *    reemplazar haría fallar TODOS los deploys.
 *  - Si el zip no trae `index.html`, `.htaccess`, `seo.php` o `manifest.json` en la RAÍZ, FALLA: un
 *    release mal empaquetado (con `dist/index.html` adentro) haría que el swap atómico deje la
 *    tienda sirviendo una carpeta que nadie pide.
 *  - Si falta un solo archivo de branding esperado, FALLA: se publicaría el ícono genérico del repo
 *    (o, peor, el de otro cliente) en vez del de la tienda.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types ni `mixed`.
 */
final class EcommerceDistCustomizer
{
    /** Token del nombre del comercio. */
    const TOKEN_SITE_NAME = '__CC_SITE_NAME__';

    /** Token de la descripción del sitio. */
    const TOKEN_SITE_DESCRIPTION = '__CC_SITE_DESCRIPTION__';

    /** Token de la URL pública de la tienda (sin barra final). */
    const TOKEN_SITE_URL = '__CC_SITE_URL__';

    /** Token de la URL absoluta de la og:image. */
    const TOKEN_SITE_IMAGE = '__CC_SITE_IMAGE__';

    /** Token del color de tema. */
    const TOKEN_THEME_COLOR = '__CC_THEME_COLOR__';

    /** Prefijo común de todos los tokens: lo que no puede quedar después de reemplazar. */
    const TOKEN_PREFIX = '__CC_';

    /**
     * Nombre de la global de runtime: empieza con el prefijo pero NO es un token (ver el docblock de
     * la clase). Sale de `SpaRuntimeConfig::VARIABLE_GLOBAL` sin el `window.`.
     */
    const GLOBAL_DE_RUNTIME = '__CC_CONFIG__';

    /** Color de tema si el branding no trae uno válido (el mismo default de `vue.config.js`). */
    const COLOR_POR_DEFECTO = '#c5111d';

    /** Nombre si el comercio no tiene ninguno (el mismo fallback de `vue.config.js`). */
    const NOMBRE_POR_DEFECTO = 'Tienda';

    /**
     * Descripción por defecto, a continuación del nombre. Es EXACTAMENTE la de `vue.config.js` de
     * `tienda-spa` (`siteName + ' - Tienda online. ...'`), para que una tienda sin `meta_description`
     * se vea igual venga de la vía vieja o del release.
     */
    const SUFIJO_DESCRIPCION_POR_DEFECTO = ' - Tienda online. Comprá online con envío o retiro en el local.';

    /** Largo máximo de la descripción (el mismo recorte que el `.env` de la vía vieja). */
    const DESCRIPCION_MAX = 300;

    /** Archivos que el zip del release tiene que traer en la raíz. */
    const ARCHIVOS_RAIZ_OBLIGATORIOS = ['index.html', '.htaccess', 'seo.php', 'manifest.json'];

    /** Archivos donde se reemplazan los tokens. */
    const ARCHIVO_HTML = 'index.html';
    const ARCHIVO_MANIFEST = 'manifest.json';

    /** Archivo de configuración de runtime que se agrega (o pisa) en la raíz. */
    const ARCHIVO_CONFIG_JS = 'config.js';

    /**
     * Los archivos de branding que generan los dos scripts node, con la ruta RELATIVA a la raíz del
     * dist (que es también la ruta relativa dentro de la carpeta de branding).
     *
     * Sale de `deploy/tienda/generate_pwa_icons.js` (FLAT_SIZES + MASKABLE_SIZES + favicon.ico, que
     * el script escribe en `<output_dir>/../../favicon.ico`, + safari-pinned-tab.svg) y de
     * `generate_og_image.js` (`img/og-image.png`). Si uno de los scripts agrega o saca un archivo,
     * esta lista se toca en el mismo cambio: un ícono que se genera y no se copia es un ícono
     * genérico publicado.
     */
    const ARCHIVOS_DE_BRANDING = [
        'img/icons/android-chrome-192x192.png',
        'img/icons/android-chrome-512x512.png',
        'img/icons/apple-touch-icon-60x60.png',
        'img/icons/apple-touch-icon-76x76.png',
        'img/icons/apple-touch-icon-120x120.png',
        'img/icons/apple-touch-icon-152x152.png',
        'img/icons/apple-touch-icon-180x180.png',
        'img/icons/apple-touch-icon.png',
        'img/icons/favicon-16x16.png',
        'img/icons/favicon-32x32.png',
        'img/icons/msapplication-icon-144x144.png',
        'img/icons/mstile-150x150.png',
        'img/icons/android-chrome-maskable-192x192.png',
        'img/icons/android-chrome-maskable-512x512.png',
        'img/icons/safari-pinned-tab.svg',
        'favicon.ico',
        'img/og-image.png',
    ];

    /**
     * Los cinco valores de los tokens, a partir de los mismos datos que alimentan el `.env` de la
     * vía vieja (sección 1.2 del plan).
     *
     * @param  string  $site_name         Nombre del comercio (`owner_display_name()`).
     * @param  string  $site_description  Descripción YA normalizada como en el `.env` (puede venir
     *                                    vacía: en ese caso se usa la descripción por defecto).
     * @param  string  $spa_url           URL pública de la tienda (con o sin barra final).
     * @param  string  $primary_color     Color primario del branding, crudo.
     * @param  string  $default_color     Color de respaldo si el primario no es un hex válido.
     * @return array<string, string>  Token => valor, sin escapar.
     */
    public static function site_tokens(
        string $site_name,
        string $site_description,
        string $spa_url,
        string $primary_color,
        string $default_color = self::COLOR_POR_DEFECTO
    ): array {
        $nombre = trim($site_name);
        if ($nombre === '') {
            $nombre = self::NOMBRE_POR_DEFECTO;
        }

        $descripcion = self::normalize_description($site_description);
        if ($descripcion === '') {
            $descripcion = $nombre . self::SUFIJO_DESCRIPCION_POR_DEFECTO;
        }

        $url = rtrim(trim($spa_url), '/');

        return [
            self::TOKEN_SITE_NAME        => $nombre,
            self::TOKEN_SITE_DESCRIPTION => $descripcion,
            self::TOKEN_SITE_URL         => $url,
            self::TOKEN_SITE_IMAGE       => $url . '/img/og-image.png',
            self::TOKEN_THEME_COLOR      => self::normalize_theme_color($primary_color, $default_color),
        ];
    }

    /**
     * Normaliza una descripción igual que la vía vieja la escribe en el `.env`: colapsa saltos de
     * línea, tabs y espacios repetidos a uno solo, recorta las puntas y la trunca a 300 caracteres.
     *
     * Es idempotente: pasarle una descripción ya normalizada la devuelve igual.
     *
     * @param  string  $description
     * @return string
     */
    public static function normalize_description(string $description): string
    {
        $normalizada = preg_replace('/\s+/u', ' ', $description);
        if (! is_string($normalizada)) {
            // UTF-8 inválido: preg_replace devuelve null. Se cae al texto con trim clásico.
            $normalizada = $description;
        }

        return mb_substr(trim($normalizada), 0, self::DESCRIPCION_MAX);
    }

    /**
     * Devuelve el color si es un hex CSS válido (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`), o el de
     * respaldo. El de respaldo también se valida, y si tampoco sirve se usa `#c5111d`.
     *
     * 🔴 Va en `<meta name="theme-color">`, en el `color` del `mask-icon` y en el `theme_color` del
     * manifest: un valor que no es un color (un nombre de clase, un texto vacío, `rgb(...)` con
     * comillas) no rompe la página pero deja el navegador sin color de barra, sin que nadie lo vea.
     *
     * @param  string  $color          Color crudo del branding.
     * @param  string  $default_color  Color de respaldo.
     * @return string
     */
    public static function normalize_theme_color(string $color, string $default_color = self::COLOR_POR_DEFECTO): string
    {
        $patron = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

        $color = trim($color);
        if (preg_match($patron, $color) === 1) {
            return $color;
        }

        $default_color = trim($default_color);
        if (preg_match($patron, $default_color) === 1) {
            return $default_color;
        }

        return self::COLOR_POR_DEFECTO;
    }

    /**
     * Reemplaza los tokens en el HTML, escapando cada valor como HTML (ENT_QUOTES).
     *
     * Usa `strtr()` y no `str_replace()` encadenados: una sola pasada, así un valor que contenga el
     * texto de otro token no se vuelve a reemplazar.
     *
     * @param  string                 $html
     * @param  array<string, string>  $tokens  Token => valor sin escapar.
     * @return string
     */
    public static function replace_tokens_in_html(string $html, array $tokens): string
    {
        $mapa = [];
        foreach ($tokens as $token => $valor) {
            $mapa[(string) $token] = htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
        }

        return strtr($html, $mapa);
    }

    /**
     * Reemplaza los tokens en un JSON, escapando cada valor como contenido de un string JSON, y
     * verifica que el resultado siga siendo JSON válido.
     *
     * @param  string                 $json
     * @param  array<string, string>  $tokens  Token => valor sin escapar.
     * @return string
     *
     * @throws \RuntimeException  Si un valor no se puede serializar (texto que no es UTF-8) o si el
     *                            resultado no es JSON válido.
     */
    public static function replace_tokens_in_json(string $json, array $tokens): string
    {
        $mapa = [];
        foreach ($tokens as $token => $valor) {
            $codificado = json_encode((string) $valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($codificado === false) {
                throw new \RuntimeException(
                    'No se pudo escapar como JSON el valor de ' . $token . ' para manifest.json: '
                    . json_last_error_msg()
                );
            }

            // Sin las comillas de las puntas: el token ya está adentro de un string del manifest.
            $mapa[(string) $token] = substr($codificado, 1, -1);
        }

        $resultado = strtr($json, $mapa);

        json_decode($resultado, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'manifest.json dejó de ser JSON válido después de reemplazar los tokens ('
                . json_last_error_msg() . '). No se despliega un manifest roto.'
            );
        }

        return $resultado;
    }

    /**
     * Los restos de token que quedan en un contenido (todo `__CC_...` salvo la global de runtime).
     *
     * @param  string  $contenido
     * @return array<int, string>  Restos distintos, en el orden en que aparecen. Vacío = limpio.
     */
    public static function leftover_tokens(string $contenido): array
    {
        // Se saca primero la global de runtime para que no cuente; lo que quede con el prefijo es
        // un token sin reemplazar (o uno roto, como `__CC_SITE_NAME_`).
        $sin_global = str_replace(self::GLOBAL_DE_RUNTIME, '', $contenido);

        if (strpos($sin_global, self::TOKEN_PREFIX) === false) {
            return [];
        }

        preg_match_all('/__CC_[A-Za-z0-9_]*/', $sin_global, $coincidencias);

        return array_values(array_unique($coincidencias[0]));
    }

    /**
     * Arma el zip personalizado de una tienda a partir del zip del release.
     *
     * No toca el zip de origen: trabaja sobre una copia en `$target_zip`. Si algo falla, borra la
     * copia antes de lanzar, para que un zip a medio personalizar no pueda pasar por bueno.
     *
     * @param  string                 $source_zip    Zip del release ya bajado (`tienda-spa-v{V}-dist.zip`).
     * @param  string                 $target_zip    Ruta del zip personalizado a crear.
     * @param  array<string, string>  $tokens        Salida de `site_tokens()`.
     * @param  array<string, mixed>   $config_vars   Variables de `config.js` (`VUE_APP_*` => valor).
     * @param  string                 $branding_dir  Carpeta local con los archivos de
     *                                               `ARCHIVOS_DE_BRANDING`, en sus rutas relativas.
     * @return array<string, mixed>  Resumen: `archivos_de_branding` (cuántos se pisaron),
     *                               `config_js_bytes`, `claves_config_js` y `tokens` (los reemplazados).
     *
     * @throws \RuntimeException  Ante cualquiera de los frenos del docblock de la clase.
     */
    public function customize(
        string $source_zip,
        string $target_zip,
        array $tokens,
        array $config_vars,
        string $branding_dir
    ): array {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'Falta la extensión zip de PHP en el admin: sin ZipArchive no se puede personalizar el dist de la tienda.'
            );
        }

        if (! is_file($source_zip)) {
            throw new \RuntimeException("No existe el zip del release a personalizar: {$source_zip}");
        }

        // Antes de copiar nada: si falta un archivo de branding, no tiene sentido seguir.
        $branding = $this->collect_branding_files($branding_dir);

        $directorio = dirname($target_zip);
        if (! is_dir($directorio) && ! mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            throw new \RuntimeException("No se pudo crear el directorio local {$directorio}.");
        }

        if (is_file($target_zip)) {
            @unlink($target_zip);
        }

        if (! copy($source_zip, $target_zip)) {
            throw new \RuntimeException("No se pudo copiar el zip del release a {$target_zip}.");
        }

        try {
            $resumen = $this->customize_copy($target_zip, $tokens, $config_vars, $branding);
            $this->verify_result($target_zip, $branding);
        } catch (\Throwable $e) {
            if (is_file($target_zip)) {
                @unlink($target_zip);
            }

            throw $e;
        }

        return $resumen;
    }

    /**
     * Hace las modificaciones sobre la copia del zip.
     *
     * @param  string                 $zip_path     Copia del zip del release.
     * @param  array<string, string>  $tokens       Token => valor.
     * @param  array<string, mixed>   $config_vars  Variables de `config.js`.
     * @param  array<string, string>  $branding     Ruta en el zip => ruta local.
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function customize_copy(string $zip_path, array $tokens, array $config_vars, array $branding): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException('ZipArchive no pudo abrir el zip del release: ' . basename($zip_path));
        }

        try {
            // 1) Los archivos de la raíz que el deploy necesita, ANTES de tocar nada.
            $faltantes = [];
            $nombres   = [];
            foreach (self::ARCHIVOS_RAIZ_OBLIGATORIOS as $archivo) {
                $nombre = $this->locate($zip, $archivo);
                if ($nombre === null) {
                    $faltantes[] = $archivo;
                    continue;
                }
                $nombres[$archivo] = $nombre;
            }

            if (! empty($faltantes)) {
                throw new \RuntimeException(
                    'El zip del release no trae en la raíz: ' . implode(', ', $faltantes) . '. El paquete '
                    . 'está mal armado (¿quedó adentro de una carpeta dist/?) y no se despliega.'
                );
            }

            // 2) index.html: tokens escapados como HTML.
            $html = $this->read_entry($zip, $nombres[self::ARCHIVO_HTML]);
            $html = self::replace_tokens_in_html($html, $tokens);
            $this->assert_without_leftovers($html, self::ARCHIVO_HTML);
            $this->write_entry($zip, $nombres[self::ARCHIVO_HTML], $html);

            // 3) manifest.json: tokens escapados como string JSON, y el JSON validado.
            $manifest = $this->read_entry($zip, $nombres[self::ARCHIVO_MANIFEST]);
            $manifest = self::replace_tokens_in_json($manifest, $tokens);
            $this->assert_without_leftovers($manifest, self::ARCHIVO_MANIFEST);
            $this->write_entry($zip, $nombres[self::ARCHIVO_MANIFEST], $manifest);

            // 4) config.js: el de la tienda, siempre (pisa el default vacío del release).
            $config_js = SpaRuntimeConfig::render($config_vars);
            $nombre_config = $this->locate($zip, self::ARCHIVO_CONFIG_JS);
            $this->write_entry($zip, $nombre_config !== null ? $nombre_config : self::ARCHIVO_CONFIG_JS, $config_js);

            // 5) Branding: cada archivo pisa (o se agrega en) su ruta del dist.
            foreach ($branding as $ruta_en_zip => $ruta_local) {
                $existente = $this->locate($zip, $ruta_en_zip);
                $destino   = $existente !== null ? $existente : $ruta_en_zip;

                if ($existente !== null && ! $zip->deleteName($existente)) {
                    throw new \RuntimeException("No se pudo reemplazar {$ruta_en_zip} en el zip del release.");
                }
                if (! $zip->addFile($ruta_local, $destino)) {
                    throw new \RuntimeException("No se pudo agregar {$ruta_en_zip} al zip personalizado.");
                }
            }
        } finally {
            $cerrado = $zip->close();
        }

        if (! $cerrado) {
            throw new \RuntimeException('No se pudo escribir el zip personalizado: ' . basename($zip_path));
        }

        return [
            'archivos_de_branding' => count($branding),
            'config_js_bytes'      => strlen($config_js),
            'claves_config_js'     => array_keys($config_vars),
            'tokens'               => array_keys($tokens),
        ];
    }

    /**
     * Vuelve a abrir el zip ya escrito y confirma que quedó como tiene que quedar: sin restos de
     * token, con `config.js` no vacío y con cada archivo de branding con el contenido de la carpeta.
     *
     * Es redundante con lo que hace `customize_copy()` y lo es a propósito: mide el archivo que se
     * va a subir, no lo que se creía estar escribiendo.
     *
     * @param  string                 $zip_path
     * @param  array<string, string>  $branding  Ruta en el zip => ruta local.
     * @return void
     *
     * @throws \RuntimeException
     */
    private function verify_result(string $zip_path, array $branding): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException('ZipArchive no pudo reabrir el zip personalizado: ' . basename($zip_path));
        }

        try {
            foreach ([self::ARCHIVO_HTML, self::ARCHIVO_MANIFEST] as $archivo) {
                $nombre = $this->locate($zip, $archivo);
                if ($nombre === null) {
                    throw new \RuntimeException("El zip personalizado perdió {$archivo}.");
                }
                $this->assert_without_leftovers($this->read_entry($zip, $nombre), $archivo);
            }

            $nombre_config = $this->locate($zip, self::ARCHIVO_CONFIG_JS);
            if ($nombre_config === null || trim($this->read_entry($zip, $nombre_config)) === '') {
                throw new \RuntimeException('El zip personalizado quedó sin config.js (o con config.js vacío).');
            }

            foreach ($branding as $ruta_en_zip => $ruta_local) {
                $nombre = $this->locate($zip, $ruta_en_zip);
                if ($nombre === null || $this->read_entry($zip, $nombre) !== (string) file_get_contents($ruta_local)) {
                    throw new \RuntimeException(
                        "El zip personalizado no quedó con el {$ruta_en_zip} de esta tienda."
                    );
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Junta los archivos de branding de la carpeta local, y falla si falta (o está vacío) alguno.
     *
     * @param  string  $branding_dir
     * @return array<string, string>  Ruta en el zip => ruta local.
     *
     * @throws \RuntimeException
     */
    private function collect_branding_files(string $branding_dir): array
    {
        $base = rtrim(str_replace('\\', '/', $branding_dir), '/');
        if ($base === '' || ! is_dir($base)) {
            throw new \RuntimeException("No existe la carpeta de branding de la tienda: {$branding_dir}");
        }

        $archivos  = [];
        $faltantes = [];
        foreach (self::ARCHIVOS_DE_BRANDING as $relativa) {
            $local = $base . '/' . $relativa;
            clearstatcache(true, $local);
            if (! is_file($local) || (int) filesize($local) === 0) {
                $faltantes[] = $relativa;
                continue;
            }
            $archivos[$relativa] = $local;
        }

        if (! empty($faltantes)) {
            throw new \RuntimeException(
                'Faltan archivos de branding de la tienda (' . implode(', ', $faltantes) . '). No se '
                . 'despliega: se publicaría el ícono genérico del release en vez del de este comercio.'
            );
        }

        return $archivos;
    }

    /**
     * Frena si un contenido conserva algún resto de token.
     *
     * @param  string  $contenido
     * @param  string  $archivo  Para el mensaje.
     * @return void
     *
     * @throws \RuntimeException
     */
    private function assert_without_leftovers(string $contenido, string $archivo): void
    {
        $restos = self::leftover_tokens($contenido);
        if (empty($restos)) {
            return;
        }

        throw new \RuntimeException(
            'Quedaron tokens sin reemplazar en ' . $archivo . ': ' . implode(', ', $restos) . '. No se '
            . 'publica una tienda con un placeholder en el título o en el manifest: revisá que el '
            . 'release de tienda-spa use solo los tokens del contrato (__CC_SITE_NAME__, '
            . '__CC_SITE_DESCRIPTION__, __CC_SITE_URL__, __CC_SITE_IMAGE__, __CC_THEME_COLOR__).'
        );
    }

    /**
     * Nombre real de una entrada del zip: `archivo` o `./archivo` (un zip armado con `zip -r . `
     * desde otra herramienta puede traer el prefijo).
     *
     * @param  \ZipArchive  $zip
     * @param  string       $archivo  Ruta relativa a la raíz.
     * @return string|null  Null si no está.
     */
    private function locate(\ZipArchive $zip, string $archivo): ?string
    {
        foreach ([$archivo, './' . $archivo] as $candidato) {
            if ($zip->locateName($candidato) !== false) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Lee una entrada del zip.
     *
     * @param  \ZipArchive  $zip
     * @param  string       $nombre
     * @return string
     *
     * @throws \RuntimeException  Si no se puede leer (zip corrupto).
     */
    private function read_entry(\ZipArchive $zip, string $nombre): string
    {
        $contenido = $zip->getFromName($nombre);
        if ($contenido === false) {
            throw new \RuntimeException("No se pudo leer {$nombre} del zip: el archivo está corrupto o incompleto.");
        }

        return $contenido;
    }

    /**
     * Escribe (o pisa) una entrada del zip con un contenido en memoria.
     *
     * Borra la entrada vieja antes de agregar la nueva: en PHP 7.4 `addFromString()` ya reemplaza,
     * pero hacerlo explícito no depende de la versión de libzip.
     *
     * @param  \ZipArchive  $zip
     * @param  string       $nombre
     * @param  string       $contenido
     * @return void
     *
     * @throws \RuntimeException
     */
    private function write_entry(\ZipArchive $zip, string $nombre, string $contenido): void
    {
        if ($zip->locateName($nombre) !== false && ! $zip->deleteName($nombre)) {
            throw new \RuntimeException("No se pudo reemplazar {$nombre} en el zip del release.");
        }

        if (! $zip->addFromString($nombre, $contenido)) {
            throw new \RuntimeException("No se pudo escribir {$nombre} en el zip personalizado.");
        }
    }
}
