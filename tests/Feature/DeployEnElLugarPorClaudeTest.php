<?php

namespace Tests\Feature;

use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `un-solo-frente` (14/10/2026) — `claude/upgrades*` con el destino por defecto en el FRENTE
 * PRINCIPAL y el `deploy/start` EN EL LUGAR.
 *
 * Hasta el 14/10/2026 el `start` sobre la API activa se frenaba siempre (salvo la bandera), porque
 * caer ahí era un accidente. Desde que Lucas decidió dejar de rotar, la activa-que-es-el-principal es
 * el caso normal, y el freno pasa a ser OTRO: el gate de horario del post-cierre. Lo que estos tests
 * fijan:
 *
 *  1. 🔴 En el lugar sobre el principal: sin bandera y con la jornada terminada → 202; con el negocio
 *     abierto → 422 del gate, sin escribir nada; con `force` + `force_reason` → 202 y constancia.
 *  2. 🔴 Activa que NO es el principal (cliente en su frente 2), sin bandera → 422 que explica la
 *     decisión y nombra el principal.
 *  3. `allow_deploy_to_active_api=true` se sigue aceptando (la skill vieja la manda).
 *  4. Una rotación sigue sin gate: es lo que se hace con el negocio abierto.
 *  5. `modo` y `frente_principal_client_api_id` en preview, creación (seco y real), start y GET.
 */
class DeployEnElLugarPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests del test. */
    const CLAVE = 'clave-de-prueba-un-solo-frente';

    /** Nombre del cliente del escenario (lo confirma `confirm_client_name`). */
    const NOMBRE = 'Pinturería Rioplatense';

    /** @var int Para que cada escenario tenga versiones con código distinto. */
    private $contador = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
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
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
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
        $version->is_hotfix    = false;
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * Cliente con sus dos frentes en el shared: `pintu` (principal) y `pintu2`. Los ids van
     * invertidos a propósito (el 2 se crea primero): la regla no puede depender del menor id.
     *
     * @param  string  $activo  'principal' | 'segundo'
     * @return array<string, mixed>
     */
    private function escenario(string $activo = 'principal'): array
    {
        /* El código de versión es único en la tabla: cada escenario usa el suyo. */
        $this->contador++;
        $from = $this->crear_version('7.' . $this->contador . '.0');
        $to   = $this->crear_version('7.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = self::NOMBRE;
        $client->company_name       = 'Empresa ' . self::NOMBRE;
        $client->slug               = 'pinturas-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta = 'pintu' . Str::lower(Str::random(4));

        $segundo = $this->crear_api($client, $carpeta . '2');
        $principal = $this->crear_api($client, $carpeta);

        $client->active_client_api_id = $activo === 'principal' ? $principal->id : $segundo->id;
        $client->save();

        return compact('from', 'to', 'client', 'principal', 'segundo');
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
     * Upgrade ya creado hacia esa API.
     *
     * @param  array<string, mixed>  $e
     * @param  ClientApi             $destino
     * @param  array<string, mixed>  $atributos
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(array $e, ClientApi $destino, array $atributos = []): ClientVersionUpgrade
    {
        return ClientVersionUpgrade::create(array_merge([
            'client_id'            => $e['client']->id,
            'from_version_id'      => $e['from']->id,
            'to_version_id'        => $e['to']->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $destino->id,
        ], $atributos));
    }

    /**
     * Horario de todos los días.
     *
     * @param  Client  $client
     * @param  string  $desde
     * @param  string  $hasta
     * @return void
     */
    private function horario(Client $client, string $desde, string $hasta): void
    {
        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = 'todos';
        $dia->save();

        $rango                         = new ClientScheduleRange();
        $rango->client_schedule_day_id = $dia->id;
        $rango->start_time             = $desde;
        $rango->end_time               = $hasta;
        $rango->sort_order             = 0;
        $rango->save();
    }

    /** Martes 25/8/2026 al mediodía, hora de la app. */
    private function mediodia(): Carbon
    {
        return Carbon::parse('2026-08-25 12:00:00', config('app.timezone'));
    }

    /**
     * @param  ClientVersionUpgrade  $upgrade
     * @param  array<string, mixed>  $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function start(ClientVersionUpgrade $upgrade, array $extra = [])
    {
        return $this->postJson(
            '/api/claude/upgrades/' . $upgrade->id . '/deploy/start',
            array_merge(['confirm_client_name' => self::NOMBRE], $extra),
            $this->headers()
        );
    }

    /**
     * @param  RunDeploymentJob  $job
     * @return string|null
     */
    private function etapa_del_job(RunDeploymentJob $job)
    {
        $propiedad = new \ReflectionProperty(RunDeploymentJob::class, 'resume_from_step');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($job);
    }

    /**
     * Deja en los logs del upgrade la marca de que ya tocó el sistema vivo, como la escribe el
     * pipeline (DeploymentService::marcar_sistema_tocado()).
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  string                $etapa
     * @return void
     */
    private function marcar_tocado(ClientVersionUpgrade $upgrade, string $etapa = 'upload_spa'): void
    {
        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => $etapa,
            'level'                     => 'warning',
            'line'                      => \App\Services\DeploymentService::MARCA_SISTEMA_TOCADO . $etapa
                . '. Desde acá el sistema vivo del cliente queda modificado por este upgrade.',
        ]);
    }

    /**
     * Deja la línea "Etapa que falló: <etapa>" como la escribe el pipeline.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  string                $etapa
     * @return void
     */
    private function fallo_en(ClientVersionUpgrade $upgrade, string $etapa): void
    {
        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => $etapa,
            'level'                     => 'error',
            'line'                      => \App\Services\DeploymentService::MARCA_ETAPA_QUE_FALLO . $etapa . '. Causa: x',
        ]);
    }

    /**
     * Un comando del upgrade en ese estado.
     *
     * @param  array<string, mixed>  $e
     * @param  ClientVersionUpgrade  $upgrade
     * @param  string                $estado
     * @return void
     */
    private function comando_en(array $e, ClientVersionUpgrade $upgrade, string $estado): void
    {
        $comando                  = new \App\Models\VersionCommand();
        $comando->version_id      = $e['to']->id;
        $comando->command         = 'php artisan algo:que-fallo';
        $comando->execution_order = 1;
        $comando->save();

        \App\Models\UpdateCommand::create([
            'client_version_upgrade_id' => $upgrade->id,
            'version_command_id'        => $comando->id,
            'status'                    => $estado,
        ]);
    }

    /**
     * Admin del panel autenticado por Sanctum.
     *
     * @return void
     */
    private function como_admin_del_panel(): void
    {
        $admin           = new \App\Models\Admin();
        $admin->name     = 'Admin un solo frente';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin);
    }

    /* ==========================================================================================
     | 1) En el lugar sobre el frente principal
     |========================================================================================= */

    /**
     * 🔴 Sin bandera, con la jornada de hoy terminada: 202, encolado en `database`, y la respuesta
     * dice `en_el_lugar` y qué va a hacer el pipeline.
     */
    public function test_en_el_lugar_sobre_el_principal_con_el_negocio_cerrado_encola_sin_bandera(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '11:00');
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $cuerpo = $this->start($upgrade)->assertStatus(202)->json();

        $this->assertSame('en_el_lugar', $cuerpo['modo']);
        $this->assertSame((int) $e['principal']->id, $cuerpo['frente_principal_client_api_id']);
        $this->assertArrayHasKey('respaldo', $cuerpo['en_el_lugar']);
        $this->assertArrayHasKey('mantenimiento', $cuerpo['en_el_lugar']);
        $this->assertArrayNotHasKey('gate_de_horario_salteado', $cuerpo);
        $this->assertStringContainsString('EN EL LUGAR', $cuerpo['advertencia']);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });
        $this->assertCount(1, $etapas);
        $this->assertNull($etapas[0], 'Arranca desde el principio del pre-cierre.');
        $this->assertSame('running', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 🔴 Con el negocio ABIERTO: 422 del gate, con el modo y los ids en el cuerpo, y sin escribir
     * nada —ni estado, ni job, ni logs borrados—.
     */
    public function test_en_el_lugar_con_el_negocio_abierto_rechaza_con_el_gate_y_no_escribe_nada(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'error',
            'line'                      => 'el intento anterior',
        ]);

        $cuerpo = $this->start($upgrade)->assertStatus(422)->json();

        $this->assertSame('abierto', $cuerpo['estado_ahora']);
        $this->assertStringContainsString('EN EL LUGAR', $cuerpo['error']);
        $this->assertSame('en_el_lugar', $cuerpo['modo']);
        $this->assertSame((int) $e['principal']->id, $cuerpo['frente_principal_client_api_id']);
        $this->assertSame((int) $e['principal']->id, $cuerpo['active_client_api_id']);

        Queue::assertNothingPushed();
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);
        $this->assertSame(1, DeploymentLog::where('client_version_upgrade_id', $upgrade->id)->count(), 'El rechazo borró logs.');
    }

    /** Sin horarios cargados también rechaza: `sin_configurar` no es `cerrado`. */
    public function test_en_el_lugar_sin_horarios_cargados_rechaza(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $cuerpo = $this->start($upgrade)->assertStatus(422)->json();

        $this->assertSame('sin_configurar', $cuerpo['estado_ahora']);
        Queue::assertNothingPushed();
        $this->assertNull($upgrade->refresh()->deployment_status);
    }

    /**
     * Con `force` + `force_reason`: 202 y la constancia del salteo en la respuesta. Sin motivo (o con
     * uno corto) sigue siendo 422.
     */
    public function test_en_el_lugar_el_gate_se_saltea_solo_con_force_y_un_motivo(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $this->start($upgrade, ['force' => true])->assertStatus(422);
        $this->start($upgrade, ['force' => true, 'force_reason' => 'corto'])->assertStatus(422);
        Queue::assertNothingPushed();
        $this->assertNull($upgrade->refresh()->deployment_status);

        $cuerpo = $this->start($upgrade, [
            'force'        => true,
            'force_reason' => 'Lucas confirmó que el local hoy no abre por inventario.',
        ])->assertStatus(202)->json();

        $this->assertSame('abierto', $cuerpo['gate_de_horario_salteado']['estado_ahora']);
        $this->assertTrue($cuerpo['gate_de_horario_salteado']['registrado']);
        $this->assertSame('en_el_lugar', $cuerpo['modo']);

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /**
     * `allow_deploy_to_active_api=true` se sigue aceptando: con ella el start pasa como antes, sin
     * gate, aunque el negocio esté abierto (compatibilidad con la skill vieja).
     */
    public function test_la_bandera_vieja_sigue_andando_en_el_lugar(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $cuerpo = $this->start($upgrade, ['allow_deploy_to_active_api' => true])->assertStatus(202)->json();

        $this->assertSame('en_el_lugar', $cuerpo['modo']);
        $this->assertSame(
            'Se desplegó sobre la API ACTIVA en producción porque vino allow_deploy_to_active_api=true.',
            $cuerpo['advertencia']
        );
        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** `resume_from_step=backup_in_place` se acepta sobre un `failed` (la etapa nueva es reanudable). */
    public function test_se_puede_reanudar_desde_el_respaldo(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '11:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);

        $cuerpo = $this->start($upgrade, ['resume_from_step' => 'backup_in_place'])->assertStatus(202)->json();

        $this->assertSame('backup_in_place', $cuerpo['desde_etapa']);
    }

    /* ==========================================================================================
     | 2) La activa que NO es el principal
     |========================================================================================= */

    /**
     * 🔴 Cliente que atiende desde su frente 2, upgrade hacia el 2: sin bandera es 422, explica la
     * decisión del 14/10 y nombra el principal para crear el upgrade hacia él.
     */
    public function test_la_activa_que_no_es_el_principal_sin_bandera_rechaza(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('segundo');
        $this->horario($e['client'], '09:00', '11:00');
        $upgrade = $this->crear_upgrade($e, $e['segundo']);

        $cuerpo = $this->start($upgrade)->assertStatus(422)->json();

        $this->assertStringContainsString('NO es su frente principal', $cuerpo['error']);
        $this->assertStringContainsString('14/10/2026', $cuerpo['error']);
        $this->assertSame((int) $e['principal']->id, $cuerpo['frente_principal_client_api_id']);
        $this->assertSame((int) $e['segundo']->id, $cuerpo['active_client_api_id']);
        $this->assertStringContainsString('target_client_api_id=' . $e['principal']->id, $cuerpo['ayuda']);

        Queue::assertNothingPushed();
        $this->assertNull($upgrade->refresh()->deployment_status);

        /* Con la bandera pasa, como siempre. */
        $this->start($upgrade, ['allow_deploy_to_active_api' => true])->assertStatus(202);
    }

    /* ==========================================================================================
     | 3) La rotación sigue como estaba
     |========================================================================================= */

    /**
     * Cliente en el 2, upgrade hacia el principal (la última rotación): sin gate, con el negocio
     * abierto, como siempre fue el pre-cierre de una rotación.
     */
    public function test_la_ultima_rotacion_al_principal_no_lleva_gate(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('segundo');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $cuerpo = $this->start($upgrade)->assertStatus(202)->json();

        $this->assertSame('rotacion', $cuerpo['modo']);
        $this->assertArrayNotHasKey('en_el_lugar', $cuerpo);
        $this->assertArrayNotHasKey('advertencia', $cuerpo);
    }

    /* ==========================================================================================
     | 4) modo y frente principal en las respuestas
     |========================================================================================= */

    /** El preview dice el frente principal y en qué modo iría la actualización por defecto. */
    public function test_el_preview_dice_el_modo_y_el_frente_principal(): void
    {
        $e = $this->escenario('principal');

        $cuerpo = $this->postJson('/api/claude/upgrades/preview', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['to']->id,
        ], $this->headers())->assertStatus(200)->json();

        $this->assertSame('en_el_lugar', $cuerpo['modo']);
        $this->assertSame((int) $e['principal']->id, $cuerpo['frente_principal_client_api_id']);
        $this->assertSame((int) $e['principal']->id, $cuerpo['active_client_api_id']);
        $this->assertNotEmpty($cuerpo['nota_modo']);

        $otro = $this->escenario('segundo');

        $cuerpo_otro = $this->postJson('/api/claude/upgrades/preview', [
            'client_id'     => $otro['client']->id,
            'to_version_id' => $otro['to']->id,
        ], $this->headers())->assertStatus(200)->json();

        $this->assertSame('rotacion', $cuerpo_otro['modo']);
        $this->assertSame((int) $otro['principal']->id, $cuerpo_otro['frente_principal_client_api_id']);
    }

    /**
     * La creación en seco y la real: el destino por defecto es el principal y las dos respuestas
     * dicen el modo (la real, en `upgrade.modo`, porque es el payload del GET).
     */
    public function test_la_creacion_va_al_principal_y_dice_el_modo(): void
    {
        $e = $this->escenario('segundo');

        $base = [
            'client_id'             => $e['client']->id,
            'to_version_id'         => $e['to']->id,
            'confirmed_version_ids' => [$e['to']->id],
        ];

        $seco = $this->postJson('/api/claude/upgrades', $base, $this->headers())->assertStatus(200)->json();

        $this->assertTrue($seco['dry_run']);
        $this->assertSame('rotacion', $seco['modo']);
        $this->assertSame((int) $e['principal']->id, $seco['frente_principal_client_api_id']);
        $this->assertSame((int) $e['principal']->id, $seco['crearia']['target_client_api']['id']);
        $this->assertTrue($seco['crearia']['target_client_api']['es_el_frente_principal']);
        $this->assertFalse($seco['crearia']['target_client_api']['es_la_activa']);

        $real = $this->postJson('/api/claude/upgrades', array_merge($base, [
            'dry_run'               => false,
            'confirm_version_count' => 1,
            'confirm_client_name'   => self::NOMBRE,
        ]), $this->headers())->assertStatus(201)->json();

        $this->assertSame('rotacion', $real['upgrade']['modo']);
        $this->assertSame((int) $e['principal']->id, $real['upgrade']['frente_principal_client_api_id']);
        $this->assertSame((int) $e['principal']->id, $real['upgrade']['target_client_api']['id']);
        $this->assertTrue($real['upgrade']['target_client_api']['es_el_frente_principal']);
    }

    /**
     * En el lugar, la simulación avisa que el `start` va a pedir el gate de horario (y no la
     * bandera), y el GET del upgrade nunca arrancado lo dice en `siguiente_accion`.
     */
    public function test_en_el_lugar_la_simulacion_y_el_get_avisan_del_gate(): void
    {
        $e = $this->escenario('principal');

        $seco = $this->postJson('/api/claude/upgrades', [
            'client_id'             => $e['client']->id,
            'to_version_id'         => $e['to']->id,
            'confirmed_version_ids' => [$e['to']->id],
        ], $this->headers())->assertStatus(200)->json();

        $this->assertSame('en_el_lugar', $seco['modo']);
        $this->assertStringContainsString('gate', $seco['crearia']['target_client_api']['advertencia']);

        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $get = $this->getJson('/api/claude/upgrades/' . $upgrade->id, $this->headers())->assertStatus(200)->json();

        $this->assertSame('en_el_lugar', $get['upgrade']['modo']);
        $this->assertStringContainsString('EN EL LUGAR', $get['siguiente_accion']['motivo']);
    }

    /**
     * En el lugar, pausado después del pre-cierre, la siguiente acción es el post-cierre y no
     * mark-crons: el pipeline ya marcó el paso (en el VPS, vps_supervisor_moved_at, que es el que
     * mira el post-cierre).
     */
    public function test_en_el_lugar_pausado_la_siguiente_accion_es_el_post_cierre(): void
    {
        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], [
            'deployment_status'       => 'paused',
            'vps_supervisor_moved_at' => now(),
        ]);

        $get = $this->getJson('/api/claude/upgrades/' . $upgrade->id, $this->headers())->assertStatus(200)->json();

        $this->assertStringContainsString('deploy/start-post-closure', $get['siguiente_accion']['endpoint']);
        $this->assertStringContainsString('EN EL LUGAR', $get['siguiente_accion']['motivo']);

        /* Una rotación pausada sin crons marcados sigue mandando a mark-crons, como siempre. */
        $rotacion = $this->crear_upgrade($e, $e['segundo'], ['deployment_status' => 'paused']);
        $get_rot  = $this->getJson('/api/claude/upgrades/' . $rotacion->id, $this->headers())->assertStatus(200)->json();
        $this->assertStringContainsString('mark-crons', $get_rot['siguiente_accion']['endpoint']);
    }

    /**
     * Un upgrade `completed` ya promovió su destino a activo: su modo no se puede reconstruir contra
     * el activo de hoy (toda rotación terminada se leería "en el lugar"), y viaja null.
     */
    public function test_un_upgrade_completado_no_inventa_el_modo(): void
    {
        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'completed']);

        $get = $this->getJson('/api/claude/upgrades/' . $upgrade->id, $this->headers())->assertStatus(200)->json();

        $this->assertNull($get['upgrade']['modo']);
        $this->assertSame((int) $e['principal']->id, $get['upgrade']['frente_principal_client_api_id']);
    }

    /* ==========================================================================================
     | 5) Correcciones tras los verificadores (14/10/2026)
     |========================================================================================= */

    /**
     * Horario partido de todos los días (dos rangos).
     *
     * @param  Client  $client
     * @param  array<int, array<int, string>>  $rangos
     * @return void
     */
    private function horario_partido(Client $client, array $rangos): void
    {
        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = 'todos';
        $dia->save();

        foreach ($rangos as $orden => $par) {
            $rango                         = new ClientScheduleRange();
            $rango->client_schedule_day_id = $dia->id;
            $rango->start_time             = $par[0];
            $rango->end_time               = $par[1];
            $rango->sort_order             = $orden;
            $rango->save();
        }
    }

    /**
     * Momento del martes 25/8/2026, hora de la app.
     *
     * @param  string  $hora
     * @return Carbon
     */
    private function a_las(string $hora): Carbon
    {
        return Carbon::parse('2026-08-25 ' . $hora . ':00', config('app.timezone'));
    }

    /**
     * 🔴 La MADRUGADA: a las 02:00 con horario 09–18 el negocio todavía no abrió y faltan 7 horas
     * para la primera apertura: el start en el lugar entra sin force (es cuando Lucas decidió
     * actualizar). A las 08:30 faltan 30 minutos: 422, y el cuerpo dice cuántos faltan.
     */
    public function test_la_madrugada_pasa_sin_force_y_cerca_de_abrir_no(): void
    {
        Queue::fake();

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        Carbon::setTestNow($this->a_las('08:30'));
        $rechazo = $this->start($upgrade)->assertStatus(422)->json();
        $this->assertSame(30, $rechazo['minutos_hasta_la_apertura']);
        $this->assertSame(120, $rechazo['minimo_requerido']);
        Queue::assertNothingPushed();
        $this->assertNull($upgrade->refresh()->deployment_status);

        Carbon::setTestNow($this->a_las('02:00'));
        $cuerpo = $this->start($upgrade)->assertStatus(202)->json();

        $this->assertSame('en_el_lugar', $cuerpo['modo']);
        $this->assertSame(420, $cuerpo['gate_de_horario_madrugada']['minutos_hasta_la_apertura']);
        $this->assertArrayNotHasKey('gate_de_horario_salteado', $cuerpo);
        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /**
     * 🔴 Un RECESO de horario partido sigue rechazando aunque falten más de 2 horas para reabrir:
     * puede haber gente trabajando adentro. 13:00 con 08–12 / 16–20 → 422.
     */
    public function test_un_receso_entre_turnos_sigue_rechazando(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->a_las('13:00'));

        $e = $this->escenario('principal');
        $this->horario_partido($e['client'], [['08:00', '12:00'], ['16:00', '20:00']]);
        $upgrade = $this->crear_upgrade($e, $e['principal']);

        $cuerpo = $this->start($upgrade)->assertStatus(422)->json();

        $this->assertStringContainsString('HUECO ENTRE TURNOS', $cuerpo['error']);
        Queue::assertNothingPushed();
    }

    /**
     * 🔴 Una REANUDACIÓN en el lugar de un upgrade que YA TOCÓ el sistema vivo no pasa por el gate: a
     * las 08:30 (que rechazaría un arranque limpio) reanudar desde upload_api encola igual.
     */
    public function test_una_reanudacion_en_el_lugar_no_pasa_por_el_gate(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->a_las('08:30'));

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);
        $this->marcar_tocado($upgrade);

        $cuerpo = $this->start($upgrade, ['resume_from_step' => 'upload_api'])->assertStatus(202)->json();

        $this->assertSame('upload_api', $cuerpo['desde_etapa']);
        $this->assertStringContainsString('reanudación', $cuerpo['gate_de_horario']);
    }

    /**
     * En el lugar, reanudar los seeders es terminar la ventana de mantenimiento: el job recibe la
     * etapa con el prefijo. En una rotación, la misma etapa es post-cierre y sale con el 422 de
     * validación de siempre.
     */
    public function test_reanudar_los_seeders_solo_en_el_lugar_y_con_el_prefijo(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);
        $this->marcar_tocado($upgrade);

        $cuerpo = $this->start($upgrade, ['resume_from_step' => 'run_seeders'])->assertStatus(202)->json();
        $this->assertSame('run_seeders', $cuerpo['desde_etapa']);

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return true;
        });
        $this->assertSame(['en_mantenimiento:run_seeders'], $etapas);

        $rotacion = $this->escenario('segundo');
        $otro     = $this->crear_upgrade($rotacion, $rotacion['principal'], ['deployment_status' => 'failed']);

        $error = $this->postJson('/api/claude/upgrades/' . $otro->id . '/deploy/start', [
            'confirm_client_name' => self::NOMBRE,
            'resume_from_step'    => 'run_seeders',
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame('parámetros inválidos', $error['error']);
        $this->assertArrayHasKey('resume_from_step', $error['errores']);
    }

    /**
     * La advertencia de rotación al frente secundario (destino que no es ni el principal ni el
     * activo) viaja en preview, simulación, creación y start. No frena.
     */
    public function test_la_rotacion_al_frente_secundario_lleva_advertencia_y_no_frena(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');

        $preview = $this->postJson('/api/claude/upgrades/preview', [
            'client_id'            => $e['client']->id,
            'to_version_id'        => $e['to']->id,
            'target_client_api_id' => $e['segundo']->id,
        ], $this->headers())->assertStatus(200)->json();
        $this->assertSame('rotacion', $preview['modo']);
        $this->assertStringContainsString('frente secundario', $preview['advertencia_frente_secundario']);

        $default = $this->postJson('/api/claude/upgrades/preview', [
            'client_id'     => $e['client']->id,
            'to_version_id' => $e['to']->id,
        ], $this->headers())->assertStatus(200)->json();
        $this->assertNull($default['advertencia_frente_secundario']);

        $base = [
            'client_id'             => $e['client']->id,
            'to_version_id'         => $e['to']->id,
            'confirmed_version_ids' => [$e['to']->id],
            'target_client_api_id'  => $e['segundo']->id,
        ];

        $seco = $this->postJson('/api/claude/upgrades', $base, $this->headers())->assertStatus(200)->json();
        $this->assertNotNull($seco['advertencia_frente_secundario']);

        $real = $this->postJson('/api/claude/upgrades', array_merge($base, [
            'dry_run'               => false,
            'confirm_version_count' => 1,
            'confirm_client_name'   => self::NOMBRE,
        ]), $this->headers())->assertStatus(201)->json();
        $this->assertNotNull($real['upgrade']['advertencia_frente_secundario']);

        $start = $this->start(ClientVersionUpgrade::find($real['upgrade']['id']))->assertStatus(202)->json();
        $this->assertSame('rotacion', $start['modo']);
        $this->assertNotNull($start['advertencia_frente_secundario']);
    }

    /**
     * El post-cierre de un upgrade en el lugar no pasa por el gate (no vuelve a correr nada): con el
     * negocio abierto encola igual.
     */
    public function test_el_post_cierre_en_el_lugar_no_pasa_por_el_gate(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], [
            'deployment_status'   => 'paused',
            'crons_supervisor_at' => now(),
        ]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(202)->json();

        $this->assertSame('en_el_lugar', $cuerpo['modo']);
        $this->assertStringContainsString('no aplica', $cuerpo['gate_de_horario']);
    }

    /**
     * En el lugar, el reintento de comandos sobre un despliegue que no llegó al `artisan up` (sin la
     * marca que el pipeline pone con el up) es 422 y manda a deploy/start desde la etapa que falló
     * (tercera tanda: los comandos corren después del up, así que no son esa etapa).
     */
    public function test_el_reintento_de_comandos_en_el_lugar_sin_llegar_al_up_rechaza(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->a_las('23:00'));

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);

        $comando                  = new \App\Models\VersionCommand();
        $comando->version_id      = $e['to']->id;
        $comando->command         = 'php artisan algo:que-fallo';
        $comando->execution_order = 1;
        $comando->save();

        \App\Models\UpdateCommand::create([
            'client_version_upgrade_id' => $upgrade->id,
            'version_command_id'        => $comando->id,
            'status'                    => 'fallido',
        ]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/retry-commands', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertStringContainsString('resume_from_step=<la etapa que falló>', $cuerpo['ayuda']);
        Queue::assertNothingPushed();
    }

    /**
     * Un upgrade en el lugar `failed` con el sistema vivo ya tocado: siguiente_accion recomienda
     * reanudar desde la etapa que falló (la línea con marca fija del pipeline), no un start limpio, y
     * dice que el gate no aplica.
     */
    public function test_un_fallo_en_el_lugar_recomienda_reanudar_desde_la_etapa_que_fallo(): void
    {
        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);
        $this->marcar_tocado($upgrade);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'upload_api',
            'level'                     => 'error',
            'line'                      => \App\Services\DeploymentService::MARCA_ETAPA_QUE_FALLO . 'upload_api. Causa: x',
        ]);
        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'vencimiento',
            'level'                     => 'error',
            'line'                      => 'El job terminó fallado: x. El estado ya estaba escrito.',
        ]);

        $get = $this->getJson('/api/claude/upgrades/' . $upgrade->id, $this->headers())->assertStatus(200)->json();

        $this->assertSame('upload_api', $get['siguiente_accion']['resume_from_step']);
        $this->assertStringContainsString('resume_from_step=upload_api', $get['siguiente_accion']['motivo']);
        $this->assertStringStartsWith('no aplica', $get['siguiente_accion']['gate_de_horario']);
    }

    /**
     * Si el job murió por timeout adentro de la ventana no hay "Etapa que falló": siguiente_accion usa
     * la última "Etapa en curso" y lo dice. (Desde la tercera tanda la ventana termina con el reinicio
     * del worker: los comandos ya no dejan "Etapa en curso".)
     */
    public function test_sin_etapa_que_fallo_se_reanuda_desde_la_ultima_etapa_en_curso(): void
    {
        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);
        $this->marcar_tocado($upgrade);

        foreach (['run_migrations', 'run_seeders'] as $etapa) {
            DeploymentLog::create([
                'client_version_upgrade_id' => $upgrade->id,
                'step'                      => $etapa,
                'level'                     => 'info',
                'line'                      => \App\Services\DeploymentService::MARCA_ETAPA_EN_CURSO . $etapa . ' (con el sistema del cliente en mantenimiento).',
            ]);
        }

        $get = $this->getJson('/api/claude/upgrades/' . $upgrade->id, $this->headers())->assertStatus(200)->json();

        $this->assertSame('run_seeders', $get['siguiente_accion']['resume_from_step']);
        $this->assertStringContainsString('timeout', $get['siguiente_accion']['motivo']);
    }

    /* ==========================================================================================
     | Tercera tanda (14/10/2026): sistema tocado, cierre con el sistema abajo, pendientes
     |========================================================================================= */

    /**
     * 🔴 C. Una reanudación de un upgrade que NO tocó el sistema vivo es una salida limpia más: a las
     * 12:00 con el negocio abierto, reanudar desde compile_spa o desde backup_in_place → 422 del gate,
     * sin encolar nada. Con la marca "Sistema vivo tocado", reanudar desde una etapa posterior → 202.
     */
    public function test_una_reanudacion_sin_el_sistema_tocado_lleva_el_gate(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);

        foreach (['compile_spa', 'backup_in_place'] as $etapa) {
            $rechazo = $this->start($upgrade, ['resume_from_step' => $etapa])->assertStatus(422)->json();
            $this->assertSame('abierto', $rechazo['estado_ahora'], 'Desde ' . $etapa);
        }
        Queue::assertNothingPushed();
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);

        $this->marcar_tocado($upgrade);

        $cuerpo = $this->start($upgrade, ['resume_from_step' => 'upload_api'])->assertStatus(202)->json();
        $this->assertSame('upload_api', $cuerpo['desde_etapa']);
        $this->assertStringStartsWith('no aplica', $cuerpo['gate_de_horario']);
        Queue::assertPushed(RunDeploymentJob::class, 1);
    }

    /**
     * 🔴 D. configure-system sobre un `failed` en el lugar que no llegó al `artisan up` (sin la marca
     * que el pipeline pone con el up): 422 en `claude/*` y en el panel, sin encolar ni tocar el estado.
     * Con la marca (el up ya pasó), los dos encolan como siempre.
     */
    public function test_configure_system_en_el_lugar_sin_llegar_al_up_rechaza_en_los_dos(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);

        $claude = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/configure-system', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422)->json();
        $this->assertStringContainsString('artisan up', $claude['error']);
        $this->assertStringContainsString('resume_from_step', $claude['ayuda']);

        $this->como_admin_del_panel();
        $panel = $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/configure-system')->assertStatus(422)->json();
        $this->assertStringContainsString('artisan up', $panel['message']);

        Queue::assertNothingPushed();
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);

        /* Con la marca del up, los dos encolan la etapa final. */
        $upgrade->update(['crons_supervisor_at' => now()]);

        $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/configure-system')->assertStatus(200);
        $upgrade->refresh()->update(['deployment_status' => 'failed']);
        $ultima = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/configure-system', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers());
        $this->assertSame(202, $ultima->status(), (string) $ultima->getContent());

        Queue::assertPushed(RunDeploymentJob::class, 2);
    }

    /**
     * 🔴 F. El post-cierre en el lugar saltea el gate SOLO si no queda nada pendiente: con un comando
     * pendiente (por ejemplo, un pre-cierre que lo hizo el admin viejo), a las 12:00 con el negocio
     * abierto → 422 del gate de siempre.
     */
    public function test_el_post_cierre_en_el_lugar_con_pendientes_lleva_el_gate(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->mediodia());

        $e = $this->escenario('principal');
        $this->horario($e['client'], '09:00', '18:00');
        $upgrade = $this->crear_upgrade($e, $e['principal'], [
            'deployment_status'   => 'paused',
            'crons_supervisor_at' => now(),
        ]);
        $this->comando_en($e, $upgrade, 'pendiente');

        $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start-post-closure', [
            'confirm_client_name' => self::NOMBRE,
        ], $this->headers())->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame('paused', (string) $upgrade->refresh()->deployment_status);
    }

    /**
     * 🔴 G. siguiente_accion en el lugar, según dónde falló:
     *  - en el cierre (complete), con el up hecho → configure-system, no resume_from_step;
     *  - en un comando (después del up) → retry-commands;
     *  - en el respaldo, sin haber tocado nada → la rama `failed` de siempre (start), con el gate
     *    aplicando y sin resume_from_step;
     *  - en el cierre pero SIN el up (lo frenó la guarda del job) → la etapa de la ventana que falló
     *    antes.
     */
    public function test_siguiente_accion_en_el_lugar_segun_donde_fallo(): void
    {
        $e = $this->escenario('principal');

        $cierre = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed', 'crons_supervisor_at' => now()]);
        $this->marcar_tocado($cierre);
        $this->fallo_en($cierre, 'complete');

        $get = $this->getJson('/api/claude/upgrades/' . $cierre->id, $this->headers())->assertStatus(200)->json();
        $this->assertStringEndsWith('/deploy/configure-system', $get['siguiente_accion']['endpoint']);
        $this->assertArrayNotHasKey('resume_from_step', $get['siguiente_accion']);
        $this->assertStringNotContainsString('con el sistema en mantenimiento', $get['siguiente_accion']['motivo']);

        $comando = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed', 'crons_supervisor_at' => now()]);
        $this->marcar_tocado($comando);
        $this->fallo_en($comando, 'run_commands');

        $get = $this->getJson('/api/claude/upgrades/' . $comando->id, $this->headers())->assertStatus(200)->json();
        $this->assertStringEndsWith('/deploy/retry-commands', $get['siguiente_accion']['endpoint']);
        $this->assertArrayNotHasKey('resume_from_step', $get['siguiente_accion']);

        $respaldo = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);
        $this->fallo_en($respaldo, 'backup_in_place');

        $get = $this->getJson('/api/claude/upgrades/' . $respaldo->id, $this->headers())->assertStatus(200)->json();
        $this->assertStringEndsWith('/deploy/start', $get['siguiente_accion']['endpoint']);
        $this->assertArrayNotHasKey('resume_from_step', $get['siguiente_accion']);
        $this->assertStringStartsWith('aplica', $get['siguiente_accion']['gate_de_horario']);

        $sin_up = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);
        $this->marcar_tocado($sin_up);
        $this->fallo_en($sin_up, 'run_migrations');
        $this->fallo_en($sin_up, 'update_default_version');

        $get = $this->getJson('/api/claude/upgrades/' . $sin_up->id, $this->headers())->assertStatus(200)->json();
        $this->assertSame('run_migrations', $get['siguiente_accion']['resume_from_step']);
    }

    /**
     * 🔴 El botón del PANEL "reintentar comandos" tiene la misma guarda: en el lugar, sin llegar al
     * `artisan up`, 422 y nada encolado. Con la marca de la pausa (ya pasó el up), encola como siempre.
     */
    public function test_el_reintento_de_comandos_del_panel_en_el_lugar_sin_llegar_al_up_rechaza(): void
    {
        Queue::fake();

        $admin           = new \App\Models\Admin();
        $admin->name     = 'Admin un solo frente';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $e       = $this->escenario('principal');
        $upgrade = $this->crear_upgrade($e, $e['principal'], ['deployment_status' => 'failed']);

        $comando                  = new \App\Models\VersionCommand();
        $comando->version_id      = $e['to']->id;
        $comando->command         = 'php artisan algo:que-fallo';
        $comando->execution_order = 1;
        $comando->save();

        \App\Models\UpdateCommand::create([
            'client_version_upgrade_id' => $upgrade->id,
            'version_command_id'        => $comando->id,
            'status'                    => 'fallido',
        ]);

        $cuerpo = $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/retry-commands')->assertStatus(422)->json();

        $this->assertStringContainsString('artisan up', $cuerpo['message']);
        Queue::assertNothingPushed();
        $this->assertSame('failed', (string) $upgrade->refresh()->deployment_status);

        /* Con la marca de la pausa (el up ya pasó), el botón funciona como siempre. */
        $upgrade->update(['crons_supervisor_at' => now()]);

        $this->postJson('/api/admin/update/' . $upgrade->id . '/deploy/retry-commands')->assertStatus(200);
        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** El lote crea hacia el frente principal de cada cliente y dice el modo de cada uno. */
    public function test_el_lote_va_al_principal_de_cada_cliente_y_dice_el_modo(): void
    {
        $en_el_1 = $this->escenario('principal');

        /* El segundo cliente reusa las versiones del primero (el código de versión es único). */
        $client                     = new Client();
        $client->name               = 'Ferretería del Dos';
        $client->company_name       = 'Empresa del Dos';
        $client->slug               = 'ferre-dos-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $en_el_1['from']->id;
        $client->save();

        $carpeta   = 'ferre' . Str::lower(Str::random(4));
        $principal = $this->crear_api($client, $carpeta);
        $segundo   = $this->crear_api($client, $carpeta . '2');
        $client->active_client_api_id = $segundo->id;
        $client->save();

        $cuerpo = $this->postJson('/api/claude/upgrades/batch', [
            'to_version_id' => $en_el_1['to']->id,
            'client_ids'    => [$en_el_1['client']->id, $client->id],
        ], $this->headers())->assertStatus(200)->json();

        $por_cliente = [];
        foreach ($cuerpo['clientes'] as $fila) {
            $por_cliente[(int) $fila['client_id']] = $fila;
        }

        $this->assertSame((int) $en_el_1['principal']->id, $por_cliente[$en_el_1['client']->id]['target_client_api_id']);
        $this->assertSame('en_el_lugar', $por_cliente[$en_el_1['client']->id]['modo']);
        $this->assertSame((int) $principal->id, $por_cliente[$client->id]['target_client_api_id']);
        $this->assertSame('rotacion', $por_cliente[$client->id]['modo']);

        $advertencias = implode(' | ', $cuerpo['advertencias']);
        $this->assertStringContainsString('EN EL LUGAR', $advertencias);
        $this->assertStringNotContainsString('los va a frenar', $advertencias);
    }
}
