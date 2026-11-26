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
 * Misión `aviso-actualizacion-sistema` (25/11/2026) — los ganchos del pipeline del admin que
 * prenden y apagan el aviso "estamos actualizando" en el sistema del cliente (CA14, CA15, CA16 y la
 * mitad pipeline de CA17).
 *
 * Qué se fija, con los mismos dobles de SSH y SFTP que `RespaldoYMantenimientoEnElLugarTest`
 * (ningún socket se abre) y con `Http::fake` para el admin-sync de empresa-api:
 *
 *  1. 🔴 CA14. Con el interruptor prendido, un deploy EN EL LUGAR desde cero hace `PUT
 *     admin-sync/actualizacion-del-sistema {estado:"actualizando"}` DESPUÉS del respaldo y ANTES de
 *     vaciar el SPA vivo y del `php artisan down` (G1); lo repite DESPUÉS de `php artisan migrate
 *     --force` y antes del `up` (G2); lo renueva al empezar cada corrida post-cierre (G3); y manda
 *     `PUT {estado:"libre"}` DESPUÉS de `complete` (G4).
 *  2. 🔴 CA15. Si empresa-api contesta 404 (la versión instalada todavía no tiene el endpoint), el
 *     deploy termina igual que sin el aviso —mismo `deployment_status`, mismos comandos SSH— y queda
 *     la línea de log "no lo tiene (404)".
 *  3. 🔴 CA16. Una falla temprana (`compile_spa`: nada vivo tocado) libera el aviso; una falla
 *     después del `down` (`run_migrations`) NO lo libera: el sistema queda en mantenimiento por
 *     diseño y lo suelta el vencimiento o la reanudación.
 *  4. 🔴 CA17 (lado pipeline). Con el interruptor apagado no sale ni una request a
 *     `actualizacion-del-sistema`, ni queda una línea de log del aviso. Tampoco en una rotación.
 *
 * El orden se afirma sobre UNA sola secuencia: cada request del aviso deja una marca (`HTTP PUT
 * actualizando`, `HTTP PUT libre`, …) en el MISMO registro donde el doble de SSH anota sus
 * comandos, y `posicion_de()` compara las dos cosas.
 *
 * PHP 7.4.
 */
class PipelineAvisoDeActualizacionTest extends TestCase
{
    use DatabaseTransactions;

    /** Contraseña de la base del frente: distintiva, para el `.env` en memoria. */
    const PASSWORD_DE_LA_BASE = 'Clave-Del-Aviso"0x7f\\fin';

    /** Home del usuario SSH del shared, tal como lo contestaría el servidor. */
    const HOME_SHARED = '/home/u767360347';

    /** Clave de admin-sync del cliente de los escenarios (`clients.api_key`). */
    const CLAVE_DEL_CLIENTE = 'clave-api-del-aviso';

    /** @var SshDelDeploymentFake */
    private $ssh;

    /** @var SftpDelDeploymentFake */
    private $sftp;

    /** @var EnvSshServiceFake */
    private $env;

    /** @var int */
    private $contador = 0;

    /**
     * Qué contesta empresa-api al admin-sync del aviso: 200 (lo tiene), 404 (versión vieja), 500.
     *
     * @var int
     */
    private $http_del_aviso = 200;

    /**
     * Cada request que el admin le hizo al aviso de empresa-api, en orden: método, url, datos y la
     * clave que viajó en `X-Admin-Api-Key`.
     *
     * @var array<int, array<string, mixed>>
     */
    private $pedidos_al_aviso = [];

    /**
     * True cuando este test ya registró el stub de GitHub (se registra una sola vez).
     *
     * @var bool
     */
    private $github_falseado = false;

    /**
     * True cuando este test ya cerró el resto de la red (un solo catch-all, siempre al final).
     *
     * @var bool
     */
    private $red_cerrada = false;

