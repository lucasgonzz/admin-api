<?php

namespace App\Services\Concerns;

use App\Services\ReleaseArtifactService;
use App\Services\SpaRuntimeConfig;

/**
 * Los artefactos que GitHub Actions publica en cada release, para los pipelines que INSTALAN
 * (misión `instalar-sin-el-vps`, 10/9/2026).
 *
 * La misión hermana `actualizar-sin-el-vps` (9/9/2026) ya sacó del VPS de builds las
 * actualizaciones de clientes: `DeploymentService` baja `empresa-spa-v{v}-dist.zip` y
 * `empresa-api-v{v}.zip` del release y no compila nada allá. Lo que seguía compilando —`npm ci` +
 * `npm run build`, un núcleo al 100 % durante 5-10 minutos sobre la máquina donde viven 12
 * clientes— eran las instalaciones nuevas y las demos: `InstallationService`,
 * `DemoInstallationService` y `DemoUpdateService`. Esto es lo que las tres comparten.
 *
 * 🔴 Va en un trait y no copiado tres veces por dos razones, y las dos son duras: el docblock de
 * `InstallationService` declara un techo de 2350 líneas (*"mover, no agregar"*), y las tres clases
 * necesitan exactamente lo mismo. Los nombres van prefijados con `artefacto_` porque las tres ya
 * tienen métodos propios que harían lo mismo con otro nombre (`assert_local_zip_file`,
 * `escape_remote_arg`, `posix_single_quote` en `DeploymentService`).
 *
 * ⚠️ **Lo que la clase que lo usa tiene que traer puesto**, porque el trait lo llama derecho (mismo
 * criterio que `InstallationProvisioningSteps`, que usa `$this->installation` sin declararlo):
 *
 *   - `artefacto_log(string $step, string $linea, string $nivel = 'info'): void` — declarado
 *     abstracto acá abajo: es lo único que las tres hacen distinto (`log()` con nivel en las
 *     instalaciones, `append_log()` con el paso adentro del texto en `DemoUpdateService`).
 *   - `assert_local_zip_file(string $path, int $bytes_esperados, string $step): void` — las tres lo
 *     tienen idéntico y ya loguea lo suyo; no se duplica acá.
 *   - `exec_hosting_ssh(string $step, string $comando, bool $must_succeed = true, bool $long = false): string`
 *     — las tres lo tienen con la misma firma. Lo usa `artefacto_escribir_config_js()`.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains/starts/ends`, argumentos nombrados, union types,
 * promoción en constructor, `readonly`, `enum`, atributos, `mixed` ni `never`. Y los traits de 7.4
 * no admiten constantes (eso llegó en 8.2), así que los nombres de los repos son literales.
 */
trait ArtefactosDeRelease
{
    /**
     * Cliente de artefactos de esta corrida, instanciado perezosamente.
     *
     * @var ReleaseArtifactService|null
     */
    private $artefactos_release_service = null;

    /**
     * Escribe una línea en el log del pipeline que use este trait.
     *
     * @param  string  $step   Etapa (`compile_spa`, `upload_api`, …).
     * @param  string  $linea  Texto.
     * @param  string  $nivel  `info` | `success` | `warning` | `error`.
     * @return void
     */
    abstract protected function artefacto_log(string $step, string $linea, string $nivel = 'info'): void;

    /**
     * Cliente de artefactos de release, creado la primera vez que se pide.
     *
     * @return ReleaseArtifactService
     */
    private function artefactos_release(): ReleaseArtifactService
    {
        if ($this->artefactos_release_service === null) {
            $this->artefactos_release_service = new ReleaseArtifactService();
        }

        return $this->artefactos_release_service;
    }

