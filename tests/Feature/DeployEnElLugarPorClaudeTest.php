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