    /**
     * `deployment_status` del upgrade en el instante en que llegó el `PUT libre` (para afirmar que
     * se liberó DESPUÉS de `complete`).
     *
     * @var string|null
     */
    private $estado_al_liberar = null;

    /**
     * Credenciales SSH a un puerto cerrado de localhost, dobles preparados, el interruptor del aviso
     * PRENDIDO y el reloj congelado de madrugada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* El hook `saved` de ClientVersionUpgrade despacha el aviso post-actualización al cerrar. */
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

        $this->reiniciar_dobles();

        config(['aviso_de_actualizacion.habilitado' => true]);

        Carbon::setTestNow(Carbon::parse('2026-11-26 02:00:00', config('app.timezone')));

        $this->falsear_el_admin_sync_del_cliente();
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
     * Dobles nuevos de SSH, SFTP y `.env` (los tests que corren dos deploys seguidos necesitan que
     * el registro de comandos del segundo empiece vacío).
     *
     * @return void
     */
    private function reiniciar_dobles(): void
    {
        $this->ssh  = new SshDelDeploymentFake();
        $this->sftp = new SftpDelDeploymentFake();
        $this->env  = new EnvSshServiceFake();
        $this->app->instance(EnvSshService::class, $this->env);
        $this->pedidos_al_aviso = [];
        $this->estado_al_liberar = null;
    }