    /**
     * Baja el bundle compilado del SPA (`empresa-spa-{tag}-dist.zip`) a un archivo local.
     *
     * El zip trae el contenido de `dist/` EN LA RAÍZ, así que se verifica `index.html` ahí: un
     * release mal empaquetado (con `dist/index.html` adentro) pasaría la validación de ZipArchive y
     * el deploy vaciaría el directorio del SPA para descomprimir una carpeta que el servidor web no
     * sirve.
     *
     * @param  string  $tag      Tag del release (`v4.0.23`).
     * @param  string  $destino  Ruta local del zip.
     * @param  string  $step     Etapa, para el log.
     * @return bool  `false` si el release no existe o no trae ese asset (versión anterior a 4.0.23).
     */
    private function artefacto_bajar_dist_spa(string $tag, string $destino, string $step): bool
    {
        return $this->artefacto_bajar_asset(
            'empresa-spa',
            'empresa-spa-' . $tag . '-dist.zip',
            $tag,
            $destino,
            'index.html',
            $step
        );
    }

    /**
     * Baja el paquete de la API (`empresa-api-{tag}.zip`) a un archivo local.
     *
     * 🔴 Ese asset trae `vendor/` adentro y NO trae `public/`, `storage/`, `.git`, `.env` ni
     * `tests/`. Que no traiga `public/` es justo lo que en un upgrade está bien (esos archivos son
     * del cliente) y en una instalación de cero deja el sistema respondiendo 404 en todo: por eso
     * quien instala tiene que bajar además `artefacto_bajar_public_del_tag()`.
     *
     * @param  string  $tag      Tag del release.
     * @param  string  $destino  Ruta local del zip.
     * @param  string  $step     Etapa, para el log.
     * @return bool  `false` si el release no existe o no trae ese asset.
     */
    private function artefacto_bajar_api(string $tag, string $destino, string $step): bool
    {
        return $this->artefacto_bajar_asset(
            'empresa-api',
            'empresa-api-' . $tag . '.zip',
            $tag,
            $destino,
            'artisan',
            $step
        );
    }

    /**
     * Deja en `$destino` un zip con SOLO el `public/` del tag, con `public/` en la raíz.
     *
     * 🔴 Sale del **zipball** del tag y no de un asset del release, y la decisión está medida: el
     * asset `-public.zip` no existe para ninguna versión publicada, así que agregarlo obligaría a
     * tocar `empresa-api`, publicar una versión nueva y arrastrar a producción todo lo que hay en
     * `develop` sólo para poder instalar. El zipball, en cambio, GitHub lo arma para CUALQUIER tag,
     * incluidos los viejos que puede pedir una demo. Medido el 10/9/2026 con `empresa-api` v4.0.23:
     * el zipball pesa 9,25 MB y baja en 2 s; `public/` son 24 entradas y 362 KB.
     *
     * El zipball trae todo colgando de un directorio raíz con el sha (`lucasgonzz-empresa-api-bc47e4c/`),
     * que se saca al re-empaquetar: lo que queda tiene `public/index.php` en la raíz del zip nuevo,
     * que es lo que el `unzip` del hosting espera.
     *
     * 🔴 Se excluye `public/storage`: es un symlink a `storage/app/public`, y empaquetado llega roto
     * (apuntando a una ruta que no existe del otro lado). El bueno lo crea la etapa de finalize, con
     * `artisan storage:link` en la instalación real y con un `ln -s` en el esqueleto.
     *
     * @param  string  $tag      Tag del release.
     * @param  string  $destino  Ruta local del zip a crear.
     * @param  string  $step     Etapa, para el log.
     * @return bool  `false` si GitHub no tiene ese tag (o el token perdió acceso al repo).
     */
    private function artefacto_bajar_public_del_tag(string $tag, string $destino, string $step): bool
    {
        $this->artefacto_preparar_destino($destino);

        /* El zipball es 25 veces más grande que lo que se le saca, así que es temporal y se borra
           sí o sí: el que queda en storage/app/deployments es el zip chico de public/. */
        $zipball = $destino . '.zipball';
        $bytes   = $this->artefactos_release()->download_source_zip('empresa-api', $tag, $zipball);

        if ($bytes === null) {
            return false;
        }

        try {
            $this->artefacto_log(
                $step,
                "Zipball de empresa-api {$tag} bajado de GitHub ({$bytes} bytes): no se hace checkout en el VPS de builds"
            );

            $copiadas = $this->artefacto_reempaquetar_public($zipball, $destino);
        } finally {
            if (is_file($zipball)) {
                @unlink($zipball);
            }
        }

        $this->assert_local_zip_file($destino, 0, $step);
        $this->artefacto_assert_zip_trae($destino, 'public/index.php', $step);
        $this->artefacto_log($step, "public/ re-empaquetado desde el zipball ({$copiadas} archivos)", 'success');

        return true;
    }

