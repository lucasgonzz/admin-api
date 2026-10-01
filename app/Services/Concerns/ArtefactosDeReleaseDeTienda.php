<?php

namespace App\Services\Concerns;

use App\Models\EcommerceVersion;
use App\Services\EcommerceDistCustomizer;
use App\Services\EcommerceReleaseArtifacts;

/**
 * La VÍA DE ARTEFACTO del pipeline de ecommerce (misión cruzada `versiones-tienda`, 1/10/2026).
 *
 * Hasta esta misión, cada instalación y cada actualización de una tienda clonaba `tienda-spa` en el
 * VPS de builds, corría `npm ci` + `npm run build` (un núcleo al 100 % durante minutos, sobre la
 * máquina donde viven los clientes migrados) y tomaba un lock GLOBAL que serializaba todas las
 * corridas de la flota. Ahora GitHub Actions compila UNA vez por versión y publica dos assets en el
 * release del tag `v{V}` (contrato en `EcommerceReleaseArtifacts`), y lo único que sigue siendo por
 * tienda se resuelve acá:
 *
 *  - `compile_spa`: baja `tienda-spa-v{V}-dist.zip`, resuelve el branding en vivo (el mismo
 *    `fetch_online_configuration_branding()` de siempre), genera íconos y og:image con los MISMOS
 *    scripts node en una carpeta de trabajo propia de la corrida en el VPS de builds (segundos, sin
 *    webpack y sin el lock global), los baja, y personaliza el zip localmente con
 *    `EcommerceDistCustomizer` (tokens + `config.js` + branding).
 *  - `upload_spa`: sube ese zip con el mismo swap atómico de siempre y verifica `config.js`.
 *  - `upload_api`: baja `tienda-api-v{V}.zip` (con `vendor/` adentro), lo sube y lo descomprime —
 *    en una actualización excluyendo `public/*`, `storage/*` y `.env`— y corre el mismo
 *    `composer install` con PHP 8.4 explícito de siempre.
 *  - `ensure_spa_cloned`, `write_env` y `finalize`: no cambian (la primera no hace nada).
 *
 * 🔴 LA DECISIÓN DE LA VÍA SE TOMA UNA SOLA VEZ, AL ARRANCAR (`tienda_resolver_version_de_la_corrida()`)
 * y antes de abrir una sola conexión SSH. Ahí se verifica que estén los DOS assets: si falta alguno,
 * la corrida falla nombrando repo, tag y asset —mismo criterio y mismo mensaje que el trait de
 * empresa (`ArtefactosDeRelease`)— y sólo cae a la vía vieja con `DEPLOY_PERMITIR_BUILD_EN_VPS=true`.
 * Decidirlo etapa por etapa permitiría una corrida mitad release y mitad `master`, que es una tienda
 * con una SPA que no corresponde a su API.
 *
 * ⚠️ Lo que la clase que lo usa tiene que traer puesto (mismo criterio que `ArtefactosDeRelease`):
 * `$installation`, `$ecommerce`, `log()`, `connect_build_vps()`, `reconnect_build_vps()`,
 * `exec_build_ssh()`, `build_vps_command()`, `verify_zip_on_vps()`, `open_sftp_session()`,
 * `sftp_download_file()`, `sftp_upload_file()`, `assert_local_zip_file()`, `reconnect_hosting_ssh()`,
 * `exec_hosting_ssh()`, `get_spa_docroot()`, `get_api_path()`, `ensure_hosting_api_directory()`,
 * `deploy_spa_zip_to_hosting()`, `build_composer_install_command()`,
 * `fetch_online_configuration_branding()`, `get_ecommerce_api_url_for_env()`, `pwa_display_name()`,
 * `assert_owner_commerce_id()`, `build_spa_env_vars()`, `build_spa_runtime_config_vars()`,
 * `log_missing_tienda_env_keys()`, `step_generate_pwa_icons()`, `step_generate_og_image()` — todos de
 * `EcommerceInstallationService` — y el trait `ArtefactosDeRelease` (de ahí salen
 * `artefacto_bajar_asset()`, `artefacto_assert_zip_trae()`, `artefacto_mensaje_sin_artefacto()`,
 * `artefacto_build_en_vps_permitido()` y `artefacto_comillas_posix()`).
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains/starts/ends`, argumentos nombrados, union types,
 * promoción en constructor, `readonly`, `enum`, atributos, `mixed` ni `never`. Los traits de 7.4 no
 * admiten constantes, así que los marcadores de los comandos son literales.
 */
