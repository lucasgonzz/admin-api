<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `aviso-actualizacion-sistema`, RONDA 2 (25/11/2026) — `deploy/start` RENUEVA el aviso cuando
 * el force pasa porque los usuarios ya dieron el OK.
 *
 * El pedido de OK deja el estado en `actualizando` con un vencimiento corto (30 minutos): alcanza
 * para que arranque el deploy, pero no si el job espera en la cola. Por eso, cuando `deploy/start`
 * deja pasar un force porque el estado ya es `actualizando`, antes de encolar le manda a empresa-api
 * `PUT admin-sync/actualizacion-del-sistema {estado: "actualizando", vence_en_minutos: 120}` con el
 * upgrade y la versión destino. Si ese PUT falla, el start sigue igual: el aviso no aborta nada.
 *
 * Y no renueva nada cuando no corresponde: 409 por falta de OK, sin force, o interruptor apagado.
 *
 * PHP 7.4.
 */
class DeployStartRenuevaElAvisoTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests del test. */
    const CLAVE = 'clave-de-prueba-deploy-start-renueva';

    /** Clave de admin-sync del cliente (`clients.api_key`). */
    const CLAVE_DEL_CLIENTE = 'clave-del-cliente-renueva';

    /** Nombre del cliente del escenario (lo confirma `confirm_client_name`). */
    const NOMBRE = 'Pinturería de la Renovación';

    /** Motivo del force: más de diez caracteres. */
    const MOTIVO = 'Hotfix urgente de facturación aprobado por Lucas.';

    /**
     * Estado que contesta empresa-api al GET.
     *
     * @var string
     */
    private $estado_de_empresa = 'actualizando';

    /**
     * HTTP con el que contesta empresa-api al PUT de renovar.
     *
     * @var int
     */
    private $http_del_put = 200;

    /**
     * Lo que le llegó a empresa-api, en orden: método, url, datos y la clave.
     *
     * @var array<int, array<string, mixed>>
     */
    private $pedidos = [];

    /**
     * Reloj congelado en horario ABIERTO (martes al mediodía), el interruptor prendido, la cola falsa
     * y el stub de empresa-api.
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

        Http::fake(function ($request) {
            if (strpos((string) $request->url(), '/api/admin-sync/actualizacion-del-sistema') === false) {
                return null;
            }

            $this->pedidos[] = [
                'metodo'  => (string) $request->method(),
                'url'     => (string) $request->url(),
                'datos'   => (array) $request->data(),
                'api_key' => $request->hasHeader('X-Admin-Api-Key') ? $request->header('X-Admin-Api-Key')[0] : null,
            ];

            if ($request->method() === 'PUT' && $this->http_del_put !== 200) {
                return Http::response(['message' => 'Server Error'], $this->http_del_put);
            }

            return Http::response([
                'ok'         => true,
                'owner_id'   => 1,
                'estado'     => $request->method() === 'PUT' ? 'actualizando' : $this->estado_de_empresa,
                'upgrade_id' => null,
                'version'    => null,
                'vence_at'   => '2026-08-25T14:00:00-03:00',
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

    /** @var int Para que cada escenario tenga versiones con código distinto. */
    private $contador = 0;

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

        $from = $this->crear_version('4.' . $this->contador . '.0');
        $to   = $this->crear_version('4.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = self::NOMBRE;
        $client->company_name       = 'Empresa ' . self::NOMBRE;
        $client->slug               = 'renueva-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = self::CLAVE_DEL_CLIENTE;
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $carpeta = 'renueva' . Str::lower(Str::random(4));

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
     * Los pedidos de un método dado.
     *
     * @param  string  $metodo
     * @return array<int, array<string, mixed>>
     */
    private function pedidos_de(string $metodo): array
    {
        return array_values(array_filter($this->pedidos, function ($pedido) use ($metodo) {
            return $pedido['metodo'] === $metodo;
        }));
    }

    /* ==========================================================================================
     | Renovar al pasar
     |========================================================================================= */

    /**
     * 🔴 Force con el estado ya en `actualizando` (vino del OK de los usuarios): 202, encolado, y ANTES
     * de encolar un `PUT … {estado: actualizando, vence_en_minutos: 120}` con el upgrade y la
     * versión destino, después del GET con el que se leyó el estado.
     */
    public function test_con_el_ok_dado_renueva_el_aviso_con_120_minutos_antes_de_encolar(): void
    {
        $upgrade = $this->upgrade_en_el_lugar();

        $respuesta = $this->start_con_force($upgrade)->assertStatus(202);

        $this->assertSame('actualizando', $respuesta->json('aviso_de_actualizacion.estado'));
        Queue::assertPushed(RunDeploymentJob::class);

        $this->assertSame(['GET', 'PUT'], array_column($this->pedidos, 'metodo'), 'Primero se lee y después se renueva.');

        $put = $this->pedidos_de('PUT')[0];
        $this->assertStringEndsWith('/api/admin-sync/actualizacion-del-sistema', $put['url']);
        $this->assertSame('actualizando', $put['datos']['estado']);
        $this->assertSame(120, (int) $put['datos']['vence_en_minutos']);
        $this->assertSame((int) $upgrade->id, (int) $put['datos']['upgrade_id']);
        $this->assertSame((string) $upgrade->to_version->version, (string) $put['datos']['version']);
        $this->assertSame(self::CLAVE_DEL_CLIENTE, $put['api_key']);
    }

    /**
     * Si el PUT de renovar falla (500), el start sigue igual: 202 y encolado. El aviso no aborta nada.
     */
    public function test_si_la_renovacion_falla_el_start_sigue_igual(): void
    {
        $this->http_del_put = 500;
        $upgrade            = $this->upgrade_en_el_lugar();

        $this->start_con_force($upgrade)->assertStatus(202);

        Queue::assertPushed(RunDeploymentJob::class);
        $this->assertCount(1, $this->pedidos_de('PUT'));
    }

    /* ==========================================================================================
     | Y no renueva cuando no corresponde
     |========================================================================================= */

    /**
     * Sin el OK (`pidiendo_ok`): 409 y NO se renueva nada (renovar sería bloquear a quienes todavía
     * no aceptaron).
     */
    public function test_sin_el_ok_el_409_no_renueva_nada(): void
    {
        $this->estado_de_empresa = 'pidiendo_ok';
        $upgrade                 = $this->upgrade_en_el_lugar();

        $this->start_con_force($upgrade)->assertStatus(409);

        $this->assertSame([], $this->pedidos_de('PUT'));
        Queue::assertNothingPushed();
    }

    /**
     * Sin force (negocio cerrado) no se consulta ni se renueva nada.
     */
    public function test_sin_force_no_renueva_nada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 20:00:00', config('app.timezone')));
        $upgrade = $this->upgrade_en_el_lugar();

        $this->start($upgrade)->assertStatus(202);

        $this->assertSame([], $this->pedidos);
    }

    /**
     * Con el interruptor apagado no sale ni una request.
     */
    public function test_con_el_interruptor_apagado_no_renueva_nada(): void
    {
        config(['aviso_de_actualizacion.habilitado' => false]);
        $upgrade = $this->upgrade_en_el_lugar();

        $this->start_con_force($upgrade)->assertStatus(202);

        $this->assertSame([], $this->pedidos);
    }
}
