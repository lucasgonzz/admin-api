<?php

namespace Tests\Feature\AvisoDeActualizacion;

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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use phpseclib3\Net\SFTP;
use Tests\Fakes\EnvSshServiceFake;
use Tests\Fakes\SftpDelDeploymentFake;
use Tests\Fakes\SshDelDeploymentFake;
use Tests\TestCase;

/**
 * Misión `aviso-actualizacion-sistema` (25/11/2026) — NINGÚN camino que suelta el aviso lo hace con otro
 * upgrade activo del mismo cliente.
 *
 * El aviso es UNO por dueño y `PUT libre` ignora `upgrade_id`: si el upgrade A sigue en curso (`running`,
 * `paused` o `paused_post_tasks`, p. ej. en el lugar, esperando el post-cierre, con el sistema bloqueado)
 * y el upgrade B del mismo cliente termina o falla, soltar el aviso desde B lo suelta también para A. La
 * guarda estaba sólo en `aviso_soltar_si_no_se_toco()` (los caminos del job); tiene que estar en el punto
 * común por el que pasan G4, G5 y esos caminos: `aviso_liberar_con_reintentos()`. Cuando no se suelta,
 * queda una línea de log `info`: "no se suelta: el upgrade #N del cliente sigue en curso".
 *
 * PHP 7.4.
 */
class AvisoOtroUpgradeActivoTest extends TestCase
{
    use DatabaseTransactions;

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
     * Qué contesta empresa-api a cada `PUT libre`, en orden (el último se repite). 200 = lo suelta.
     *
     * @var array<int, int>
     */
    private $respuestas_al_libre = [200];

    /**
     * Cuántos `PUT libre` llegaron (para elegir la respuesta que toca).
     *
     * @var int
     */
    private $libres_recibidos = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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

        /* Sin esperas reales entre reintentos: los tests no duermen. */
        config([
            'aviso_de_actualizacion.habilitado'          => true,
            'aviso_de_actualizacion.esperas_de_reintento' => [0, 0],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-26 02:00:00', config('app.timezone')));

        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (strpos($url, '/api/admin-sync/update-default-version') !== false) {
                $this->ssh->comandos[] = 'HTTP PUT update-default-version';

                return Http::response(['ok' => true], 200);
            }

            if (strpos($url, '/api/admin-sync/actualizacion-del-sistema') === false) {
                return null;
            }

            $datos  = (array) $request->data();
            $estado = isset($datos['estado']) ? (string) $datos['estado'] : null;

            $this->ssh->comandos[] = 'HTTP ' . $request->method() . ($estado !== null ? ' ' . $estado : '');

            if ($estado === 'libre') {
                $indice = min($this->libres_recibidos, count($this->respuestas_al_libre) - 1);
                $this->libres_recibidos++;

                if ($this->respuestas_al_libre[$indice] !== 200) {
                    return Http::response(['message' => 'Server Error'], $this->respuestas_al_libre[$indice]);
                }
            }

            return Http::response([
                'ok'         => true,
                'owner_id'   => 1,
                'estado'     => $estado === 'libre' ? 'libre' : 'actualizando',
                'upgrade_id' => isset($datos['upgrade_id']) ? (int) $datos['upgrade_id'] : null,
                'version'    => isset($datos['version']) ? (string) $datos['version'] : null,
                'vence_at'   => now()->addMinutes(120)->toIso8601String(),
                'pedido'     => null,
            ], 200);
        });
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
     * Upgrade EN EL LUGAR de un cliente con dos frentes.
     *
     * @param  string  $hosting_type  'shared_hosting' | 'vps' (el perfil de runtime sólo se consulta en el VPS).
     * @return array<string, mixed>
     */
    private function escenario(string $hosting_type = 'shared_hosting'): array
    {
        $this->contador++;

        $from = $this->crear_version('8.' . $this->contador . '.0');
        $to   = $this->crear_version('8.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente aviso seguro';
        $client->company_name       = 'Empresa aviso seguro';
        $client->slug               = 'seguro-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api-seguro';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta   = 'seguro' . $this->contador . Str::lower(Str::random(3));
        $principal = $this->crear_api($client, $carpeta, $hosting_type);
        $this->crear_api($client, $carpeta . '2', $hosting_type);

        $client->active_client_api_id = $principal->id;
        $client->save();

        $upgrade = ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $principal->id,
        ]);

