<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use App\Services\DeploymentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `aviso-actualizacion-sistema`, RONDA 4 (25/11/2026) — los dos huecos que la revisión 3 encontró
 * en `DeploymentService::aviso_soltar_si_no_se_toco()` (el que llama `RunDeploymentJob` cuando `connect()`
 * lanza y desde `failed()`):
 *
 *  1. 🔴 NUNCA suelta si hay OTRO upgrade del mismo cliente en curso (`running`, `paused` o
 *     `paused_post_tasks`, los estados de ACTIVE_DEPLOYMENT_STATUSES). El aviso es UNO por dueño y
 *     `libre` ignora `upgrade_id`: soltarlo desde el upgrade B le suelta el aviso al A, que puede estar
 *     en migraciones. El caso concreto: el candado lo tiene A, el job de B vence el `block(10)`, marca B
 *     `failed`, relanza, y `failed()` de B miraba sólo SUS logs (vacíos). Tampoco suelta si `failed()`
 *     recibe la `LockTimeoutException`.
 *  2. 🔴 El "ya liberado" se limita a la CORRIDA ACTUAL. Una reanudación conserva los logs del intento
 *     anterior, y el "liberado" de aquél impedía soltar el aviso en el intento nuevo (renovado por
 *     `deploy/start`, con el sistema intacto). El ancla de la corrida es `deployment_running_since`:
 *     la estampan TODOS los caminos que arrancan un deployment (panel, `claude/upgrades` y el circuito
 *     de soporte) junto con `deployment_status = running`. Las señales de "sistema tocado" NO se
 *     acotan: lo que el sistema vivo ya sufrió en un intento anterior sigue siendo verdad.
 *
 * PHP 7.4.
 */