trait ArtefactosDeReleaseDeTienda
{
    /**
     * Si esta corrida va por la vía de artefacto. La decide `tienda_resolver_version_de_la_corrida()`
     * al arrancar; false = vía vieja (compilar en el VPS de builds).
     *
     * @var bool
     */
    protected $tienda_via_artefacto = false;

    /**
     * Versión de ecommerce que despliega esta corrida por la vía de artefacto (null en la vieja).
     *
     * @var EcommerceVersion|null
     */
    protected $tienda_version = null;

    /**
     * Ruta local del zip del dist ya personalizado que deja `compile_spa` para `upload_spa`.
     *
     * @var string|null
     */
    protected $tienda_dist_personalizado = null;

    /* ═════════════════════════════════════════════════════════════════════════════════════════
     * 1) Qué versión se despliega y por qué vía
     * ═════════════════════════════════════════════════════════════════════════════════════════ */

    /**
     * Resuelve la versión de ecommerce de la corrida y decide la vía, ANTES de tocar ningún servidor.
     *
     * Orden:
     *  1. `ecommerce_version_id` de la corrida, si viene: tiene que existir y estar `published`; si
     *     no, la corrida falla (no hay salida de emergencia: alguien pidió ESA versión).
     *  2. Si no viene: la última publicada (por orden semántico), y se GUARDA en la corrida para que
     *     el historial diga qué se desplegó.
     *  3. Sin ninguna publicada: vía vieja si `DEPLOY_PERMITIR_BUILD_EN_VPS=true`; si no, la corrida
     *     falla diciendo que no hay versión publicada y cómo se publica.
     *  4. Con versión: los dos assets del release tienen que existir. Si falta uno: vía vieja con la
     *     bandera prendida (y la tienda NO va a quedar en esa versión, se avisa); si no, falla
     *     nombrando repo, tag y asset.
     *
     * @return void
     *
     * @throws \RuntimeException  En los casos de falla de arriba, o si GitHub responde un error
     *                            distinto de "no está" (un token vencido no se disfraza de "no hay
     *                            artefacto", y no se compila en el VPS por eso).
     */
    protected function tienda_resolver_version_de_la_corrida(): void
    {
        $step = 'ensure_spa_cloned';

        $this->tienda_via_artefacto = false;
        $this->tienda_version       = null;

        $pedida_id = $this->installation->ecommerce_version_id;

        if ($pedida_id !== null) {
            $version = EcommerceVersion::find((int) $pedida_id);
            if ($version === null) {
                throw new \RuntimeException(
                    'La corrida pide la versión de ecommerce #' . (int) $pedida_id . ', que no existe en el '
                    . 'admin. Mirá las publicadas con GET claude/ecommerce/versions y volvé a pedir la '
                    . 'actualización con una de esas.'
                );
            }

            if (! $version->is_published()) {
                throw new \RuntimeException(
                    'La corrida pide la versión de ecommerce ' . $version->version . ', que está en estado «'
                    . $version->status . '»: sólo se despliega una versión publicada. Publicala (PATCH '
                    . 'claude/ecommerce/versions/' . (int) $version->id . ' con status=published, o desde '
                    . 'Versiones de ecommerce en el panel) o pedí otra.'
                );
            }

            $origen = 'pedida para esta corrida';
        } else {
            $version = EcommerceVersion::latest_published();

            if ($version === null) {
                if ($this->artefacto_build_en_vps_permitido()) {
                    $this->log(
                        $step,
                        'No hay ninguna versión de ecommerce publicada y DEPLOY_PERMITIR_BUILD_EN_VPS está '
                        . 'prendida: se compila la última de master en el VPS de builds (vía vieja). La tienda '
                        . 'no va a quedar registrada en ninguna versión.',
                        'warning'
                    );

                    return;
                }

                $mensaje = $this->tienda_mensaje_sin_version_publicada();
                $this->log($step, $mensaje, 'error');

                throw new \RuntimeException($mensaje);
            }

            // Se guarda en la corrida: el historial tiene que decir qué versión se desplegó.
            $this->installation->update(['ecommerce_version_id' => $version->id]);

            $origen = 'la última publicada';
        }

        // Los dos assets del release, antes de abrir una sola conexión.
        $artefactos = (new EcommerceReleaseArtifacts($this->artefactos_release()))->find((string) $version->version);

        foreach ($artefactos as $artefacto) {
            if ($artefacto['found'] !== null) {
                continue;
            }

            $mensaje = $this->artefacto_mensaje_sin_artefacto($artefacto['asset'], $artefacto['tag'], $artefacto['repo']);

            if ($this->artefacto_build_en_vps_permitido()) {
                $this->log(
                    $step,
                    $mensaje . ' Como DEPLOY_PERMITIR_BUILD_EN_VPS está prendida, se compila la última de '
                    . 'master en el VPS de builds: la tienda NO va a quedar en la versión ' . $version->version . '.',
                    'warning'
                );

                return;
            }

            $this->log($step, $mensaje, 'error');

            throw new \RuntimeException($mensaje);
        }

        $this->tienda_version       = $version;
        $this->tienda_via_artefacto = true;

        $this->log(
            $step,
            'Versión de ecommerce ' . $version->version . ' (' . $origen . '): se despliegan los artefactos del '
            . 'release ' . EcommerceReleaseArtifacts::tag((string) $version->version) . ' de '
            . EcommerceReleaseArtifacts::spa_repo() . ' y ' . EcommerceReleaseArtifacts::api_repo()
            . ', sin compilar en el VPS de builds.',
            'success'
        );
    }

