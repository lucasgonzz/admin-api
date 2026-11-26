<?php

namespace Tests\Feature\AvisoDeActualizacion;

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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `aviso-actualizacion-sistema` (25/11/2026) — CA19: `POST claude/upgrades/{id}/deploy/start`
 * EXIGE que los usuarios ya hayan dado el OK cuando el `force` pisa el sistema con el negocio
 * abierto.
 *
 * La regla (plan §2.6 paso 8 y §4.5): si el `force` EFECTIVAMENTE saltó el gate de horario
 * (`gate_de_horario_salteado`), el interruptor está prendido y el cliente soporta el aviso (su
 * `GET admin-sync/actualizacion-del-sistema` contesta 200), y el estado NO es `actualizando` ⇒
 * 409 `falta_ok_de_los_usuarios`, sin encolar nada, sin borrar los logs del intento anterior y sin
 * tocar el estado del upgrade.
 *
 * Y lo que NO la dispara, que importa tanto como lo otro:
 *
 *  - empresa 404 (versión sin aviso), 401 o sin respuesta: sigue como hoy (202).
 *  - sin `force` (negocio cerrado, el gate pasa solo): ni siquiera se le pregunta a empresa.
 *  - interruptor apagado: ningún HTTP, sigue como hoy.
 *
 * Molde: `DeployEnElLugarPorClaudeTest` (cliente con frente principal activo, horario 09–18, reloj
 * congelado, `Queue::fake()`).
 *
 * PHP 7.4.
 */
class DeployStartExigeOkTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests del test. */
    const CLAVE = 'clave-de-prueba-deploy-start-exige-ok';

    /** Clave de admin-sync del cliente (`clients.api_key`). */
    const CLAVE_DEL_CLIENTE = 'clave-del-cliente-exige-ok';

    /** Nombre del cliente del escenario (lo confirma `confirm_client_name`). */
    const NOMBRE = 'Pinturería del Aviso';

    /** Motivo del force: más de diez caracteres. */
    const MOTIVO = 'Hotfix urgente de facturación aprobado por Lucas.';

    /** @var int Para que cada escenario tenga versiones con código distinto. */
    private $contador = 0;

    /**
     * Reloj congelado en horario ABIERTO (martes al mediodía), el interruptor prendido y la cola
     * falsa (la prueba es que NO se encole).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config([
            'services.claude_task_ingest.key'   => self::CLAVE,
            'aviso_de_actualizacion.habilitado' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', config('app.timezone')));
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
     * Cliente con el negocio abierto de 09 a 18 y su frente principal ACTIVO (el caso en el lugar),
     * más un upgrade hacia él.
     *
     * @return ClientVersionUpgrade
     */
    private function upgrade_en_el_lugar(): ClientVersionUpgrade
    {
        $this->contador++;

        $from = $this->crear_version('5.' . $this->contador . '.0');
        $to   = $this->crear_version('5.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = self::NOMBRE;
        $client->company_name       = 'Empresa ' . self::NOMBRE;
        $client->slug               = 'exige-ok-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = self::CLAVE_DEL_CLIENTE;
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta = 'exigeok' . Str::lower(Str::random(4));

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com/public';
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = 'todos';
        $dia->save();

        $rango                         = new ClientScheduleRange();
        $rango->client_schedule_day_id = $dia->id;
        $rango->start_time             = '09:00';
        $rango->end_time               = '18:00';
        $rango->sort_order             = 0;
        $rango->save();

        return ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $api->id,
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
        $version->is_hotfix    = false;
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * `POST deploy/start` con el nombre confirmado y lo que se pida.
     *
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
     * `POST deploy/start` con `force` y su motivo (el caso del plan).
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @return \Illuminate\Testing\TestResponse
     */
    private function start_con_force(ClientVersionUpgrade $upgrade)
    {
        return $this->start($upgrade, ['force' => true, 'force_reason' => self::MOTIVO]);
    }

    /**
     * Lo que contesta empresa-api al `GET admin-sync/actualizacion-del-sistema`: se lee del
     * momento, así un test que prueba varios casos seguidos no queda atado al primero (un
     * `Http::fake` posterior NO pisa al anterior: gana el primero que responde).
     *
     * @var array<string, mixed>
     */
    private $empresa = ['http' => 200, 'estado' => 'libre', 'corte' => false];

    /**
     * Cuántas veces el admin le preguntó a empresa-api por el aviso (también cuando la respuesta fue
     * un corte de red, que `Http::recorded()` no guarda).
     *
     * @var int
     */
    private $consultas_a_empresa = 0;

    /**
     * True cuando este test ya registró el stub de empresa-api (se registra una sola vez).
     *
     * @var bool
     */
    private $stub_registrado = false;

    /**
     * Registra (una vez) el stub de empresa-api y deja dicho qué contesta ahora.
     *
     * @param  int          $http    Código HTTP.
     * @param  string|null  $estado  Estado del aviso (sólo con 200).
     * @param  bool         $corte   True: no hay respuesta (corte de red).
     * @return void
     */
    private function empresa_responde(int $http, $estado = null, bool $corte = false): void
    {
        $this->empresa = ['http' => $http, 'estado' => $estado, 'corte' => $corte];

        if ($this->stub_registrado) {
            return;
        }
        $this->stub_registrado = true;

        Http::fake(function ($request) {
            if (strpos((string) $request->url(), '/api/admin-sync/actualizacion-del-sistema') === false) {
                return null;
            }

            $this->consultas_a_empresa++;

            if ($this->empresa['corte']) {
                throw new ConnectionException('cURL error 28: timed out');
            }

            if ($this->empresa['http'] !== 200) {
                return Http::response(['message' => 'error de prueba'], $this->empresa['http']);
            }

            $estado = (string) $this->empresa['estado'];

            return Http::response([
                'ok'         => true,
                'owner_id'   => 1,
                'estado'     => $estado,
                'upgrade_id' => null,
                'version'    => null,
                'vence_at'   => $estado === 'actualizando' ? '2026-08-25T12:30:00-03:00' : null,
                'pedido'     => $estado === 'pidiendo_ok'
                    ? ['total' => 4, 'aceptaron' => 1, 'faltan' => 3, 'pendientes' => ['Juan', 'Caja 2', 'Caja 3'],
                        'rechazado_por' => null, 'vence_respuesta_at' => '2026-08-25T12:10:00-03:00', 'segundos_para_responder' => 540]
                    : null,
            ], 200);
        });
    }

    /**
     * Empresa-api contesta 200 con este estado del aviso.
     *
     * @param  string  $estado
     * @return void
     */
    private function empresa_dice(string $estado): void
    {
        $this->empresa_responde(200, $estado);
    }

    /**
     * Pedidos que salieron al aviso de empresa-api.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_al_aviso(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            if (strpos((string) $par[0]->url(), '/api/admin-sync/actualizacion-del-sistema') !== false) {
                $pedidos[] = $par[0];
            }
        }

        return $pedidos;
    }

    /* ==========================================================================================
     | CA19) Lo que frena
     |========================================================================================= */

    /**
     * 🔴 CA19. Negocio abierto + `force` + interruptor prendido + empresa en `pidiendo_ok` ⇒ 409
     * `falta_ok_de_los_usuarios` con el aviso, y NO se encola, ni se borran los logs, ni se toca el
     * estado del upgrade.
     */
    public function test_con_force_y_el_pedido_sin_resolver_es_409_y_no_se_encola_nada(): void
    {
        $upgrade = $this->upgrade_en_el_lugar();
        $this->empresa_dice('pidiendo_ok');

        $log = DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'info',
            'line'                      => 'Línea de un intento anterior que no se puede perder.',
        ]);

        $respuesta = $this->start_con_force($upgrade)->assertStatus(409);

        $this->assertSame('falta_ok_de_los_usuarios', $respuesta->json('error'));
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame('pidiendo_ok', $respuesta->json('aviso.estado'));
        $this->assertSame(3, $respuesta->json('aviso.pedido.faltan'));
        $this->assertArrayNotHasKey('ok', (array) $respuesta->json('aviso'));

        Queue::assertNothingPushed();
        Queue::assertNotPushed(RunDeploymentJob::class);
        $this->assertNull($upgrade->refresh()->deployment_status);
        $this->assertNotNull(DeploymentLog::find($log->id), 'Un 409 no borra los logs del intento anterior.');

        /* Se le preguntó a empresa con GET y la clave del cliente. */
        $pedidos = $this->pedidos_al_aviso();
        $this->assertCount(1, $pedidos);
        $this->assertSame('GET', $pedidos[0]->method());
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $pedidos[0]->header('X-Admin-Api-Key')[0]);
    }

    /**
     * 🔴 CA19. Nadie pidió el OK (`libre`) o alguien dijo "Ahora no" (`rechazado`): el force tampoco
     * pasa. Sólo `actualizando` habilita el deploy.
     */
    public function test_con_force_y_el_estado_libre_o_rechazado_tambien_es_409(): void
    {
        foreach (['libre', 'rechazado'] as $estado) {
            $upgrade = $this->upgrade_en_el_lugar();
            $this->empresa_dice($estado);

            $respuesta = $this->start_con_force($upgrade)->assertStatus(409);

            $this->assertSame('falta_ok_de_los_usuarios', $respuesta->json('error'), $estado);
            $this->assertSame($estado, $respuesta->json('aviso.estado'));
            Queue::assertNothingPushed();
        }
    }

    /* ==========================================================================================
     | CA19) Lo que deja pasar
     |========================================================================================= */

    /**
     * 🔴 CA19. Con el estado `actualizando` (todos aceptaron o venció el plazo) el force pasa: 202 y se
     * encola en la conexión `database`. La respuesta informa el estado del aviso.
     */
    public function test_con_force_y_el_estado_actualizando_pasa_y_se_encola(): void
    {
        $upgrade = $this->upgrade_en_el_lugar();
        $this->empresa_dice('actualizando');

        $respuesta = $this->start_con_force($upgrade)->assertStatus(202);

        $this->assertSame('abierto', $respuesta->json('gate_de_horario_salteado.estado_ahora'));
        $this->assertTrue($respuesta->json('aviso_de_actualizacion.habilitado'));
        $this->assertTrue($respuesta->json('aviso_de_actualizacion.soportado'));
        $this->assertSame('actualizando', $respuesta->json('aviso_de_actualizacion.estado'));

        Queue::assertPushed(RunDeploymentJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /**
     * 🔴 CA19. Empresa 404 (versión sin aviso): sigue como hoy, 202 y encolado. La primera
     * actualización a esta versión no puede pedir el OK (la skill la deja para la noche, P4).
     */
    public function test_con_empresa_404_sigue_como_hoy(): void
    {
        $upgrade = $this->upgrade_en_el_lugar();
        $this->empresa_responde(404);

        $respuesta = $this->start_con_force($upgrade)->assertStatus(202);

        $this->assertTrue($respuesta->json('aviso_de_actualizacion.habilitado'));
        $this->assertFalse($respuesta->json('aviso_de_actualizacion.soportado'));
        $this->assertNull($respuesta->json('aviso_de_actualizacion.estado'));
        Queue::assertPushed(RunDeploymentJob::class);
    }

    /**
     * Empresa que no contesta bien (401, 500 o corte de red) tampoco frena: sigue como hoy. El
     * aviso nunca puede ser la razón de que no se pueda desplegar un hotfix.
     */
    public function test_con_empresa_caida_o_con_otra_clave_sigue_como_hoy(): void
    {
        $casos = [
            'sin respuesta' => [0, true],
            '401'           => [401, false],
            '500'           => [500, false],
        ];

        foreach ($casos as $nombre => $caso) {
            $upgrade = $this->upgrade_en_el_lugar();
            $this->empresa_responde($caso[0], null, $caso[1]);

            $antes = $this->consultas_a_empresa;

            $this->start_con_force($upgrade)->assertStatus(202);
            $this->assertSame($antes + 1, $this->consultas_a_empresa, 'Se le preguntó a empresa una vez: ' . $nombre);
        }

        Queue::assertPushed(RunDeploymentJob::class, 3);
    }

    /**
     * 🔴 CA19. Sin `force` (el negocio cerrado: el gate pasa solo) no se le pregunta nada a empresa y
     * se encola como hoy. El bloqueo protege igual desde el pipeline.
     */
    public function test_sin_force_con_el_negocio_cerrado_no_consulta_a_empresa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 20:00:00', config('app.timezone')));
        $upgrade = $this->upgrade_en_el_lugar();
        $this->empresa_dice('pidiendo_ok');

        $respuesta = $this->start($upgrade)->assertStatus(202);

        $this->assertArrayNotHasKey('gate_de_horario_salteado', $respuesta->json());
        $this->assertSame([], $this->pedidos_al_aviso(), 'Sin force no hay nada que preguntarle a empresa.');
        Queue::assertPushed(RunDeploymentJob::class);
    }

    /**
     * 🔴 CA17. Con el interruptor APAGADO el `force` pasa como hoy y no sale ni una request.
     */
    public function test_con_el_interruptor_apagado_el_force_pasa_como_hoy_sin_consultar(): void
    {
        config(['aviso_de_actualizacion.habilitado' => false]);
        $upgrade = $this->upgrade_en_el_lugar();
        $this->empresa_dice('pidiendo_ok');

        $respuesta = $this->start_con_force($upgrade)->assertStatus(202);

        $this->assertSame([], $this->pedidos_al_aviso());
        Http::assertNothingSent();
        $this->assertSame('abierto', $respuesta->json('gate_de_horario_salteado.estado_ahora'));
        Queue::assertPushed(RunDeploymentJob::class);
    }

    /**
     * Un `force` con un motivo inválido sigue siendo el 422 de siempre, y ni siquiera llega a
     * preguntarle a empresa (el gate rechaza antes).
     */
    public function test_el_force_sin_motivo_sigue_siendo_422_y_no_consulta(): void
    {
        $upgrade = $this->upgrade_en_el_lugar();
        $this->empresa_dice('pidiendo_ok');

        $this->start($upgrade, ['force' => true])->assertStatus(422);

        $this->assertSame([], $this->pedidos_al_aviso());
        Queue::assertNothingPushed();
    }
}
