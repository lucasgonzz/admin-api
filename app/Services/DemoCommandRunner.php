<?php

namespace App\Services;

use App\Models\ClientSshCredential;
use App\Models\Demo;
use phpseclib3\Net\SSH2;

/**
 * Corre un comando de Artisan puntual sobre el servidor de una demo, por SSH.
 *
 * 🔴 POR QUÉ EXISTE. El pipeline de actualización de una demo (`DemoUpdateService`) hace seis
 * etapas fijas —`compile_spa`, `upload_spa`, `upload_api`, `run_migrations`,
 * `restart_queue_workers`, `verify_demo`— y **no corre comandos sueltos**. `DeploymentService`, el
 * equivalente de los clientes, sí tiene `run_seeders` y `run_commands`: la asimetría es vieja y ya
 * trabó trabajo dos veces.
 *
 * Los dos casos concretos, los dos de la producción de los videos de la demo:
 *
 *  - El clip `4.4` necesita `demo:sembrar-trazabilidad`. El comando viajó en la release 4.0.7 y
 *    está en el servidor de las tres demos desde el 29/8/2026, pero **no había forma de
 *    ejecutarlo**. La única alternativa era el demo-setup, que arranca con `migrate:fresh` y le
 *    vacía la base a la instancia — inaceptable con sesiones filmando encima.
 *  - Los clips `1.7`, `1.8` y `2.10` se trabaron el 28/8 porque los workers de cola tenían el
 *    entorno viejo y hacía falta un `queue:restart` que nadie podía correr.
 *
 * 🔴 LISTA BLANCA, NO COMANDO LIBRE. Esto ejecuta en el servidor de una demo, así que **no** acepta
 * cualquier cosa: solo los comandos de `COMANDOS_PERMITIDOS`, y los argumentos tienen que matchear
 * un patrón cerrado. Un endpoint que acepte comando libre es una shell remota con otro nombre.
 *
 * ⚠️ Es SÍNCRONO y a propósito: un artisan de estos tarda segundos, no minutos como el pipeline de
 * actualización. Por eso no necesita job ni cola. Si alguna vez se le agrega un comando lento a la
 * lista, eso deja de valer y hay que encolarlo.
 */
class DemoCommandRunner
{
    /**
     * Los comandos que se pueden correr, con el patrón que tienen que cumplir sus argumentos.
     *
     * 🔴 El patrón NO es una validación de conveniencia: es lo que impide que por el argumento se
     * cuele otra cosa. Se aplica sobre el string de argumentos COMPLETO y todo lo que no matchea se
     * rechaza antes de tocar el SSH.
     *
     * Los comandos de acá son los que efectivamente hicieron falta, no una lista especulativa:
     * sembrar la trazabilidad del clip 4.4, reiniciar los workers (que trabó tres clips el 28/8) y
     * limpiar cachés, que es lo que se pide cuando una demo sirve código viejo.
     *
     * 🔴 `db:seed` NO es libre: solo vale con `--class=VenderLayoutAgilSeeder`, el seeder que
     * siembra el Diseño ágil de Vender (es idempotente, se puede correr varias veces sobre la misma
     * demo). `--force` es opcional pero hace falta en la práctica: en producción `db:seed` pide
     * confirmación interactiva y por SSH sin TTY se queda esperando. El patrón está anclado con `^`
     * y `\z` (sin `$`, que aceptaría un salto de línea final) y no admite otra clase, otro flag ni
     * espacios de más: un `db:seed` abierto podría correr cualquier seeder, incluidos los que
     * borran datos.
     */
    const COMANDOS_PERMITIDOS = [
        // --class=VenderLayoutAgilSeeder [--force]
        'db:seed'                   => '/^--class=VenderLayoutAgilSeeder( --force)?\z/',
        // --article_id=43 --user_id=400 --limpiar
        'demo:sembrar-trazabilidad' => '/^(--article_id=\d{1,9}|--user_id=\d{1,9}|--limpiar|\s)*$/',
        'queue:restart'             => '/^\s*$/',
        'config:clear'              => '/^\s*$/',
        'cache:clear'               => '/^\s*$/',
        'route:clear'               => '/^\s*$/',
        'view:clear'                => '/^\s*$/',
    ];

    /**
     * Binario de PHP con el que se corre artisan en una demo que vive en el VPS.
     *
     * 🔴 Medido el 27/11/2026: las tres demos (api-demo, api-demo2, api-demo3) corren con PHP 8.4
     * (pool /etc/php/8.4/fpm y queue:work con /usr/bin/php8.4), pero el `php` por defecto del CLI
     * del VPS es 7.4 y el vendor de las demos exige >=8.4.27: `php artisan db:seed` moría en
     * platform_check. Por eso se usa la ruta absoluta del 8.4.
     */
    const PHP_BINARIO_VPS = '/usr/bin/php8.4';

    /** Binario de PHP en hosting compartido: el `php` del PATH, como siempre. */
    const PHP_BINARIO_COMPARTIDO = 'php';

    /** Segundos que se le dan al comando antes de cortarlo. */
    const TIMEOUT_SEGUNDOS = 180;