    /**
     * El mensaje con el que falla una corrida cuando no hay ninguna versión de ecommerce publicada.
     *
     * Dice qué falta y cómo se arregla, sin ir a buscar nada: el endpoint, el panel, y la salida de
     * emergencia (que es la misma bandera que empresa).
     *
     * @return string
     */
    protected function tienda_mensaje_sin_version_publicada(): string
    {
        return 'No hay ninguna versión de ecommerce publicada en el admin, así que no hay un release de '
            . 'tienda-spa / tienda-api para desplegar, y no se compila en el VPS de builds. Publicá la '
            . 'versión con POST claude/ecommerce/versions (version, p. ej. 1.0.0: verifica que el release '
            . 'tenga los dos assets) o desde Versiones de ecommerce en el panel, y volvé a correr la corrida. '
            . 'Para forzar el build en el VPS de builds, DEPLOY_PERMITIR_BUILD_EN_VPS=true en el .env del admin.';
    }

    /**
     * Versión que queda registrada en la tienda al terminar bien: la de la corrida si fue por
     * artefacto, null si fue por la vía vieja (compiló master, que no es ninguna versión registrada).
     *
     * @return int|null
     */
    protected function tienda_version_instalada_id(): ?int
    {
        if (! $this->tienda_via_artefacto || $this->tienda_version === null) {
            return null;
        }

        return (int) $this->tienda_version->id;
    }

    /* ═════════════════════════════════════════════════════════════════════════════════════════
     * 2) compile_spa: dist del release + branding + personalización
     * ═════════════════════════════════════════════════════════════════════════════════════════ */

