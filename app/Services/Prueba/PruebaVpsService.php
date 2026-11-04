<?php

namespace App\Services\Prueba;

use App\Models\ClientSshCredential;
use App\Services\EnvSshService;
use App\Services\RemoteCommandRunner;
use Illuminate\Support\Facades\Log;

/**
 * TODO lo que la prueba gratuita le dice al VPS pasa por acá (decisión D8 del plan de
 * `prueba-alta-instancia`, 3/11/2026): los scripts versionados de
 * `claude-comerciocity/herramientas/prueba-gratuita/vps/` (copiados a `/root/prueba-gratuita/` con
 * `instalar-scripts-vps.ps1`) y la escritura de archivos (`.env`, `config.js`, `index.html`).
 *
 * Reglas:
 *  - Los argumentos se VALIDAN antes de armar el comando (`PruebaNombres`: slug `^[a-z0-9][a-z0-9-]{0,19}$`,
 *    nombre `^prueba-[0-9]{2}$`, versiones `^[0-9][0-9A-Za-z.\-]{0,29}$`, user_id entero) y se escapan con
 *    `RemoteCommandRunner::escapar_argumento()`: nada de un request llega crudo a un `bash` que corre como root.
 *  - Cada script imprime UN JSON en su última línea y sale con código distinto de 0 ante error. Acá se
 *    lee esa línea, se exige `ok: true` y se devuelve el array.
 *  - Lo que vuelve con una contraseña (`pool-crear.sh`) no se loguea: el runner NO tiene logger enchufado
 *    (así nada de la salida cruda va a `deployment_logs` ni al log de Laravel), y lo que esta clase loguea
 *    son resúmenes sin secretos.
 *  - Es la costura que falsean los tests: `Tests\Fakes\PruebaVpsServiceFake` sobreescribe `ejecutar()` y
 *    `escribir_archivo()`, lo único que abre SSH.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor.
 */
class PruebaVpsService
{
    /** Carpeta del VPS donde `instalar-scripts-vps.ps1` deja los scripts. */
    const CARPETA_SCRIPTS = '/root/prueba-gratuita';

    /** Versión de empresa o de tienda: `4.3.27`, `1.2.0-beta`. */
    const REGEX_VERSION = '/^[0-9][0-9A-Za-z.\-]{0,29}$/';

    /** Modo con el que quedan los archivos que escribe el admin (el `.env` no es legible por otros). */
    const MODO_ARCHIVO = '640';

    /**
     * Tiempo máximo de un script del pool, en segundos (65 min).
     *
     * 🔴 phpseclib corta cada `exec()` a los 10 s de tiempo TOTAL si nadie le sube el tope, y devuelve la salida a
     * medias (medido en producción el 4/11/2026: un script de 25 s volvió a los 10 s). Los scripts tardan de
     * segundos (`prueba-estado`) a ~60 min (`pool-crear` con SSL de cuatro hosts, `pool-plantilla` con composer), así
     * que el tope se pone por encima del job más largo —`CrearInstanciaDePoolJob`, 3600—: el que manda es el
     * `$timeout` del job, y este número solo evita que un comando legítimo se corte antes.
     */
    const TIMEOUT_DE_SCRIPT_SEGUNDOS = 3900;

    /**
     * Runner SSH, abierto al primer comando.
     *
     * @var RemoteCommandRunner|null
     */
    private $runner = null;

    /* ============================================================================================
     | Scripts del pool
     |============================================================================================ */

    /**
     * `pool-plantilla.sh <version_empresa> <version_tienda>`: deja en `/root/plantillas/` el código de
     * las dos versiones publicadas, con `vendor/` instalado.
     */
    public function pool_plantilla(string $version_empresa, string $version_tienda): array
    {
        return $this->ejecutar_script('pool-plantilla.sh', [
            $this->assert_version($version_empresa),
            $this->assert_version($version_tienda),
        ]);
    }

    /**
     * `pool-crear.sh <prueba-NN> <version_empresa> <version_tienda> <user_id>`: los 4 sitios, la base y
     * el supervisor. Devuelve `db: {name, user, pass}` (la contraseña viaja UNA vez y no se loguea).
     */
    public function pool_crear(string $nombre, string $version_empresa, string $version_tienda, int $user_id): array
    {
        return $this->ejecutar_script('pool-crear.sh', [
            PruebaNombres::assert_nombre($nombre),
            $this->assert_version($version_empresa),
            $this->assert_version($version_tienda),
            (string) $this->assert_user_id($user_id),
        ]);
    }