    /** Cuántos caracteres de salida se devuelven como mucho. */
    const MAXIMO_SALIDA = 8000;

    /**
     * Corre el comando y devuelve lo que imprimió.
     *
     * @param Demo   $demo      Demo sobre la que se corre.
     * @param string $comando   Nombre del comando (tiene que estar en COMANDOS_PERMITIDOS).
     * @param string $argumentos Argumentos, ya validados contra el patrón del comando.
     *
     * @return array{salida: string, comando_completo: string}
     *
     * @throws \RuntimeException Si el comando no está permitido, los argumentos no matchean, no hay
     *                           credencial o el SSH rechaza.
     */
    public function run(Demo $demo, $comando, $argumentos = '')
    {
        $comando    = trim((string) $comando);
        $argumentos = trim((string) $argumentos);

        if (! array_key_exists($comando, self::COMANDOS_PERMITIDOS)) {
            throw new \RuntimeException('El comando "' . $comando . '" no está en la lista blanca.');
        }

        if (preg_match(self::COMANDOS_PERMITIDOS[$comando], $argumentos) !== 1) {
            throw new \RuntimeException(
                'Los argumentos de "' . $comando . '" no tienen la forma permitida.'
            );
        }

        $resolver = new DemoPathResolver();

        // Se arma ANTES de abrir el SSH: si la ruta no se puede resolver, falla sin tocar el servidor.
        $linea = $this->armar_linea($demo, $comando, $argumentos);

        /*
         * 🔴 EL TIPO DE CREDENCIAL SALE DEL RESOLVER, NO SE ASUME.
         *
         * La primera versión de esto hardcodeaba `shared_hosting`, copiando lo que parecía hacer el
         * constructor de `DemoUpdateService`. Está mal: ese constructor llama a
         * `demo_credential_type()`, que delega en `DemoPathResolver::credential_type()` y devuelve
         * `vps` para las demos que viven en el VPS — que son las tres de hoy.
         *
         * El síntoma fue engañoso y conviene dejarlo escrito: la ruta que se armaba era la
         * CORRECTA (`/home/api-demo/empresa-api`, la del VPS), pero se abría contra el servidor de
         * hosting compartido, así que el error que volvía era
         * `cd: /home/api-demo/empresa-api: No such file or directory` — un mensaje que hace pensar
         * en la ruta cuando el problema era a qué máquina se estaba entrando. Medido el 30/8/2026
         * contra las tres demos.
         */
        $credential_type = $resolver->credential_type($demo);

        $credential = ClientSshCredential::where('type', $credential_type)->first();

        if ($credential === null) {
            throw new \RuntimeException('No hay credencial SSH de tipo ' . $credential_type . ' cargada.');
        }

        $ssh = new SSH2($credential->host, (int) $credential->port);

        if (! $ssh->login($credential->username, $credential->password)) {
            throw new \RuntimeException('No se pudo conectar por SSH a la demo: credenciales rechazadas.');
        }

        $ssh->setTimeout(self::TIMEOUT_SEGUNDOS);

        $salida = $ssh->exec($linea);
        $ssh->disconnect();

        $salida = (string) $salida;

        if (mb_strlen($salida) > self::MAXIMO_SALIDA) {
            $salida = mb_substr($salida, -self::MAXIMO_SALIDA);
        }

        return [
            'salida'           => $salida,
            'comando_completo' => 'php artisan ' . $comando . ($argumentos !== '' ? ' ' . $argumentos : ''),
        ];
    }

    /**
     * Arma la línea de shell que se ejecuta en el servidor de la demo (sin abrir SSH, por eso es
     * testeable). No valida contra la lista blanca: eso lo hace run() antes de llamarla.
     *
     * La ruta sale del MISMO resolver que usa el pipeline de actualización: dos definiciones de
     * "dónde vive la API de una demo" se separan con el tiempo y la segunda termina corriendo
     * comandos contra un directorio equivocado. El binario de PHP depende de dónde vive la demo
     * (ver PHP_BINARIO_VPS).
     *
     * `escapeshellarg` sobre la ruta; los argumentos NO se escapan de a uno porque tienen que llegar
     * como flags separados: los hace seguros el patrón cerrado de COMANDOS_PERMITIDOS.
     *
     * @param Demo   $demo       Demo sobre la que se corre.
     * @param string $comando    Nombre del comando de artisan.
     * @param string $argumentos Argumentos ya validados (puede ser '').
     *
     * @return string Línea completa, p. ej. `cd '/ruta' && /usr/bin/php8.4 artisan x --no-ansi 2>&1`.
     */
    public function armar_linea(Demo $demo, string $comando, string $argumentos): string
    {
        $resolver = new DemoPathResolver();

        $binario = $resolver->is_vps($demo) ? self::PHP_BINARIO_VPS : self::PHP_BINARIO_COMPARTIDO;

        return 'cd ' . escapeshellarg($resolver->api_path($demo))
            . ' && ' . $binario . ' artisan ' . $comando
            . ($argumentos !== '' ? ' ' . $argumentos : '')
            . ' --no-ansi 2>&1';
    }
}
