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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `aviso-actualizacion-sistema`, RONDA 3 (25/11/2026) — las fallas del arranque que ocurren
 * FUERA de `DeploymentService::run()` también sueltan el aviso cuando el sistema no se tocó.
 *
 * G5 vive en el `catch` de `run()`, pero hay tres caminos que no pasan por ahí y dejaban el aviso
 * prendido (hasta 120 minutos, renovado por `deploy/start`) con el sistema del cliente intacto:
 *
 *  1. `RunDeploymentJob::handle()` → `$service->connect()` lanza (SSH rechazado, host inalcanzable);
 *  2. el candado `client-deployment-<cliente>` tarda más de 10 s y el job vuelve en silencio;
 *  3. el worker mata el job por timeout (o la cola lo da por agotado) y sólo corre `failed()`.
 *
 * Cada uno llama a `DeploymentService::aviso_soltar_si_no_se_toco()`, que reusa la lógica de G5: suelta
 * sólo si el interruptor está prendido, es en el lugar y NO hay señal de que el sistema vivo se tocó
 * (marca en los logs, mantenimiento…); si no se puede saber con certeza, NO suelta. Nunca lanza.
 *
 * El job se prueba de verdad (`handle()` y `failed()`): la conexión SSH real apunta a un puerto
 * cerrado de localhost, así que `connect()` falla sola y sin salir de la máquina.
 *
 * PHP 7.4.
 */
class AvisoSueltaFallasDelJobTest extends TestCase
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

    /* ==========================================================================================
     | 1) connect() lanza
     |========================================================================================= */

    /**
     * 🔴 `connect()` falla (SSH rechazado o host inalcanzable): el job termina fallado y suelta el aviso,
     * porque el sistema del cliente no se tocó.
     */
    public function test_si_connect_falla_el_job_suelta_el_aviso(): void
    {
        $upgrade = $this->upgrade_en_marcha();

        $error = $this->correr_el_job($upgrade);

        $this->assertNotNull($error, 'El connect() contra el puerto cerrado tiene que lanzar.');
        $this->assertSame('failed', (string) $upgrade->fresh()->deployment_status);
        $this->assertSame(1, $this->libres(), implode("\n", $this->marcas));
        $this->assertStringContainsString('Aviso de actualización: liberado', $this->logs($upgrade));
    }

    /**
     * 🔴 Control: si el upgrade YA tocó el sistema vivo en un intento anterior (la marca en sus logs, que
     * una reanudación conserva), un `connect()` fallido NO suelta el aviso.
     */
    public function test_si_connect_falla_con_el_sistema_ya_tocado_no_suelta(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->marcar_tocado($upgrade);

        $error = $this->correr_el_job($upgrade);

        $this->assertNotNull($error);
        $this->assertSame(0, $this->libres(), 'Con el sistema ya tocado no se suelta.');
    }

    /* ==========================================================================================
     | 2) El candado tarda y el job vuelve en silencio
     |========================================================================================= */

    /**
     * 🔴 El candado del cliente está tomado por otro proceso más de 10 s y el job vuelve sin correr ni
     * fallar (`LockTimeoutException` sin otro upgrade del cliente en marcha): el aviso queda prendido
     * y nadie está actualizando, así que se suelta. (Este test espera los 10 s reales del `block()`.)
     */
    public function test_si_el_candado_tarda_y_el_job_vuelve_en_silencio_suelta_el_aviso(): void
    {
        $upgrade = $this->upgrade_en_marcha();

        $candado = Cache::lock('client-deployment-' . $upgrade->client_id, 120);
        $this->assertTrue($candado->get(), 'El test tiene que poder tomar el candado.');

        try {
            $error = $this->correr_el_job($upgrade);
        } finally {
            $candado->release();
        }

        $this->assertNull($error, 'El job vuelve en silencio, sin lanzar.');
        $this->assertSame(1, $this->libres(), implode("\n", $this->marcas));
    }

    /**
     * El método público no suelta si hay actividad reciente en los logs del upgrade: otro proceso
     * puede estar corriendo ESTE upgrade (el candado lo tiene tomado) y no se sabe con certeza. Con los
     * logs quietos, sí.
     */
    public function test_con_actividad_reciente_no_suelta_pero_sin_ella_si(): void
    {
        $upgrade = $this->upgrade_en_marcha();

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'info',
            'line'                      => 'Una línea de un proceso que sigue corriendo.',
            'created_at'                => now(),
        ]);

        (new DeploymentService($upgrade))->aviso_soltar_si_no_se_toco(5);
        $this->assertSame(0, $this->libres(), 'Hay actividad reciente: no se sabe con certeza, no se suelta.');

        DeploymentLog::where('client_version_upgrade_id', $upgrade->id)->update(['created_at' => now()->subMinutes(30)]);

        (new DeploymentService($upgrade->fresh()))->aviso_soltar_si_no_se_toco(5);
        $this->assertSame(1, $this->libres(), 'Con los logs quietos hace media hora, se suelta.');
    }

    /* ==========================================================================================
     | 3) failed(): el worker mató el job
     |========================================================================================= */

    /**
     * 🔴 El worker dio por fallado el job (timeout, `MaxAttemptsExceededException`) y sólo corre
     * `failed()`: el aviso se suelta si el sistema no se tocó.
     */
    public function test_failed_suelta_el_aviso_si_el_sistema_no_se_toco(): void
    {
        $upgrade = $this->upgrade_en_marcha();

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('El job excedió el timeout.'));

        $this->assertSame('failed', (string) $upgrade->fresh()->deployment_status);
        $this->assertSame(1, $this->libres(), implode("\n", $this->marcas));
    }

    /**
     * 🔴 Control: con el sistema tocado (marca en los logs) `failed()` NO suelta: el sistema queda a
     * medio actualizar y lo suelta el vencimiento, la reanudación o la skill.
     */
    public function test_failed_no_suelta_si_el_sistema_ya_se_toco(): void
    {
        $upgrade = $this->upgrade_en_marcha();
        $this->marcar_tocado($upgrade);

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('El job excedió el timeout.'));

        $this->assertSame('failed', (string) $upgrade->fresh()->deployment_status);
        $this->assertSame(0, $this->libres());
    }

    /**
     * Si `run()` ya soltó el aviso (G5) y después el worker llama a `failed()`, no se suelta dos veces.
     */
    public function test_failed_no_vuelve_a_soltar_lo_que_ya_se_solto(): void
    {
        $upgrade = $this->upgrade_en_marcha();

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'success',
            'line'                      => 'Aviso de actualización: liberado',
        ]);

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('El job terminó fallado.'));

        $this->assertSame(0, $this->libres(), 'Ya estaba liberado: no se repite.');
    }

    /* ==========================================================================================
     | Apagado
     |========================================================================================= */

    /**
     * Con el interruptor apagado, ninguno de los tres caminos le habla al cliente.
     */
    public function test_con_el_interruptor_apagado_ningun_camino_toca_el_aviso(): void
    {
        config(['aviso_de_actualizacion.habilitado' => false]);
        $upgrade = $this->upgrade_en_marcha();

        $error = $this->correr_el_job($upgrade);
        $this->assertNotNull($error);

        (new RunDeploymentJob($upgrade))->failed(new \RuntimeException('timeout'));

        $this->assertSame([], $this->marcas);
    }

    /**
     * @param  ClientVersionUpgrade  $upgrade
     * @return string
     */
    private function logs(ClientVersionUpgrade $upgrade): string
    {
        return DeploymentLog::where('client_version_upgrade_id', $upgrade->id)->orderBy('id')->pluck('line')->implode("\n");
    }
}