    /** `pool-configurar.sh <prueba-NN>`: artisan, tokens de la tienda, reinicios y SSL de los hosts del pool. */
    public function pool_configurar(string $nombre): array
    {
        return $this->ejecutar_script('pool-configurar.sh', [PruebaNombres::assert_nombre($nombre)]);
    }

    /* ============================================================================================
     | Scripts de una prueba
     |============================================================================================ */

    /**
     * `prueba-asignar.sh <prueba-NN> <slug> <user_id> [--sin-ssl]`: alias en los vhosts, `.env`,
     * `config.js`, reinicios. Con `--sin-ssl` no pide los certificados (los pide `prueba_ssl()` después
     * del demo setup, cuando el DNS ya propagó).
     */
    public function prueba_asignar(string $nombre, string $slug, int $user_id, bool $con_ssl = false): array
    {
        $args = [
            PruebaNombres::assert_nombre($nombre),
            PruebaNombres::assert_slug($slug),
            (string) $this->assert_user_id($user_id),
        ];

        if (! $con_ssl) {
            $args[] = '--sin-ssl';
        }

        return $this->ejecutar_script('prueba-asignar.sh', $args);
    }

    /** `prueba-ssl.sh <prueba-NN> <slug>`: los 4 certificados del alias (espera el DNS). */
    public function prueba_ssl(string $nombre, string $slug): array
    {
        return $this->ejecutar_script('prueba-ssl.sh', [
            PruebaNombres::assert_nombre($nombre),
            PruebaNombres::assert_slug($slug),
        ]);
    }

    public function prueba_dormir(string $nombre): array
    {
        return $this->ejecutar_script('prueba-dormir.sh', [PruebaNombres::assert_nombre($nombre)]);
    }

    public function prueba_despertar(string $nombre): array
    {
        return $this->ejecutar_script('prueba-despertar.sh', [PruebaNombres::assert_nombre($nombre)]);
    }

    /** `prueba-borrar.sh <prueba-NN> <slug>`: respaldo y baja de los 4 sitios, la base y el supervisor. */
    public function prueba_borrar(string $nombre, string $slug): array
    {
        return $this->ejecutar_script('prueba-borrar.sh', [
            PruebaNombres::assert_nombre($nombre),
            PruebaNombres::assert_slug($slug),
        ]);
    }

    public function prueba_estado(string $nombre): array
    {
        return $this->ejecutar_script('prueba-estado.sh', [PruebaNombres::assert_nombre($nombre)]);
    }

    /* ============================================================================================
     | Archivos
     |============================================================================================ */

    /**
     * Escribe un archivo ENTERO (un `.env`, un `config.js`) y lo deja del usuario del sitio.
     *
     * Va en base64 adentro del comando: es el único patrón que no depende de escapar el contenido, y
     * el blob entero se pasa como secreto al runner para que no quede en ningún log (adentro viajan
     * contraseñas).
     *
     * @param array<int, string> $secretos Valores que, además del blob, no pueden aparecer en un log.
     */
    public function escribir_archivo(string $ruta, string $contenido, string $dueno, array $secretos = []): void
    {
        $this->assert_ruta($ruta);
        $this->assert_usuario($dueno);

        $blob    = base64_encode($contenido);
        $comando = 'umask 027 && printf %s ' . RemoteCommandRunner::escapar_argumento($blob)
            . ' | base64 -d > ' . RemoteCommandRunner::escapar_argumento($ruta)
            . ' && chown ' . RemoteCommandRunner::escapar_argumento($dueno . ':' . $dueno) . ' ' . RemoteCommandRunner::escapar_argumento($ruta)
            . ' && chmod ' . self::MODO_ARCHIVO . ' ' . RemoteCommandRunner::escapar_argumento($ruta)
            . ' && echo ARCHIVO_OK';

        $salida = $this->ejecutar($comando, array_merge([$blob], $secretos));

        if (strpos($salida, 'ARCHIVO_OK') === false) {
            throw new \RuntimeException('No se pudo escribir ' . $ruta . ' en el VPS: el servidor no confirmó la escritura.');
        }
    }

