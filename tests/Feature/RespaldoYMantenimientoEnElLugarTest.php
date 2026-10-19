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
 * Misión `un-solo-frente` (14/10/2026) — lo que `DeploymentService` hace distinto cuando el
 * despliegue va EN EL LUGAR (la API destino es la que está atendiendo), y el respaldo de la última
 * rotación al frente principal.
 *
 * Con la rotación, la red de seguridad era la carpeta vieja intacta. En el lugar esa red no existe,
 * y el pipeline arma otra. Lo que estos tests fijan, con dobles de SSH y SFTP (no se abre ningún
 * socket) y el `.env` del frente en memoria:
 *
 *  1. 🔴 El respaldo arma los comandos esperados —tar de la API sin `storage/` ni `vendor/`, tar del
 *     SPA, mysqldump con `--single-transaction --quick --no-tablespaces`— en un `.parcial` que se
 *     renombra al final, y la contraseña de la base NO aparece en ningún comando ni log.
 *  2. 🔴 A prueba de reintentos: un respaldo completo de ESTE upgrade en el disco se reusa SOLO si
 *     ese upgrade ya tocó el sistema vivo (marca TOCADO, o el sistema en mantenimiento); si no, se
 *     respalda de nuevo. Los `.parcial` se borran; la retención nunca borra el de este upgrade y de
 *     los demás deja uno.
 *  3. 🔴 Si el respaldo falla, el deployment queda `failed` y nada del cliente se tocó.
 *  4. 🔴 En el lugar: la API se sube antes de tocar el SPA; `artisan down` antes del `unzip`;
 *     migraciones y seeders ADENTRO del mantenimiento; `artisan up` después del reinicio del worker;
 *     los comandos con el sistema arriba; el post-cierre no vuelve a correr nada; y el upgrade no se
 *     cierra con el sistema en mantenimiento. En una rotación, nada de eso.
 *  5. Las líneas propias de cada fallo: en mantenimiento (recomienda la etapa que falló), SPA nuevo
 *     con API vieja, o nada tocado.
 */
class RespaldoYMantenimientoEnElLugarTest extends TestCase
{
    use DatabaseTransactions;

    /** Contraseña de la base del frente: distintiva, para afirmar que no aparece en ningún lado. */
    const PASSWORD_DE_LA_BASE = 'Clave-De-La-Base"0x7f\\fin';

    /** Home del usuario SSH del shared, tal como lo contestaría el servidor. */
    const HOME_SHARED = '/home/u767360347';

    /** @var SshDelDeploymentFake */
    private $ssh;

    /** @var SftpDelDeploymentFake */
    private $sftp;

    /** @var EnvSshServiceFake */
    private $env;

    /** @var int */
    private $contador = 0;

    /**
     * Credenciales SSH apuntando a un puerto cerrado de localhost (nada de lo que se escape de los
     * dobles puede llegar a un servidor real), y los dobles preparados.
     *
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

        Carbon::setTestNow(Carbon::parse('2026-10-14 02:00:00', config('app.timezone')));
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
     * Upgrade de un cliente con dos frentes (`<carpeta>` principal y `<carpeta>2`).
     *
     * @param  string  $activo        'principal' | 'segundo': cuál atiende.
     * @param  string  $destino       'principal' | 'segundo': hacia cuál va el upgrade.
     * @param  string  $hosting_type  'shared_hosting' | 'vps'
     * @return array<string, mixed>
     */
    private function escenario(string $activo = 'principal', string $destino = 'principal', string $hosting_type = 'shared_hosting'): array
    {
        $this->contador++;

        $from = $this->crear_version('8.' . $this->contador . '.0');
        $to   = $this->crear_version('8.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente respaldo';
        $client->company_name       = 'Empresa respaldo';
        $client->slug               = 'respaldo-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        /* 2700 para el primer escenario de cada test y un bloque distinto para cada escenario extra
         * del mismo test: desde la misión pusher-canales-por-sistema (19/10/2026) el admin no acepta
         * dos clientes con el mismo `user_id`, y varios tests de acá arman más de un escenario. */
        $client->user_id            = 2700 + ($this->contador - 1) * 100;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta   = 'respaldo' . $this->contador . Str::lower(Str::random(3));
        $principal = $this->crear_api($client, $carpeta, $hosting_type);
        $segundo   = $this->crear_api($client, $carpeta . '2', $hosting_type);

        $client->active_client_api_id = $activo === 'principal' ? $principal->id : $segundo->id;
        $client->save();

        $api_destino = $destino === 'principal' ? $principal : $segundo;

        $upgrade = ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $api_destino->id,
        ]);

        $this->env->envs[$api_destino->id] = "APP_NAME=Respaldo\n"
            . "DB_CONNECTION=mysql\n"
            . "DB_HOST=127.0.0.1\n"
            . "DB_PORT=3306\n"
            . "DB_DATABASE=u767360347_respaldo\n"
            . "DB_USERNAME=u767360347_user\n"
            . 'DB_PASSWORD=' . "'" . self::PASSWORD_DE_LA_BASE . "'\n";

        $carpeta_destino = $destino === 'principal' ? $carpeta : $carpeta . '2';

        return compact('client', 'principal', 'segundo', 'upgrade', 'carpeta', 'carpeta_destino', 'to');
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
     * @param  Client  $client
     * @param  string  $carpeta
     * @param  string  $hosting_type
     * @return ClientApi
     */
    private function crear_api(Client $client, string $carpeta, string $hosting_type): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com' . ($hosting_type === 'vps' ? '' : '/public');
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $hosting_type === 'vps' ? $carpeta : null;
        $api->save();

