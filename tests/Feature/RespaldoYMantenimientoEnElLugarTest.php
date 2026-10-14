<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
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
 * despliegue va EN EL LUGAR (la API destino es la que está atendiendo).
 *
 * Con la rotación, la red de seguridad era la carpeta vieja intacta. En el lugar esa red no existe,
 * y el pipeline arma otra. Lo que estos tests fijan, con dobles de SSH y SFTP (no se abre ningún
 * socket) y el `.env` del frente en memoria:
 *
 *  1. 🔴 El respaldo (`backup_in_place`) arma los comandos esperados —tar de la API sin `storage/` ni
 *     `vendor/`, tar del SPA, mysqldump con `--single-transaction --quick --no-tablespaces`— y la
 *     contraseña de la base NO aparece en ningún comando ni en ninguna línea de log: viaja solo en el
 *     archivo de opciones, escrito por SFTP con permisos 600, que se borra al terminar.
 *  2. 🔴 Si el respaldo falla, el deployment queda `failed` y `upload_spa` no corre: nada del cliente
 *     se tocó.
 *  3. 🔴 `artisan down` va antes de reemplazar la API y `artisan up` después de migrar, solo en el
 *     lugar; en una rotación, nada de eso.
 *  4. La retención borra solo respaldos viejos de esa carpeta, y el respaldo nunca cae en una carpeta
 *     servida por web.
 *  5. Si algo falla entre el `down` y el `up`, queda una línea propia que dice que el cliente quedó
 *     en mantenimiento, cómo levantarlo y dónde está el respaldo.
 *  6. En el lugar, `pause_for_crons` marca sola el paso del post-cierre, y los sync de `.env` y de
 *     certificados son no-op explícitos.
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
     * Upgrade de un cliente con dos frentes en el shared (`<carpeta>` y `<carpeta>2`).
     *
     * @param  string  $modo         'en_el_lugar' | 'rotacion'
     * @param  string  $hosting_type 'shared_hosting' | 'vps'
     * @return array<string, mixed>
     */
    private function escenario(string $modo = 'en_el_lugar', string $hosting_type = 'shared_hosting'): array
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
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta   = 'respaldo' . $this->contador . Str::lower(Str::random(3));
        $principal = $this->crear_api($client, $carpeta, $hosting_type);
        $segundo   = $this->crear_api($client, $carpeta . '2', $hosting_type);

        $client->active_client_api_id = $modo === 'en_el_lugar' ? $principal->id : $segundo->id;
        $client->save();

        $upgrade = ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $principal->id,
        ]);

        $this->env->envs[$principal->id] = "APP_NAME=Respaldo\n"
            . "DB_CONNECTION=mysql\n"
            . "DB_HOST=127.0.0.1\n"
            . "DB_PORT=3306\n"
            . "DB_DATABASE=u767360347_respaldo\n"
            . "DB_USERNAME=u767360347_user\n"
            . 'DB_PASSWORD=' . "'" . self::PASSWORD_DE_LA_BASE . "'\n";

        return compact('client', 'principal', 'segundo', 'upgrade', 'carpeta', 'to');
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
     * Respuestas del servidor para un respaldo que sale bien.
     *
     * @param  string  $home
     * @return void
     */
    private function servidor_sano(string $home = self::HOME_SHARED): void
    {
        $this->ssh->responder('printf %s "$HOME"', $home);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('CC_CREDENCIALES_BORRADAS', 'CC_CREDENCIALES_BORRADAS');
        $this->ssh->responder('gzip -dc', '-- Dump completed on 2026-10-14  2:00:01');
        $this->ssh->responder('gzip -t', '524288');
    }

    /**
     * Directorio del respaldo de este escenario (el reloj está congelado).
     *
     * @param  array<string, mixed>  $e
     * @param  string                $home
     * @return string
     */
    private function dir_del_respaldo(array $e, string $home = self::HOME_SHARED): string
    {
        return $home . '/cc-respaldos/' . $e['carpeta'] . '/20261014-020000-upgrade-' . $e['upgrade']->id;
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
     * Zip mínimo de la API (con `artisan` en la raíz) y los fakes de GitHub para el artefacto.
     *
     * @param  Version  $to
     * @return void
     */
    private function artefacto_de_la_api_en_github(Version $to): void
    {
        $temporal = tempnam(sys_get_temp_dir(), 'api-zip-');
        $zip      = new \ZipArchive();
        $zip->open($temporal, \ZipArchive::OVERWRITE);
        $zip->addFromString('artisan', "#!/usr/bin/env php\n<?php\n");
        $zip->addFromString('composer.lock', '{}');
        /* Bytes al azar: no se comprimen, y el pipeline rechaza un zip de menos de 500 bytes. */
        $zip->addFromString('relleno.bin', random_bytes(2048));
        $zip->close();

        $contenido = (string) file_get_contents($temporal);
        unlink($temporal);

        $nombre = 'empresa-api-v' . $to->version . '.zip';

        Http::fake([
            '*/releases/tags/*'   => Http::response([
                'id'     => 1,
                'assets' => [[
                    'id'                   => 1,
                    'name'                 => $nombre,
                    'size'                 => strlen($contenido),
                    'url'                  => 'https://api.github.com/repos/lucasgonzz/empresa-api/releases/assets/1',
                    'browser_download_url' => 'https://github.com/lucasgonzz/empresa-api/releases/download/' . $nombre,
                ]],
            ], 200),
            '*/releases/assets/*' => Http::response($contenido, 200),
        ]);
    }

    /**
     * Deja en el log la línea de un respaldo exitoso de este upgrade, como la dejaría un intento
     * anterior (las reanudaciones conservan los logs).
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function respaldo_previo_en_el_log(array $e): void
    {
        DeploymentLog::create([
            'client_version_upgrade_id' => $e['upgrade']->id,
            'step'                      => 'backup_in_place',
            'level'                     => 'success',
            'line'                      => DeploymentService::MARCA_RESPALDO_OK . $this->dir_del_respaldo($e)
                . ' — api.tar.gz 1 bytes · spa.tar.gz 1 bytes · base.sql.gz 1 bytes',
            'created_at'                => now(),
        ]);
    }

    /* ==========================================================================================
     | 1) El respaldo
     |========================================================================================= */

    /**
     * 🔴 Los comandos del respaldo, en orden, y la contraseña de la base en NINGÚN comando ni log:
     * solo en el archivo de opciones escrito por SFTP, con permisos 600, que después se borra.
     */
    public function test_el_respaldo_arma_los_comandos_esperados_sin_la_password(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->servidor_sano();

        $servicio = $this->servicio($e['upgrade']);
        $this->invocar($servicio, 'step_backup_in_place');

        $dir      = $this->dir_del_respaldo($e);
        $api_path = 'domains/comerciocity.com/public_html/' . $e['carpeta'] . '/api';
        $spa_dir  = 'domains/comerciocity.com/public_html/' . $e['carpeta'] . '/spa';

        $orden = [
            'printf %s "$HOME"',
            "umask 077 && mkdir -p '" . $dir . "'",
            "cd '" . $api_path . "' && tar --anchored --exclude=./storage --exclude=./vendor -czf '" . $dir . "/api.tar.gz' .",
            "gzip -t '" . $dir . "/api.tar.gz'",
            "cd '" . $spa_dir . "' && tar -czf '" . $dir . "/spa.tar.gz' .",
            "gzip -t '" . $dir . "/spa.tar.gz'",
            "mysqldump --defaults-extra-file='" . $dir . "/.cc-dump.cnf' --single-transaction --quick --no-tablespaces",
            "rm -f '" . $dir . "/.cc-dump.cnf'",
            "gzip -t '" . $dir . "/base.sql.gz'",
            "gzip -dc '" . $dir . "/base.sql.gz' | tail -n 1",
        ];

        $anterior = -1;
        foreach ($orden as $aguja) {
            $posicion = $this->ssh->posicion_de($aguja);
            $this->assertNotNull($posicion, 'No se mandó: ' . $aguja . "\n\nComandos:\n" . $this->ssh->todo());
            $this->assertGreaterThan($anterior, $posicion, 'Fuera de orden: ' . $aguja);
            $anterior = $posicion;
        }

        /* La contraseña: en el archivo de opciones sí (si no, mysqldump no entra)... */
        $cnf = $dir . '/.cc-dump.cnf';
        $this->assertArrayHasKey($cnf, $this->sftp->archivos);
        $this->assertStringContainsString('[client]', $this->sftp->archivos[$cnf]);
        $this->assertStringContainsString('user="u767360347_user"', $this->sftp->archivos[$cnf]);
        /* Lleva comillas dobles: va entre simples, con la barra invertida duplicada. */
        $this->assertStringContainsString(
            "password='" . str_replace('\\', '\\\\', self::PASSWORD_DE_LA_BASE) . "'",
            $this->sftp->archivos[$cnf]
        );
        $this->assertSame(0600, $this->sftp->permisos[$cnf]);

        /* ...y en ningún comando ni en ninguna línea del log. */
        $this->assertStringNotContainsString('Clave-De-La-Base', $this->ssh->todo());
        $this->assertStringNotContainsString('Clave-De-La-Base', $this->logs($e['upgrade']));

        /* Nunca en una carpeta servida por web, y la línea del éxito nombra la ruta. */
        $this->assertStringStartsWith(self::HOME_SHARED . '/cc-respaldos/', $dir);
        $this->assertStringNotContainsString('public_html', $dir);
        $this->assertStringContainsString(DeploymentService::MARCA_RESPALDO_OK . $dir, $this->logs($e['upgrade']));
    }

    /**
     * 🔴 Si mysqldump falla, el respaldo falla, el archivo de credenciales se borra igual, y el
     * pipeline no llega a upload_spa: nada del cliente se tocó.
     */
    public function test_si_el_respaldo_falla_no_se_ejecuta_upload_spa(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('mysqldump', "mysqldump: Got error: 1045: Access denied\n2");
        $this->ssh->responder('CC_CREDENCIALES_BORRADAS', 'CC_CREDENCIALES_BORRADAS');
        $this->ssh->responder('gzip -t', '524288');

        $servicio = $this->servicio($e['upgrade']);

        $error = null;
        try {
            $servicio->run('backup_in_place');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error, 'El respaldo fallido no cortó el pipeline.');
        $this->assertStringContainsString('mysqldump', $error->getMessage());
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);

        /* El archivo de credenciales se borró aunque el dump falló. */
        $this->assertNotNull($this->ssh->posicion_de("rm -f '" . $this->dir_del_respaldo($e) . "/.cc-dump.cnf'"));

        /* upload_spa no corrió: ni línea de log, ni el borrado del SPA, ni ningún zip subido. */
        $this->assertSame(0, DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)->where('step', 'upload_spa')->count());
        $this->assertNull($this->ssh->posicion_de('find . -mindepth 1 -delete'));
        $this->assertNull($this->ssh->posicion_de('artisan down'));
        foreach ($this->sftp->operaciones as $operacion) {
            $this->assertStringNotContainsString('.zip', $operacion);
        }

        /* Y no quedó ninguna línea de "quedó en mantenimiento": no se tocó nada. */
        $this->assertStringNotContainsString('QUEDÓ EN MANTENIMIENTO', $this->logs($e['upgrade']));
    }

    /** Un dump que no termina en `-- Dump completed` está cortado: el respaldo falla. */
    public function test_un_dump_sin_la_marca_de_fin_hace_fallar_el_respaldo(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('CC_CREDENCIALES_BORRADAS', 'CC_CREDENCIALES_BORRADAS');
        $this->ssh->responder('gzip -dc', 'INSERT INTO `articles` VALUES (1,');
        $this->ssh->responder('gzip -t', '524288');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dump completed');

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
    }

    /** Si no se puede confirmar que el archivo de credenciales se borró, el respaldo falla. */
    public function test_si_no_se_confirma_el_borrado_de_las_credenciales_el_respaldo_falla(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('gzip -t', '524288');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credenciales');

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
    }

    /** 🔴 Un home que cae adentro de una carpeta servida por web frena antes de crear nada. */
    public function test_el_respaldo_nunca_cae_en_una_carpeta_servida_por_web(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->servidor_sano('/home/u767360347/domains/comerciocity.com/public_html');

        $error = null;
        try {
            $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error);
        $this->assertStringContainsString('servida por web', $error->getMessage());
        $this->assertNull($this->ssh->posicion_de('mkdir -p'), 'Creó el directorio antes de validar la ruta.');
        $this->assertNull($this->ssh->posicion_de('mysqldump'));
    }

    /** Un home vacío o la raíz frenan antes de crear nada. */
    public function test_un_home_que_no_es_absoluto_frena_el_respaldo(): void
    {
        foreach (['', '/'] as $home) {
            $this->ssh = new SshDelDeploymentFake();
            $e         = $this->escenario('en_el_lugar');
            $this->ssh->responder('printf %s "$HOME"', $home);

            $error = null;
            try {
                $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');
            } catch (\Throwable $ex) {
                $error = $ex;
            }

            $this->assertNotNull($error, 'Pasó con HOME=' . var_export($home, true));
            $this->assertNull($this->ssh->posicion_de('mkdir -p'));
        }
    }

    /**
     * 🔴 La retención deja los dos más nuevos y borra solo respaldos de esa carpeta: nunca una
     * entrada con otro nombre, nunca la carpeta del cliente ni `cc-respaldos/`.
     */
    public function test_la_retencion_borra_solo_los_respaldos_viejos_de_esa_carpeta(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->servidor_sano();

        $nuevo = '20261014-020000-upgrade-' . $e['upgrade']->id;
        $this->ssh->responder('ls -1', implode("\n", [
            '20260901-020000-upgrade-11',
            '20260915-020000-upgrade-12',
            '20261001-020000-upgrade-13',
            $nuevo,
            'notas-de-lucas',
            '.cc-dump.cnf',
        ]));

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $base = self::HOME_SHARED . '/cc-respaldos/' . $e['carpeta'];

        $borrados = array_values(array_filter($this->ssh->comandos, function ($comando) {
            return strpos($comando, 'rm -rf') !== false;
        }));

        $this->assertCount(2, $borrados, "Comandos de borrado:\n" . implode("\n", $borrados));
        $this->assertStringContainsString("rm -rf -- '" . $base . "/20260901-020000-upgrade-11'", $borrados[0]);
        $this->assertStringContainsString("rm -rf -- '" . $base . "/20260915-020000-upgrade-12'", $borrados[1]);
        $this->assertStringNotContainsString('notas-de-lucas', implode("\n", $borrados));
        $this->assertStringNotContainsString($nuevo, implode("\n", $borrados));
        $this->assertStringNotContainsString('20261001-020000-upgrade-13', implode("\n", $borrados));
    }

    /** En una rotación el respaldo no aplica: lo dice y no manda ningún comando. */
    public function test_en_una_rotacion_el_respaldo_no_aplica(): void
    {
        $e = $this->escenario('rotacion');

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $this->assertSame([], $this->ssh->comandos);
        $linea = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)->where('step', 'backup_in_place')->first();
        $this->assertNotNull($linea);
        $this->assertStringContainsString('No aplica', $linea->line);
        $this->assertSame('info', $linea->level);
    }

    /** En el VPS (entra como root) el respaldo va al home de ese usuario y la carpeta es el vps_path. */
    public function test_en_el_vps_el_respaldo_va_al_home_del_usuario_con_el_que_entra(): void
    {
        $e = $this->escenario('en_el_lugar', 'vps');
        $this->servidor_sano('/root');

        $this->invocar($this->servicio($e['upgrade']), 'step_backup_in_place');

        $dir = '/root/cc-respaldos/' . $e['carpeta'] . '/20261014-020000-upgrade-' . $e['upgrade']->id;
        $this->assertNotNull($this->ssh->posicion_de("umask 077 && mkdir -p '" . $dir . "'"));
        $this->assertNotNull($this->ssh->posicion_de("cd '/home/api-" . $e['carpeta'] . "/empresa-api' && tar --anchored"));
        $this->assertNotNull($this->ssh->posicion_de("cd '/home/" . $e['carpeta'] . '/htdocs/' . $e['carpeta'] . ".comerciocity.com' && tar -czf"));
    }

    /* ==========================================================================================
     | 2) El mantenimiento
     |========================================================================================= */

    /**
     * 🔴 En el lugar, de upload_api a la pausa: `artisan down` antes del unzip de la API,
     * `artisan up` después del migrate, y la pausa marca los crons sola.
     */
    public function test_en_el_lugar_down_antes_de_reemplazar_la_api_y_up_despues_de_migrar(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->artefacto_de_la_api_en_github($e['to']);
        $this->respaldo_previo_en_el_log($e);

        $this->servicio($e['upgrade'])->run('upload_api');

        $down    = $this->ssh->posicion_de('php artisan down');
        $unzip   = $this->ssh->posicion_de('unzip -o');
        $migrate = $this->ssh->posicion_de('php artisan migrate --force');
        $up      = $this->ssh->posicion_de('php artisan up');

        $this->assertNotNull($down, "No hubo artisan down.\n" . $this->ssh->todo());
        $this->assertNotNull($up, 'No hubo artisan up.');
        $this->assertLessThan($unzip, $down, 'El down tiene que ir antes de descomprimir la API.');
        $this->assertGreaterThan($migrate, $up, 'El up tiene que ir después de migrar.');

        /* Ninguna etapa entre down y up sale por HTTP al cliente (el único HTTP es GitHub, antes). */
        Http::assertNotSent(function ($request) use ($e) {
            return strpos((string) $request->url(), $e['carpeta']) !== false;
        });

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('paused', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->crons_supervisor_at, 'En el lugar, la pausa marca sola crons_supervisor_at.');
        $this->assertNull($upgrade->vps_supervisor_moved_at);

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('EN MANTENIMIENTO desde ahora', $logs);
        $this->assertStringContainsString($this->dir_del_respaldo($e), $logs, 'La línea del down nombra el respaldo.');
        $this->assertStringContainsString('no hay frente que mudar', $logs);
        $this->assertStringNotContainsString('QUEDÓ EN MANTENIMIENTO', $logs);
    }

    /** En una rotación, el mismo tramo no pone nada en mantenimiento ni marca los crons. */
    public function test_en_una_rotacion_no_hay_down_ni_up_ni_crons_marcados(): void
    {
        $e = $this->escenario('rotacion');
        $this->artefacto_de_la_api_en_github($e['to']);

        $this->servicio($e['upgrade'])->run('upload_api');

        $this->assertNotNull($this->ssh->posicion_de('unzip -o'), "No corrió el tramo.\n" . $this->ssh->todo());
        $this->assertNull($this->ssh->posicion_de('artisan down'));
        $this->assertNull($this->ssh->posicion_de('artisan up'));

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('paused', (string) $upgrade->deployment_status);
        $this->assertNull($upgrade->crons_supervisor_at, 'En una rotación los crons los mueve una persona.');
    }

    /**
     * 🔴 Si algo falla entre el down y el up, queda una línea propia: el cliente quedó en
     * mantenimiento, con qué se levanta y dónde está el respaldo. Y el up no se manda.
     */
    public function test_si_falla_en_el_medio_queda_dicho_que_el_cliente_quedo_en_mantenimiento(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->respaldo_previo_en_el_log($e);
        $this->ssh->responder('php artisan migrate --force', 'SQLSTATE[42S01]: Base table or view already exists', 1);

        $error = null;
        try {
            $this->servicio($e['upgrade'])->run('run_migrations');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error);
        $this->assertNull($this->ssh->posicion_de('php artisan up'), 'Con las migraciones fallidas no se levanta el sistema.');
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);

        $linea = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('line', 'like', '%QUEDÓ EN MANTENIMIENTO%')
            ->first();

        $this->assertNotNull($linea, "No quedó la línea de mantenimiento.\n" . $this->logs($e['upgrade']));
        $this->assertSame('error', $linea->level);
        $this->assertStringContainsString('php artisan up', $linea->line);
        $this->assertStringContainsString($this->dir_del_respaldo($e), $linea->line);
        $this->assertStringContainsString('resume_from_step=run_migrations', $linea->line);
    }

    /** Reanudar desde run_migrations termina con el sistema arriba: es la salida del mantenimiento. */
    public function test_reanudar_desde_run_migrations_termina_con_artisan_up(): void
    {
        $e = $this->escenario('en_el_lugar');
        $this->respaldo_previo_en_el_log($e);

        $this->servicio($e['upgrade'])->run('run_migrations');

        $this->assertGreaterThan(
            $this->ssh->posicion_de('php artisan migrate --force'),
            $this->ssh->posicion_de('php artisan up')
        );
        $this->assertSame('paused', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /**
     * 🔴 En el lugar, reanudar desde una etapa que pisa algo sin un respaldo exitoso en los logs
     * frena antes de mandar un solo comando: si no, saltearía la red.
     */
    public function test_reanudar_despues_del_respaldo_sin_respaldo_previo_frena_sin_tocar_nada(): void
    {
        $e = $this->escenario('en_el_lugar');

        $error = null;
        try {
            $this->servicio($e['upgrade'])->run('upload_spa');
        } catch (\Throwable $ex) {
            $error = $ex;
        }

        $this->assertNotNull($error);
        $this->assertStringContainsString('resume_from_step=backup_in_place', $error->getMessage());
        $this->assertSame([], $this->ssh->comandos, 'Mandó comandos antes de frenar.');
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /* ==========================================================================================
     | 3) Lo demás que cambia en el lugar
     |========================================================================================= */

    /** En el VPS, la pausa en el lugar marca vps_supervisor_moved_at (y no crons_supervisor_at). */
    public function test_en_el_vps_la_pausa_marca_el_supervisor(): void
    {
        $e = $this->escenario('en_el_lugar', 'vps');

        $this->invocar($this->servicio($e['upgrade']), 'step_pause_for_crons');

        $upgrade = $e['upgrade']->fresh();
        $this->assertSame('paused', (string) $upgrade->deployment_status);
        $this->assertNotNull($upgrade->vps_supervisor_moved_at);
        $this->assertNull($upgrade->crons_supervisor_at);
    }

    /** Los sync de `.env` y de certificados AFIP son no-op explícitos: origen y destino son lo mismo. */
    public function test_los_sync_de_env_y_de_certificados_son_no_op_en_el_lugar(): void
    {
        $e        = $this->escenario('en_el_lugar');
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

    /** La etapa nueva está en el pipeline entre compile_spa y upload_spa, y enganchada en el switch. */
    public function test_la_etapa_de_respaldo_va_entre_compile_spa_y_upload_spa(): void
    {
        $e        = $this->escenario('en_el_lugar');
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