        $this->env->envs[$principal->id] = "APP_NAME=Seguro\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\n"
            . "DB_DATABASE=u767360347_seguro\nDB_USERNAME=u767360347_user\nDB_PASSWORD='clave'\n";

        return compact('client', 'upgrade', 'to', 'carpeta');
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
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com/public';
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $hosting_type === 'vps' ? $carpeta : null;
        $api->save();

        return $api;
    }

    /**
     * DeploymentService con los seams de transporte apuntando a los dobles, ya conectado, y los
     * frenos de soporte que se pidan (se inyectan directo en sus propiedades privadas: el circuito
     * real de soporte los arma con `configure_support_schedule()` y artefactos fijados).
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  callable|null         $antes_de_preparar  `support_before_staging`.
     * @param  callable|null         $antes_de_escribir  `support_before_live_write`.
     * @return DeploymentService
     */
    private function servicio(ClientVersionUpgrade $upgrade, $antes_de_preparar = null, $antes_de_escribir = null): DeploymentService
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

        foreach (['support_before_staging' => $antes_de_preparar, 'support_before_live_write' => $antes_de_escribir] as $nombre => $freno) {
            if ($freno !== null) {
                $propiedad = new \ReflectionProperty(DeploymentService::class, $nombre);
                $propiedad->setAccessible(true);
                $propiedad->setValue($servicio, $freno);
            }
        }

        $this->ssh->responder('storage/framework/down', 'CC_SIN_MANTENIMIENTO');

        $servicio->connect();

        return $servicio;
    }

    /**
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
     * Un respaldo completo de ESTE upgrade en el disco, SIN la marca de sistema tocado: lo dejó un
     * intento anterior que no llegó a escribir nada vivo.
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function respaldo_en_disco_sin_tocar(array $e): void
    {
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('ls -1', '20261125-020000-upgrade-' . $e['upgrade']->id);
        $this->ssh->responder('CC_RESPALDO_COMPLETO', 'CC_RESPALDO_COMPLETO');
    }

    /**
     * El zip del SPA donde lo deja compile_spa.
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
     * Fakes de GitHub con el release (API y SPA) de cualquier versión, y cierre del resto de la red.
     *
     * @return void
     */
    private function github_con_el_release(): void
    {
        $api = $this->zip(['artisan' => "#!/usr/bin/env php\n<?php\n", 'composer.lock' => '{}']);
        $spa = $this->zip(['index.html' => '<!doctype html><title>spa</title>']);

        Http::fake(function ($request) use ($api, $spa) {
            $url = (string) $request->url();

            if (preg_match('#/releases/tags/([^/?]+)#', $url, $m)) {
                return Http::response(['id' => 1, 'assets' => [
                    ['id' => 1, 'name' => 'empresa-api-' . $m[1] . '.zip', 'size' => strlen($api),
                        'url' => 'https://api.github.com/repos/lucasgonzz/empresa-api/releases/assets/1', 'browser_download_url' => 'https://github.com/x/1'],
                    ['id' => 2, 'name' => 'empresa-spa-' . $m[1] . '-dist.zip', 'size' => strlen($spa),
                        'url' => 'https://api.github.com/repos/lucasgonzz/empresa-spa/releases/assets/2', 'browser_download_url' => 'https://github.com/x/2'],
                ]], 200);
            }
            if (preg_match('#/releases/assets/1$#', $url)) {
                return Http::response($api, 200);
            }
            if (preg_match('#/releases/assets/2$#', $url)) {
                return Http::response($spa, 200);
            }

            return null;
        });

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
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
     * @return string
     */
    private function logs(ClientVersionUpgrade $upgrade): string
    {
        return DeploymentLog::where('client_version_upgrade_id', $upgrade->id)->orderBy('id')->pluck('line')->implode("\n");
    }

    /**
     * Posiciones (en el registro único de SSH + HTTP) de las entradas que contienen la aguja.
     *
     * @param  string  $aguja
     * @return array<int, int>
     */
    private function posiciones_de(string $aguja): array
    {
        $posiciones = [];
        foreach ($this->ssh->comandos as $indice => $comando) {
            if (strpos($comando, $aguja) !== false) {
                $posiciones[] = $indice;
            }
        }

        return $posiciones;
    }

    /**
     * Las tres corridas de un deploy en el lugar completo: pre-cierre, start-post-closure y
     * configure-system (que cierra con `complete`).
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function deploy_completo(array $e): void
    {
        $this->github_con_el_release();
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('CC_CREDENCIALES_BORRADAS', 'CC_CREDENCIALES_BORRADAS');
        $this->ssh->responder('gzip -dc', "-- Dump completed on 2026-11-26  2:00:01\n524288");
        $this->ssh->responder('tar --warning=no-file-changed', 'CC_TAR_EXIT=0');
        $this->ssh->responder('gzip -t', '524288');

        foreach ([null, 'run_seeders', 'update_default_version'] as $desde) {
            $error = $this->correr($this->servicio($e['upgrade']), $desde);
            $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        }
    }

    /**
     * Otro upgrade del mismo cliente (con versiones propias) en ese estado de deployment.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  string                $estado
     * @return ClientVersionUpgrade
     */
    private function otro_upgrade_del_cliente(ClientVersionUpgrade $upgrade, string $estado): ClientVersionUpgrade
    {
        $this->contador++;

        return ClientVersionUpgrade::create([
            'client_id'            => $upgrade->client_id,
            'from_version_id'      => $this->crear_version('5.' . $this->contador . '.0')->id,
            'to_version_id'        => $this->crear_version('5.' . $this->contador . '.1')->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $upgrade->target_client_api_id,
            'deployment_status'    => $estado,
        ]);
    }

    /**
     * 🔴 G5: A está en `paused_post_tasks` (con el sistema bloqueado) y B, del mismo cliente, falla en
     * `compile_spa` DENTRO de `run()`. Sin la guarda, B soltaba el aviso de A. Cero `PUT libre`, y la
     * línea de log que dice por qué.
     */
    public function test_g5_de_b_no_suelta_si_a_sigue_en_curso(): void
    {
        $e = $this->escenario();
        $a = $this->otro_upgrade_del_cliente($e['upgrade'], 'paused_post_tasks');
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertSame([], $this->posiciones_de('HTTP PUT libre'), "G5 no suelta el aviso de A.\n" . $this->ssh->todo());
        $this->assertStringContainsString(
            'no se suelta: el upgrade #' . $a->id . ' del cliente sigue en curso',
            $this->logs($e['upgrade'])
        );
    }

    /**
     * 🔴 G4: B completa su deploy (las tres corridas) mientras A, del mismo cliente, sigue `running`. B
     * queda `completed`, pero no suelta el aviso: cero `PUT libre` y la línea de log.
     */
    public function test_g4_de_b_no_suelta_si_a_sigue_en_curso(): void
    {
        $e = $this->escenario();
        $a = $this->otro_upgrade_del_cliente($e['upgrade'], 'running');

        $this->deploy_completo($e);

        $this->assertSame('completed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertSame([], $this->posiciones_de('HTTP PUT libre'), "G4 no suelta el aviso de A.\n" . $this->ssh->todo());
        $this->assertStringContainsString(
            'no se suelta: el upgrade #' . $a->id . ' del cliente sigue en curso',
            $this->logs($e['upgrade'])
        );
        $this->assertNotEmpty($this->posiciones_de('HTTP PUT actualizando'), 'Prender y renovar siguen andando.');
    }

    /**
     * Control: si el otro upgrade del cliente ya terminó (`completed`) o falló (`failed`), no cuenta: B
     * suelta normalmente en G5.
     */
    public function test_con_el_otro_upgrade_terminado_o_fallado_g5_suelta_como_siempre(): void
    {
        $e = $this->escenario();
        $this->otro_upgrade_del_cliente($e['upgrade'], 'completed');
        $this->otro_upgrade_del_cliente($e['upgrade'], 'failed');
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), $this->ssh->todo());
        $this->assertStringNotContainsString('no se suelta', $this->logs($e['upgrade']));
    }

    /**
     * Control: un upgrade en curso de OTRO cliente no cuenta.
     */
    public function test_un_upgrade_en_curso_de_otro_cliente_no_impide_soltar(): void
    {
        $e     = $this->escenario();
        $ajeno = $this->escenario();
        $ajeno['upgrade']->update(['deployment_status' => 'running']);
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), $this->ssh->todo());
    }
}