    /**
     * Etapa `compile_spa` de la vía de artefacto. Deja en `$tienda_dist_personalizado` el zip listo
     * para subir.
     *
     * @return void
     *
     * @throws \RuntimeException  Si falta configuración de la tienda, si el asset desapareció del
     *                            release, si la generación de íconos falla o si la personalización
     *                            frena (ver `EcommerceDistCustomizer`).
     */
    protected function tienda_artefacto_compile_spa(): void
    {
        $step = 'compile_spa';
        $uuid = $this->tienda_uuid_de_la_corrida();

        // Mismas dos guardas que la vía vieja, ANTES de bajar nada.
        $spa_url = trim((string) $this->ecommerce->spa_url);
        if ($spa_url === '') {
            throw new \RuntimeException('La tienda no tiene spa_url configurada.');
        }
        $this->assert_owner_commerce_id();

        $esperado     = EcommerceReleaseArtifacts::expected((string) $this->tienda_version->version);
        $spa          = $esperado['spa'];
        $dist_release = storage_path('app/deployments/tienda_release_dist_' . $uuid . '.zip');
        $branding_dir = storage_path('app/deployments/tienda_branding_' . $uuid);

        // La descarga va ADENTRO del try: si el zip bajó pero no pasa la verificación (no es un zip,
        // no trae index.html en la raíz), el finally lo borra igual y no queda tirado en
        // storage/app/deployments.
        try {
            if (! $this->artefacto_bajar_asset($spa['repo'], $spa['asset'], $spa['tag'], $dist_release, 'index.html', $step)) {
                // Se verificó al arrancar: si ahora no está, alguien lo sacó del release en el medio.
                $mensaje = $this->artefacto_mensaje_sin_artefacto($spa['asset'], $spa['tag'], $spa['repo']);
                $this->log($step, $mensaje, 'error');

                throw new \RuntimeException($mensaje);
            }

            // a) Branding en vivo, con la misma cadena de fuentes que la vía vieja.
            list($primary_color, $logo_url, $logo_source, $meta_description) = $this->fetch_online_configuration_branding();

            // b) Las variables de config.js: la MISMA fuente que el .env de la vía vieja.
            $api_url_for_env = $this->get_ecommerce_api_url_for_env();
            $site_name       = $this->pwa_display_name();
            $env_vars        = $this->build_spa_env_vars($api_url_for_env, $spa_url, $site_name, $meta_description);
            $config_vars     = $this->build_spa_runtime_config_vars($env_vars);
            $this->log_missing_tienda_env_keys('config.js');

            $this->log(
                $step,
                'config.js de la tienda: ' . count($config_vars) . ' variables — API: ' . $api_url_for_env
                . ' | SPA: ' . $spa_url . ' | Nombre: ' . $site_name . ' | Descripcion: '
                . (trim($meta_description) !== '' ? 'con descripcion' : 'sin descripcion')
                . ' | Logo: ' . ($logo_source !== null ? $logo_source : 'sin logo')
            );

            // c) Íconos PWA y og:image: mismos scripts node, en la carpeta de esta corrida.
            $this->tienda_generar_branding($logo_url, $primary_color, $site_name, $branding_dir);

            // d) Personalización local del zip del release.
            $default_color = trim((string) config('services.deploy_tienda.default_theme_color', '#c5111d'));
            $tokens        = EcommerceDistCustomizer::site_tokens(
                $env_vars['VUE_APP_SITE_NAME'],
                $env_vars['VUE_APP_SITE_DESCRIPTION'],
                $env_vars['VUE_APP_SITE_URL'],
                $primary_color,
                $default_color
            );

            $destino = storage_path('app/deployments/tienda_dist_' . $uuid . '.zip');
            $resumen = (new EcommerceDistCustomizer())->customize(
                $dist_release,
                $destino,
                $tokens,
                $config_vars,
                $branding_dir
            );
            $this->assert_local_zip_file($destino, 0, $step);

            $this->tienda_dist_personalizado = $destino;

            $this->log(
                $step,
                'Dist de la versión ' . $this->tienda_version->version . ' personalizado para la tienda: '
                . count($resumen['tokens']) . ' tokens reemplazados (color ' . $tokens[EcommerceDistCustomizer::TOKEN_THEME_COLOR]
                . '), config.js con ' . count($resumen['claves_config_js']) . ' claves y '
                . (int) $resumen['archivos_de_branding'] . ' archivos de branding. No se compiló nada en el VPS de builds.',
                'success'
            );
        } finally {
            if (is_file($dist_release)) {
                @unlink($dist_release);
            }
            $this->tienda_borrar_carpeta_local($branding_dir);
        }
    }