    /**
     * Corta la etapa cuando falta el artefacto, en vez de mandar el trabajo al VPS de builds.
     *
     * 🔴 Decisión de Lucas del 9/9/2026: *"no quiero que se vuelva a usar más el VPS para actualizar
     * clientes"*, y acá vale igual para instalar. Antes de esa decisión un asset ausente hacía caer
     * a la vía vieja con una línea de log, y ese camino no duele en el momento —la corrida termina
     * bien— pero clava un núcleo de la máquina donde viven 12 clientes durante 5-10 minutos.
     *
     * Lo que un 404 de GitHub esconde es genuinamente ambiguo: un release sin el asset, un Action
     * que falló, la versión escrita distinto en el commit (`[release:X.Y.Z]`) que en el admin, o un
     * token que perdió acceso al repo. Ninguno de los cuatro se arregla compilando en producción, y
     * el mensaje nombra el archivo, el tag y el repo, que es lo que hace falta para distinguirlos.
     *
     * @param  string  $step    Etapa que corta, para el log.
     * @param  string  $nombre  Nombre exacto del artefacto que se buscó.
     * @param  string  $tag     Tag donde se lo buscó.
     * @param  string  $repo    Repo de GitHub donde se lo buscó.
     * @return void
     *
     * @throws \RuntimeException  Siempre, salvo que la salida de emergencia esté prendida.
     */
    private function artefacto_frenar_si_no_hay(string $step, string $nombre, string $tag, string $repo): void
    {
        if ($this->artefacto_build_en_vps_permitido()) {
            return;
        }

        $mensaje = $this->artefacto_mensaje_sin_artefacto($nombre, $tag, $repo);

        $this->artefacto_log($step, $mensaje, 'error');

        throw new \RuntimeException($mensaje);
    }