class AvisoSueltaFallasDelJobRonda4Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int */
    private $contador = 0;

    /**
     * Marcas del admin-sync en orden (`PUT libre`, `PUT actualizando`, …).
     *
     * @var array<int, string>
     */
    private $marcas = [];

    /**
     * Credenciales SSH a un puerto cerrado de localhost (el `connect()` real falla sin red), el
     * interruptor prendido, la cola falsa y el stub del admin-sync.
     *
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

        config([
            'aviso_de_actualizacion.habilitado'           => true,
            'aviso_de_actualizacion.esperas_de_reintento' => [0, 0],
        ]);

        Http::fake(function ($request) {
            if (strpos((string) $request->url(), '/api/admin-sync/actualizacion-del-sistema') === false) {
                return null;
            }

            $datos  = (array) $request->data();
            $estado = isset($datos['estado']) ? (string) $datos['estado'] : null;

            $this->marcas[] = 'HTTP ' . $request->method() . ($estado !== null ? ' ' . $estado : '');

            return Http::response([
                'ok'         => true,
                'owner_id'   => 1,
                'estado'     => $estado === 'libre' ? 'libre' : 'actualizando',
                'upgrade_id' => null,
                'version'    => null,
                'vence_at'   => now()->addMinutes(120)->toIso8601String(),
                'pedido'     => null,
            ], 200);
        });
    }

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * Upgrade EN EL LUGAR, ya en `running` (como lo deja `deploy/start`), de un cliente con dos frentes.
     *
     * @return ClientVersionUpgrade
     */
    private function upgrade_en_marcha(): ClientVersionUpgrade
    {
        $this->contador++;

        $from = $this->crear_version('7.' . $this->contador . '.0');
        $to   = $this->crear_version('7.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente fallas del job';
        $client->company_name       = 'Empresa fallas del job';
        $client->slug               = 'job-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api-del-job';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta   = 'jobfalla' . $this->contador . Str::lower(Str::random(3));
        $principal = $this->crear_api($client, $carpeta);
        $this->crear_api($client, $carpeta . '2');

        $client->active_client_api_id = $principal->id;
        $client->save();

        return ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $principal->id,
            'deployment_status'    => 'running',
        ]);
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
     * Deja la marca de que el upgrade ya tocó el sistema vivo, como la escribe el pipeline.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @return void
     */
    private function marcar_tocado(ClientVersionUpgrade $upgrade): void
    {
        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'upload_spa',
            'level'                     => 'warning',
            'line'                      => DeploymentService::MARCA_SISTEMA_TOCADO . 'upload_spa. Desde acá el sistema vivo del cliente queda modificado por este upgrade.',
        ]);
    }

    /**
     * Cuántas veces el admin le soltó el aviso al cliente.
     *
     * @return int
     */
    private function libres(): int
    {
        return count(array_keys($this->marcas, 'HTTP PUT libre', true));
    }

    /**
     * Corre `handle()` del job y devuelve la excepción con la que terminó (o null).
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @return \Throwable|null
     */
    private function correr_el_job(ClientVersionUpgrade $upgrade)
    {
        try {
            (new RunDeploymentJob($upgrade))->handle();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * Otro upgrade del mismo cliente (versiones propias) en ese estado de deployment.
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
            'from_version_id'      => $this->crear_version('6.' . $this->contador . '.0')->id,
            'to_version_id'        => $this->crear_version('6.' . $this->contador . '.1')->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $upgrade->target_client_api_id,
            'deployment_status'    => $estado,
        ]);
    }

    /**
     * Estampa el ancla de la corrida actual, como lo hace todo `deploy/start`.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  \Carbon\Carbon       $desde
     * @return void
     */
    private function corrida_arrancada(ClientVersionUpgrade $upgrade, $desde): void
    {
        $upgrade->update(['deployment_running_since' => $desde, 'deployment_started_at' => $desde]);
    }

    /**
     * Una línea de log con fecha.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  string                $linea
     * @param  \Carbon\Carbon       $cuando
     * @param  string                $nivel
     * @return void
     */
    private function linea(ClientVersionUpgrade $upgrade, string $linea, $cuando, string $nivel = 'info'): void
    {
        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => $nivel,
            'line'                      => $linea,
            'created_at'                => $cuando,
        ]);
    }

    /* ==========================================================================================
     | (a) Otro upgrade del mismo cliente en curso
     |========================================================================================= */

    /**
     * 🔴 El candado lo tiene el upgrade A y hay un job de B (mismo cliente): B vence el `block(10)`, se
     * marca `failed`, relanza, y después el worker llama a `failed()`. Ni `handle()` ni ese `failed()`
     * pueden soltar el aviso: es el de A, que sigue corriendo. (Espera los 10 s reales del `block()`.)
     */
    public function test_con_el_candado_tomado_y_otro_upgrade_del_cliente_corriendo_ni_handle_ni_failed_sueltan(): void
    {
        $b = $this->upgrade_en_marcha();
        $this->corrida_arrancada($b, now()->subMinutes(1));
        $a = $this->otro_upgrade_del_cliente($b, 'running');

        $candado = Cache::lock('client-deployment-' . $b->client_id, 120);
        $this->assertTrue($candado->get());

        try {
            $error = $this->correr_el_job($b);
        } finally {
            $candado->release();
        }

        $this->assertInstanceOf(LockTimeoutException::class, $error, 'Con otro upgrade corriendo el job relanza el timeout.');
        $this->assertSame('failed', (string) $b->fresh()->deployment_status);
        $this->assertSame('running', (string) $a->fresh()->deployment_status, 'El upgrade dueño del candado no se toca.');
        $this->assertSame(0, $this->libres(), 'handle() no suelta.');

        (new RunDeploymentJob($b))->failed($error);

        $this->assertSame(0, $this->libres(), 'El failed() posterior tampoco: el aviso es el de A. ' . implode("\n", $this->marcas));
    }

    /**
     * 🔴 Pausado también es "en curso": un upgrade en `paused_post_tasks` (en el lugar, a la espera del
     * post-cierre) sigue bloqueando el sistema. Un `failed()` de otro upgrade del cliente no lo suelta.
     */
    public function test_failed_no_suelta_si_otro_upgrade_del_cliente_esta_pausado(): void
    {
        $b = $this->upgrade_en_marcha();
        $this->corrida_arrancada($b, now()->subMinutes(1));
        $this->otro_upgrade_del_cliente($b, 'paused_post_tasks');

        (new RunDeploymentJob($b))->failed(new \RuntimeException('El job excedió el timeout.'));

        $this->assertSame(0, $this->libres(), implode("\n", $this->marcas));
    }

    /**
     * 🔴 Si `failed()` recibe la `LockTimeoutException` (el job nunca tuvo el candado) no suelta, aunque
     * en ese instante ya no se vea otro upgrade en curso (el otro pudo terminar entre medio).
     */
    public function test_failed_con_lock_timeout_no_suelta(): void
    {
        $b = $this->upgrade_en_marcha();
        $this->corrida_arrancada($b, now()->subMinutes(1));

        (new RunDeploymentJob($b))->failed(new LockTimeoutException('No se pudo tomar el candado.'));

        $this->assertSame(0, $this->libres(), implode("\n", $this->marcas));
    }

    /* ==========================================================================================
     | (b) El "liberado" de un intento anterior no cuenta
     |========================================================================================= */

    /**
     * 🔴 Un intento anterior terminó con "Aviso de actualización: liberado"; la reanudación conserva los
     * logs, deja su línea "Reanudando desde…" y el aviso se renovó. Si ahora `connect()` falla, el
     * "liberado" viejo no puede impedir soltar: es del intento anterior, no de esta corrida.
     */
    public function test_el_liberado_de_un_intento_anterior_no_impide_soltar_cuando_connect_falla(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->linea($upgrade, 'Aviso de actualización: liberado', now()->subMinutes(30), 'success');
        $this->linea($upgrade, 'Reanudando desde compile_spa (pedido por claude/upgrades): se conservan los logs del intento anterior.', now()->subMinutes(1));
        $this->corrida_arrancada($upgrade, now()->subMinutes(1));

        $error = $this->correr_el_job($upgrade);

        $this->assertNotNull($error, 'El connect() contra el puerto cerrado tiene que lanzar.');
        $this->assertSame(1, $this->libres(), implode("\n", $this->marcas));
    }

    /**
     * 🔴 Lo mismo desde `failed()` (timeout del worker en la corrida nueva).
     */
    public function test_el_liberado_de_un_intento_anterior_no_impide_soltar_desde_failed(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->linea($upgrade, 'Aviso de actualización: liberado', now()->subMinutes(30), 'success');
        $this->linea($upgrade, 'Reanudando desde compile_spa (pedido por claude/upgrades): se conservan los logs del intento anterior.', now()->subMinutes(1));
        $this->corrida_arrancada($upgrade, now()->subMinutes(1));

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('El job excedió el timeout.'));

        $this->assertSame(1, $this->libres(), implode("\n", $this->marcas));
    }

    /**
     * Control: la marca de "sistema tocado" de un intento anterior SÍ sigue valiendo en la corrida nueva
     * (lo que el sistema vivo ya sufrió no se borra por reanudar): no se suelta.
     */
    public function test_el_sistema_tocado_en_un_intento_anterior_sigue_impidiendo_soltar(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->linea(
            $upgrade,
            DeploymentService::MARCA_SISTEMA_TOCADO . 'upload_spa. Desde acá el sistema vivo del cliente queda modificado por este upgrade.',
            now()->subMinutes(30),
            'warning'
        );
        $this->corrida_arrancada($upgrade, now()->subMinutes(1));

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('El job excedió el timeout.'));

        $this->assertSame(0, $this->libres());
    }

    /* ==========================================================================================
     | (c) El "liberado" de ESTA corrida sí cuenta
     |========================================================================================= */

    /**
     * Control: si esta misma corrida ya soltó el aviso (G5, o el `connect()` de arriba) y después el
     * worker llama a `failed()`, no se repite el PUT.
     */
    public function test_el_liberado_de_esta_corrida_no_se_repite_en_failed(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->corrida_arrancada($upgrade, now()->subMinutes(5));
        $this->linea($upgrade, 'Aviso de actualización: liberado', now()->subMinutes(1), 'success');

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('El job terminó fallado.'));

        $this->assertSame(0, $this->libres(), 'Ya estaba liberado en esta corrida: no se repite.');
    }

    /**
     * Control del recorrido completo: `connect()` falla, suelta una vez, y el `failed()` que llama el
     * worker después no lo repite.
     */
    public function test_connect_que_falla_y_el_failed_posterior_sueltan_una_sola_vez(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->corrida_arrancada($upgrade, now()->subMinutes(1));

        $error = $this->correr_el_job($upgrade);
        $this->assertNotNull($error);
        (new RunDeploymentJob($upgrade))->failed($error);

        $this->assertSame(1, $this->libres(), implode("\n", $this->marcas));
    }
}
