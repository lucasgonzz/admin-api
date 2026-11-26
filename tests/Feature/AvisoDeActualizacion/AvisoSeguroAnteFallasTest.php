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
 * Misión `aviso-actualizacion-sistema`, RONDA 2 (25/11/2026) — el aviso "estamos actualizando" no
 * puede dejar a un negocio afuera con el sistema intacto ni ensuciar los frenos de soporte.
 *
 * Lo que rechazaron las revisiones de la ronda 1, y que estos tests fijan:
 *
 *  1. 🔴 G1 se prende JUSTO ANTES DE ESCRIBIR EL SISTEMA VIVO, no antes de `step_upload_spa()`. Los
 *     frenos de soporte (`support_before_staging` y `support_before_live_write`) miden la actividad
 *     de los usuarios con `users.last_activity`, y prender el aviso la pone en NULL: con el aviso
 *     prendido antes, el freno de actividad queda ciego. Y si un freno aborta, no puede quedar el
 *     aviso prendido: ni se prende, o se suelta.
 *  2. 🔴 G5 suelta el aviso cuando la corrida falla y el sistema vivo NO se tocó (ni en esta corrida
 *     ni en una anterior), en CUALQUIER etapa: una falla en `upload_spa` antes de escribir, o en
 *     `runtime_preflight` (cuando todavía no hay etapa en curso). Si ya se tocó, no se suelta.
 *  3. 🔴 G4 (y G5) reintentan: 3 intentos con espera creciente. Con 2 fallos y 1 éxito queda liberado;
 *     si fallan los 3, una línea de log de nivel `error` con "NO se pudo liberar", y el upgrade queda
 *     `completed` igual.
 *
 * Mismos dobles que `PipelineAvisoDeActualizacionTest` (SSH, SFTP y `.env` en memoria, `Http::fake`
 * para el admin-sync). Cada request del aviso deja su marca en el registro de comandos del SSH.
 *
 * PHP 7.4.
 */