    /**
     * Genera los íconos PWA, `favicon.ico`, `safari-pinned-tab.svg` y la og:image de la tienda en el
     * VPS de builds, con los MISMOS scripts y la MISMA lógica de logo/placeholder que la vía vieja, y
     * los deja en una carpeta local con las rutas del dist (`img/icons/...`, `favicon.ico`,
     * `img/og-image.png`).
     *
     * 🔴 La carpeta de trabajo es PROPIA de la corrida (`<branding_work_path>/runs/<uuid>/`), no el
     * clone compartido de `tienda-spa`: por eso no hace falta el lock global, y dos corridas en
     * paralelo no se pisan los íconos. `sharp` vive UNA vez en `<branding_work_path>/node_modules`
     * (node lo encuentra subiendo directorios desde el script) y se instala con `flock` la primera.
     *
     * La carpeta de la corrida en el VPS se borra al final pase lo que pase (best-effort).
     *
     * @param  string|null  $logo_url       Logo resuelto del branding (null = placeholder).
     * @param  string       $primary_color  Color primario (fondo del placeholder y de la og:image).
     * @param  string       $display_name   Nombre del comercio (inicial del placeholder).
     * @param  string       $local_dir      Carpeta local donde quedan los archivos.
     * @return void
     *
     * @throws \RuntimeException  Si la generación o la descarga fallan.
     */
    protected function tienda_generar_branding(?string $logo_url, string $primary_color, string $display_name, string $local_dir): void
    {
        $step       = 'compile_spa';
        $work       = $this->tienda_branding_work_path();
        $run_dir    = $work . '/runs/' . $this->tienda_uuid_de_la_corrida();
        $out_dir    = $run_dir . '/out';
        $remote_zip = $run_dir . '/branding.zip';
        $local_zip  = $local_dir . '.zip';

        $this->log($step, 'Generando íconos PWA y og:image en la carpeta de esta corrida (' . $run_dir . ') del VPS de builds...');

        $this->connect_build_vps();

        try {
            $this->tienda_asegurar_sharp($work);

            // Carpeta limpia de la corrida, con la estructura del dist: out/img/icons.
            $this->exec_build_ssh(
                $step,
                'rm -rf ' . $this->artefacto_comillas_posix($run_dir)
                . ' && mkdir -p ' . $this->artefacto_comillas_posix($out_dir . '/img/icons') . ' 2>&1'
            );

            // Mismos generadores que la vía vieja. favicon.ico lo escribe el script dos niveles arriba
            // de img/icons, o sea en out/favicon.ico: justo la raíz del dist.
            $this->step_generate_pwa_icons($run_dir, $logo_url, $primary_color, $display_name, $out_dir . '/img/icons', false);
            $this->step_generate_og_image($run_dir, $logo_url, $primary_color, $display_name, $out_dir . '/img/og-image.png', false);

            $this->exec_build_ssh(
                $step,
                'cd ' . $this->artefacto_comillas_posix($out_dir) . ' && rm -f ../branding.zip && zip -r ../branding.zip . 2>&1',
                true,
                true
            );
            $bytes = $this->verify_zip_on_vps($remote_zip, $step);

            $sftp = $this->open_sftp_session('vps');
            $this->sftp_download_file($sftp, $remote_zip, $local_zip, $bytes, $step);
            $this->tienda_extraer_branding($local_zip, $local_dir);

            $this->log($step, 'Branding de la tienda generado y bajado al admin (' . $bytes . ' bytes)', 'success');
        } finally {
            if (is_file($local_zip)) {
                @unlink($local_zip);
            }

            try {
                $this->reconnect_build_vps();
                $this->exec_build_ssh($step, 'rm -rf ' . $this->artefacto_comillas_posix($run_dir), false);
            } catch (\Throwable $e) {
                // Best-effort: una carpeta de corrida que quedó en el VPS no rompe nada (la próxima
                // corrida con el mismo uuid no existe), y no tiene que tapar el error real.
                $this->log($step, 'No se pudo limpiar ' . $run_dir . ' en el VPS de builds: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * Deja `sharp` instalado UNA vez en la carpeta de branding del VPS de builds.
     *
     * 🔴 Con `flock`: dos corridas que arrancan juntas sobre una carpeta sin `sharp` lo instalarían a
     * la vez y se romperían el `node_modules` entre ellas. El `package.json` mínimo existe para que
     * `npm` instale ACÁ y no en el primer `package.json` que encuentre subiendo directorios.
     *
     * @param  string  $work  `branding_work_path`.
     * @return void
     *
     * @throws \RuntimeException  Si después de instalar, `require("sharp")` sigue fallando.
     */
    protected function tienda_asegurar_sharp(string $work): void
    {
        $step = 'compile_spa';

        // Primero la carpeta: build_vps_command() hace `cd` antes del comando.
        $this->exec_build_ssh($step, 'mkdir -p ' . $this->artefacto_comillas_posix($work . '/runs') . ' 2>&1');

        $instalar = 'cd ' . $this->artefacto_comillas_posix($work)
            . ' && if [ ! -f node_modules/sharp/package.json ] || ! node -e "require(\'sharp\')" >/dev/null 2>&1; '
            . 'then npm install sharp --no-save --no-audit --no-fund 2>&1; fi';

        $comando = '{ [ -f package.json ] || printf %s ' . $this->artefacto_comillas_posix('{"name":"tienda-branding","private":true}')
            . ' > package.json; }'
            . ' && flock -w 600 ' . $this->artefacto_comillas_posix($work . '/.sharp.lock')
            . ' -c ' . $this->artefacto_comillas_posix($instalar)
            . ' && node -e ' . $this->artefacto_comillas_posix('require("sharp")')
            . ' && echo SHARP_LISTO';

        $this->log($step, 'Verificando sharp en la carpeta de branding del VPS de builds (se instala una sola vez)...');
        $salida = $this->exec_build_ssh($step, $this->build_vps_command($work, $comando), true, true);

        if (stripos($salida, 'SHARP_LISTO') === false) {
            throw new \RuntimeException(
                'No quedó sharp disponible en ' . $work . ' del VPS de builds: sin él no se pueden generar '
                . 'los íconos de la tienda. ' . $this->truncate_for_log($salida, 600)
            );
        }
    }

    /**
     * Saca del zip de branding bajado del VPS los archivos esperados, a la carpeta local.
     *
     * Sólo los de `EcommerceDistCustomizer::ARCHIVOS_DE_BRANDING`, uno por uno (nada de
     * `extractTo()` del zip entero): así no se escribe en disco nada que no se haya pedido. Si falta
     * alguno no se frena acá: lo frena `EcommerceDistCustomizer`, que es la única definición de "el
     * branding está completo".
     *
     * @param  string  $zip_path   Zip local de branding.
     * @param  string  $local_dir  Carpeta destino (se crea).
     * @return void
     *
     * @throws \RuntimeException  Si el zip no abre.
     */
    protected function tienda_extraer_branding(string $zip_path, string $local_dir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException('ZipArchive no pudo abrir el zip de branding bajado del VPS de builds.');
        }

        try {
            foreach (EcommerceDistCustomizer::ARCHIVOS_DE_BRANDING as $relativa) {
                $contenido = $zip->getFromName($relativa);
                if ($contenido === false) {
                    $contenido = $zip->getFromName('./' . $relativa);
                }
                if ($contenido === false) {
                    continue;
                }

                $destino = rtrim($local_dir, '/\\') . '/' . $relativa;
                if (! is_dir(dirname($destino)) && ! mkdir(dirname($destino), 0755, true) && ! is_dir(dirname($destino))) {
                    throw new \RuntimeException('No se pudo crear ' . dirname($destino) . ' para el branding de la tienda.');
                }
                file_put_contents($destino, $contenido);
            }
        } finally {
            $zip->close();
        }
    }

    /* ═════════════════════════════════════════════════════════════════════════════════════════
     * 3) upload_spa: el zip personalizado, con el mismo swap atómico
     * ═════════════════════════════════════════════════════════════════════════════════════════ */

    /**
     * Etapa `upload_spa` de la vía de artefacto: sube el zip personalizado con el mismo
     * `deploy_spa_zip_to_hosting()` (y por lo tanto el mismo `build_spa_atomic_deploy_shell()`) que la
     * vía vieja, y después verifica que `config.js` haya quedado en el docroot y no vacío.
     *
     * 🔴 La verificación no es redundante con la del zip: lo que importa es lo que sirve el docroot.
     * Un bundle de release sin `config.js` arriba arranca sin saber a qué API pegarle.
     *
     * @return void
     *
     * @throws \RuntimeException  Si no está el zip de compile_spa o si config.js no quedó arriba.
     */
    protected function tienda_artefacto_upload_spa(): void
    {
        $step      = 'upload_spa';
        $local_zip = $this->tienda_dist_personalizado;

        if ($local_zip === null || ! is_file($local_zip)) {
            throw new \RuntimeException(
                'No está el dist personalizado de la tienda que tenía que dejar compile_spa: no hay nada que subir.'
            );
        }

        $this->assert_local_zip_file($local_zip, 0, $step);
        $this->deploy_spa_zip_to_hosting($local_zip);
        $this->tienda_dist_personalizado = null;

        $docroot = $this->get_spa_docroot();
        $this->reconnect_hosting_ssh();
        $salida = $this->exec_hosting_ssh(
            $step,
            'test -s ' . $this->artefacto_comillas_posix($docroot . '/config.js')
            . ' && echo CONFIG_JS_PRESENTE || echo CONFIG_JS_AUSENTE',
            false
        );

        if (stripos($salida, 'CONFIG_JS_PRESENTE') === false) {
            throw new \RuntimeException(
                'Después del deploy, ' . $docroot . '/config.js no está o está vacío: la tienda arranca sin '
                . 'saber a qué API pegarle. Revisá el docroot en el hosting y volvé a correr la corrida.'
            );
        }

        $this->log($step, 'Verificado config.js en el docroot de la tienda', 'success');

        // Informativo: qué versión dice servir la tienda (version.json lo escribe Actions en el dist).
        $version_servida = trim($this->exec_hosting_ssh(
            $step,
            'cat ' . $this->artefacto_comillas_posix($docroot . '/version.json') . ' 2>/dev/null || true',
            false
        ));
        if ($version_servida !== '') {
            $this->log($step, 'version.json de la tienda: ' . $this->truncate_for_log($version_servida, 200));
        }
    }

    /* ═════════════════════════════════════════════════════════════════════════════════════════
     * 4) upload_api: tienda-api-v{V}.zip
     * ═════════════════════════════════════════════════════════════════════════════════════════ */

    /**
     * Etapa `upload_api` de la vía de artefacto.
     *
     * @param  bool  $es_actualizacion  True en `EcommerceDeploymentService` (update): el `unzip`
     *                                  excluye `public/*`, `storage/*` y `.env`. False en la
     *                                  instalación: se descomprime todo, porque esos directorios
     *                                  todavía no existen en el hosting.
     * @return void
     *
     * @throws \RuntimeException  Si el asset desapareció o vino sin `artisan` / `vendor/autoload.php`.
     */
    protected function tienda_artefacto_upload_api(bool $es_actualizacion): void
    {
        $step      = 'upload_api';
        $esperado  = EcommerceReleaseArtifacts::expected((string) $this->tienda_version->version);
        $api       = $esperado['api'];
        $zip_name  = 'tienda_api_release_' . $this->tienda_uuid_de_la_corrida() . '.zip';
        $local_zip = storage_path('app/deployments/' . $zip_name);

        // Descarga adentro del try, por lo mismo que en compile_spa: un zip que no pasa la
        // verificación no queda tirado en storage/app/deployments.
        try {
            if (! $this->artefacto_bajar_asset($api['repo'], $api['asset'], $api['tag'], $local_zip, 'artisan', $step)) {
                $mensaje = $this->artefacto_mensaje_sin_artefacto($api['asset'], $api['tag'], $api['repo']);
                $this->log($step, $mensaje, 'error');

                throw new \RuntimeException($mensaje);
            }

            // Con vendor/ adentro: el composer install de abajo es un no-op rápido, como en empresa.
            $this->artefacto_assert_zip_trae($local_zip, 'vendor/autoload.php', $step);

            $api_path = $this->get_api_path();
            $this->ensure_hosting_api_directory($step);

            $sftp = $this->open_sftp_session('shared_hosting');
            $this->sftp_upload_file($sftp, $local_zip, $api_path . '/' . $zip_name, $step);
            $this->log($step, 'ZIP de tienda-api ' . $api['tag'] . ' (release) subido al hosting');

            $this->reconnect_hosting_ssh();
            $this->exec_hosting_ssh($step, $this->tienda_comando_unzip_api($api_path, $zip_name, $es_actualizacion), true, true);
            $this->log(
                $step,
                $es_actualizacion
                    ? 'tienda-api ' . $api['tag'] . ' descomprimida en el hosting (sin tocar .env, public/ ni storage/)'
                    : 'tienda-api ' . $api['tag'] . ' descomprimida en el hosting (instalación: todo el paquete)',
                'success'
            );

            // Mismo composer install de siempre: PHP 8.4 explícito y sin scripts (finalize corre
            // package:discover con el .env ya en disco).
            $this->log($step, 'Corriendo composer install en hosting (sin scripts)...');
            $this->reconnect_hosting_ssh();
            $this->exec_hosting_ssh($step, $this->build_composer_install_command($api_path, false), true, true);
            $this->log($step, 'tienda-api lista en el hosting', 'success');
        } finally {
            if (is_file($local_zip)) {
                @unlink($local_zip);
            }
        }
    }

    /**
     * Comando remoto que descomprime el zip de la API y lo borra.
     *
     * 🔴 En una actualización excluye `public/*`, `storage/*` y `.env` (mismo criterio que el zip de
     * la vía vieja: son de la tienda y no se pisan). El código de salida 11 de `unzip` ("un patrón
     * no matcheó", que pasa con `.env` porque el release no lo trae) se tolera explícitamente; cualquier
     * otro distinto de 0 corta la corrida.
     *
     * @param  string  $api_path          Directorio de la API en el hosting.
     * @param  string  $zip_name          Nombre del zip subido.
     * @param  bool    $es_actualizacion  Ver `tienda_artefacto_upload_api()`.
     * @return string
     */
    protected function tienda_comando_unzip_api(string $api_path, string $zip_name, bool $es_actualizacion): string
    {
        $cd  = 'cd ' . $this->artefacto_comillas_posix($api_path);
        $zip = $this->artefacto_comillas_posix($zip_name);

        if (! $es_actualizacion) {
            return $cd . ' && unzip -o ' . $zip . ' && rm -f ' . $zip . ' 2>&1';
        }

        return $cd . ' && { unzip -o ' . $zip . " -x 'public/*' 'storage/*' '.env'; RC=\$?; "
            . 'if [ "$RC" -ne 0 ] && [ "$RC" -ne 11 ]; then echo "UNZIP_FALLO $RC"; exit "$RC"; fi; }'
            . ' && rm -f ' . $zip . ' 2>&1';
    }

    /* ═════════════════════════════════════════════════════════════════════════════════════════
     * Helpers
     * ═════════════════════════════════════════════════════════════════════════════════════════ */

    /**
     * Carpeta de trabajo de branding en el VPS de builds (`services.deploy_tienda.branding_work_path`).
     *
     * 🔴 No puede quedar vacía ni ser la raíz: adentro se hace `rm -rf runs/<uuid>`.
     *
     * @return string  Sin barra final.
     *
     * @throws \RuntimeException  Si la config no es una ruta absoluta usable.
     */
    protected function tienda_branding_work_path(): string
    {
        $ruta = rtrim(trim((string) config('services.deploy_tienda.branding_work_path', '/home/builds/tienda-branding')), '/');

        if ($ruta === '' || strpos($ruta, '/') !== 0 || $ruta === '/home' || $ruta === '/home/builds') {
            throw new \RuntimeException(
                'services.deploy_tienda.branding_work_path (DEPLOY_TIENDA_BRANDING_WORK_PATH) tiene que ser una '
                . 'carpeta absoluta propia en el VPS de builds (por defecto /home/builds/tienda-branding); vino «'
                . $ruta . '».'
            );
        }

        return $ruta;
    }

    /**
     * Uuid de la corrida, exigido no vacío: arma nombres de archivos y la carpeta que se borra con
     * `rm -rf` en el VPS.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    protected function tienda_uuid_de_la_corrida(): string
    {
        $uuid = trim((string) $this->installation->uuid);
        if ($uuid === '' || preg_match('/^[A-Za-z0-9-]+$/', $uuid) !== 1) {
            throw new \RuntimeException('La corrida no tiene un uuid válido: no se arman rutas de trabajo con él.');
        }

        return $uuid;
    }

    /**
     * Borra una carpeta local entera (la de branding de la corrida). Best-effort.
     *
     * @param  string  $dir
     * @return void
     */
    protected function tienda_borrar_carpeta_local(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterador as $archivo) {
            if ($archivo->isDir()) {
                @rmdir($archivo->getPathname());
            } else {
                @unlink($archivo->getPathname());
            }
        }
        @rmdir($dir);
    }
}