    /**
     * Escribe o reemplaza variables sueltas de un `.env` que YA existe (las claves de admin-sync del
     * cliente, por ejemplo), con la misma regla de formato que `EnvSshService` y en UN solo comando.
     *
     * @param array<string, string> $vars     KEY => valor, sin comillas.
     * @param array<int, string>    $secretos Valores que no pueden aparecer en un log.
     */
    public function escribir_variables_env(string $ruta_env, array $vars, array $secretos = []): void
    {
        $this->assert_ruta($ruta_env);

        if ($vars === []) {
            return;
        }

        $formato = app(EnvSshService::class);
        $archivo = RemoteCommandRunner::escapar_argumento($ruta_env);
        $partes  = ['[ -f ' . $archivo . ' ] || { echo ENV_NO_EXISTE; exit 3; }'];

        foreach ($vars as $clave => $valor) {
            $clave = (string) $clave;
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $clave) !== 1) {
                throw new \InvalidArgumentException('Clave de .env inválida: "' . $clave . '".');
            }
            $valor = (string) $valor;
            if (strpos($valor, "\n") !== false || strpos($valor, "\r") !== false) {
                throw new \InvalidArgumentException('El valor de ' . $clave . ' tiene un salto de línea.');
            }

            $linea      = $clave . '=' . $formato->format_env_value($valor);
            $reemplazo  = str_replace(['\\', '|', '&'], ['\\\\', '\\|', '\\&'], $linea);
            $patron     = RemoteCommandRunner::escapar_argumento('^' . $clave . '=');
            $sed        = RemoteCommandRunner::escapar_argumento('s|^' . $clave . '=.*|' . $reemplazo . '|');
            $partes[]   = 'if grep -q ' . $patron . ' ' . $archivo . '; then sed -i ' . $sed . ' ' . $archivo
                . '; else { [ -z "$(tail -c1 ' . $archivo . ')" ] || printf ' . RemoteCommandRunner::escapar_argumento('\n') . ' >> ' . $archivo
                . '; }; printf ' . RemoteCommandRunner::escapar_argumento('%s\n') . ' ' . RemoteCommandRunner::escapar_argumento($linea) . ' >> ' . $archivo . '; fi';
        }

        $partes[] = 'echo ENV_OK';

        $salida = $this->ejecutar(implode(' && ', $partes), $secretos);

        if (strpos($salida, 'ENV_NO_EXISTE') !== false) {
            throw new \RuntimeException('No existe ' . $ruta_env . ' en el VPS: se aborta sin escribir.');
        }
        if (strpos($salida, 'ENV_OK') === false) {
            throw new \RuntimeException('No se pudo escribir ' . $ruta_env . ' en el VPS: el servidor no confirmó la escritura.');
        }
    }

    /* ============================================================================================
     | Internas
     |============================================================================================ */

    /**
     * Corre un script de `/root/prueba-gratuita/` y devuelve el JSON de su última línea.
     *
     * @param array<int, string> $args Ya validados por el llamador; acá solo se escapan.
     */
    protected function ejecutar_script(string $script, array $args): array
    {
        if (preg_match('/^[a-z-]+\.sh$/', $script) !== 1) {
            throw new \InvalidArgumentException('Script inválido: ' . $script);
        }

        $comando = 'bash ' . self::CARPETA_SCRIPTS . '/' . $script;
        foreach ($args as $arg) {
            $comando .= ' ' . RemoteCommandRunner::escapar_argumento((string) $arg);
        }

        $inicio = microtime(true);

        try {
            /* 🔴 Timeout alto y exit obligatorio SOLO acá (los scripts largos del pool): ver las constantes. */
            $salida = $this->ejecutar($comando, [], self::TIMEOUT_DE_SCRIPT_SEGUNDOS, true);
        } catch (\RuntimeException $e) {
            /* El runner ya armó el mensaje (exit y salida recortada). Si la salida traía el JSON de error del
               script, se prefiere ese texto, que está en castellano. */
            $json = self::json_de_la_ultima_linea($e->getMessage());
            $detalle = is_array($json) && isset($json['error']) ? (string) $json['error'] : self::sin_secretos($e->getMessage());

            throw new \RuntimeException($script . ' falló: ' . $detalle, 0, $e);
        }

        $json = self::json_de_la_ultima_linea($salida);

        if (! is_array($json)) {
            /* Lo crudo puede traer el JSON de `pool-crear.sh` con `db.pass`: se tapa antes de que viaje a `trial_instances.error`. */
            throw new \RuntimeException($script . ' no devolvió un JSON en su última línea: ' . self::sin_secretos(substr(trim($salida), -300)));
        }

        if (empty($json['ok'])) {
            throw new \RuntimeException($script . ' falló: ' . (isset($json['error']) ? (string) $json['error'] : 'sin detalle'));
        }

        Log::info('PruebaVpsService: ' . $script . ' OK.', [
            'args'     => $this->args_para_el_log($script, $args),
            'segundos' => round(microtime(true) - $inicio, 1),
            'etapas'   => isset($json['etapas']) ? $json['etapas'] : null,
        ]);

        return $json;
    }

    /**
     * Tapa lo que un JSON de `pool-crear.sh` o un `.env` podrían traer antes de que un texto crudo viaje en una
     * excepción (y de ahí a `trial_instances.error`, al log y al panel): `"pass":"…"`, `DB_PASSWORD=…`, `password=…`.
     */
    public static function sin_secretos(string $texto): string
    {
        $texto = (string) preg_replace('/"(pass|password|db_password|app_key)"\s*:\s*"[^"]*"/i', '"$1":"***"', $texto);

        return (string) preg_replace('/\b(DB_PASSWORD|APP_KEY|ADMIN_API_INBOUND_KEY|ADMIN_API_OUTBOUND_KEY|password|pw)=\S+/i', '$1=***', $texto);
    }

    /**
     * 🔴 LA COSTURA: lo único que abre SSH. `Tests\Fakes\PruebaVpsServiceFake` la sobreescribe.
     *
     * @param array<int, string> $secretos Strings que el runner reemplaza por *** en todo lo logueado.
     * @param int|null           $timeout_segundos  null = el de la sesión (10 s de phpseclib: alcanza para los comandos
     *                                              cortos de `escribir_archivo()` y `escribir_variables_env()`).
     * @param bool               $exit_obligatorio  true = un exit status ausente (salida cortada) es falla.
     *
     * @return string Salida cruda.
     *
     * @throws \RuntimeException Si el comando sale con un código distinto de 0.
     */
    protected function ejecutar(string $comando, array $secretos, ?int $timeout_segundos = null, bool $exit_obligatorio = false): string
    {
        return $this->runner()->run($comando, $secretos, true, $timeout_segundos, $exit_obligatorio);
    }

    protected function runner(): RemoteCommandRunner
    {
        if ($this->runner !== null) {
            return $this->runner;
        }

        $credencial = ClientSshCredential::where('type', 'vps')->first();

        if ($credencial === null) {
            throw new \RuntimeException(
                'No hay credencial SSH de tipo "vps" cargada en el admin: sin ella la prueba gratuita no puede tocar el VPS.'
            );
        }

        /* makeWith() y no `new`: es lo que deja que un test bindee RemoteCommandRunner a un fake. Sin logger a
           propósito (ver el docblock de la clase). */
        $this->runner = app()->makeWith(RemoteCommandRunner::class, ['credential' => $credencial]);

        return $this->runner;
    }

    /** El JSON de la última línea no vacía de un texto, o null. */
    public static function json_de_la_ultima_linea(string $texto): ?array
    {
        $lineas = preg_split('/\r\n|\r|\n/', trim($texto));
        if ($lineas === false) {
            return null;
        }

        for ($i = count($lineas) - 1; $i >= 0; $i--) {
            $linea = trim($lineas[$i]);
            if ($linea === '') {
                continue;
            }
            /* El mensaje de una excepción del runner trae "→ " antes de la salida: se busca el primer "{". */
            $desde = strpos($linea, '{');
            if ($desde === false) {
                return null;
            }
            $json = json_decode(substr($linea, $desde), true);

            return is_array($json) ? $json : null;
        }

        return null;
    }

    private function assert_version(string $version): string
    {
        if (preg_match(self::REGEX_VERSION, $version) !== 1) {
            throw new \InvalidArgumentException('Versión inválida: "' . $version . '".');
        }

        return $version;
    }

    private function assert_user_id(int $user_id): int
    {
        if ($user_id <= 0) {
            throw new \InvalidArgumentException('user_id inválido: ' . $user_id . '.');
        }

        return $user_id;
    }

    /** Solo rutas absolutas adentro de `/home/`, sin `..`. */
    private function assert_ruta(string $ruta): void
    {
        if (preg_match('#^/home/[A-Za-z0-9._/-]+$#', $ruta) !== 1 || strpos($ruta, '..') !== false) {
            throw new \InvalidArgumentException('Ruta del VPS inválida: "' . $ruta . '".');
        }
    }

    private function assert_usuario(string $usuario): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $usuario) !== 1) {
            throw new \InvalidArgumentException('Usuario del VPS inválido: "' . $usuario . '".');
        }
    }

    /** Los argumentos tal cual (ninguno es secreto: nombres, slugs, versiones y un user_id). */
    private function args_para_el_log(string $script, array $args): array
    {
        return array_values(array_map('strval', $args));
    }
}