class AvisoSeguroAnteFallasTest extends TestCase
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

    /* ==========================================================================================
     | 1) G1: justo antes de escribir el sistema vivo
     |========================================================================================= */

    /**
     * 🔴 Un freno de soporte que ABORTA en `upload_spa` (hay gente trabajando) deja el sistema
     * intacto: el aviso NO se prendió, y si el OK de los usuarios ya lo había dejado en
     * `actualizando`, se suelta. Nadie queda afuera con el sistema sin tocar.
     */
    public function test_un_freno_de_soporte_que_aborta_en_upload_spa_no_deja_el_aviso_prendido(): void
    {
        $e = $this->escenario();
        $this->github_con_el_release();
        $this->respaldo_en_disco_sin_tocar($e);
        $this->spa_ya_bajado($e['upgrade']);

        $error = $this->correr($this->servicio($e['upgrade'], null, function () {
            throw new \RuntimeException('Freno de soporte: hay usuarios trabajando.');
        }), 'upload_spa');

        $this->assertNotNull($error);
        $this->assertStringContainsString('Freno de soporte', $error->getMessage());
        $this->assertSame([], $this->posiciones_de('HTTP PUT actualizando'), 'Si el freno aborta, el aviso no se prende.');
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), 'El sistema está intacto: se suelta el aviso del OK.');
        $this->assertNull($this->ssh->posicion_de('php artisan down'));
        $this->assertSame([], $this->posiciones_de('find . -mindepth 1 -delete'), 'No se tocó el SPA vivo.');
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
    }

    /**
     * 🔴 Los frenos de soporte miden la actividad con `last_activity` INTACTO: los dos corren ANTES de
     * que el aviso se prenda (que la pone en NULL). Orden de llamadas sobre una sola secuencia, y el
     * aviso se prende recién después del último freno y antes de vaciar el SPA vivo.
     */
    public function test_los_frenos_de_soporte_corren_antes_de_que_se_prenda_el_aviso(): void
    {
        $e = $this->escenario();
        $this->github_con_el_release();
        $this->respaldo_en_disco_sin_tocar($e);
        $this->spa_ya_bajado($e['upgrade']);

        $llamadas = ['staging' => 0, 'live_write' => 0];

        $error = $this->correr($this->servicio(
            $e['upgrade'],
            function () use (&$llamadas) {
                $llamadas['staging']++;
                $this->ssh->comandos[] = 'SOPORTE before_staging';
            },
            function () use (&$llamadas) {
                $llamadas['live_write']++;
                $this->ssh->comandos[] = 'SOPORTE before_live_write';
            }
        ), 'upload_spa');

        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        $this->assertSame(['staging' => 1, 'live_write' => 1], $llamadas, 'Cada freno corre una sola vez.');

        $staging = $this->ssh->posicion_de('SOPORTE before_staging');
        $escribir = $this->ssh->posicion_de('SOPORTE before_live_write');
        $prende  = $this->posiciones_de('HTTP PUT actualizando');
        $vacia   = $this->ssh->posicion_de('find . -mindepth 1 -delete');

        $this->assertNotEmpty($prende, "No se prendió el aviso.\n" . $this->ssh->todo());
        $this->assertLessThan($prende[0], $staging, 'El freno de staging corre ANTES de prender el aviso.');
        $this->assertLessThan($prende[0], $escribir, 'El freno de escritura corre ANTES de prender el aviso.');
        $this->assertLessThan($vacia, $prende[0], 'El aviso se prende ANTES de vaciar el SPA vivo.');
        $this->assertCount(1, $prende, 'En upload_spa se prende UNA vez.');
    }

    /**
     * Sin circuito de soporte (no hay freno configurado) el aviso se prende igual, antes de vaciar el
     * SPA vivo: el gancho no depende de que exista un freno.
     */
    public function test_sin_frenos_de_soporte_el_aviso_se_prende_antes_de_vaciar_el_spa(): void
    {
        $e = $this->escenario();
        $this->github_con_el_release();
        $this->respaldo_en_disco_sin_tocar($e);
        $this->spa_ya_bajado($e['upgrade']);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');

        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        $prende = $this->posiciones_de('HTTP PUT actualizando');
        $this->assertNotEmpty($prende);
        $this->assertLessThan($this->ssh->posicion_de('find . -mindepth 1 -delete'), $prende[0]);
        $this->assertLessThan($this->ssh->posicion_de('php artisan down'), $prende[0]);
    }

    /* ==========================================================================================
     | 2) G5: se suelta si el sistema vivo no se tocó, en cualquier etapa
     |========================================================================================= */

    /**
     * 🔴 Falla en `upload_spa` ANTES de tocar el sistema vivo (no se pudo preparar la API: GitHub no
     * tiene el release): se suelta el aviso. Antes sólo se soltaba en compile_spa y backup_in_place.
     */
    public function test_una_falla_en_upload_spa_antes_de_tocar_lo_vivo_suelta_el_aviso(): void
    {
        $e = $this->escenario();
        Http::fake(['*/releases/tags/*' => Http::response(['message' => 'Not Found'], 404)]);
        Http::fake(['*' => Http::response([], 200)]);
        $this->respaldo_en_disco_sin_tocar($e);
        $this->spa_ya_bajado($e['upgrade']);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_spa');

        $this->assertNotNull($error);
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertSame([], $this->posiciones_de('HTTP PUT actualizando'));
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), "Sistema intacto: se suelta.\n" . $this->ssh->todo());
        $this->assertNull($this->ssh->posicion_de('php artisan down'));
        $this->assertStringContainsString('Aviso de actualización: liberado', $this->logs($e['upgrade']));
    }

    /**
     * 🔴 Falla en `runtime_preflight` (el marcador del perfil de runtime es inválido): ocurre antes de
     * la primera etapa, con `etapa_en_curso` en null. El sistema está intacto: se suelta.
     */
    public function test_una_falla_en_runtime_preflight_suelta_el_aviso(): void
    {
        $e = $this->escenario('vps');
        $this->github_con_el_release();
        $this->ssh->responder('.comerciocity-runtime.json', '{"schema":1,"profile":"desconocido"}');

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertStringContainsString('Marcador runtime inválido', $error->getMessage());
        $this->assertSame([], $this->posiciones_de('HTTP PUT actualizando'));
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), "Sistema intacto: se suelta.\n" . $this->ssh->todo());
        $this->assertNull($this->ssh->posicion_de('php artisan down'));
    }

    /**
     * 🔴 Si el upgrade YA tocó el sistema vivo en un intento anterior (la línea "Sistema vivo tocado" en
     * sus logs, que una reanudación conserva), una falla temprana de la corrida siguiente NO suelta
     * el aviso: el sistema quedó a medio actualizar y no hay motivo para dejar entrar a nadie.
     */
    public function test_una_falla_temprana_con_el_sistema_ya_tocado_en_un_intento_anterior_no_suelta(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        DeploymentLog::create([
            'client_version_upgrade_id' => $e['upgrade']->id,
            'step'                      => 'upload_spa',
            'level'                     => 'warning',
            'line'                      => DeploymentService::MARCA_SISTEMA_TOCADO . 'upload_spa. Desde acá el sistema vivo del cliente queda modificado por este upgrade.',
        ]);

        $error = $this->correr($this->servicio($e['upgrade']), 'compile_spa');

        $this->assertNotNull($error);
        $this->assertSame([], $this->posiciones_de('HTTP PUT libre'), 'Con el sistema ya tocado no se suelta.');
    }

    /**
     * Un `down` heredado de un intento anterior (el sistema ya está en mantenimiento) tampoco se
     * suelta ante una falla temprana.
     */
    public function test_una_falla_temprana_con_el_sistema_en_mantenimiento_no_suelta(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertSame([], $this->posiciones_de('HTTP PUT libre'));
    }

    /* ==========================================================================================
     | 3) G4 y G5: reintento real
     |========================================================================================= */

    /**
     * 🔴 G4: dos fallos del `PUT libre` y un éxito (3 intentos): queda liberado y el deploy cerrado.
     */
    public function test_g4_con_dos_fallos_y_un_exito_queda_liberado(): void
    {
        $this->respuestas_al_libre = [500, 500, 200];
        $e = $this->escenario();

        $this->deploy_completo($e);

        $this->assertCount(3, $this->posiciones_de('HTTP PUT libre'), 'Tres intentos de liberar.');
        $this->assertSame('completed', (string) $e['upgrade']->fresh()->deployment_status);
        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('Aviso de actualización: liberado', $logs);
        $this->assertStringNotContainsString('NO se pudo liberar', $logs);
    }

    /**
     * 🔴 G4: si fallan los tres intentos, una línea de nivel `error` con un texto inconfundible. El
     * upgrade queda `completed` igual (el aviso nunca aborta el deploy) y la etapa que falló no es
     * `complete`.
     */
    public function test_g4_con_los_tres_intentos_fallidos_deja_una_linea_de_error_inconfundible(): void
    {
        $this->respuestas_al_libre = [500];
        $e = $this->escenario();

        $this->deploy_completo($e);

        $this->assertCount(3, $this->posiciones_de('HTTP PUT libre'));
        $this->assertSame('completed', (string) $e['upgrade']->fresh()->deployment_status);

        $linea = DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('line', 'like', 'Aviso de actualización: NO se pudo liberar%')
            ->first();
        $this->assertNotNull($linea, $this->logs($e['upgrade']));
        $this->assertSame('error', $linea->level);
        $this->assertSame('complete', $linea->step);

        /* Ninguna lectura de "el deploy falló" se confunde con esta línea: no es una MARCA_ETAPA_QUE_FALLO. */
        $this->assertFalse(DeploymentLog::where('client_version_upgrade_id', $e['upgrade']->id)
            ->where('line', 'like', DeploymentService::MARCA_ETAPA_QUE_FALLO . '%')->exists());
    }

    /**
     * G5 también reintenta: una falla en compile_spa con el `PUT libre` fallando una vez y después
     * andando termina liberado.
     */
    public function test_g5_tambien_reintenta_el_libre(): void
    {
        $this->respuestas_al_libre = [500, 200];
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertCount(2, $this->posiciones_de('HTTP PUT libre'));
        $this->assertStringContainsString('Aviso de actualización: liberado', $this->logs($e['upgrade']));
    }

    /**
     * Un 404 al liberar (la versión del cliente no tiene el aviso) no se reintenta: no hay nada que
     * esperar.
     */
    public function test_un_404_al_liberar_no_se_reintenta(): void
    {
        $this->respuestas_al_libre = [404];
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), 'Un 404 no se reintenta.');
    }
}
