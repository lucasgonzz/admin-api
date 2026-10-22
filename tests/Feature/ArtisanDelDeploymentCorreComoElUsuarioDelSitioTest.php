<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use App\Services\DeploymentService;
use App\Services\EnvSshService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Net\SFTP;
use Tests\Fakes\EnvSshServiceFake;
use Tests\Fakes\SftpDelDeploymentFake;
use Tests\Fakes\SshDelDeploymentFake;
use Tests\TestCase;

/**
 * Misión `deploy-vps-artisan-como-usuario-del-sitio` (18/10/2026) — el despliegue en el VPS ya no
 * arranca Laravel como root.
 *
 * El caso: el upgrade #356 de Ananda (VPS, 2.0.1.2 → 4.3.14). La credencial SSH del VPS es root, y
 * una migración que corría como root logueó un warning de AuditLogRecorder ("Table
 * 'ananda.audit_logs' doesn't exist") antes de que existiera el log del día: `laravel-2026-10-18.log`
 * nació `root:root 664` y PHP-FPM (`api-ananda`) no pudo escribir ahí hasta medianoche.
 *
 * Qué se fija, con dobles de SSH y SFTP (no se abre ningún socket; lo que se afirma es QUÉ se manda):
 *
 *  1. 🔴 En el VPS, TODO comando que menciona `artisan` va dentro de
 *     `sudo -H -u 'api-<vps_path>' /bin/bash -c '…'`: migraciones, clears, seeders, comandos de la
 *     versión, `queue:restart`, `down` y `up`. El invariante es el de la CLASE ("ningún artisan pelado"),
 *     no el de las siete instancias de hoy: un artisan nuevo que no pase por el helper hace fallar este
 *     test.
 *  2. El comando de la versión llega entero adentro del `bash -c` (el `USER_ID=…`, el encadenado `&&` y
 *     las comillas del `--class='…'`), y desenvuelto da exactamente el comando de antes.
 *  3. Antes del primer artisan de la migración, `storage/logs`, `storage/framework` y
 *     `bootstrap/cache` se devuelven al usuario del sitio (cubre el `composer install`, que sigue
 *     siendo root, y el `mkdir -p` del esqueleto); y `storage/app` no se toca.
 *  4. El nombre del usuario sale de `vps_path` (texto libre) y no puede romper el entrecomillado; sin
 *     `vps_path` el pipeline falla cerrado en vez de caer a root.
 *  5. En el shared hosting nada cambia: ni un `sudo`, ni el chown (un solo usuario, no hay root).
 */
class ArtisanDelDeploymentCorreComoElUsuarioDelSitioTest extends TestCase
{
    use DatabaseTransactions;

    /** Home del usuario SSH, tal como lo contestaría el servidor. */
    const HOME = '/root';

    /** @var SshDelDeploymentFake */
    private $ssh;

    /** @var SftpDelDeploymentFake */
    private $sftp;

    /** @var EnvSshServiceFake */
    private $env;