    /**
     * Registra el stub del admin-sync de empresa-api. Va PRIMERO (los stubs de GitHub y el
     * catch-all se registran después, y gana el primero que responde).
     *
     * Cada request del aviso (`…/actualizacion-del-sistema…`) deja su marca en el registro de
     * comandos del SSH doble; la del `update-default-version` también, para poder ordenar el
     * `libre` contra el cierre. 🔴 No es decoración: sin una sola secuencia no hay forma de afirmar
     * "antes del down" o "después del complete" mezclando SSH y HTTP.
     *
     * @return void
     */
    private function falsear_el_admin_sync_del_cliente(): void
    {
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
            $metodo = (string) $request->method();
            $estado = isset($datos['estado']) ? (string) $datos['estado'] : null;

            $this->pedidos_al_aviso[] = [
                'metodo'   => $metodo,
                'url'      => $url,
                'datos'    => $datos,
                'api_key'  => $request->hasHeader('X-Admin-Api-Key') ? $request->header('X-Admin-Api-Key')[0] : null,
            ];

            $this->ssh->comandos[] = 'HTTP ' . $metodo . ($estado !== null ? ' ' . $estado : '');

            if ($estado === 'libre' && isset($datos['upgrade_id'])) {
                $this->estado_al_liberar = (string) ClientVersionUpgrade::where('id', (int) $datos['upgrade_id'])
                    ->value('deployment_status');
            }

            if ($this->http_del_aviso === 404) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            if ($this->http_del_aviso !== 200) {
                return Http::response(['message' => 'Server Error'], $this->http_del_aviso);
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
     * Cierra el resto de la red: cualquier otra request contesta 200 vacío. 🔴 Se llama DESPUÉS de
     * los stubs de GitHub (gana el primero que responde, y un `*` antes los taparía).
     *
     * @return void
     */
    private function cerrar_el_resto_de_la_red(): void
    {
        if ($this->red_cerrada) {
            return;
        }
        $this->red_cerrada = true;

        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * Upgrade EN EL LUGAR (activa = principal = destino) de un cliente con dos frentes.
     *
     * @param  string  $activo   'principal' | 'segundo'
     * @param  string  $destino  'principal' | 'segundo'
     * @return array<string, mixed>
     */
    private function escenario(string $activo = 'principal', string $destino = 'principal'): array
    {
        $this->contador++;

        $from = $this->crear_version('9.' . $this->contador . '.0');
        $to   = $this->crear_version('9.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente aviso';
        $client->company_name       = 'Empresa aviso';
        $client->slug               = 'aviso-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = self::CLAVE_DEL_CLIENTE;
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta   = 'aviso' . $this->contador . Str::lower(Str::random(3));
        $principal = $this->crear_api($client, $carpeta);
        $segundo   = $this->crear_api($client, $carpeta . '2');

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

        $this->env->envs[$api_destino->id] = "APP_NAME=Aviso\n"
            . "DB_CONNECTION=mysql\n"
            . "DB_HOST=127.0.0.1\n"
            . "DB_PORT=3306\n"
            . "DB_DATABASE=u767360347_aviso\n"
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
     * @return ClientApi
     */
    private function crear_api(Client $client, string $carpeta): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com/public';
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = 'shared_hosting';
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

        /* Por defecto el sistema NO está en mantenimiento. */
        $this->ssh->responder('storage/framework/down', 'CC_SIN_MANTENIMIENTO');

        $servicio->connect();

        return $servicio;
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
     * Respuestas del servidor para un respaldo que sale bien.
     *
     * @return void
     */
    private function servidor_sano(): void
    {
        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('mysqldump', '0');
        $this->ssh->responder('CC_CREDENCIALES_BORRADAS', 'CC_CREDENCIALES_BORRADAS');
        $this->ssh->responder('gzip -dc', "-- Dump completed on 2026-11-26  2:00:01\n524288");
        $this->ssh->responder('tar --warning=no-file-changed', 'CC_TAR_EXIT=0');
        $this->ssh->responder('gzip -t', '524288');
    }

    /**
     * Deja en el disco (en lo que contesta el doble) un respaldo completo de ESTE upgrade, hecho
     * por un intento anterior, para poder reanudar desde una etapa posterior al respaldo.
     *
     * @param  array<string, mixed>  $e
     * @return void
     */
    private function respaldo_en_disco(array $e): void
    {
        $nombre = '20261125-020000-upgrade-' . $e['upgrade']->id;

        $this->ssh->responder('printf %s "$HOME"', self::HOME_SHARED);
        $this->ssh->responder('ls -1', $nombre);
        $this->ssh->responder('CC_RESPALDO_COMPLETO', "CC_RESPALDO_COMPLETO\nCC_RESPALDO_TOCADO");
    }

    /**
     * Fakes de GitHub con la API (y, si se pide, el SPA) del release. Se registran UNA sola vez por
     * test y valen para cualquier versión: los nombres de los assets se arman con el tag que viene
     * en la URL (un test que corre dos deploys seguidos usa dos versiones distintas).
     *
     * @param  Version  $to
     * @param  bool     $con_spa
     * @return void
     */
    private function artefactos_en_github(Version $to, bool $con_spa = false): void
    {
        if ($this->github_falseado) {
            return;
        }
        $this->github_falseado = true;

        $api = $this->zip(['artisan' => "#!/usr/bin/env php\n<?php\n", 'composer.lock' => '{}']);
        $spa = $this->zip(['index.html' => '<!doctype html><title>spa</title>']);

        Http::fake(function ($request) use ($api, $spa, $con_spa) {
            $url = (string) $request->url();

            if (preg_match('#/releases/tags/([^/?]+)#', $url, $coincidencia)) {
                $tag    = $coincidencia[1];
                $assets = [[
                    'id'                   => 1,
                    'name'                 => 'empresa-api-' . $tag . '.zip',
                    'size'                 => strlen($api),
                    'url'                  => 'https://api.github.com/repos/lucasgonzz/empresa-api/releases/assets/1',
                    'browser_download_url' => 'https://github.com/x/1',
                ]];

                if ($con_spa) {
                    $assets[] = [
                        'id'                   => 2,
                        'name'                 => 'empresa-spa-' . $tag . '-dist.zip',
                        'size'                 => strlen($spa),
                        'url'                  => 'https://api.github.com/repos/lucasgonzz/empresa-spa/releases/assets/2',
                        'browser_download_url' => 'https://github.com/x/2',
                    ];
                }

                return Http::response(['id' => 1, 'assets' => $assets], 200);
            }

            if (preg_match('#/releases/assets/1$#', $url)) {
                return Http::response($api, 200);
            }

            if (preg_match('#/releases/assets/2$#', $url)) {
                return Http::response($spa, 200);
            }

            return null;
        });
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
     * Posiciones (en el registro único de SSH + HTTP) de todas las entradas que contienen la aguja.
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
     * Las tres corridas de un deploy en el lugar completo, como las dispara la skill: el pre-cierre
     * desde cero (hasta la pausa), `start-post-closure` (desde `run_seeders`) y `configure-system`
     * (desde `update_default_version`, que cierra con `complete`).
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>  `errores` (por corrida) y `status` final del upgrade.
     */
    private function deploy_completo(array $e): array
    {
        $this->artefactos_en_github($e['to'], true);
        $this->cerrar_el_resto_de_la_red();
        $this->servidor_sano();

        $errores   = [];
        $errores[] = $this->correr($this->servicio($e['upgrade']));
        $errores[] = $this->correr($this->servicio($e['upgrade']), 'run_seeders');
        $errores[] = $this->correr($this->servicio($e['upgrade']), 'update_default_version');

        return [
            'errores' => $errores,
            'status'  => (string) $e['upgrade']->fresh()->deployment_status,
        ];
    }

    /* ==========================================================================================
     | CA14) El orden de los ganchos
     |========================================================================================= */

    /**
     * 🔴 CA14. Prende DESPUÉS del respaldo y ANTES de vaciar el SPA vivo y del `down`; repite
     * DESPUÉS de migrar y antes del `up`; renueva al empezar cada corrida post-cierre; y libera
     * DESPUÉS de `complete` (con el upgrade ya cerrado).
     */
    public function test_el_aviso_se_prende_antes_de_tocar_se_renueva_y_se_libera_al_completar(): void
    {
        $e = $this->escenario();

        $resultado = $this->deploy_completo($e);
        foreach ($resultado['errores'] as $indice => $error) {
            $this->assertNull(
                $error,
                'La corrida ' . $indice . ' falló: ' . ($error === null ? '' : $error->getMessage()) . "\n" . $this->logs($e['upgrade'])
            );
        }
        $this->assertSame('completed', $resultado['status']);

        $prende = $this->posiciones_de('HTTP PUT actualizando');
        $libera = $this->posiciones_de('HTTP PUT libre');

        /* Pre-cierre: dos veces (G1 y G2). Post-cierre: una por corrida (G3 × 2). */
        $this->assertCount(4, $prende, "PUT actualizando esperados: G1, G2 y G3 de las dos corridas.\n" . $this->ssh->todo());
        $this->assertCount(1, $libera, "PUT libre esperado una sola vez (G4).\n" . $this->ssh->todo());

        $respaldo   = $this->posiciones_de("mv '" . self::HOME_SHARED . '/cc-respaldos/' . $e['carpeta_destino']);
        $vacia_spa  = $this->ssh->posicion_de('find . -mindepth 1 -delete');
        $down       = $this->ssh->posicion_de('php artisan down');
        $migrate    = $this->ssh->posicion_de('php artisan migrate --force');
        $seeders    = $this->ssh->posicion_de('db:seed');
        $up         = $this->ssh->posicion_de('php artisan up');
        $default    = $this->ssh->posicion_de('HTTP PUT update-default-version');

        foreach (compact('vacia_spa', 'down', 'migrate', 'up', 'default') as $nombre => $posicion) {
            $this->assertNotNull($posicion, 'Falta ' . $nombre . "\n" . $this->ssh->todo());
        }
        $this->assertNotEmpty($respaldo, "No se encontró el cierre del respaldo.\n" . $this->ssh->todo());

        /* G1: después del respaldo, antes de vaciar el SPA vivo y del down. */
        $this->assertGreaterThan($respaldo[0], $prende[0], 'G1 va DESPUÉS del respaldo.');
        $this->assertLessThan($vacia_spa, $prende[0], 'G1 va ANTES de vaciar el SPA vivo.');
        $this->assertLessThan($down, $prende[0], 'G1 va ANTES del php artisan down.');

        /* G2: después de migrar, antes del up (y antes de los seeders). */
        $this->assertGreaterThan($migrate, $prende[1], 'G2 va DESPUÉS de php artisan migrate --force.');
        $this->assertLessThan($up, $prende[1], 'G2 va ANTES del php artisan up.');
        if ($seeders !== null) {
            $this->assertLessThan($seeders, $prende[1], 'G2 va ANTES de los seeders.');
        }

        /* G3: las dos renovaciones del post-cierre van después del up, y la segunda antes de cerrar. */
        $this->assertGreaterThan($up, $prende[2]);
        $this->assertGreaterThan($up, $prende[3]);
        $this->assertLessThan($default, $prende[3], 'G3 de configure-system va ANTES del update-default-version.');

        /* G4: DESPUÉS del cierre, y con el upgrade ya completado. */
        $this->assertGreaterThan($default, $libera[0], 'G4 va DESPUÉS del update-default-version.');
        $this->assertGreaterThan($prende[3], $libera[0]);
        $this->assertSame('completed', $this->estado_al_liberar, 'El libre se manda DESPUÉS de step_complete().');

        /* Lo que viaja: PUT actualizando con 120 minutos, el upgrade y la versión destino. */
        $this->assertSame('PUT', $this->pedidos_al_aviso[0]['metodo']);
        $this->assertStringEndsWith('/api/admin-sync/actualizacion-del-sistema', $this->pedidos_al_aviso[0]['url']);
        $this->assertSame('actualizando', $this->pedidos_al_aviso[0]['datos']['estado']);
        $this->assertSame(120, (int) $this->pedidos_al_aviso[0]['datos']['vence_en_minutos']);
        $this->assertSame((int) $e['upgrade']->id, (int) $this->pedidos_al_aviso[0]['datos']['upgrade_id']);
        $this->assertSame((string) $e['to']->version, (string) $this->pedidos_al_aviso[0]['datos']['version']);
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $this->pedidos_al_aviso[0]['api_key']);

        $logs = $this->logs($e['upgrade']);
        $this->assertStringContainsString('Aviso de actualización: prendido hasta', $logs);
        $this->assertStringContainsString('Aviso de actualización: liberado', $logs);
    }

    /**
     * 🔴 CA14. Reanudar desde una etapa posterior a `upload_spa` también prende el aviso, antes de
     * la primera etapa de esa corrida (si no, una reanudación dejaría entrar a los usuarios).
     */
    public function test_una_reanudacion_posterior_a_upload_spa_prende_antes_de_la_primera_etapa(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $this->cerrar_el_resto_de_la_red();
        $this->respaldo_en_disco($e);
        $this->ssh->responder('storage/framework/down', 'CC_YA_EN_MANTENIMIENTO');

        $error = $this->correr($this->servicio($e['upgrade']), 'run_migrations');
        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));

        $prende  = $this->posiciones_de('HTTP PUT actualizando');
        $migrate = $this->ssh->posicion_de('php artisan migrate --force');

        $this->assertNotEmpty($prende, "No se prendió el aviso al reanudar.\n" . $this->ssh->todo());
        $this->assertNotNull($migrate);
        $this->assertLessThan($migrate, $prende[0], 'Al reanudar, el aviso se prende ANTES de la primera etapa.');
    }

    /* ==========================================================================================
     | CA15) Versión vieja del cliente
     |========================================================================================= */

    /**
     * 🔴 CA15. Empresa-api contesta 404: el deploy termina igual que sin el aviso (mismo
     * `deployment_status` y los mismos comandos SSH, salvo lo propio de cada escenario) y queda la
     * línea de log "no lo tiene (404)".
     */
    public function test_con_la_version_vieja_del_cliente_el_deploy_termina_igual_que_sin_el_aviso(): void
    {
        /* Línea base: el mismo deploy con el interruptor APAGADO. */
        config(['aviso_de_actualizacion.habilitado' => false]);
        $base       = $this->escenario();
        $resultado0 = $this->deploy_completo($base);
        foreach ($resultado0['errores'] as $error) {
            $this->assertNull($error, $error === null ? '' : $error->getMessage());
        }
        $ssh_base = $this->normalizado($this->sin_marcas_de_http($this->ssh->comandos), $base);

        /* Mismo deploy, interruptor PRENDIDO y empresa-api sin el endpoint (404). */
        $this->reiniciar_dobles();
        config(['aviso_de_actualizacion.habilitado' => true]);
        $this->http_del_aviso = 404;
        $con_aviso            = $this->escenario();
        $resultado1           = $this->deploy_completo($con_aviso);
        foreach ($resultado1['errores'] as $error) {
            $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($con_aviso['upgrade']));
        }

        $this->assertSame($resultado0['status'], $resultado1['status']);
        $this->assertSame('completed', $resultado1['status']);

        $this->assertSame(
            $ssh_base,
            $this->normalizado($this->sin_marcas_de_http($this->ssh->comandos), $con_aviso),
            'Con la versión vieja del cliente (404) los comandos SSH tienen que ser los mismos que sin el aviso.'
        );

        $this->assertNotEmpty($this->pedidos_al_aviso, 'El admin tendría que haber intentado el aviso.');
        $this->assertStringContainsString(
            'Aviso de actualización: la versión del cliente no lo tiene (404), se sigue como hoy',
            $this->logs($con_aviso['upgrade'])
        );
        $this->assertStringNotContainsString('Aviso de actualización: prendido hasta', $this->logs($con_aviso['upgrade']));
    }

    /**
     * Un error del cliente que NO es 404 (500) tampoco aborta el deploy: queda "no respondió".
     */
    public function test_un_500_del_cliente_no_aborta_el_deploy_y_queda_en_el_log(): void
    {
        $this->http_del_aviso = 500;
        $e                    = $this->escenario();

        $resultado = $this->deploy_completo($e);
        foreach ($resultado['errores'] as $error) {
            $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        }

        $this->assertSame('completed', $resultado['status']);
        $this->assertStringContainsString('Aviso de actualización: no respondió (HTTP 500), se sigue', $this->logs($e['upgrade']));
    }

    /* ==========================================================================================
     | CA16) Fallas
     |========================================================================================= */

    /**
     * 🔴 CA16. Falla en `compile_spa` (nada vivo tocado): el aviso se libera. Y como todavía no se
     * había prendido en esta corrida, el primer movimiento es el libre.
     */
    public function test_una_falla_en_compile_spa_libera_el_aviso(): void
    {
        $e = $this->escenario();
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $error = $this->correr($this->servicio($e['upgrade']));

        $this->assertNotNull($error);
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertCount(1, $this->posiciones_de('HTTP PUT libre'), "Una falla temprana libera el aviso.\n" . $this->ssh->todo());
        $this->assertSame([], $this->posiciones_de('HTTP PUT actualizando'), 'No llegó a prender nada.');
        $this->assertNull($this->ssh->posicion_de('php artisan down'));
        $this->assertStringContainsString('Aviso de actualización: liberado', $this->logs($e['upgrade']));
    }

    /**
     * 🔴 CA16. Falla en `run_migrations` (ya con el sistema en mantenimiento): el aviso NO se libera.
     * Quedó prendido por G1 y no se repite G2 (la migración no terminó).
     */
    public function test_una_falla_en_run_migrations_no_libera_el_aviso(): void
    {
        $e = $this->escenario();
        $this->artefactos_en_github($e['to']);
        $this->cerrar_el_resto_de_la_red();
        $this->respaldo_en_disco($e);
        $this->ssh->responder('php artisan migrate --force', 'SQLSTATE[42S01]: Base table or view already exists', 1);

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_api');

        $this->assertNotNull($error);
        $this->assertSame('failed', (string) $e['upgrade']->fresh()->deployment_status);
        $this->assertNotNull($this->ssh->posicion_de('php artisan down'), 'El sistema quedó en mantenimiento.');
        $this->assertCount(1, $this->posiciones_de('HTTP PUT actualizando'), 'Sólo G1: G2 va después de migrar.');
        $this->assertSame([], $this->posiciones_de('HTTP PUT libre'), 'Una falla después del down no libera el aviso.');
        $this->assertStringNotContainsString('Aviso de actualización: liberado', $this->logs($e['upgrade']));
    }

    /* ==========================================================================================
     | CA17) Interruptor apagado
     |========================================================================================= */

    /**
     * 🔴 CA17 (lado pipeline). Con el interruptor apagado un deploy completo no manda NI UNA request
     * al aviso de empresa-api, y el log no menciona el aviso.
     */
    public function test_con_el_interruptor_apagado_el_deploy_no_toca_el_aviso(): void
    {
        config(['aviso_de_actualizacion.habilitado' => false]);
        $e = $this->escenario();

        $resultado = $this->deploy_completo($e);
        foreach ($resultado['errores'] as $error) {
            $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        }

        $this->assertSame('completed', $resultado['status']);
        Http::assertNotSent(function ($request) {
            return strpos((string) $request->url(), 'actualizacion-del-sistema') !== false;
        });
        $this->assertSame([], $this->pedidos_al_aviso);
        $this->assertStringNotContainsString('Aviso de actualización', $this->logs($e['upgrade']));
    }

    /**
     * 🔴 La rotación (destino que no es el frente que atiende) no bloquea al cliente: el sistema
     * viejo sigue atendiendo hasta `configure-system`. Ni siquiera con el interruptor prendido.
     */
    public function test_una_rotacion_no_toca_el_aviso(): void
    {
        $e = $this->escenario('principal', 'segundo');
        $this->artefactos_en_github($e['to']);
        $this->cerrar_el_resto_de_la_red();

        $error = $this->correr($this->servicio($e['upgrade']), 'upload_api');

        $this->assertNull($error, $error === null ? '' : $error->getMessage() . "\n" . $this->logs($e['upgrade']));
        $this->assertNotNull($this->ssh->posicion_de('unzip -o api_'), "No corrió el tramo.\n" . $this->ssh->todo());
        $this->assertSame([], $this->pedidos_al_aviso, 'Una rotación no prende el aviso.');
        $this->assertStringNotContainsString('Aviso de actualización', $this->logs($e['upgrade']));
    }

    /* ------------------------------------------------------------------------------------------
     | Comparación de comandos entre escenarios
     |----------------------------------------------------------------------------------------- */

    /**
     * Saca del registro las marcas de HTTP (`HTTP PUT …`) y deja sólo lo que se mandó por SSH.
     *
     * @param  array<int, string>  $comandos
     * @return array<int, string>
     */
    private function sin_marcas_de_http(array $comandos): array
    {
        return array_values(array_filter($comandos, function ($comando) {
            return strpos($comando, 'HTTP ') !== 0;
        }));
    }

    /**
     * Los comandos de un escenario sin lo que es propio de él (la carpeta, el id del upgrade y los
     * uuid de los archivos temporales), para poder comparar dos deploys de escenarios distintos.
     *
     * @param  array<int, string>    $comandos
     * @param  array<string, mixed>  $e
     * @return array<int, string>
     */
    private function normalizado(array $comandos, array $e): array
    {
        $salida = [];
        foreach ($comandos as $comando) {
            $comando = str_replace($e['carpeta'], 'CARPETA', $comando);
            $comando = str_replace('upgrade-' . $e['upgrade']->id, 'upgrade-N', $comando);
            $comando = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'UUID', $comando);
            $salida[] = $comando;
        }

        return $salida;
    }
}