    /**
     * Baja el `public/` del tag y lo deja descomprimido en el directorio de la API destino.
     *
     * Es lo mismo para las tres instalaciones —la real de un cliente, el esqueleto de su subdominio
     * secundario y la de una demo—, así que vive acá y no copiado tres veces.
     *
     * 🔴 `$pisar` es la única diferencia entre ellas, y no es cosmética. Una instalación de cero
     * descomprime con `unzip -o` sobre un directorio virgen. El ESQUELETO va con `-n`: rellena
     * huecos y nunca pisa un archivo que el cliente ya tiene, porque el subdominio secundario puede
     * estar sirviendo producción hoy mismo —el blue/green alterna cuál de los dos es la API activa—
     * y con `-o` un tag más viejo que el instalado le bajaría de versión el `index.php` a un sistema
     * andando.
     *
     * @param  string  $tag         Tag del release.
     * @param  string  $api_path    Directorio de la API en el servidor destino.
     * @param  string  $zip_name    Nombre del zip, único por corrida (lleva el uuid adentro).
     * @param  string  $credencial  Tipo de credencial SFTP (`shared_hosting` | `vps`).
     * @param  string  $step        Etapa, para el log.
     * @param  bool    $pisar       `true` para `unzip -o`, `false` para `unzip -n`.
     * @return void
     *
     * @throws \RuntimeException  Si GitHub no tiene el zipball de ese tag.
     */
    private function artefacto_desplegar_public(
        string $tag,
        string $api_path,
        string $zip_name,
        string $credencial,
        string $step,
        bool $pisar
    ): void {
        $local_zip = storage_path('app/deployments/' . $zip_name);

        if (! $this->artefacto_bajar_public_del_tag($tag, $local_zip, $step)) {
            $this->artefacto_frenar_sin_public($step, $tag);
        }

        $sftp = $this->open_sftp_session($credencial);
        $this->sftp_upload_file($sftp, $local_zip, $api_path . '/' . $zip_name, $step);
        $this->artefacto_log($step, 'ZIP de public/ subido al servidor destino');

        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            $step,
            'cd ' . $this->artefacto_comillas_posix($api_path)
            . ' && unzip ' . ($pisar ? '-o' : '-n') . ' ' . $this->artefacto_comillas_posix($zip_name)
            . ' && rm -f ' . $this->artefacto_comillas_posix($zip_name) . ' 2>&1',
            true,
            true
        );
        $this->artefacto_log($step, 'public/ descomprimido en el servidor destino', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }
    }

    /**
     * Sube el zip de la API al servidor destino, lo descomprime encima y corre `composer install`.
     *
     * La segunda mitad de la etapa `upload_api`, común a las dos vías (artefacto del release y VPS de
     * builds) y a los tres pipelines. Con el `vendor/` del artefacto adentro del zip ese
     * `composer install` es un no-op rápido; sin él —la vía vieja lo excluye— instala como siempre.
     *
     * El comando de composer viaja armado desde afuera porque no es el mismo en todos lados: una
     * instalación de cero va SIN scripts (el `.env` todavía no existe y el `post-autoload-dump` de
     * Laravel bootea el framework, que sin entorno revienta) y una actualización de demo puede
     * correrlos.
     *
     * @param  string  $local_zip          Zip local ya verificado.
     * @param  string  $api_path           Directorio de la API en el servidor destino.
     * @param  string  $zip_name           Nombre con el que viaja el zip.
     * @param  string  $credencial         Tipo de credencial SFTP (`shared_hosting` | `vps`).
     * @param  string  $comando_composer   Comando completo de composer para el servidor destino.
     * @param  string  $step               Etapa, para el log.
     * @return void
     */
    private function artefacto_desplegar_zip_api(
        string $local_zip,
        string $api_path,
        string $zip_name,
        string $credencial,
        string $comando_composer,
        string $step
    ): void {
        $sftp = $this->open_sftp_session($credencial);
        $this->sftp_upload_file($sftp, $local_zip, $api_path . '/' . $zip_name, $step);
        $this->artefacto_log($step, 'ZIP de la API subido al servidor destino');

        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            $step,
            'cd ' . $this->artefacto_comillas_posix($api_path)
            . ' && unzip -o ' . $this->artefacto_comillas_posix($zip_name)
            . ' && rm -f ' . $this->artefacto_comillas_posix($zip_name) . ' 2>&1',
            true,
            true
        );
        $this->artefacto_log($step, 'API descomprimida en el servidor destino');

        $this->artefacto_log($step, 'Corriendo composer install en el servidor destino...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh($step, $comando_composer, true, true);
        $this->artefacto_log($step, 'API lista en el servidor destino', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }
    }

    /**
     * Corta cuando el tag no tiene zipball: acá no hay vía vieja a la que caer.
     *
     * 🔴 A diferencia de un asset del release, el zipball GitHub lo arma solo para CUALQUIER tag que
     * exista. Que no esté sólo puede querer decir dos cosas —el tag no existe, o el token del admin
     * perdió acceso al repo— y ninguna de las dos se arregla haciendo checkout en el VPS de builds.
     * Por eso esto no tiene salida de emergencia: falla siempre, con la bandera prendida o apagada.
     *
     * @param  string  $step  Etapa que corta, para el log.
     * @param  string  $tag   Tag donde se buscó el zipball.
     * @return void
     *
     * @throws \RuntimeException  Siempre.
     */
    private function artefacto_frenar_sin_public(string $step, string $tag): void
    {
        $mensaje = "GitHub no tiene el zipball del tag {$tag} de empresa-api, asi que no hay de donde "
            . 'sacar public/ — y una instalacion sin public/index.php responde 404 en todo. Revisa que '
            . 'el tag exista y que el token del admin tenga acceso al repo, y volve a correr la corrida.';

        $this->artefacto_log($step, $mensaje, 'error');

        throw new \RuntimeException($mensaje);
    }

    /**
     * Escribe `config.js` en el directorio del SPA del servidor destino.
     *
     * Es la mitad del admin de un contrato con `empresa-spa`: `public/index.html` carga
     * `<BASE_URL>config.js` síncrono ANTES de cualquier otro script, y `src/runtime_config.js` lee
     * `window.__CC_CONFIG__[clave]` cayendo a `process.env[clave]` si la global no está. El formato
     * exacto lo fija `SpaRuntimeConfig`.
     *
     * 🔴 Va SIEMPRE, venga el bundle del release o del VPS de builds: un bundle viejo (con las
     * `VUE_APP_*` cocinadas adentro) ignora el archivo, y uno de GitHub Actions lo necesita para
     * saber a qué API pegarle. Escribirlo de más no molesta a nadie; no escribirlo deja el frente
     * arriba y mudo.
     *
     * 🔴 Y va DESPUÉS del deploy del SPA, que vacía el directorio con un `find . -mindepth 1 -delete`:
     * escrito antes, se lo lleva puesto.
     *
     * @param  string  $spa_dir  Directorio del SPA en el servidor (sin barra final).
     * @param  array<string, mixed>  $vars  Variables `VUE_APP_*` del frente.
     * @param  string  $step  Etapa, para el log.
     * @return void
     */
    private function artefacto_escribir_config_js(string $spa_dir, array $vars, string $step): void
    {
        $config_js = SpaRuntimeConfig::render($vars);

        $this->exec_hosting_ssh($step, $this->artefacto_comando_config_js($spa_dir, $config_js));

        $api_url = isset($vars['VUE_APP_API_URL']) ? (string) $vars['VUE_APP_API_URL'] : '';
        $this->artefacto_log($step, "config.js escrito en {$spa_dir} — API: {$api_url}", 'success');
    }

    /**
     * Comando remoto que escribe `config.js` en el directorio del SPA.
     *
     * 🔴 Las comillas simples van escapadas a mano (`'\''`) tanto en el contenido como en la ruta, y
     * NO con `escapeshellarg()`: en Windows esa función envuelve con comillas dobles, y del otro
     * lado del SSH hay un shell POSIX. Así el comando es el mismo se arme donde se arme.
     *
     * @param  string  $spa_dir    Directorio del SPA (sin barra final).
     * @param  string  $config_js  Contenido completo del archivo.
     * @return string
     */
    private function artefacto_comando_config_js(string $spa_dir, string $config_js): string
    {
        $destino = rtrim($spa_dir, '/') . '/config.js';

        return "printf '%s' " . $this->artefacto_comillas_posix($config_js)
            . ' > ' . $this->artefacto_comillas_posix($destino);
    }

    /**
     * Si esta corrida tiene permitido caer al VPS de builds.
     *
     * 🔴 El default es `false` y así tiene que quedarse. Vive en un método propio para que un test
     * pueda afirmar exactamente eso: el día que alguien invierta el default, el test se pone rojo
     * antes de que una instalación se lleve puesta la CPU de los 12 clientes del VPS.
     *
     * @return bool
     */
    private function artefacto_build_en_vps_permitido(): bool
    {
        return (bool) config('services.deploy.permitir_build_en_vps');
    }

    /**
     * El mensaje con el que se corta cuando falta el artefacto.
     *
     * Nombra el archivo exacto, el tag y el repo: son los tres datos con los que se distingue un
     * Action que falló de una versión escrita distinta en el commit y en el admin, y sin ellos el
     * error obliga a ir a buscarlos a mano.
     *
     * @param  string  $nombre  Nombre exacto del artefacto que se buscó.
     * @param  string  $tag     Tag donde se lo buscó.
     * @param  string  $repo    Repo de GitHub donde se lo buscó.
     * @return string
     */
    private function artefacto_mensaje_sin_artefacto(string $nombre, string $tag, string $repo): string
    {
        return "No esta el artefacto {$nombre} en el tag {$tag} de {$repo}. No se compila ni se "
            . 'empaqueta en el VPS de builds: revisa que el release exista, que el workflow haya '
            . 'terminado verde y que la version del tag sea la misma que la del admin. Cuando este '
            . 'publicado, volve a correr la corrida. Para forzar el build en el VPS de builds, '
            . 'DEPLOY_PERMITIR_BUILD_EN_VPS=true en el .env del admin.';
    }

    /**
     * Busca un asset en el release del tag, lo baja y lo verifica.
     *
     * @param  string  $repo             Repo de GitHub (sin owner).
     * @param  string  $nombre           Nombre exacto del asset.
     * @param  string  $tag              Tag del release.
     * @param  string  $destino          Ruta local del zip.
     * @param  string  $archivo_en_raiz  Archivo que el zip tiene que traer en la raíz.
     * @param  string  $step             Etapa, para el log.
     * @return bool  `false` si no hay release para ese tag o el release no trae ese asset.
     */
    private function artefacto_bajar_asset(
        string $repo,
        string $nombre,
        string $tag,
        string $destino,
        string $archivo_en_raiz,
        string $step
    ): bool {
        $asset = $this->artefactos_release()->find_asset($repo, $tag, $nombre);
        if ($asset === null) {
            return false;
        }

        $this->artefacto_preparar_destino($destino);

        $bytes = $this->artefactos_release()->download_asset($asset, $destino);
        $this->assert_local_zip_file($destino, $bytes, $step);
        $this->artefacto_assert_zip_trae($destino, $archivo_en_raiz, $step);
        $this->artefacto_log(
            $step,
            "Artefacto {$nombre} bajado de GitHub ({$bytes} bytes): no se toca el VPS de builds",
            'success'
        );

        return true;
    }

    /**
     * Arma un zip nuevo con las entradas de `public/` del zipball, sacándole el directorio raíz.
     *
     * @param  string  $zipball  Zipball del tag ya bajado.
     * @param  string  $destino  Ruta del zip a crear.
     * @return int  Cuántos archivos se copiaron.
     *
     * @throws \RuntimeException  Si falta ZipArchive, si alguno de los dos zips no abre, o si el tag
     *                            no trajo un solo archivo bajo `public/`.
     */
    private function artefacto_reempaquetar_public(string $zipball, string $destino): int
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'Falta la extensión zip de PHP en el admin: sin ZipArchive no se puede sacar public/ '
                . 'del zipball del tag.'
            );
        }

        $origen = new \ZipArchive();
        if ($origen->open($zipball) !== true) {
            throw new \RuntimeException("ZipArchive no pudo abrir el zipball {$zipball}.");
        }

        $salida = new \ZipArchive();
        if ($salida->open($destino, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $origen->close();

            throw new \RuntimeException("ZipArchive no pudo crear el zip de public/ en {$destino}.");
        }

        $copiadas = 0;
        for ($i = 0; $i < $origen->numFiles; $i++) {
            $ruta = $this->artefacto_ruta_public((string) $origen->getNameIndex($i));
            if ($ruta === null) {
                continue;
            }

            $contenido = $origen->getFromIndex($i);
            if ($contenido === false) {
                $origen->close();
                $salida->close();

                throw new \RuntimeException(
                    'No se pudo leer ' . $ruta . ' del zipball: el archivo bajó incompleto o corrupto.'
                );
            }

            $salida->addFromString($ruta, $contenido);
            $copiadas++;
        }

        $origen->close();
        if (! $salida->close()) {
            throw new \RuntimeException("No se pudo escribir el zip de public/ en {$destino}.");
        }

        if ($copiadas === 0) {
            @unlink($destino);

            throw new \RuntimeException(
                'El zipball del tag no trajo un solo archivo bajo public/: el paquete está mal armado '
                . 'y una instalación sin public/ responde 404 en todo.'
            );
        }

        return $copiadas;
    }

    /**
     * La ruta con la que una entrada del zipball entra al zip de `public/`, o `null` si se descarta.
     *
     * El zipball cuelga todo de un directorio raíz con el sha del commit
     * (`lucasgonzz-empresa-api-bc47e4c/public/index.php`): se le saca ese primer segmento y queda
     * `public/index.php`. Se descartan las entradas de directorio (el `unzip` del hosting los crea
     * solos) y todo lo que no sea `public/`, más el symlink `public/storage`.
     *
     * @param  string  $nombre  Nombre de la entrada tal como viene en el zipball.
     * @return string|null
     */
    private function artefacto_ruta_public(string $nombre): ?string
    {
        $barra = strpos($nombre, '/');
        if ($barra === false) {
            return null;
        }

        $ruta = substr($nombre, $barra + 1);
        if ($ruta === '' || substr($ruta, -1) === '/') {
            return null;
        }

        if (strpos($ruta, 'public/') !== 0) {
            return null;
        }

        if ($ruta === 'public/storage' || strpos($ruta, 'public/storage/') === 0) {
            return null;
        }

        return $ruta;
    }

    /**
     * Comprueba que un zip local traiga un archivo dado EN LA RAÍZ (no adentro de otra carpeta).
     *
     * @param  string  $local_zip  Zip ya verificado como zip.
     * @param  string  $archivo    Ruta esperada dentro del zip (`index.html`, `public/index.php`).
     * @param  string  $step       Etapa, para el log.
     * @return void
     *
     * @throws \RuntimeException  Si el zip no trae ese archivo.
     */
    private function artefacto_assert_zip_trae(string $local_zip, string $archivo, string $step): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->artefacto_log(
                $step,
                "ZipArchive no está disponible: no se pudo verificar que el artefacto traiga {$archivo}",
                'warning'
            );

            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($local_zip) !== true) {
            throw new \RuntimeException("ZipArchive no pudo abrir el artefacto {$local_zip}.");
        }

        $encontrado = $zip->locateName($archivo) !== false || $zip->locateName('./' . $archivo) !== false;
        $zip->close();

        if (! $encontrado) {
            throw new \RuntimeException(
                'El artefacto ' . basename($local_zip) . " no trae {$archivo} donde va: el paquete está "
                . 'mal armado y no se despliega.'
            );
        }

        $this->artefacto_log($step, "Verificado {$archivo} en el artefacto", 'success');
    }

    /**
     * Deja el directorio del destino creado y sin un archivo viejo de una corrida anterior.
     *
     * 🔴 El borrado previo no es prolijidad: las etapas de subida deciden la vía por la existencia
     * del zip local, y un zip de un intento anterior no puede pasar por recién bajado.
     *
     * @param  string  $destino  Ruta local del zip.
     * @return void
     *
     * @throws \RuntimeException  Si el directorio no se puede crear.
     */
    private function artefacto_preparar_destino(string $destino): void
    {
        $directorio = dirname($destino);
        if (! is_dir($directorio) && ! mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            throw new \RuntimeException("No se pudo crear el directorio local {$directorio}.");
        }

        if (is_file($destino)) {
            @unlink($destino);
        }
    }

    /**
     * Entrecomilla un texto para un shell POSIX: comillas simples, con las del texto como `'\''`.
     *
     * @param  string  $texto
     * @return string
     */
    private function artefacto_comillas_posix(string $texto): string
    {
        return "'" . str_replace("'", "'\\''", $texto) . "'";
    }
}