        return $api;
    }

    /**
     * Un seeder y un comando en el upgrade, pendientes.
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function seeder_y_comando(array $e): void
    {
        $seeder                  = new VersionSeeder();
        $seeder->version_id      = $e['to']->id;
        $seeder->seeder_class    = 'SeederDeLaVentana';
        $seeder->execution_order = 1;
        $seeder->save();

        $comando                  = new VersionCommand();
        $comando->version_id      = $e['to']->id;
        $comando->command         = 'php artisan comando:de-la-ventana';
        $comando->execution_order = 1;
        $comando->save();

        UpdateSeeder::create([
            'client_version_upgrade_id' => $e['upgrade']->id,
            'version_seeder_id'         => $seeder->id,
            'status'                    => 'pendiente',
        ]);
        UpdateCommand::create([
            'client_version_upgrade_id' => $e['upgrade']->id,
            'version_command_id'        => $comando->id,
            'status'                    => 'pendiente',
        ]);
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

        /* Por defecto el sistema NO está en mantenimiento. Va al final: las reglas del doble ganan
           en orden de declaración, así que un test que lo pone en `down` lo declara antes. */
        $this->ssh->responder('storage/framework/down', 'CC_SIN_MANTENIMIENTO');

        $servicio->connect();

        return $servicio;
    }

    /**
     * Invoca una etapa privada del servicio.
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
     * Corre el pipeline y devuelve la excepción con la que falló (o null).
     *
     * @param  DeploymentService  $servicio
     * @param  string|null        $desde
     * @return \Throwable|null
     */
    private function correr(DeploymentService $servicio, $desde = null)
    {
        try {
            $servicio->run($desde);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * Respuestas del servidor para un respaldo que sale bien. Los `ls` y el chequeo de respaldo
     * completo se preparan aparte (por defecto: la carpeta está vacía).
     *
     * @param  string  $home
     * @return void
     */
    private function servidor_sano(string $home = self::HOME_SHARED): void
    {
        $this->ssh->responder('printf %s "$HOME"', $home);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('CC_CREDENCIALES_BORRADAS', 'CC_CREDENCIALES_BORRADAS');
        $this->ssh->responder('gzip -dc', "-- Dump completed on 2026-10-14  2:00:01\n524288");
        $this->ssh->responder('tar --warning=no-file-changed', 'CC_TAR_EXIT=0');
        $this->ssh->responder('gzip -t', '524288');
    }

    /**
     * Base de respaldos del escenario.
     *
     * @param  array<string, mixed>  $e
     * @param  string                $home
     * @return string
     */
    private function base(array $e, string $home = self::HOME_SHARED): string
    {
        return $home . '/cc-respaldos/' . $e['carpeta_destino'];
    }

    /**
     * Directorio del respaldo nuevo de este escenario (el reloj está congelado).
     *
     * @param  array<string, mixed>  $e
     * @param  string                $home
     * @return string
     */
    private function dir_del_respaldo(array $e, string $home = self::HOME_SHARED): string
    {
        return $this->base($e, $home) . '/20261014-020000-upgrade-' . $e['upgrade']->id;
    }

    /**
     * Deja en el disco (en lo que contesta el doble) un respaldo completo de ESTE upgrade, hecho por
     * un intento anterior, más lo que se pida.
     *
     * @param  array<string, mixed>  $e
     * @param  array<int, string>    $otros   Otras entradas de la carpeta.
     * @param  bool                  $tocado  Si ese intento llegó a tocar el sistema vivo (la marca
     *                                        TOCADO adentro del respaldo).
     * @return string  La ruta del respaldo de este upgrade.
     */
    private function respaldo_en_disco(array $e, array $otros = [], bool $tocado = true): string
    {
        $nombre = '20261013-020000-upgrade-' . $e['upgrade']->id;

        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('ls -1', implode("\n", array_merge($otros, [$nombre])));
        $this->ssh->responder('CC_RESPALDO_COMPLETO', 'CC_RESPALDO_COMPLETO' . ($tocado ? "\nCC_RESPALDO_TOCADO" : ''));

        return $this->base($e) . '/' . $nombre;
    }

    /**
     * Zips mínimos de la API (con `artisan`) y del SPA (con `index.html`) y los fakes de GitHub.
     *
     * @param  Version  $to
     * @param  bool     $con_spa  Si también se publica el artefacto del SPA.
     * @return void
     */
    private function artefactos_en_github(Version $to, bool $con_spa = false): void
    {
        $api = $this->zip(['artisan' => "#!/usr/bin/env php\n<?php\n", 'composer.lock' => '{}']);
        $spa = $this->zip(['index.html' => '<!doctype html><title>spa</title>']);

        $assets = [[
            'id'                   => 1,
            'name'                 => 'empresa-api-v' . $to->version . '.zip',
            'size'                 => strlen($api),
            'url'                  => 'https://api.github.com/repos/lucasgonzz/empresa-api/releases/assets/1',
            'browser_download_url' => 'https://github.com/x/1',
        ]];

        if ($con_spa) {
            $assets[] = [
                'id'                   => 2,
                'name'                 => 'empresa-spa-v' . $to->version . '-dist.zip',
                'size'                 => strlen($spa),
                'url'                  => 'https://api.github.com/repos/lucasgonzz/empresa-spa/releases/assets/2',
                'browser_download_url' => 'https://github.com/x/2',
            ];
        }

        Http::fake([
            '*/releases/tags/*'   => Http::response(['id' => 1, 'assets' => $assets], 200),
            '*/releases/assets/1' => Http::response($api, 200),
            '*/releases/assets/2' => Http::response($spa, 200),
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
     * Deja el zip del SPA donde lo deja compile_spa al bajar el artefacto.
     *
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
     * Todas las líneas de log del upgrade, pegadas.
     *
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

    /* ==========================================================================================
     | 1) El respaldo
     |========================================================================================= */

    /**
     * 🔴 Los comandos del respaldo, en orden, en un `.parcial` que se renombra al final; y la
     * contraseña de la base en NINGÚN comando ni log: solo en el archivo de opciones escrito por
     * SFTP, con permisos 600, que después se borra.
     */
    public function test_el_respaldo_arma_los_comandos_esperados_sin_la_password(): void
    {
        $e = $this->escenario();
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dir      = $this->dir_del_respaldo($e);
        $parcial  = $dir . '.parcial';
        $api_path = 'domains/comerciocity.com/public_html/' . $e['carpeta'] . '/api';
        $spa_dir  = 'domains/comerciocity.com/public_html/' . $e['carpeta'] . '/spa';

        $orden = [
            'printf %s "$HOME"',
            "umask 077 && mkdir -p '" . $parcial . "'",
            "if cd '" . $api_path . "'; then tar --warning=no-file-changed --anchored --exclude=./storage --exclude=./vendor -czf '" . $parcial . "/api.tar.gz' .",
            "gzip -t '" . $parcial . "/api.tar.gz'",
            "if cd '" . $spa_dir . "'; then tar --warning=no-file-changed -czf '" . $parcial . "/spa.tar.gz' .",
            "gzip -t '" . $parcial . "/spa.tar.gz'",
            "mysqldump --defaults-extra-file='" . $parcial . "/.cc-dump.cnf' --single-transaction --quick --no-tablespaces",
            "rm -f '" . $parcial . "/.cc-dump.cnf'",
            "{ gzip -dc '" . $parcial . "/base.sql.gz' || echo CC_GZIP_FALLO; } | tail -n 1; wc -c < '" . $parcial . "/base.sql.gz'",
            "mv '" . $parcial . "' '" . $dir . "'",
        ];

        $anterior = -1;
        foreach ($orden as $aguja) {
            $posicion = $this->ssh->posicion_de($aguja);
            $this->assertNotNull($posicion, 'No se mandó: ' . $aguja . "\n\nComandos:\n" . $this->ssh->todo());
            $this->assertGreaterThan($anterior, $posicion, 'Fuera de orden: ' . $aguja);
            $anterior = $posicion;
        }

        /* La verificación del dump es de UNA pasada: no hay `gzip -t` del dump. */
        $this->assertNull($this->ssh->posicion_de("gzip -t '" . $parcial . "/base.sql.gz'"));

        /* La contraseña: en el archivo de opciones sí (si no, mysqldump no entra)... */
        $cnf = $parcial . '/.cc-dump.cnf';
        $this->assertArrayHasKey($cnf, $this->sftp->archivos);
        $this->assertStringContainsString('[client]', $this->sftp->archivos[$cnf]);
        $this->assertStringContainsString('user="u767360347_user"', $this->sftp->archivos[$cnf]);
        $this->assertStringContainsString(
            "password='" . str_replace('\\', '\\\\', self::PASSWORD_DE_LA_BASE) . "'",
            $this->sftp->archivos[$cnf]
        );
        $this->assertSame(0600, $this->sftp->permisos[$cnf]);

        /* ...y en ningún comando ni en ninguna línea del log. */
        $this->assertStringNotContainsString('Clave-De-La-Base', $this->ssh->todo());
        $this->assertStringNotContainsString('Clave-De-La-Base', $this->logs($e['upgrade']));

        /* Nunca en una carpeta servida por web, y la línea del éxito nombra la ruta final. */
        $this->assertStringStartsWith(self::HOME_SHARED . '/cc-respaldos/', $dir);
        $this->assertStringNotContainsString('public_html', $dir);
        $this->assertStringContainsString(DeploymentService::MARCA_RESPALDO_OK . $dir . ' ', $this->logs($e['upgrade']));
    }

    /**
     * `--column-statistics=0` solo si el binario lo conoce, detectado guardando el `--help` en una
     * variable y mirándolo con `case`: nunca con `| grep -q`. Y sin `--routines` ni `--events`.
     */
    public function test_el_dump_detecta_column_statistics_sin_grep_y_sin_routines(): void
    {
        $e = $this->escenario();
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dump = $this->comandos_con('mysqldump --defaults-extra-file');
        $this->assertCount(1, $dump);
        $this->assertStringContainsString('CC_AYUDA=$(mysqldump --help 2>/dev/null); case "$CC_AYUDA" in *column-statistics*)', $dump[0]);
        $this->assertStringContainsString('$CC_CS', $dump[0]);
        $this->assertStringNotContainsString('grep', $dump[0]);
        $this->assertStringNotContainsString('--routines', $dump[0]);
        $this->assertStringNotContainsString('--events', $dump[0]);
    }

    /* ------------------------------------------------------------------------------------------
     | Tablas SIN datos en el respaldo (Servian, 16/10/2026)
     |
     | El MySQL del VPS corta con max_execution_time = 120 s el SELECT del mysqldump de una tabla
     | de varios GB (article_import_result_observations de Servian, 3,1 GB): Error 3024 y el upgrade
     | queda `failed` sin tocar nada. Lucas decidió dejar afuera los DATOS de esa tabla, por cliente,
     | con la estructura adentro. Sin entrada en la config, el comando es el de siempre.
     |----------------------------------------------------------------------------------------- */

    /** Un cliente SIN entrada en la config: el comando de dump es el de siempre, ni un carácter de más. */
    public function test_un_cliente_sin_tablas_sin_datos_vuelca_la_base_entera_como_siempre(): void
    {
        config(['services.deploy.respaldo_tablas_sin_datos' => [999999 => ['article_import_result_observations']]]);

        $e = $this->escenario();
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dump = $this->comandos_con('mysqldump --defaults-extra-file');
        $this->assertCount(1, $dump);
        $this->assertSame(1, substr_count($dump[0], 'mysqldump --defaults-extra-file'));
        $this->assertStringNotContainsString('--ignore-table', $dump[0]);
        $this->assertStringNotContainsString('--no-data', $dump[0]);
        $this->assertStringNotContainsString('CC_RC', $dump[0]);
        $this->assertStringContainsString("'u767360347_respaldo'; echo \$? > '", $dump[0]);
        $this->assertStringNotContainsString('SIN los datos', $this->logs($e['upgrade']));
    }

    /**
     * 🔴 El cliente con entrada: la base entera MENOS esa tabla (`--ignore-table` con `base.tabla`),
     * y a continuación SOLO la estructura de la tabla (`--no-data`), en el mismo comando y con las
     * mismas opciones. El código de salida junta el de las dos invocaciones. La contraseña sigue
     * fuera de todo comando, y la línea del éxito avisa que la tabla va sin datos.
     */
    public function test_un_cliente_con_tabla_sin_datos_vuelca_la_base_menos_esa_tabla_y_despues_su_estructura(): void
    {
        $e = $this->escenario();
        config(['services.deploy.respaldo_tablas_sin_datos' => [(int) $e['client']->id => [
            'article_import_result_observations',
            'otra_tabla_enorme',
        ]]]);
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dump = $this->comandos_con('mysqldump --defaults-extra-file');
        $this->assertCount(1, $dump, 'Los dos volcados van en UN comando: un solo stream hacia gzip.');
        $comando = $dump[0];
        $cnf     = "'" . $this->dir_del_respaldo($e) . ".parcial/.cc-dump.cnf'";

        $this->assertSame(2, substr_count($comando, 'mysqldump --defaults-extra-file=' . $cnf));
        $this->assertSame(
            2,
            substr_count($comando, ' --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 $CC_CS '),
            'Las dos invocaciones llevan las mismas opciones.'
        );

        /* Primero: la base entera sin esas tablas. */
        $this->assertStringContainsString(
            "--ignore-table='u767360347_respaldo.article_import_result_observations' "
            . "--ignore-table='u767360347_respaldo.otra_tabla_enorme' 'u767360347_respaldo' || CC_RC=\$?; ",
            $comando
        );
        /* Después: solo la estructura de ellas. */
        $this->assertStringContainsString(
            "--no-data --skip-add-drop-table 'u767360347_respaldo' 'article_import_result_observations' 'otra_tabla_enorme' || CC_RC=\$?; "
            . "echo \$CC_RC > '",
            $comando
        );
        $this->assertLessThan(
            strpos($comando, '--no-data'),
            strpos($comando, '--ignore-table'),
            'El volcado de la base va antes que el de la estructura.'
        );
        $this->assertStringContainsString('CC_AYUDA=$(mysqldump --help 2>/dev/null); case', $comando);
        $this->assertStringContainsString('CC_RC=0; mysqldump --defaults-extra-file=', $comando);
        $this->assertStringContainsString('| gzip > ', $comando);

        /* Un comentario en el dump avisa que esas tablas van sin datos (y sin comillas sueltas). */
        $this->assertStringContainsString(
            "echo '-- ComercioCity: los DATOS de article_import_result_observations, otra_tabla_enorme NO estan en este respaldo",
            $comando
        );

        /* La contraseña sigue sin aparecer en ningún comando ni log. */
        $this->assertStringNotContainsString('Clave-De-La-Base', $this->ssh->todo());
        $this->assertStringNotContainsString('Clave-De-La-Base', $this->logs($e['upgrade']));

        /* La línea del éxito y la del arranque lo dicen. */
        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString(DeploymentService::MARCA_RESPALDO_OK, $logs);
        $this->assertStringContainsString('SIN los datos de article_import_result_observations, otra_tabla_enorme (solo su estructura).', $logs);
        $this->assertStringContainsString('excepción configurada para este cliente', $logs);
    }

    /**
     * 🔴 Lado shell: el comando generado se CORRE en un `sh` de verdad, con un `mysqldump` falso en el PATH que
     * contesta el código de salida que se le pide, y se mira la última línea de la salida (la que lee el pipeline):
     * tiene que ser el código de la invocación que falló, en cualquiera de las dos, y 0 solo si las dos andan.
     * También se comprueba qué le llegó a cada invocación (`--ignore-table` a la primera; `--no-data
     * --skip-add-drop-table` y la tabla a la segunda) y que el dump comprimido quedó armado.
     *
     * Se saltea si no hay `sh` + `gzip` (en Windows, los de Git for Windows).
     */
    public function test_el_comando_con_tabla_sin_datos_corre_en_un_shell_y_propaga_el_fallo_de_cualquiera_de_las_dos_invocaciones(): void
    {
        $git_bin = 'C:\\Program Files\\Git\\usr\\bin';
        $sh      = is_file($git_bin . '\\sh.exe') ? $git_bin . '\\sh.exe' : 'sh';
        $sep     = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';

        $e = $this->escenario();
        config(['services.deploy.respaldo_tablas_sin_datos' => [(int) $e['client']->id => ['article_import_result_observations']]]);
        $this->servidor_sano();
        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dump = $this->comandos_con('mysqldump --defaults-extra-file');
        $this->assertCount(1, $dump);

        /* Un home de mentira en una carpeta temporal, con el `.parcial` ya creado (en producción lo crea
           el `mkdir` de la etapa, que acá corrió contra el doble). */
        $tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/cc-dump-' . uniqid();
        $parcial_real = $this->dir_del_respaldo($e) . '.parcial';
        $parcial      = str_replace(self::HOME_SHARED, $tmp, $parcial_real);
        $comando      = str_replace(self::HOME_SHARED, $tmp, $dump[0]);
        mkdir($parcial, 0777, true);
        mkdir($tmp . '/bin', 0777, true);

        /* `mysqldump` falso: `--help` contesta que conoce column-statistics; si no, registra sus argumentos,
           escribe un trozo de dump con su footer y sale con el código pedido por entorno (uno por invocación,
           según lleve `--no-data` o no). */
        file_put_contents($tmp . '/bin/mysqldump', <<<'SH'
#!/bin/sh
for a in "$@"; do
  if [ "$a" = "--help" ]; then echo "  --column-statistics"; exit 0; fi
done
modo=primera; rc=$CC_FALSO_RC_PRIMERA
for a in "$@"; do
  if [ "$a" = "--no-data" ]; then modo=segunda; rc=$CC_FALSO_RC_SEGUNDA; fi
done
echo "$modo: $*" >> "$CC_FALSO_LOG"
echo "-- cuerpo de la $modo invocacion"
echo "-- Dump completed"
exit $rc
SH
        );

        $escenarios = [
            // [rc primera, rc segunda, última línea esperada, "-- Dump completed" al final]
            [0, 0, '0'],
            [2, 0, '2'],
            [0, 3, '3'],
            [2, 3, '3'],
        ];

        foreach ($escenarios as $caso) {
            list($rc_primera, $rc_segunda, $esperado) = $caso;
            $log = $tmp . '/args-' . $rc_primera . $rc_segunda . '.log';

            $entorno = getenv();
            $clave_path = 'PATH';
            foreach (array_keys($entorno) as $k) {
                if (strtoupper($k) === 'PATH') {
                    $clave_path = $k;
                }
            }
            $entorno[$clave_path]        = $tmp . '/bin' . $sep . (is_dir($git_bin) ? $git_bin . $sep : '') . (isset($entorno[$clave_path]) ? $entorno[$clave_path] : '');
            $entorno['CC_FALSO_RC_PRIMERA'] = (string) $rc_primera;
            $entorno['CC_FALSO_RC_SEGUNDA'] = (string) $rc_segunda;
            $entorno['CC_FALSO_LOG']        = $log;

            $proceso = @proc_open([$sh, '-c', $comando], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $entorno);
            if (! is_resource($proceso)) {
                $this->markTestSkipped('No hay un sh para correr el comando.');
            }
            $salida = stream_get_contents($pipes[1]);
            $error  = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proceso);

            if (trim($salida) === '' && stripos($error, 'gzip') !== false) {
                $this->markTestSkipped('No hay gzip para correr el comando: ' . $error);
            }

            $lineas = preg_split('/\r?\n/', trim($salida));
            $this->assertSame($esperado, trim((string) end($lineas)), 'rc ' . $rc_primera . '/' . $rc_segunda . ". Salida: $salida $error");

            /* Las dos invocaciones corrieron SIEMPRE (la primera que falla no corta a la segunda). */
            $args = file($log, FILE_IGNORE_NEW_LINES);
            $this->assertCount(2, $args, 'Tenían que correr las dos invocaciones.');
            $this->assertStringStartsWith('primera: --defaults-extra-file=', $args[0]);
            $this->assertStringContainsString("--ignore-table=u767360347_respaldo.article_import_result_observations", $args[0]);
            $this->assertStringNotContainsString('--no-data', $args[0]);
            $this->assertStringStartsWith('segunda: --defaults-extra-file=', $args[1]);
            $this->assertStringContainsString('--no-data --skip-add-drop-table u767360347_respaldo article_import_result_observations', $args[1]);
            $this->assertStringNotContainsString('--ignore-table', $args[1]);

            /* El dump comprimido: los dos trozos, en orden, con el aviso en el medio y el footer al final. */
            $sql = gzdecode(file_get_contents($parcial . '/base.sql.gz'));
            $this->assertSame(
                ['-- cuerpo de la primera invocacion', '-- Dump completed', '-- ComercioCity', '-- cuerpo de la segunda invocacion', '-- Dump completed'],
                array_map(function ($linea) {
                    return strpos($linea, '-- ComercioCity') === 0 ? '-- ComercioCity' : $linea;
                }, preg_split('/\r?\n/', trim($sql)))
            );
            @unlink($parcial . '/base.sql.gz');
            @unlink($parcial . '/.dump-exit');
        }

        /* Limpieza de la carpeta temporal. */
        @unlink($tmp . '/bin/mysqldump');
        foreach (glob($tmp . '/args-*.log') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($tmp . '/bin');
        @rmdir($parcial);
        @rmdir(dirname($parcial));
        @rmdir(dirname(dirname($parcial)));
        @rmdir(dirname(dirname(dirname($parcial))));
        @rmdir($tmp);
    }

    /**
     * Lado PHP: si el stream de salida termina con un código distinto de 0, el respaldo falla igual que siempre.
     * El lado shell (que `|| CC_RC=$?` junte el código de las dos invocaciones) lo cubre el test que corre el
     * comando generado en un `sh` de verdad.
     */
    public function test_con_tabla_sin_datos_un_codigo_de_salida_distinto_de_cero_hace_fallar_el_respaldo_lado_php(): void
    {
        $e = $this->escenario();
        config(['services.deploy.respaldo_tablas_sin_datos' => [(int) $e['client']->id => ['article_import_result_observations']]]);
        /* Antes que servidor_sano(): el doble contesta con la primera regla que coincide. */
        $this->ssh->responder('mysqldump', "mysqldump: Couldn't find table: \"article_import_result_observations\"\n2");
        $this->servidor_sano();

        try {
            $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
            $this->fail('El respaldo tenía que fallar.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('mysqldump no terminó bien', $error->getMessage());
            $this->assertStringContainsString('No se tocó nada del cliente', $error->getMessage());
        }

        /* El archivo de credenciales se borra aunque el dump haya fallado. */
        $this->assertNotEmpty($this->comandos_con("rm -f '" . $this->dir_del_respaldo($e) . ".parcial/.cc-dump.cnf'"));
        $this->assertSame([], $this->comandos_con('mv '));
    }

    /**
     * Un nombre de tabla inválido en la config frena el respaldo ANTES de escribir el archivo de
     * credenciales y de mandar ningún mysqldump: el nombre termina entre comillas en un comando remoto.
     */
    public function test_un_nombre_de_tabla_invalido_en_la_config_frena_el_respaldo_sin_dumpear(): void
    {
        $e = $this->escenario();
        config(['services.deploy.respaldo_tablas_sin_datos' => [(int) $e['client']->id => ["tabla'; rm -rf /; echo '"]]]);
        $this->servidor_sano();

        try {
            $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
            $this->fail('El respaldo tenía que frenar.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('respaldo_tablas_sin_datos', $error->getMessage());
            $this->assertStringContainsString('No se tocó nada del cliente', $error->getMessage());
        }

        $this->assertSame([], $this->comandos_con('mysqldump --defaults-extra-file'));
        $this->assertArrayNotHasKey($this->dir_del_respaldo($e) . '.parcial/.cc-dump.cnf', $this->sftp->archivos);
        $this->assertStringNotContainsString('rm -rf /', $this->ssh->todo());
    }

    /** Un nombre de base que empieza con `-` se rechaza: mysqldump lo leería como una opción. */
    public function test_un_nombre_de_base_que_empieza_con_guion_se_rechaza(): void
    {
        $e = $this->escenario();
        $this->servidor_sano();
        $this->env->envs[$e['principal']->id] = str_replace(
            'DB_DATABASE=u767360347_respaldo',
            'DB_DATABASE=-respaldo',
            $this->env->envs[$e['principal']->id]
        );

        $error = null;
        try {
            $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error);
        $this->assertSame([], $this->comandos_con('mysqldump'));
    }

    /**
     * `tar` con archivos que cambiaron mientras se leían (exit 1, el sistema factura en vivo) se
     * acepta; un exit 2 hace fallar el respaldo.
     */
    public function test_tar_con_archivos_que_cambiaron_se_acepta_y_un_error_de_verdad_no(): void
    {
        $e = $this->escenario();
        $this->ssh->responder('tar --warning=no-file-changed', "tar: ./public/afip/ws/x.xml: file changed as we read it\nCC_TAR_EXIT=1");
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $this->assertStringContainsString('cambió mientras se leía', $this->logs($e['upgrade']));
        $this->assertNotNull($this->ssh->posicion_de("mv '" . $this->dir_del_respaldo($e) . ".parcial'"));

        /* Exit 2: falla y no se renombra el parcial. */
        $this->ssh = new SshDelDeploymentFake();
        $otro      = $this->escenario();
        $this->ssh->responder('tar --warning=no-file-changed', "tar: no se puede abrir\nCC_TAR_EXIT=2");
        $this->servidor_sano();

        $error = null;
        try {
            $this->invocar($this->servicio($otro['upgrade']), 'step_backup_in_place');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error);
        $this->assertStringContainsString('salió con 2', $error->getMessage());
        $this->assertSame([], $this->comandos_con('mv '));
    }

    /** Un dump cortado (sin la marca de fin) o con el gzip roto hace fallar el respaldo. */
    public function test_un_dump_cortado_o_con_el_gzip_roto_hace_fallar_el_respaldo(): void
    {
        foreach (["INSERT INTO `articles` VALUES (1,\n524288", "CC_GZIP_FALLO\n524288"] as $cola) {
            $this->ssh = new SshDelDeploymentFake();
            $e         = $this->escenario();
            $this->ssh->responder('gzip -dc', $cola);
            $this->servidor_sano();

            $error = null;
            try {
                $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
            } catch (\Throwable $ex) {
                $error = $ex;
            }

            $this->assertNotNull($error, 'Pasó con: ' . $cola);
            $this->assertStringContainsString('Dump completed', $error->getMessage());
            $this->assertSame([], $this->comandos_con('mv '), 'Un respaldo que no se verificó no se renombra.');
        }
    }

    /** Si no se puede confirmar que el archivo de credenciales se borró, el respaldo falla. */
    public function test_si_no_se_confirma_el_borrado_de_las_credenciales_el_respaldo_falla(): void
    {
        $e = $this->escenario();
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('tar --warning=no-file-changed', 'CC_TAR_EXIT=0');
        $this->ssh->responder('gzip -t', '524288');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credenciales');

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
    }

    /** 🔴 Un home que cae adentro de una carpeta servida por web frena antes de crear nada. */
    public function test_el_respaldo_nunca_cae_en_una_carpeta_servida_por_web(): void
    {
        $e = $this->escenario();
        $this->servidor_sano('/home/u767360347/domains/comerciocity.com/public_html');

        $error = null;
        try {
            $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error);
        $this->assertStringContainsString('servida por web', $error->getMessage());
        $this->assertSame([], $this->comandos_con('mkdir -p'), 'Creó el directorio antes de validar la ruta.');
        $this->assertSame([], $this->comandos_con('mysqldump'));
    }

    /** Un home vacío o la raíz frenan antes de crear nada. */
    public function test_un_home_que_no_es_absoluto_frena_el_respaldo(): void
    {
        foreach (['', '/'] as $home) {
            $this->ssh = new SshDelDeploymentFake();
            $e         = $this->escenario();
            $this->ssh->responder('printf %s "$HOME"', $home);

            $error = null;
            try {
                $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
            } catch (\Throwable $ex) {
                $error = $ex;
            }

            $this->assertNotNull($error, 'Pasó con HOME=' . var_export($home, true));
            $this->assertSame([], $this->comandos_con('mkdir -p'));
        }
    }

    /* ==========================================================================================
     | 2) A prueba de reintentos
     |========================================================================================= */

    /**
     * 🔴 Si en el disco ya hay un respaldo completo de ESTE upgrade con la marca TOCADO (un intento
     * anterior que llegó a tocar el sistema vivo), se reusa: no se vuelve a respaldar un sistema que
     * puede estar a medio actualizar. La línea dice por qué.
     */
    public function test_un_respaldo_completo_de_este_upgrade_se_reusa_y_no_se_respalda_de_nuevo(): void
    {
        $e     = $this->escenario();
        $ruta  = $this->respaldo_en_disco($e);
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $this->assertSame([], $this->comandos_con('mysqldump'));
        $this->assertSame([], $this->comandos_con('tar --warning'));
        $this->assertSame([], $this->comandos_con('mkdir -p'));
        $this->assertSame([], $this->comandos_con('rm -rf'), 'Borró algo teniendo solo el respaldo de este upgrade.');
        $this->assertStringContainsString(DeploymentService::MARCA_RESPALDO_OK . $ruta . ' — se REUSA', $this->logs($e['upgrade']));
        $this->assertStringContainsString('marca ' . DeploymentService::ARCHIVO_TOCADO, $this->logs($e['upgrade']));
    }

    /**
     * 🔴 B. Un respaldo de este upgrade SIN la marca TOCADO (el intento anterior no llegó a tocar
     * nada, por ejemplo falló compilando o preparando la API) NO se reusa: el estado vivo de ahora es
     * el bueno —ese respaldo puede ser de hace días— y se respalda de nuevo. El viejo no se borra (es
     * de este upgrade: queda para la retención).
     */
    public function test_un_respaldo_de_este_upgrade_sin_tocar_el_sistema_no_se_reusa(): void
    {
        $e    = $this->escenario();
        $ruta = $this->respaldo_en_disco($e, [], false);
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $nuevo = $this->dir_del_respaldo($e);
        $this->assertNotEmpty($this->comandos_con('mysqldump --defaults-extra-file'), 'Tenía que respaldar de nuevo.');
        $this->assertNotNull($this->ssh->posicion_de("mv '" . $nuevo . ".parcial' '" . $nuevo . "'"));
        $this->assertSame([], $this->comandos_con("rm -rf -- '" . $ruta . "'"), 'Se borró el respaldo viejo de este upgrade.');

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('no llegó a tocar el sistema vivo', $logs);
        $this->assertStringContainsString(DeploymentService::MARCA_RESPALDO_OK . $nuevo . ' ', $logs);
        $this->assertStringNotContainsString('se REUSA', $logs);
    }

    /**
     * B. Sin la marca TOCADO pero con el sistema EN MANTENIMIENTO (`storage/framework/down`): un intento
     * anterior ya lo tocó, y el respaldo de este upgrade se reusa diciendo por qué.
     */
    public function test_sin_la_marca_pero_en_mantenimiento_el_respaldo_se_reusa(): void
    {
        $e = $this->escenario();
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');
        $ruta = $this->respaldo_en_disco($e, [], false);
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $this->assertSame([], $this->comandos_con('mysqldump'));
        $this->assertStringContainsString(
            DeploymentService::MARCA_RESPALDO_OK . $ruta . ' — se REUSA el respaldo de este upgrade porque el sistema está en mantenimiento',
            $this->logs($e['upgrade'])
        );
    }

    /**
     * 🔴 A. La marca de sistema tocado: en el lugar, la escribe upload_spa (archivo TOCADO en el
     * respaldo y la línea "Sistema vivo tocado: upload_spa") JUSTO ANTES del primer comando que
     * modifica el sistema vivo (vaciar el SPA), y una sola vez por corrida.
     */
    public function test_la_marca_de_sistema_tocado_va_antes_del_primer_comando_que_lo_modifica(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $ruta = $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);

        $this->assertFalse(DeploymentService::sistema_vivo_tocado_segun_los_logs((int) $e['upgrade']->id));

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));

        $marca = $this->ssh->posicion_de("> '" . $ruta . '/' . DeploymentService::ARCHIVO_TOCADO . "'");
        $this->assertNotNull($marca, $this->ssh->todo());
        $this->assertLessThan($this->ssh->posicion_de('find . -mindepth 1 -delete'), $marca);
        $this->assertCount(1, $this->comandos_con("> '" . $ruta . '/' . DeploymentService::ARCHIVO_TOCADO . "'"), 'La marca se escribe una sola vez.');

        $this->assertTrue(DeploymentService::sistema_vivo_tocado_segun_los_logs((int) $e['upgrade']->id));
        $this->assertSame(
            1,
            DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
                ->where('line', 'like', DeploymentService::MARCA_SISTEMA_TOCADO . 'upload_spa%')
                ->count()
        );

        @unlink(storage_path('app/deployments/dist_' . $e['upgrade']->uuid . '.zip'));
    }

    /**
     * 🔴 Start limpio después de un fallo en el lugar: el pipeline entero, desde compile_spa, reusa el
     * respaldo del intento anterior, no lo borra, y termina con el sistema arriba.
     */
    public function test_un_start_limpio_despues_de_un_fallo_reusa_el_respaldo_y_no_lo_borra(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to'], true);
        $ruta = $this->respaldo_en_disco($e, ['20260901-020000-upgrade-11']);
        $this->servidor_sano();

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        $this->assertSame([], $this->comandos_con('mysqldump'));
        $this->assertSame([], $this->comandos_con("rm -rf -- '" . $ruta . "'"));
        $this->assertStringContainsString('se REUSA', $this->logs($e['upgrade']));
        $this->assertNotNull($this->ssh->posicion_de('php artisan up'));
        $this->assertSame('paused', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /** Los `.parcial` de intentos cortados se borran antes de respaldar. */
    public function test_los_parciales_de_otros_intentos_se_borran(): void
    {
        $e = $this->escenario();
        $this->ssh->responder('ls -1', "20261013-020000-upgrade-" . $e['upgrade']->id . ".parcial\n20261012-020000-upgrade-9.parcial");
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $base = $this->base($e);
        $this->assertNotEmpty($this->comandos_con("rm -rf -- '" . $base . "/20261013-020000-upgrade-" . $e['upgrade']->id . ".parcial'"));
        $this->assertNotEmpty($this->comandos_con("rm -rf -- '" . $base . "/20261012-020000-upgrade-9.parcial'"));
        $this->assertLessThan(
            $this->ssh->posicion_de('mkdir -p'),
            $this->ssh->posicion_de('rm -rf'),
            'Los parciales se borran antes de empezar el respaldo nuevo.'
        );
    }

    /**
     * 🔴 Retención por upgrade, con dos reintentos: nunca se borra el respaldo de este upgrade (aunque
     * sea el más viejo de la carpeta), de los demás queda solo el más reciente, y nunca se toca una
     * entrada que no tenga nombre de respaldo.
     */
    public function test_la_retencion_no_borra_el_de_este_upgrade_y_deja_uno_de_los_demas(): void
    {
        $e = $this->escenario();

        $propio = '20250101-020000-upgrade-' . $e['upgrade']->id;
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('ls -1', implode("\n", [
            $propio,
            '20260901-020000-upgrade-11',
            '20260915-020000-upgrade-12',
            '20261001-020000-upgrade-13',
            'notas-de-lucas',
        ]));
        $this->ssh->responder('CC_RESPALDO_COMPLETO', "CC_RESPALDO_COMPLETO\nCC_RESPALDO_TOCADO");

        /* Dos reintentos: los dos reusan el respaldo de este upgrade (que ya tocó el sistema). */
        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $borrados = implode("\n", $this->comandos_con('rm -rf'));
        $base     = $this->base($e);

        $this->assertStringContainsString("rm -rf -- '" . $base . "/20260901-020000-upgrade-11'", $borrados);
        $this->assertStringContainsString("rm -rf -- '" . $base . "/20260915-020000-upgrade-12'", $borrados);
        $this->assertStringNotContainsString('20261001-020000-upgrade-13', $borrados, 'Se borró el del upgrade anterior.');
        $this->assertStringNotContainsString($propio, $borrados, 'Se borró el respaldo de este upgrade.');
        $this->assertStringNotContainsString('notas-de-lucas', $borrados);
        $this->assertSame([], $this->comandos_con('mysqldump'));
    }

    /** 🔴 Si el respaldo falla, no se ejecuta upload_spa ni nada que toque al cliente. */
    public function test_si_el_respaldo_falla_no_se_ejecuta_upload_spa(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $this->ssh->responder('mysqldump', "mysqldump: Got error: 1045: Access denied\n2");
        $this->servidor_sano();

        $error = $this->correr($this->servicio($e['upgrade']), 'backup_in_place');

        $this->assertNotNull($error);
        $this->assertStringContainsString('mysqldump', $error->getMessage());
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertNotEmpty($this->comandos_con("rm -f '" . $this->dir_del_respaldo($e) . ".parcial/.cc-dump.cnf'"));

        $this->assertSame(0, DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)->where('step', 'upload_spa')->count());
        $this->assertSame([], $this->comandos_con('find . -mindepth 1 -delete'));
        $this->assertSame([], $this->comandos_con('artisan down'));
        foreach ($this->sftp->operaciones as $operacion) {
            $this->assertStringNotContainsString('.zip', $operacion, 'Se subió un zip al hosting con el respaldo fallado.');
        }
        $this->assertStringNotContainsString('QUEDÓ EN MANTENIMIENTO', $this->logs($e['upgrade']));
        $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'backup_in_place', $this->logs($e['upgrade']));
    }

    /**
     * 🔴 Reanudar desde una etapa que pisa algo sin un respaldo completo EN EL DISCO frena antes de
     * tocar nada (aunque los logs no digan nada: un start limpio los borra), y la etapa que se
     * recomienda es la del respaldo.
     */
    public function test_reanudar_despues_del_respaldo_sin_respaldo_en_disco_frena_sin_tocar_nada(): void
    {
        $e = $this->escenario();
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');

        $this->assertNotNull($error);
        $this->assertStringContainsString('resume_from_step=backup_in_place', $error->getMessage());
        $this->assertSame([], $this->comandos_con('find . -mindepth 1'));
        $this->assertSame([], $this->sftp->operaciones);
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'backup_in_place', $this->logs($e['upgrade']));
    }

    /* ==========================================================================================
     | 3) El orden en el lugar y el mantenimiento
     |========================================================================================= */

    /**
     * 🔴 En el lugar, de upload_spa a la pausa: la API se sube ANTES de tocar el SPA; `artisan down`
     * antes del unzip; migraciones y seeders adentro del mantenimiento; `artisan up` después del
     * reinicio del worker; los COMANDOS después del up (tercera tanda); la pausa marca los crons sola.
     */
    public function test_en_el_lugar_el_orden_completo_con_seeders_adentro_del_mantenimiento(): void
    {
        $e = $this->escenario();
        $this->seeder_y_comando($e);
        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));

        /* La API sube ANTES que el SPA (SFTP) y antes de vaciar el SPA vivo. */
        $subidas = $this->sftp->operaciones;
        $api_zip = null;
        $spa_zip = null;
        foreach ($subidas as $indice => $operacion) {
            if ($api_zip === null && strpos($operacion, 'put ') === 0 && strpos($operacion, '/api/api_') !== false) {
                $api_zip = $indice;
            }
            if ($spa_zip === null && strpos($operacion, 'put ') === 0 && strpos($operacion, '/spa/dist.zip') !== false) {
                $spa_zip = $indice;
            }
        }
        $this->assertNotNull($api_zip, implode("\n", $subidas));
        $this->assertNotNull($spa_zip, implode("\n", $subidas));
        $this->assertLessThan($spa_zip, $api_zip, 'La API tiene que subir antes que el SPA.');

        $vacia_spa = $this->ssh->posicion_de('find . -mindepth 1 -delete');
        $down      = $this->ssh->posicion_de('php artisan down');
        $unzip     = $this->ssh->posicion_de('unzip -o api_');
        $migrate   = $this->ssh->posicion_de('php artisan migrate --force');
        $seeder    = $this->ssh->posicion_de('db:seed');
        $comando   = $this->ssh->posicion_de('comando:de-la-ventana');
        $up        = $this->ssh->posicion_de('php artisan up');

        foreach (compact('vacia_spa', 'down', 'unzip', 'migrate', 'seeder', 'comando', 'up') as $nombre => $posicion) {
            $this->assertNotNull($posicion, 'Falta ' . $nombre . "\n" . $this->ssh->todo());
        }

        $this->assertLessThan($down, $vacia_spa, $this->ssh->todo());
        $this->assertLessThan($unzip, $down);
        $this->assertLessThan($seeder, $migrate);
        $this->assertLessThan($up, $seeder, 'Los seeders corren ANTES del up.');
        $this->assertLessThan($comando, $up, 'Los comandos corren DESPUÉS del up, con el sistema arriba.');
        $this->assertCount(1, $this->comandos_con('php artisan up'));

        /* Ninguna etapa entre el down y el up sale por HTTP al cliente (GitHub es antes). */
        Http::assertNotSent(function ($request) use ($e) {
            return strpos((string) $request->url(), $e['carpeta']) !== false;
        });

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('paused', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->crons_supervisor_at);
        $this->assertNotNull($upgrade->seeders_ejecutados_at);
        $this->assertNotNull($upgrade->comandos_ejecutados_at);
        $this->assertSame('exitoso', UpdateSeeder::where('client_version_upgrade_id', $upgrade->id)->value('status'));
        $this->assertSame('exitoso', UpdateCommand::where('client_version_upgrade_id', $upgrade->id)->value('status'));

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('EN MANTENIMIENTO desde ahora', $logs);
        $this->assertStringContainsString('NO lo levantes a mano sin terminar el despliegue', $logs);
        $this->assertStringNotContainsString('QUEDÓ', $logs);

        /* Y el post-cierre del en el lugar no vuelve a correr nada: un no-op hasta paused_post_tasks. */
        $comandos_antes = count($this->ssh->comandos);
        $error          = $this->correr($this->servicio($e['upgrade']), 'run_seeders');
        $this->assertNull($error, $error === null ? '' : $error->getMessage());

        $nuevos = implode("\n", array_slice($this->ssh->comandos, $comandos_antes));
        $this->assertStringNotContainsString('db:seed', $nuevos);
        $this->assertStringNotContainsString('comando:de-la-ventana', $nuevos);
        $this->assertSame('paused_post_tasks', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /** En una rotación hacia el 2 no hay respaldo, ni down, ni up, ni crons marcados, ni seeders. */
    public function test_en_una_rotacion_al_segundo_no_hay_respaldo_ni_mantenimiento(): void
    {
        $e = $this->escenario('principal', 'segundo');
        $this->seeder_y_comando($e);
        $this->artefactos_en_github($e['to']);

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
        $this->assertSame([], $this->ssh->comandos, 'Una rotación al 2 no se respalda.');

        $this->servicio($e['upgrade'])->run('upload_api');

        $this->assertNotNull($this->ssh->posicion_de('unzip -o api_'), "No corrió el tramo.\n" . $this->ssh->todo());
        $this->assertNull($this->ssh->posicion_de('artisan down'));
        $this->assertNull($this->ssh->posicion_de('artisan up'));
        $this->assertNull($this->ssh->posicion_de('db:seed'), 'En una rotación los seeders son post-cierre.');

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('paused', (string) $upgrade->deployment_status);
        $this->assertNull($upgrade->crons_supervisor_at, 'En una rotación los crons los mueve una persona.');
    }

    /**
     * La ÚLTIMA ROTACIÓN hacia el principal (cliente que atiende desde el 2) SÍ se respalda: las
     * migraciones pegan sobre la misma base viva. Pero sin mantenimiento.
     */
    public function test_la_ultima_rotacion_al_principal_se_respalda_sin_mantenimiento(): void
    {
        $e = $this->escenario('segundo', 'principal');
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $this->assertNotEmpty($this->comandos_con('mysqldump --defaults-extra-file'));
        $this->assertStringContainsString('Última rotación al frente principal', $this->logs($e['upgrade']));
        $this->assertSame([], $this->comandos_con('artisan down'));
    }

    /**
     * 🔴 Falla en upload_api DESPUÉS del down: la línea dice que quedó en mantenimiento y recomienda
     * reanudar desde upload_api (no desde run_migrations, que migraría sobre código a medio
     * descomprimir).
     */
    public function test_si_falla_upload_api_despues_del_down_la_linea_recomienda_upload_api(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);
        $this->ssh->responder('unzip -o api_', 'error: zip roto', 1);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_api');

        $this->assertNotNull($error);
        $this->assertNotNull($this->ssh->posicion_de('php artisan down'));
        $this->assertNull($this->ssh->posicion_de('php artisan up'));

        $linea = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('line', 'like', '%QUEDÓ EN MANTENIMIENTO%')
            ->value('line');

        $this->assertNotNull($linea, $this->logs($e['upgrade']));
        $this->assertStringContainsString('resume_from_step=upload_api', $linea);
        $this->assertStringNotContainsString('resume_from_step=run_migrations', $linea);
        $this->assertStringContainsString('NO lo levantes a mano', $linea);
        $this->assertStringContainsString($this->base($e) . '/20261013-020000-upgrade-' . $e['upgrade']->id, $linea, 'La línea nombra el respaldo del disco.');
        $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'upload_api', $this->logs($e['upgrade']));
    }

    /**
     * 🔴 Falla de GitHub preparando la API, en upload_spa: el SPA vivo no se toca (ni se vacía ni se
     * sube nada), y la línea lo dice.
     */
    public function test_si_github_falla_antes_del_spa_el_spa_vivo_no_se_toca(): void
    {
        $e = $this->escenario();
        $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');

        $this->assertNotNull($error);
        $this->assertSame([], $this->comandos_con('find . -mindepth 1 -delete'));
        $this->assertSame([], $this->sftp->operaciones, 'Se subió algo con GitHub caído.');
        $this->assertSame([], $this->comandos_con('artisan down'));
        $this->assertStringContainsString('ANTES de tocar el SPA', $this->logs($e['upgrade']));
        $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'upload_spa', $this->logs($e['upgrade']));

        @unlink(storage_path('app/deployments/dist_' . $e['upgrade']->uuid . '.zip'));
    }

    /**
     * Falla entre el SPA nuevo y el `down` (el `down` mismo falla): queda el SPA nuevo con la API
     * vieja, y una línea propia lo dice y manda a reanudar desde upload_api.
     */
    public function test_si_falla_entre_el_spa_y_el_down_queda_dicho_spa_nuevo_con_api_vieja(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);
        $this->ssh->responder('php artisan down', 'Failed to enter maintenance mode.', 1);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');

        $this->assertNotNull($error);
        $this->assertNotNull($this->ssh->posicion_de('find . -mindepth 1 -delete'), 'El SPA tenía que haberse desplegado.');
        $this->assertNull($this->ssh->posicion_de('unzip -o api_'));

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('QUEDÓ EL SPA NUEVO CON LA API VIEJA', $logs);
        $this->assertStringContainsString('resume_from_step=upload_api', $logs);
        $this->assertStringNotContainsString('QUEDÓ EN MANTENIMIENTO', $logs);
    }

    /**
     * 🔴 Si falla una migración (adentro del mantenimiento), la línea dice que quedó en mantenimiento,
     * recomienda reanudar desde run_migrations y el up no se manda.
     */
    public function test_si_falla_una_migracion_queda_en_mantenimiento_y_se_reanuda_desde_ahi(): void
    {
        $e = $this->escenario();
        $this->respaldo_en_disco($e);
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');
        $this->ssh->responder('php artisan migrate --force', 'SQLSTATE[42S01]: Base table or view already exists', 1);

        $error = $this->correr($this->servicio($e['upgrade']), 'run_migrations');

        $this->assertNotNull($error);
        $this->assertNull($this->ssh->posicion_de('php artisan up'), 'Con las migraciones fallidas no se levanta el sistema.');

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('YA ESTÁ EN MANTENIMIENTO al arrancar', $logs);
        $this->assertStringContainsString('QUEDÓ EN MANTENIMIENTO', $logs);
        $this->assertStringContainsString('resume_from_step=run_migrations', $logs);
    }

    /** Reanudar los seeders adentro de la ventana (con el prefijo) termina con el sistema arriba. */
    public function test_reanudar_los_seeders_adentro_del_mantenimiento_termina_con_el_up(): void
    {
        $e = $this->escenario();
        $this->seeder_y_comando($e);
        $this->respaldo_en_disco($e);

        $error = $this->correr($this->servicio($e['upgrade']), DeploymentService::PREFIJO_REANUDACION_EN_MANTENIMIENTO . 'run_seeders');
        $this->assertNull($error, $error === null ? '' : $error->getMessage());

        $this->assertLessThan($this->ssh->posicion_de('php artisan up'), $this->ssh->posicion_de('db:seed'));
        $this->assertSame('paused', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /**
     * 🔴 Cada etapa que arranca con el sistema en mantenimiento deja su "Etapa en curso" (desde el
     * `down` adentro de upload_api hasta el `up` del final de restart_queue_workers), y ninguna antes
     * del `down` ni después del `up`. Es lo que queda si el job muere por timeout adentro de la
     * ventana; con el `up` hecho, la ventana se cerró y no hay etapa en curso que reanudar.
     */
    public function test_cada_etapa_adentro_del_mantenimiento_deja_su_etapa_en_curso(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_api');
        $this->assertNull($error, $error === null ? '' : $error->getMessage());

        $etapas = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('line', 'like', DeploymentService::MARCA_ETAPA_EN_CURSO . '%')
            ->orderBy('id')
            ->pluck('step')
            ->all();

        $this->assertSame(
            ['upload_api', 'sync_env_keys', 'sync_pusher_template', 'run_migrations', 'run_seeders',
                'restart_queue_workers'],
            $etapas
        );
        $this->assertNull(DeploymentService::ultima_etapa_en_curso_en_los_logs((int) $e['upgrade']->id));
        $this->assertStringContainsString(DeploymentService::MARCA_FUERA_DE_MANTENIMIENTO, $this->logs($e['upgrade']));

        /* La de upload_api va DESPUÉS del down, no antes. */
        $logs = $this->logs($e['upgrade']);
        $this->assertLessThan(
            strpos($logs, DeploymentService::MARCA_ETAPA_EN_CURSO . 'upload_api'),
            strpos($logs, 'EN MANTENIMIENTO desde ahora')
        );
    }

    /**
     * Si el intento anterior murió sin dejar la etapa que falló (timeout) y el sistema quedó abajo, la
     * corrida siguiente lo dice nombrando la última "Etapa en curso".
     */
    public function test_ya_en_mantenimiento_nombra_la_ultima_etapa_en_curso(): void
    {
        $e = $this->escenario();
        $this->respaldo_en_disco($e);
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');

        DeploymentLog::create([
            'client_version_upgrade_id' => $e['upgrade']->id,
            'step'                      => 'run_seeders',
            'level'                     => 'info',
            'line'                      => DeploymentService::MARCA_ETAPA_EN_CURSO . 'run_seeders (con el sistema del cliente en mantenimiento).',
            'created_at'                => now(),
        ]);

        $this->correr($this->servicio($e['upgrade']), DeploymentService::PREFIJO_REANUDACION_EN_MANTENIMIENTO . 'run_seeders');

        $linea = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('line', 'like', '%YA ESTÁ EN MANTENIMIENTO%')
            ->value('line');

        $this->assertNotNull($linea);
        $this->assertStringContainsString('La última etapa que arrancó con el sistema abajo fue run_seeders', $linea);
    }

    /* ==========================================================================================
     | 4) Lo demás que cambia en el lugar
     |========================================================================================= */

    /** En el VPS el respaldo va al home del usuario con el que entra (root) y la carpeta es el vps_path. */
    public function test_en_el_vps_el_respaldo_va_al_home_del_usuario_con_el_que_entra(): void
    {
        $e = $this->escenario('principal', 'principal', 'vps');
        $this->servidor_sano('/root');

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dir = '/root/cc-respaldos/' . $e['carpeta'] . '/20261014-020000-upgrade-' . $e['upgrade']->id;
        $this->assertNotNull($this->ssh->posicion_de("umask 077 && mkdir -p '" . $dir . ".parcial'"));
        $this->assertNotNull($this->ssh->posicion_de("if cd '/home/api-" . $e['carpeta'] . "/empresa-api'; then tar"));
        $this->assertNotNull($this->ssh->posicion_de("if cd '/home/" . $e['carpeta'] . '/htdocs/' . $e['carpeta'] . ".comerciocity.com'; then tar"));
    }

    /**
     * En el VPS, el `up` (al final de restart_queue_workers) marca vps_supervisor_moved_at y no
     * crons_supervisor_at; la pausa ya no vuelve a levantar nada.
     */
    public function test_en_el_vps_el_up_marca_el_supervisor_y_la_pausa_no_levanta_de_nuevo(): void
    {
        $e = $this->escenario('principal', 'principal', 'vps');
        $this->respaldo_en_disco($e);

        $error = $this->correr($this->servicio($e['upgrade']), 'restart_queue_workers');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));

        $upgrade = $e['upgrade']->fresh();
        /* El `up` apunta a la carpeta del sitio y, en el VPS, corre como su usuario (no como root). */
        $this->assertNotNull($this->ssh->posicion_de(
            "cd '/home/api-" . $e['carpeta'] . "/empresa-api' && sudo -H -u 'api-" . $e['carpeta'] . "' /bin/bash -c 'php artisan up"
        ));
        $this->assertCount(1, $this->comandos_con('php artisan up'));
        $this->assertSame('paused', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->vps_supervisor_moved_at);
        $this->assertNull($upgrade->crons_supervisor_at);
    }

    /**
     * 🔴 E. Un comando que falla DESPUÉS del up deja al cliente ARRIBA: sin línea de mantenimiento,
     * con la marca del up puesta, y el reintento de comandos (el del panel) habilitado; y
     * siguiente_accion manda a retry-commands, no a reanudar.
     */
    public function test_un_comando_que_falla_despues_del_up_deja_al_cliente_arriba(): void
    {
        $e = $this->escenario();
        $this->seeder_y_comando($e);
        $this->artefactos_en_github($e['to']);
        $this->respaldo_en_disco($e);
        $this->spa_ya_bajado($e['upgrade']);
        $this->ssh->responder('comando:de-la-ventana', 'Error: el comando falló', 1);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');
        $this->assertNotNull($error);

        $up      = $this->ssh->posicion_de('php artisan up');
        $comando = $this->ssh->posicion_de('comando:de-la-ventana');
        $this->assertNotNull($up, $this->ssh->todo());
        $this->assertLessThan($comando, $up);
        $this->assertCount(1, $this->comandos_con('php artisan down'));

        $logs = $this->logs($e['upgrade']);
        $this->assertStringNotContainsString('QUEDÓ EN MANTENIMIENTO', $logs);
        $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'run_commands', $logs);
        $this->assertNull(DeploymentService::ultima_etapa_en_curso_en_los_logs((int) $e['upgrade']->id));

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('failed', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->crons_supervisor_at);
        $this->assertFalse(DeploymentService::en_el_lugar_sin_llegar_al_up($upgrade));
        $this->assertSame('fallido', UpdateCommand::where('client_version_upgrade_id', $upgrade->id)->value('status'));

        /* siguiente_accion: el reintento de comandos, no reanudar. */
        config(['services.claude_task_ingest.key' => 'clave-de-prueba-respaldo']);
        $get = $this->getJson('/api/claude/upgrades/' . $upgrade->id, [
            'X-Claude-Task-Key' => 'clave-de-prueba-respaldo',
            'Accept'            => 'application/json',
        ])->assertStatus(200)->json();
        $this->assertStringEndsWith('/deploy/retry-commands', $get['siguiente_accion']['endpoint']);

        /* Y el botón del panel lo acepta (la marca del up ya está). */
        \Illuminate\Support\Facades\Queue::fake();
        $admin           = new \App\Models\Admin();
        $admin->name     = 'Admin respaldo';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/retry-commands')->assertStatus(200);

        @unlink(storage_path('app/deployments/dist_' . $e['upgrade']->uuid . '.zip'));
    }

    /**
     * 🔴 1. El escenario exacto del verificador: un intento dejó el `down`; un start limpio (que borró
     * los logs, con la marca adentro) falla en compile_spa. El job encuentra el `down` y escribe la
     * marca HEREDADA, así que siguiente_accion recomienda reanudar desde compile_spa sin gate y, a las
     * 08:00 con horario 09–18 (que el gate rechazaría), la reanudación encola.
     */
    public function test_marca_heredada_tras_un_start_limpio_que_falla_en_compile_spa(): void
    {
        $e = $this->escenario();
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        /* El start limpio borró los logs del intento que dejó el down. */
        $this->assertSame(0, DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)->count());

        $error = $this->correr($this->servicio($e['upgrade']));
        $this->assertNotNull($error);

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString(DeploymentService::MARCA_SISTEMA_TOCADO . 'heredado de un intento anterior', $logs);
        $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'compile_spa', $logs);
        $this->assertTrue(DeploymentService::sistema_vivo_tocado_segun_los_logs((int) $e['upgrade']->id));

        /* Horario 09–18 y las 08:00: un arranque limpio daría 422. */
        $dia            = new \App\Models\ClientScheduleDay();
        $dia->client_id = $e['client']->id;
        $dia->day_key   = 'todos';
        $dia->save();
        $rango                         = new \App\Models\ClientScheduleRange();
        $rango->client_schedule_day_id = $dia->id;
        $rango->start_time             = '09:00';
        $rango->end_time               = '18:00';
        $rango->sort_order             = 0;
        $rango->save();
        Carbon::setTestNow(Carbon::parse('2026-10-14 08:00:00', config('app.timezone')));

        config(['services.claude_task_ingest.key' => 'clave-de-prueba-respaldo']);
        $headers = ['X-Claude-Task-Key' => 'clave-de-prueba-respaldo', 'Accept' => 'application/json'];

        $get = $this->getJson('/api/claude/upgrades/' . $e['upgrade']->id, $headers)->assertStatus(200)->json();
        $this->assertSame('compile_spa', $get['siguiente_accion']['resume_from_step']);
        $this->assertStringStartsWith('no aplica', $get['siguiente_accion']['gate_de_horario']);

        \Illuminate\Support\Facades\Queue::fake();
        $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente respaldo',
            'resume_from_step'    => 'compile_spa',
        ], $headers)->assertStatus(202);

        /* Contra prueba: un start limpio a la misma hora sí lleva el gate. */
        $e['upgrade']->refresh()->update(['deployment_status' => 'failed']);
        $rechazo = $this->postJson('/api/claude/upgrades/' . $e['upgrade']->id . '/deploy/start', [
            'confirm_client_name' => 'Cliente respaldo',
        ], $headers)->assertStatus(422)->json();
        $this->assertSame(60, $rechazo['minutos_hasta_la_apertura']);
    }

    /**
     * 1. Reusar un respaldo con la marca TOCADO también deja la marca heredada en el log (una vez).
     */
    public function test_reusar_un_respaldo_con_tocado_deja_la_marca_heredada(): void
    {
        $e = $this->escenario();
        $this->respaldo_en_disco($e);
        $this->servidor_sano();

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $this->assertSame(
            1,
            DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
                ->where('line', 'like', DeploymentService::MARCA_SISTEMA_TOCADO . 'heredado%')
                ->count()
        );
    }

    /**
     * 🔴 2. Una reanudación desde upload_api deja la marca (archivo TOCADO y línea) ANTES del `down`; y
     * una desde run_migrations, antes del `migrate`. Una sola vez por corrida.
     */
    public function test_reanudar_desde_upload_api_o_run_migrations_deja_la_marca_antes_de_tocar(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $ruta = $this->respaldo_en_disco($e, [], false);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_api');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));

        $marca = $this->ssh->posicion_de("> '" . $ruta . '/' . DeploymentService::ARCHIVO_TOCADO . "'");
        $this->assertNotNull($marca, $this->ssh->todo());
        $this->assertLessThan($this->ssh->posicion_de('php artisan down'), $marca);
        $this->assertCount(1, $this->comandos_con("> '" . $ruta . '/' . DeploymentService::ARCHIVO_TOCADO . "'"));
        $this->assertStringContainsString(DeploymentService::MARCA_SISTEMA_TOCADO . 'upload_api', $this->logs($e['upgrade']));

        /* Desde run_migrations (con el down de antes): la marca va antes del migrate. */
        $this->ssh = new SshDelDeploymentFake();
        $otro      = $this->escenario();
        $ruta      = $this->respaldo_en_disco($otro, [], false);
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');

        $error = $this->correr($this->servicio($otro['upgrade']), 'run_migrations');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($otro['upgrade']));

        $marca = $this->ssh->posicion_de("> '" . $ruta . '/' . DeploymentService::ARCHIVO_TOCADO . "'");
        $this->assertNotNull($marca, $this->ssh->todo());
        $this->assertLessThan($this->ssh->posicion_de('php artisan migrate --force'), $marca);
        $this->assertStringContainsString(DeploymentService::MARCA_SISTEMA_TOCADO . 'run_migrations', $this->logs($otro['upgrade']));
    }

    /**
     * 🔴 E. Con el sistema en mantenimiento no se reanuda desde una etapa que va DESPUÉS del up (los
     * comandos o la pausa): frena sin correr ningún comando ni marcar el paso del up, y la línea de la
     * etapa que falló nombra la última etapa de la ventana.
     */
    public function test_con_el_sistema_abajo_no_se_reanudan_los_comandos_ni_la_pausa(): void
    {
        foreach (['run_commands', 'pause_for_crons'] as $desde) {
            $this->ssh = new SshDelDeploymentFake();
            $e         = $this->escenario();
            $this->seeder_y_comando($e);
            $this->respaldo_en_disco($e);
            $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');

            DeploymentLog::create([
                'client_version_upgrade_id' => $e['upgrade']->id,
                'step'                      => 'run_seeders',
                'level'                     => 'info',
                'line'                      => DeploymentService::MARCA_ETAPA_EN_CURSO . 'run_seeders (con el sistema del cliente en mantenimiento).',
                'created_at'                => now(),
            ]);

            $error = $this->correr($this->servicio($e['upgrade']), DeploymentService::PREFIJO_REANUDACION_EN_MANTENIMIENTO . $desde);

            $this->assertNotNull($error, 'Desde ' . $desde);
            $this->assertStringContainsString('EN MANTENIMIENTO', $error->getMessage());
            $this->assertSame([], $this->comandos_con('comando:de-la-ventana'), 'Desde ' . $desde);
            $this->assertSame([], $this->comandos_con('php artisan up'), 'Desde ' . $desde);

            $upgrade = $e['upgrade']->fresh();
            $this->assertNull($upgrade->crons_supervisor_at, 'Desde ' . $desde);
            $this->assertSame('failed', (string) $upgrade->deployment_status);
            $this->assertStringContainsString(DeploymentService::MARCA_ETAPA_QUE_FALLO . 'run_seeders', $this->logs($e['upgrade']));
            $this->assertStringContainsString('resume_from_step=run_seeders', $this->logs($e['upgrade']));
        }
    }

    /**
     * 🔴 D. Adentro del job: con `storage/framework/down` presente, update_default_version NO cierra el
     * upgrade (aunque tenga la marca del up): falla sin el PUT al cliente, sin mover current_version, y
     * la línea dice que no se cerró.
     */
    public function test_el_job_no_cierra_el_upgrade_con_el_sistema_en_mantenimiento(): void
    {
        $e = $this->escenario();
        $e['upgrade']->update(['deployment_status' => 'failed', 'crons_supervisor_at' => now()]);
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');
        Http::fake();

        $version_antes = (int) $e['client']->fresh()->current_version_id;

        $error = $this->correr($this->servicio($e['upgrade']), 'update_default_version');

        $this->assertNotNull($error);
        $this->assertStringContainsString('EN MANTENIMIENTO', $error->getMessage());
        Http::assertNothingSent();

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('failed', (string) $upgrade->deployment_status);
        $this->assertNotSame('terminada', (string) $upgrade->status);
        $this->assertSame($version_antes, (int) $e['client']->fresh()->current_version_id);
        $this->assertStringContainsString('NO SE CERRÓ EL UPGRADE', $this->logs($e['upgrade']));

        /* Lo mismo si se entra directo por complete. */
        $error = $this->correr($this->servicio($e['upgrade']), 'complete');
        $this->assertNotNull($error);
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /** Los sync de `.env` y de certificados AFIP son no-op explícitos: origen y destino son lo mismo. */
    public function test_los_sync_de_env_y_de_certificados_son_no_op_en_el_lugar(): void
    {
        $e        = $this->escenario();
        $servicio = $this->servicio($e['upgrade']);

        $this->invocar($servicio, 'step_sync_env_keys');
        $this->invocar($servicio, 'sync_afip_certificates', ['run_migrations']);

        $env = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)->where('step', 'sync_env_keys')->get();
        $this->assertCount(1, $env);
        $this->assertStringContainsString('En el lugar', $env->first()->line);

        $afip = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)->where('step', 'run_migrations')->get();
        $this->assertCount(1, $afip);
        $this->assertStringContainsString('En el lugar', $afip->first()->line);

        $this->assertSame([], $this->ssh->comandos);
    }

    /** La etapa nueva está en `$steps` entre compile_spa y upload_spa, y enganchada en el switch. */
    public function test_la_etapa_de_respaldo_va_entre_compile_spa_y_upload_spa(): void
    {
        $e        = $this->escenario();
        $servicio = $this->servicio($e['upgrade']);

        $propiedad = new \ReflectionProperty(DeploymentService::class, 'steps');
        $propiedad->setAccessible(true);
        $steps = $propiedad->getValue($servicio);

        $this->assertSame(
            array_search('compile_spa', $steps, true) + 1,
            array_search('backup_in_place', $steps, true)
        );
        $this->assertSame(
            array_search('backup_in_place', $steps, true) + 1,
            array_search('upload_spa', $steps, true)
        );
        $this->assertStringContainsString(
            "case 'backup_in_place':",
            (string) file_get_contents(app_path('Services/DeploymentService.php'))
        );
    }
}