    /** @var int */
    private $contador = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shared_hosting', 'vps'] as $tipo) {
            $credencial = ClientSshCredential::where('type', $tipo)->first();
            if ($credencial === null) {
                $credencial       = new ClientSshCredential();
                $credencial->type = $tipo;
            }
            $credencial->host     = '127.0.0.1';
            $credencial->port     = 1;
            $credencial->username = 'nadie';
            $credencial->password = 'clave-ssh-falsa';
            $credencial->save();
        }

        $this->ssh  = new SshDelDeploymentFake();
        $this->sftp = new SftpDelDeploymentFake();
        $this->env  = new EnvSshServiceFake();
        $this->app->instance(EnvSshService::class, $this->env);

        Carbon::setTestNow(Carbon::parse('2026-10-18 22:00:00', config('app.timezone')));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * Upgrade EN EL LUGAR (la API destino es la activa) de un cliente con dos frentes.
     *
     * @param  string       $hosting_type  'shared_hosting' | 'vps'
     * @param  string|null  $vps_path      Lo que se guarda en client_apis.vps_path (por defecto, la carpeta).
     * @return array<string, mixed>
     */
    private function escenario(string $hosting_type, ?string $vps_path = null, bool $rotacion = false): array
    {
        $this->contador++;

        $from = $this->crear_version('9.' . $this->contador . '.0');
        $to   = $this->crear_version('9.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente artisan';
        $client->company_name       = 'Empresa artisan';
        $client->slug               = 'artisan-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        /* 2700 para el primer escenario de cada test (es el que verifican las aserciones de
         * `USER_ID=2700`) y un bloque distinto para cada escenario extra del mismo test: desde la
         * misión pusher-canales-por-sistema (19/10/2026) el admin no acepta dos clientes con el
         * mismo `user_id`, y un test que arma dos escenarios necesita dos dueños distintos. */
        $client->user_id            = 2700 + ($this->contador - 1) * 100;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta = 'sitio' . $this->contador . Str::lower(Str::random(3));
        $api     = $this->crear_api($client, $carpeta, $hosting_type, $vps_path ?? $carpeta);

        $client->active_client_api_id = $api->id;
        $otra                         = null;
        if ($rotacion) {
            /* Rotación: el destino del upgrade es la carpeta que NO atiende; la otra (`<carpeta>2`) es la activa. */
            $otra                         = $this->crear_api($client, $carpeta . '2', $hosting_type, ($vps_path ?? $carpeta) . '2');
            $client->active_client_api_id = $otra->id;
        }
        $client->save();

        $upgrade = ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $api->id,
        ]);

        $env = "APP_NAME=Artisan\n"
            . "DB_CONNECTION=mysql\n"
            . "DB_HOST=127.0.0.1\n"
            . "DB_PORT=3306\n"
            . "DB_DATABASE=u767360347_artisan\n"
            . "DB_USERNAME=u767360347_user\n"
            . "DB_PASSWORD='clave-de-la-base'\n";

        $this->env->envs[$api->id] = $env;
        if ($otra !== null) {
            $this->env->envs[$otra->id] = $env;
        }

        return compact('client', 'api', 'otra', 'upgrade', 'carpeta', 'to');
    }

    /**
     * @param  string  $codigo
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = 'published';
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * @param  Client       $client
     * @param  string       $carpeta
     * @param  string       $hosting_type
     * @param  string|null  $vps_path
     * @return ClientApi
     */
    private function crear_api(Client $client, string $carpeta, string $hosting_type, ?string $vps_path): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com' . ($hosting_type === 'vps' ? '' : '/public');
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $hosting_type === 'vps' ? $vps_path : null;
        $api->save();

        return $api;
    }

    /**
     * Dos seeders (uno con namespace, que lleva las comillas del `--class='…'`) y dos comandos de la versión (uno
     * por usuario, que lleva el `USER_ID=` pegado, y uno encadenado), todos pendientes.
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function seeders_y_comandos(array $e): void
    {
        $por_defecto                  = new VersionSeeder();
        $por_defecto->version_id      = $e['to']->id;
        $por_defecto->seeder_class    = 'SeederPorDefecto';
        $por_defecto->execution_order = 1;
        $por_defecto->save();

        $propio                  = new VersionSeeder();
        $propio->version_id      = $e['to']->id;
        $propio->seeder_class    = 'Database\\Seeders\\SeederPropio';
        $propio->execution_order = 2;
        $propio->save();

        $por_usuario                  = new VersionCommand();
        $por_usuario->version_id      = $e['to']->id;
        $por_usuario->command         = 'php artisan comando:por-usuario';
        $por_usuario->run_scope       = 'per_user';
        $por_usuario->execution_order = 1;
        $por_usuario->save();

        $encadenado                  = new VersionCommand();
        $encadenado->version_id      = $e['to']->id;
        $encadenado->command         = "php artisan comando:uno && php artisan comando:dos --texto='con espacios y \$HOME'";
        $encadenado->execution_order = 2;
        $encadenado->save();

        foreach ([$por_defecto, $propio] as $seeder) {
            UpdateSeeder::create([
                'client_version_upgrade_id' => $e['upgrade']->id,
                'version_seeder_id'         => $seeder->id,
                'status'                    => 'pendiente',
            ]);
        }
        foreach ([$por_usuario, $encadenado] as $comando) {
            UpdateCommand::create([
                'client_version_upgrade_id' => $e['upgrade']->id,
                'version_command_id'        => $comando->id,
                'status'                    => 'pendiente',
            ]);
        }
    }

    /**
     * DeploymentService con los dos seams de transporte apuntando a los dobles, ya conectado.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @return DeploymentService
     */
    private function servicio(ClientVersionUpgrade $upgrade): DeploymentService
    {
        $servicio = new class($upgrade->fresh()) extends DeploymentService {
            /** @var SshDelDeploymentFake */
            public $ssh_doble;

            /** @var SftpDelDeploymentFake */
            public $sftp_doble;

            protected function abrir_sesion_ssh(ClientSshCredential $credential)
            {
                return $this->ssh_doble;
            }

            protected function open_sftp_session(string $credential_type): SFTP
            {
                return $this->sftp_doble;
            }
        };

        $servicio->ssh_doble  = $this->ssh;
        $servicio->sftp_doble = $this->sftp;

        /* El sistema NO está en mantenimiento al empezar. */
        $this->ssh->responder('storage/framework/down', 'CC_SIN_MANTENIMIENTO');

        $servicio->connect();

        return $servicio;
    }

    /**
     * Invoca un método privado del servicio.
     *
     * @param  DeploymentService  $servicio
     * @param  string             $metodo
     * @param  array              $argumentos
     * @return mixed
     */
    private function invocar(DeploymentService $servicio, string $metodo, array $argumentos = [])
    {
        $reflexion = new \ReflectionMethod(DeploymentService::class, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($servicio, $argumentos);
    }

    /**
     * Corre el pipeline desde esa etapa y devuelve la excepción con la que falló (o null).
     *
     * @param  DeploymentService  $servicio
     * @param  string             $desde
     * @return \Throwable|null
     */
    private function correr(DeploymentService $servicio, string $desde)
    {
        try {
            $servicio->run($desde);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * El pipeline completo en el lugar, de upload_spa a la pausa: GitHub, SPA ya bajado y un respaldo
     * en el disco del servidor, como en RespaldoYMantenimientoEnElLugarTest.
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function correr_el_despliegue_entero(array $e): void
    {
        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');

        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        $this->assertSame('paused', (string) $e['upgrade']->fresh()->deployment_status, $this->logs($e['upgrade']));
    }

    /**
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function respaldo_en_disco(array $e): void
    {
        $nombre = '20261017-020000-upgrade-' . $e['upgrade']->id;

        $this->ssh->responder('printf %s "$HOME"', self::HOME);
        $this->ssh->responder('ls -1', $nombre);
        $this->ssh->responder('CC_RESPALDO_COMPLETO', "CC_RESPALDO_COMPLETO\nCC_RESPALDO_TOCADO");
    }

    /**
     * Zip mínimo de la API (con `artisan`) y el fake de GitHub.
     *
     * @param  Version  $to
     * @return void
     */
    private function artefactos_en_github(Version $to): void
    {
        $api = $this->zip(['artisan' => "#!/usr/bin/env php\n<?php\n", 'composer.lock' => '{}']);

        Http::fake([
            '*/releases/tags/*'   => Http::response(['id' => 1, 'assets' => [[
                'id'                   => 1,
                'name'                 => 'empresa-api-v' . $to->version . '.zip',
                'size'                 => strlen($api),
                'url'                  => 'https://api.github.com/repos/lucasgonzz/empresa-api/releases/assets/1',
                'browser_download_url' => 'https://github.com/x/1',
            ]]], 200),
            '*/releases/assets/1' => Http::response($api, 200),
        ]);
    }

    /**
     * Zip con esos archivos más relleno al azar (el pipeline rechaza un zip de menos de 500 bytes).
     *
     * @param  array<string, string>  $archivos
     * @return string
     */
    private function zip(array $archivos): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'zip-');
        $zip      = new \ZipArchive();
        $zip->open($temporal, \ZipArchive::OVERWRITE);
        foreach ($archivos as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->addFromString('relleno.bin', random_bytes(2048));
        $zip->close();

        $contenido = (string) file_get_contents($temporal);
        unlink($temporal);

        return $contenido;
    }

    /**
     * @param  ClientVersionUpgrade  $upgrade
     * @return void
     */
    private function spa_ya_bajado(ClientVersionUpgrade $upgrade): void
    {
        $dir = storage_path('app/deployments');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/dist_' . $upgrade->uuid . '.zip', $this->zip(['index.html' => '<!doctype html>']));
    }

    /**
     * @param  ClientVersionUpgrade  $upgrade
     * @return string
     */
    private function logs(ClientVersionUpgrade $upgrade): string
    {
        return DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->orderBy('id')
            ->pluck('line')
            ->implode("\n");
    }

    /**
     * Comandos que contienen esa aguja.
     *
     * @param  string  $aguja
     * @return array<int, string>
     */
    private function comandos_con(string $aguja): array
    {
        return array_values(array_filter($this->ssh->comandos, function ($comando) use ($aguja) {
            return strpos($comando, $aguja) !== false;
        }));
    }

    /**
     * Parte un comando de shell en palabras, entendiendo SOLO lo que arma el pipeline: texto pelado,
     * comillas simples y la comilla simple escapada afuera (`\'`). Es un lector independiente de
     * posix_single_quote(): si el entrecomillado del servicio dejara algo sin cerrar, o algo que el
     * shell remoto tomaría como operador, esto da distinto que el original.
     *
     * Devuelve null si el texto tiene un operador de shell (`;`, `&`, `|`, `$`, comilla doble…) FUERA de
     * comillas, que es lo que un entrecomillado roto dejaría suelto.
     *
     * @param  string  $linea
     * @return array<int, string>|null
     */
    private function palabras_de_shell(string $linea): ?array
    {
        $palabras   = [];
        $actual     = '';
        $en_palabra = false;
        $abierta    = false;
        $largo      = strlen($linea);

        for ($i = 0; $i < $largo; $i++) {
            $c = $linea[$i];

            /* Adentro de comillas simples todo es literal, hasta la próxima comilla simple. */
            if ($abierta) {
                if ($c === "'") {
                    $abierta = false;
                } else {
                    $actual .= $c;
                }
                continue;
            }

            if ($c === "'") {
                $abierta    = true;
                $en_palabra = true;
                continue;
            }

            /* La comilla simple escapada afuera: `\'`. */
            if ($c === '\\' && isset($linea[$i + 1]) && $linea[$i + 1] === "'") {
                $actual    .= "'";
                $en_palabra = true;
                $i++;
                continue;
            }

            if ($c === ' ') {
                if ($en_palabra) {
                    $palabras[] = $actual;
                    $actual     = '';
                    $en_palabra = false;
                }
                continue;
            }

            /* Un operador de shell suelto, fuera de las comillas. */
            if (strpos(";&|$`\"\\<>(){}*?\n", $c) !== false) {
                return null;
            }

            $actual    .= $c;
            $en_palabra = true;
        }

        if ($abierta) {
            return null;
        }
        if ($en_palabra) {
            $palabras[] = $actual;
        }

        return $palabras;
    }

    /**
     * El comando tal como lo mandó el pipeline, sin el envoltorio `{ … } < /dev/null` del stdin cerrado.
     *
     * @param  string  $comando
     * @return string
     */
    private function sin_el_envoltorio_de_stdin(string $comando): string
    {
        $this->assertSame('{ ', substr($comando, 0, 2), $comando);
        $this->assertSame("\n} < /dev/null", substr($comando, -14), $comando);

        return substr($comando, 2, -14);
    }

    /**
     * Desenvuelve `cd '<ruta>' && sudo -H -u '<usuario>' /bin/bash -c '<comando>'` y devuelve
     * [usuario, comando], o null si el comando no tiene esa forma.
     *
     * @param  string  $comando  Tal cual llegó a exec().
     * @return array{0: string, 1: string}|null
     */
    private function desenvolver_el_sudo(string $comando): ?array
    {
        $sin = $this->sin_el_envoltorio_de_stdin($comando);

        $marca = ' && sudo -H -u ';
        $donde = strpos($sin, $marca);
        if ($donde === false) {
            return null;
        }

        $palabras = $this->palabras_de_shell(substr($sin, $donde + strlen(' && ')));
        if ($palabras === null || count($palabras) !== 7) {
            return null;
        }

        [$sudo, $h, $u, $usuario, $bash, $c, $inner] = $palabras;
        if ([$sudo, $h, $u, $bash, $c] !== ['sudo', '-H', '-u', '/bin/bash', '-c']) {
            return null;
        }

        return [$usuario, $inner];
    }

    /**
     * Los comandos que mencionan `artisan` y NO van dentro de un `sudo -H -u` (el invariante de la clase).
     *
     * @return array<int, string>
     */
    private function artisan_que_corre_como_root(): array
    {
        $pelados = [];

        foreach ($this->ssh->comandos as $comando) {
            if (stripos($comando, 'artisan') === false) {
                continue;
            }

            $sudo = strpos($comando, 'sudo -H -u ');
            if ($sudo === false || $sudo > strpos($comando, 'artisan')) {
                $pelados[] = $comando;
            }
        }

        return $pelados;
    }

    /* ==========================================================================================
     | 1) VPS: todo artisan del despliegue corre como el usuario del sitio
     |========================================================================================= */

    /**
     * 🔴 La clase, no el ejemplo. Un despliegue entero en el lugar sobre un VPS: ningún comando que
     * mencione `artisan` corre como root, y las siete invocaciones (clears, migrate, seeders, comandos,
     * queue:restart, down, up) están y van con el usuario del sitio.
     */
    public function test_en_el_vps_ningun_artisan_del_despliegue_corre_como_root(): void
    {
        $e = $this->escenario('vps');
        $this->seeders_y_comandos($e);

        $this->correr_el_despliegue_entero($e);

        $this->assertSame([], $this->artisan_que_corre_como_root(), "Artisan pelado (root):\n" . $this->ssh->todo());

        $usuario = 'api-' . $e['carpeta'];
        $ruta    = '/home/api-' . $e['carpeta'] . '/empresa-api';

        /* Las invocaciones que tienen que estar, cada una como el usuario del sitio y en su carpeta. */
        $esperadas = [
            'php artisan down --no-ansi 2>&1'                              => 1,
            'php artisan config:clear --no-ansi 2>&1'                      => 1,
            'php artisan cache:clear --no-ansi 2>&1'                       => 1,
            'php artisan view:clear --no-ansi 2>&1'                        => 1,
            'php artisan route:clear --no-ansi 2>&1'                       => 1,
            'php artisan migrate --force'                                  => 1,
            "php artisan db:seed --class='SeederPorDefecto' --force"       => 1,
            'php artisan queue:restart --no-ansi 2>&1'                     => 1,
            'php artisan up --no-ansi 2>&1'                                => 1,
        ];

        $vistos = [];
        foreach ($this->ssh->comandos as $comando) {
            if (stripos($comando, 'artisan') === false) {
                continue;
            }

            $desenvuelto = $this->desenvolver_el_sudo($comando);
            $this->assertNotNull($desenvuelto, 'No tiene la forma cd … && sudo -H -u … /bin/bash -c …: ' . $comando);
            $this->assertSame($usuario, $desenvuelto[0], $comando);
            /* En la carpeta del sitio; cada etapa entrecomilla el path a su manera (sin comillas, simples o
               dobles), eso no cambió. */
            $this->assertMatchesRegularExpression(
                '#^cd [\'"]?' . preg_quote($ruta, '#') . '[\'"]? && sudo -H -u #',
                $this->sin_el_envoltorio_de_stdin($comando)
            );

            $vistos[$desenvuelto[1]] = ($vistos[$desenvuelto[1]] ?? 0) + 1;
        }

        foreach ($esperadas as $inner => $veces) {
            $this->assertSame($veces, $vistos[$inner] ?? 0, 'Falta o se repite: ' . $inner . "\n" . implode("\n", array_keys($vistos)));
        }
    }

    /**
     * El `down` y el `up` en el lugar son del usuario del sitio, y el orden de siempre se conserva
     * (down antes del unzip, migrate antes del up, comandos después del up).
     */
    public function test_en_el_vps_el_orden_del_pipeline_no_cambia(): void
    {
        $e = $this->escenario('vps');
        $this->seeders_y_comandos($e);

        $this->correr_el_despliegue_entero($e);

        $down    = $this->ssh->posicion_de('php artisan down');
        $unzip   = $this->ssh->posicion_de('unzip -o api_');
        $migrate = $this->ssh->posicion_de('php artisan migrate --force');
        $seeder  = $this->ssh->posicion_de('db:seed');
        $restart = $this->ssh->posicion_de('queue:restart');
        $up      = $this->ssh->posicion_de('php artisan up');
        $comando = $this->ssh->posicion_de('comando:por-usuario');

        foreach (compact('down', 'unzip', 'migrate', 'seeder', 'restart', 'up', 'comando') as $nombre => $posicion) {
            $this->assertNotNull($posicion, 'Falta ' . $nombre . "\n" . $this->ssh->todo());
        }

        $this->assertLessThan($unzip, $down);
        $this->assertLessThan($seeder, $migrate);
        $this->assertLessThan($restart, $seeder);
        $this->assertLessThan($up, $restart);
        $this->assertLessThan($comando, $up);
    }

    /**
     * Lo que la versión escribe a mano llega intacto adentro del `bash -c`: el `USER_ID=` que pega el
     * resolver, el encadenado `&&`, las comillas y el `$HOME` literal. Desenvuelto con un lector de
     * shell independiente, da EXACTAMENTE el comando que se mandaba antes del cambio.
     */
    public function test_en_el_vps_el_comando_de_la_version_llega_entero_adentro_del_bash(): void
    {
        $e = $this->escenario('vps');
        $this->seeders_y_comandos($e);

        $this->correr_el_despliegue_entero($e);

        $internos = [];
        foreach ($this->ssh->comandos as $comando) {
            $desenvuelto = stripos($comando, 'artisan') === false ? null : $this->desenvolver_el_sudo($comando);
            if ($desenvuelto !== null) {
                $internos[] = $desenvuelto[1];
            }
        }

        $this->assertContains("php artisan db:seed --class='Database\\Seeders\\SeederPropio' --force", $internos);
        $this->assertContains('USER_ID=2700 php artisan comando:por-usuario', $internos);
        /* `run_scope` por defecto de un comando es per_user: el resolver le pega el USER_ID, también al encadenado. */
        $this->assertContains("USER_ID=2700 php artisan comando:uno && php artisan comando:dos --texto='con espacios y \$HOME'", $internos);
    }

    /* ==========================================================================================
     | 2) VPS: la guarda de dueño de storage
     |========================================================================================= */

    /**
     * El `chown -R` de `storage/logs`, `storage/framework` y `bootstrap/cache` al usuario del sitio va
     * ANTES del primer artisan de la migración (cubre el composer de root y el mkdir del esqueleto) y
     * no toca `storage/app` (puede tener años de adjuntos).
     */
    public function test_en_el_vps_el_storage_vuelve_al_usuario_del_sitio_antes_del_primer_artisan_de_la_migracion(): void
    {
        $e = $this->escenario('vps');
        $this->seeders_y_comandos($e);
        $this->ssh->responder('CC_STORAGE_DEL_SITIO_OK', 'CC_STORAGE_DEL_SITIO_OK');

        $this->correr_el_despliegue_entero($e);

        $usuario = 'api-' . $e['carpeta'];

        /* Dos veces en el lugar: antes del `down` (el primer artisan del sitio) y en el esqueleto de storage. */
        $posiciones = [];
        foreach ($this->ssh->comandos as $posicion => $comando) {
            if (strpos($comando, 'storage/logs storage/framework bootstrap/cache') === false) {
                continue;
            }

            $posiciones[] = $posicion;

            $this->assertStringContainsString("chown -R '" . $usuario . ':' . $usuario . "' storage/logs storage/framework bootstrap/cache", $comando);
            $this->assertStringNotContainsString('storage/app', $comando);
            $this->assertStringContainsString("cd '/home/api-" . $e['carpeta'] . "/empresa-api'", $comando);
        }
        $this->assertCount(2, $posiciones, 'En el lugar la guarda corre antes del down y en el esqueleto: ' . $this->ssh->todo());

        $down     = $this->ssh->posicion_de('php artisan down');
        $composer = $this->ssh->posicion_de('composer');
        $mkdir    = $this->ssh->posicion_de('mkdir -p storage/app/public');
        $clears   = $this->ssh->posicion_de('config:clear');
        $migrate  = $this->ssh->posicion_de('php artisan migrate --force');

        /* La primera antes del primer artisan del sitio. */
        $this->assertLessThan($down, $posiciones[0], 'La guarda va antes del down.');

        /* La segunda DESPUÉS de lo que sigue siendo root (el composer de upload_api y el mkdir del esqueleto) y
           ANTES del primer artisan de la migración. */
        $this->assertLessThan($posiciones[1], $composer, 'La guarda va después del composer install (root).');
        $this->assertLessThan($posiciones[1], $mkdir, 'La guarda va después del mkdir del esqueleto (root).');
        $this->assertLessThan($clears, $posiciones[1], $this->ssh->todo());
        $this->assertLessThan($migrate, $posiciones[1]);

        $this->assertStringContainsString(
            'con el dueño del sitio (' . $usuario . ')',
            $this->logs($e['upgrade']),
            'Si el servidor confirma el chown, el log lo dice.'
        );
    }

    /**
     * 🔴 Rotación: el despliegue va a la carpeta que NO atiende (`<carpeta>`), mientras la activa es otra
     * (`<carpeta>2`). Los artisan y el chown son del usuario del frente DESTINO (cuyo código se está
     * desplegando), no del que atiende. En la rotación no hay mantenimiento (`down`/`up`).
     */
    public function test_en_la_rotacion_en_el_vps_el_usuario_y_el_chown_son_los_del_frente_destino(): void
    {
        $e = $this->escenario('vps', null, true);
        $this->seeders_y_comandos($e);
        $this->ssh->responder('CC_STORAGE_DEL_SITIO_OK', 'CC_STORAGE_DEL_SITIO_OK');

        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));

        $this->assertSame([], $this->artisan_que_corre_como_root(), $this->ssh->todo());
        $this->assertSame([], $this->comandos_con('artisan down'), 'La rotación no pone el sistema en mantenimiento.');

        $destino = 'api-' . $e['carpeta'];
        $otro    = 'api-' . $e['carpeta'] . '2';

        $usuarios = [];
        foreach ($this->ssh->comandos as $comando) {
            if (stripos($comando, 'artisan') === false) {
                continue;
            }

            $desenvuelto = $this->desenvolver_el_sudo($comando);
            $this->assertNotNull($desenvuelto, $comando);
            $usuarios[$desenvuelto[0]] = true;
        }
        $this->assertSame([$destino], array_keys($usuarios), 'Todo artisan va con el usuario del frente destino, nunca con el del activo.');

        $chowns = $this->comandos_con('storage/logs storage/framework bootstrap/cache');
        $this->assertCount(1, $chowns);
        $this->assertStringContainsString("chown -R '" . $destino . ':' . $destino . "'", $chowns[0]);
        $this->assertStringContainsString("cd '/home/" . $destino . "/empresa-api'", $chowns[0]);
        // El contrato opt-in exige leer el marcador activo; ésa es la única excepción a no tocarlo.
        $lectura_exacta = '{ ' . (new \App\Services\RuntimeProfileService())->markerReadCommand(
            '/home/' . $otro . '/.comerciocity-runtime.json'
        ) . "\n} < /dev/null";
        $lecturas = 0;
        foreach ($this->ssh->comandos as $comando) {
            if ($comando === $lectura_exacta) {
                $lecturas++;
                continue;
            }
            $this->assertStringNotContainsString($otro, $comando, 'Ningún otro comando puede tocar el frente activo.');
        }
        $this->assertSame(1, $lecturas, 'Sólo una lectura exacta del marcador activo, sin escrituras.');
    }

    /**
     * La guarda no aborta: si el servidor no confirma el chown, la migración sigue y queda un warning.
     */
    public function test_en_el_vps_si_no_se_confirma_el_chown_el_despliegue_sigue_con_un_warning(): void
    {
        $e = $this->escenario('vps');
        $this->ssh->responder('storage/logs storage/framework bootstrap/cache', 'chown: cambiando el propietario: Operación no permitida', 1);

        $this->correr_el_despliegue_entero($e);

        $warning = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('level', 'warning')
            ->where('line', 'like', 'No se pudo confirmar el dueño de storage/logs%')
            ->exists();
        $this->assertTrue($warning, $this->logs($e['upgrade']));
        $this->assertNotNull($this->ssh->posicion_de('php artisan migrate --force'));
    }

    /* ==========================================================================================
     | 3) VPS: el nombre del usuario
     |========================================================================================= */

    /**
     * El usuario sale de `client_apis.vps_path`, que es texto libre del CRUD, y la credencial del VPS
     * es root: un vps_path con comillas y operadores de shell no puede salirse del entrecomillado.
     * El comando completo sigue siendo exactamente siete palabras, y el usuario es `api-` + el texto.
     */
    public function test_un_vps_path_con_metacaracteres_no_escapa_del_entrecomillado(): void
    {
        $malo = "x'; touch /tmp/pwn; echo '";
        $e    = $this->escenario('vps', $malo);

        $envuelto = $this->invocar($this->servicio($e['upgrade']), 'como_usuario_del_sitio', ['php artisan migrate --force']);

        $palabras = $this->palabras_de_shell($envuelto);
        $this->assertNotNull($palabras, 'Quedó algo suelto fuera de las comillas: ' . $envuelto);
        $this->assertSame(
            ['sudo', '-H', '-u', 'api-' . $malo, '/bin/bash', '-c', 'php artisan migrate --force'],
            $palabras
        );
    }

    /**
     * Falla cerrada: un VPS sin vps_path no cae a root en silencio, lanza.
     */
    public function test_un_vps_sin_vps_path_falla_cerrado_en_vez_de_correr_como_root(): void
    {
        $e = $this->escenario('vps', '');

        $servicio = $this->servicio($e['upgrade']);

        $this->expectException(\RuntimeException::class);
        $this->invocar($servicio, 'como_usuario_del_sitio', ['php artisan migrate --force']);
    }

    /**
     * El comando que el log sugiere para levantar a mano el sistema también es del usuario del sitio.
     */
    public function test_el_comando_para_levantar_a_mano_lleva_el_sudo_en_el_vps_y_no_en_el_shared(): void
    {
        $vps = $this->escenario('vps');
        $this->assertSame(
            "cd /home/api-" . $vps['carpeta'] . "/empresa-api && sudo -H -u api-" . $vps['carpeta'] . ' php artisan up',
            $this->invocar($this->servicio($vps['upgrade']), 'comando_para_levantar')
        );

        $shared = $this->escenario('shared_hosting');
        $levantar = $this->invocar($this->servicio($shared['upgrade']), 'comando_para_levantar');
        $this->assertStringEndsWith(' && php artisan up', $levantar);
        $this->assertStringNotContainsString('sudo', $levantar);
    }

    /* ==========================================================================================
     | 4) Shared hosting: nada cambia
     |========================================================================================= */

    /**
     * En el shared el usuario SSH es el único dueño de todo (una cuenta de Hostinger): no hay root ni
     * segundo usuario. Ningún comando lleva `sudo`, no hay chown de storage, y los artisan son los de
     * siempre, pelados y en su carpeta.
     */
    public function test_en_el_shared_hosting_no_aparece_sudo_ni_chown_y_los_comandos_son_los_de_siempre(): void
    {
        $e = $this->escenario('shared_hosting');
        $this->seeders_y_comandos($e);

        $this->correr_el_despliegue_entero($e);

        $this->assertStringNotContainsString('sudo', $this->ssh->todo());
        $this->assertStringNotContainsString('storage/logs storage/framework', $this->ssh->todo());
        $this->assertSame([], $this->comandos_con('chown -R'), 'El shared no necesita ningún chown.');

        $this->assertNotNull($this->ssh->posicion_de("&& php artisan down --no-ansi 2>&1\n}"));
        $this->assertNotNull($this->ssh->posicion_de("&& php artisan up --no-ansi 2>&1\n}"));
        $this->assertNotNull($this->ssh->posicion_de(' && php artisan migrate --force' . "\n}"));
        $this->assertNotNull($this->ssh->posicion_de(' && USER_ID=2700 php artisan comando:por-usuario' . "\n}"));
        $this->assertNotNull($this->ssh->posicion_de(" && php artisan db:seed --class='SeederPorDefecto' --force\n}"));
        $this->assertNotNull($this->ssh->posicion_de(' && php artisan config:clear --no-ansi 2>&1' . "\n}"));
    }
}
