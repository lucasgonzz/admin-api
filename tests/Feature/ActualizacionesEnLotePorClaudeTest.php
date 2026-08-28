<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClaudeUpgradeBatchController;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionSeeder;
use App\Services\ClientVersionUpgradeCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST claude/upgrades/batch`: el alta de actualizaciones EN LOTE.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que el lote NO ARRANQUE NINGÚN DEPLOYMENT. Es la decisión que gobierna el endpoint entero:
 *     el gate de horario y `allow_deploy_to_active_api` son POR CLIENTE, así que un arranque plural
 *     o rechaza a diecinueve de veinte o los saltea a todos. El lote escribe filas y nada más, y
 *     `test_el_lote_no_encola_ningun_deployment` lo mide con `Queue::assertNothingPushed()`.
 *  2. 🔴 Que `confirm_token` sea el equivalente real de `confirm_client_name` en un lote: incorpora
 *     el id, el nombre normalizado Y el conjunto de versiones de cada cliente, así que un lote que
 *     cambió entre la simulación y la confirmación no pasa.
 *  3. 🔴 Que cada cliente confirme SU propia cantidad de versiones. Es lo que reemplaza a
 *     `confirm_version_count`, que no se puede pedir una vez para veinte caminos distintos.
 *  4. Que un cliente raro salga como `omitido` y no voltee el lote entero.
 *  5. Que todo freno que rechaza devuelva 422 sin crear una sola fila.
 */
class ActualizacionesEnLotePorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-lote';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed, así
     * que sin esto todo devolvería 401 y los tests medirían el middleware, no el endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /* ------------------------------------------------------------------------------------------
     | Armado del escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Headers con la clave de ingesta.
     *
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
     * Cuerpo de la respuesta como texto, con los acentos SIN escapar.
     *
     * 🔴 `getContent()` devuelve el JSON crudo, donde "Panadería" viaja escapado como
     * "Panadería": una comparación de texto sobre eso mide cualquier cosa menos lo que dice
     * medir. Copiado de `ActualizacionDelEcommercePorClaudeTest::cuerpo()`.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a leer.
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Versión del catálogo.
     *
     * @param string $codigo    Número de versión.
     * @param bool   $is_hotfix Si es hotfix.
     * @param string $status    draft | published | archived.
     *
     * @return Version
     */
    private function crear_version(string $codigo, bool $is_hotfix = false, string $status = 'published'): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = $status;
        $version->is_hotfix    = $is_hotfix;
        $version->published_at = $status === 'published' ? now() : null;
        $version->save();

        return $version;
    }

    /**
     * Cliente con una API destino cargada (sin ella el upgrade nacería sin poder arrancar).
     *
     * @param string   $nombre     Nombre del negocio.
     * @param int|null $version_id Versión actual.
     * @param bool     $activo     Si el cliente está activo.
     * @param bool     $con_api    Si se le carga una ClientApi.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, $version_id = null, bool $activo = true, bool $con_api = true): Client
    {
        $client                     = new Client();
        $client->name               = $nombre;
        $client->company_name       = 'Empresa ' . $nombre;
        $client->slug               = Str::slug($nombre !== '' ? $nombre : 'sin-nombre') . '-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = $activo;
        $client->current_version_id = $version_id;
        $client->save();

        if ($con_api) {
            $activa  = $this->crear_api($client);
            $this->crear_api($client);

            $client->active_client_api_id = $activa->id;
            $client->save();
        }

        return $client;
    }

    /**
     * API de un cliente.
     *
     * @param Client $client Cliente dueño.
     *
     * @return ClientApi
     */
    private function crear_api(Client $client): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . Str::random(6) . '.ejemplo.test';
        $api->path         = 'ejemplo/' . Str::random(6);
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * Escenario base: un camino con un hotfix en el medio y dos clientes parados en puntos distintos.
     *
     * `panaderia` viene de 4.0.0 (le tocan la troncal 4.0.1 y el destino 4.0.2 = 2 versiones) y
     * `ferreteria` de 4.0.1 (le toca sólo el destino = 1 versión). Esa asimetría es a propósito: es
     * lo que hace imposible un `confirm_version_count` único para el lote.
     *
     * @return array<string, mixed>
     */
    private function armar_escenario(): array
    {
        $cero   = $this->crear_version('4.0.0');
        $uno    = $this->crear_version('4.0.1');
        $hotfix = $this->crear_version('4.0.1.1', true);
        $dos    = $this->crear_version('4.0.2');

        $version_seeder                  = new VersionSeeder();
        $version_seeder->version_id      = $dos->id;
        $version_seeder->seeder_class    = 'Database\\Seeders\\Falso' . Str::random(6) . 'Seeder';
        $version_seeder->execution_order = 1;
        $version_seeder->save();

        $version_command                  = new VersionCommand();
        $version_command->version_id      = $dos->id;
        $version_command->command         = 'comando:falso-' . Str::random(6);
        $version_command->execution_order = 1;
        $version_command->save();

        $panaderia  = $this->crear_cliente('Panadería Rosa', $cero->id);
        $ferreteria = $this->crear_cliente('Ferretería Rioplatense', $uno->id);

        return compact('cero', 'uno', 'hotfix', 'dos', 'panaderia', 'ferreteria', 'version_seeder', 'version_command');
    }

    /**
     * Cuerpo base del lote sobre los dos clientes del escenario.
     *
     * @param array<string, mixed> $escenario Escenario base.
     * @param array<string, mixed> $extra     Campos a pisar.
     *
     * @return array<string, mixed>
     */
    private function cuerpo_del_lote(array $escenario, array $extra = []): array
    {
        return array_merge([
            'to_version_id' => $escenario['dos']->id,
            'client_ids'    => [$escenario['panaderia']->id, $escenario['ferreteria']->id],
        ], $extra);
    }

    /**
     * Corre la simulación y devuelve su cuerpo, que es de donde sale el `confirm_token`.
     *
     * @param array<string, mixed> $cuerpo Body del lote.
     *
     * @return array<string, mixed>
     */
    private function simular(array $cuerpo): array
    {
        return $this->postJson('/api/claude/upgrades/batch', $cuerpo, $this->headers())
            ->assertStatus(200)
            ->json();
    }

    /**
     * Motivo con el que quedó omitido un cliente, o null si no está en la lista.
     *
     * @param array<int, array<string, mixed>> $omitidos Lista de omitidos.
     * @param int                              $id       Cliente buscado.
     *
     * @return string|null
     */
    private function motivo_de($omitidos, int $id)
    {
        foreach ($omitidos as $omitido) {
            if ((int) $omitido['client_id'] === $id) {
                return $omitido['motivo'];
            }
        }

        return null;
    }

    /* ==========================================================================================
     | La puerta
     |========================================================================================= */

    /** Sin el header X-Claude-Task-Key el lote devuelve 401. */
    public function test_sin_clave_el_lote_devuelve_401(): void
    {
        $this->postJson('/api/claude/upgrades/batch', [])->assertStatus(401);
    }

    /* ==========================================================================================
     | La simulación
     |========================================================================================= */

    /** 🔴 El default es simular: devuelve qué crearía, por cliente, y no escribe una sola fila. */
    public function test_el_lote_simula_por_defecto_y_no_crea_nada(): void
    {
        $e = $this->armar_escenario();

        $upgrades_antes = ClientVersionUpgrade::count();
        $seeders_antes  = UpdateSeeder::count();

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $this->assertTrue($cuerpo['dry_run']);
        $this->assertSame(2, $cuerpo['crearian']);
        $this->assertSame([], $cuerpo['omitidos']);
        $this->assertNotEmpty($cuerpo['confirm_token']);
        $this->assertSame(ClaudeUpgradeBatchController::MAX_LOTE_CLIENTES, $cuerpo['max_lote']);
        $this->assertStringContainsString('NO arranca ningún deployment', $cuerpo['nota_deployment']);

        $this->assertSame($upgrades_antes, ClientVersionUpgrade::count(), 'La simulación creó un upgrade.');
        $this->assertSame($seeders_antes, UpdateSeeder::count(), 'La simulación creó UpdateSeeders.');
    }

    /**
     * 🔴 EL TEST DE LA DECISIÓN: cada cliente confirma SU propia cantidad de versiones, y por eso el
     * lote no puede pedir un `confirm_version_count` único. La política por defecto es la del panel
     * —troncal sí, hotfix no, destino siempre—, así que el hotfix del medio queda afuera de los dos.
     */
    public function test_cada_cliente_confirma_su_propia_cantidad_de_versiones(): void
    {
        $e = $this->armar_escenario();

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $por_cliente = [];
        foreach ($cuerpo['clientes'] as $fila) {
            $por_cliente[(int) $fila['client_id']] = $fila;
        }

        $panaderia  = $por_cliente[$e['panaderia']->id];
        $ferreteria = $por_cliente[$e['ferreteria']->id];

        $this->assertSame(2, $panaderia['cantidad'], 'Desde 4.0.0 le tocan la troncal 4.0.1 y el destino 4.0.2.');
        $this->assertSame('4.0.0', $panaderia['from_version']);
        $this->assertSame(
            ['4.0.1', '4.0.2'],
            array_column($panaderia['versiones_confirmadas'], 'version')
        );

        $this->assertSame(1, $ferreteria['cantidad'], 'Desde 4.0.1 le toca sólo el destino.');
        $this->assertSame(['4.0.2'], array_column($ferreteria['versiones_confirmadas'], 'version'));

        /* Con la política que confirma todo, el hotfix del medio entra. */
        $todas = $this->simular($this->cuerpo_del_lote($e, [
            'politica_de_versiones' => ClaudeUpgradeBatchController::POLITICA_TODAS,
        ]));

        $cantidades = [];
        foreach ($todas['clientes'] as $fila) {
            $cantidades[(int) $fila['client_id']] = $fila['cantidad'];
        }

        $this->assertSame(3, $cantidades[$e['panaderia']->id]);
        $this->assertSame(2, $cantidades[$e['ferreteria']->id]);
    }

    /* ==========================================================================================
     | Los frenos de la confirmación
     |========================================================================================= */

    /** Sin `confirm_client_count` no se crea nada, aunque `dry_run` venga en false. */
    public function test_sin_confirm_client_count_no_se_crea_nada_aunque_dry_run_sea_false(): void
    {
        $e     = $this->armar_escenario();
        $antes = ClientVersionUpgrade::count();

        $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'dry_run' => false,
        ]), $this->headers())->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /** Un `confirm_client_count` que no coincide exacto no crea nada. */
    public function test_un_confirm_client_count_equivocado_no_crea_nada(): void
    {
        $e     = $this->armar_escenario();
        $token = $this->simular($this->cuerpo_del_lote($e))['confirm_token'];
        $antes = ClientVersionUpgrade::count();

        $cuerpo = $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'dry_run'              => false,
            'confirm_client_count' => 3,
            'confirm_token'        => $token,
        ]), $this->headers())->assertStatus(422)->json();

        $this->assertSame(2, $cuerpo['crearian']);
        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /**
     * 🔴 Un token calculado sobre OTRO conjunto no habilita el alta, aunque la cantidad coincida.
     * Es lo que `confirm_client_count` solo no puede ver: "la lista cambió" no es "cambió de tamaño".
     */
    public function test_un_token_de_otro_conjunto_no_habilita_el_alta(): void
    {
        $e = $this->armar_escenario();

        /* Token de un conjunto de UN cliente… */
        $token_ajeno = $this->simular([
            'to_version_id' => $e['dos']->id,
            'client_ids'    => [$e['panaderia']->id],
        ])['confirm_token'];

        $antes = ClientVersionUpgrade::count();

        /* …usado para confirmar el conjunto de DOS. La cantidad la mandamos bien a propósito: lo
           único que puede frenar esto es el token. */
        $cuerpo = $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $token_ajeno,
        ]), $this->headers())->assertStatus(422)->json();

        $this->assertArrayHasKey('confirm_token_esperado', $cuerpo);
        $this->assertSame($antes, ClientVersionUpgrade::count(), 'Un token ajeno no puede crear nada.');
    }

    /**
     * Y el token también cambia si al mismo cliente le cambia el CAMINO de versiones: alguien publica
     * un hotfix entre la simulación y la confirmación y el token viejo deja de servir. Eso es lo que
     * hace las veces de `confirm_version_count` en el lote.
     */
    public function test_el_token_cambia_si_cambian_las_versiones_de_un_cliente(): void
    {
        $e     = $this->armar_escenario();
        $token = $this->simular($this->cuerpo_del_lote($e))['confirm_token'];

        /* Se publica una troncal nueva en el medio del rango: el camino de los dos clientes cambia. */
        $this->crear_version('4.0.1.5');

        $token_nuevo = $this->simular($this->cuerpo_del_lote($e))['confirm_token'];

        $this->assertNotSame($token, $token_nuevo);

        $antes = ClientVersionUpgrade::count();

        $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $token,
        ]), $this->headers())->assertStatus(422);

        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /* ==========================================================================================
     | La selección
     |========================================================================================= */

    /** `client_ids` y el selector a la vez: 422, porque habría que adivinar si suma o filtra. */
    public function test_client_ids_y_el_selector_a_la_vez_devuelven_422(): void
    {
        $e = $this->armar_escenario();

        $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'from_version_id' => $e['cero']->id,
        ]), $this->headers())->assertStatus(422);
    }

    /** Sin ninguna de las dos formas de elegir, tampoco: no hay a quién crearle nada. */
    public function test_sin_client_ids_ni_selector_devuelve_422(): void
    {
        $e = $this->armar_escenario();

        $this->postJson('/api/claude/upgrades/batch', [
            'to_version_id' => $e['dos']->id,
        ], $this->headers())->assertStatus(422);
    }

    /** El selector por versión actual arma exactamente el conjunto de los que están en esa versión. */
    public function test_el_selector_por_version_arma_el_conjunto_esperado(): void
    {
        $e = $this->armar_escenario();

        /* Un tercer cliente parado en la misma versión que la panadería, y el de 4.0.1 que NO tiene
           que aparecer. */
        $verduleria = $this->crear_cliente('Verdulería del Centro', $e['cero']->id);

        $cuerpo = $this->simular([
            'to_version_id'   => $e['dos']->id,
            'from_version_id' => $e['cero']->id,
        ]);

        $ids = array_map('intval', array_column($cuerpo['clientes'], 'client_id'));
        sort($ids);

        $esperados = [(int) $e['panaderia']->id, (int) $verduleria->id];
        sort($esperados);

        $this->assertSame($esperados, $ids);
        $this->assertNull(
            $this->motivo_de($cuerpo['omitidos'], (int) $e['ferreteria']->id),
            'El cliente de otra versión ni siquiera entra al lote: no es un omitido, no fue seleccionado.'
        );

        /* El mismo selector por número de versión da lo mismo. */
        $por_numero = $this->simular([
            'to_version_id' => $e['dos']->id,
            'from_version'  => '4.0.0',
        ]);

        $ids_por_numero = array_map('intval', array_column($por_numero['clientes'], 'client_id'));
        sort($ids_por_numero);

        $this->assertSame($esperados, $ids_por_numero);
    }

    /** Por encima del tope el lote se rechaza ENTERO, antes de tocar la base. */
    public function test_un_lote_por_encima_del_tope_se_rechaza_entero(): void
    {
        $e     = $this->armar_escenario();
        $antes = ClientVersionUpgrade::count();

        $demasiados = range(900001, 900001 + ClaudeUpgradeBatchController::MAX_LOTE_CLIENTES);

        $cuerpo = $this->postJson('/api/claude/upgrades/batch', [
            'to_version_id' => $e['dos']->id,
            'client_ids'    => $demasiados,
        ], $this->headers())->assertStatus(422)->json();

        $this->assertSame(ClaudeUpgradeBatchController::MAX_LOTE_CLIENTES, $cuerpo['max_lote']);
        $this->assertSame(count($demasiados), $cuerpo['recibidos']);
        $this->assertSame($antes, ClientVersionUpgrade::count());
    }

    /* ==========================================================================================
     | Las omisiones
     |========================================================================================= */

    /** Un cliente con un deployment en curso queda omitido, con el motivo escrito. */
    public function test_un_cliente_con_deployment_en_curso_queda_omitido(): void
    {
        $e = $this->armar_escenario();

        ClientVersionUpgrade::create([
            'client_id'         => $e['panaderia']->id,
            'from_version_id'   => $e['cero']->id,
            'to_version_id'     => $e['uno']->id,
            'status'            => 'pendiente',
            'scheduled_date'    => now()->toDateString(),
            'deployment_status' => 'running',
        ]);

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $this->assertSame(1, $cuerpo['crearian']);
        $this->assertSame(
            'el cliente tiene un deployment en curso',
            $this->motivo_de($cuerpo['omitidos'], (int) $e['panaderia']->id)
        );
    }

    /** Un cliente que ya tiene un upgrade ABIERTO a la misma versión destino queda omitido. */
    public function test_un_cliente_con_un_upgrade_abierto_a_la_misma_version_queda_omitido(): void
    {
        $e = $this->armar_escenario();

        ClientVersionUpgrade::create([
            'client_id'       => $e['ferreteria']->id,
            'from_version_id' => $e['uno']->id,
            'to_version_id'   => $e['dos']->id,
            'status'          => 'pendiente',
            'scheduled_date'  => now()->toDateString(),
        ]);

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $this->assertSame(1, $cuerpo['crearian']);
        $this->assertSame(
            'el cliente ya tiene un upgrade abierto a esta versión',
            $this->motivo_de($cuerpo['omitidos'], (int) $e['ferreteria']->id)
        );
    }

    /** El cooldown: un upgrade de Claude a la misma versión, reciente, omite al cliente. */
    public function test_un_cliente_con_un_upgrade_reciente_de_claude_a_la_misma_version_queda_omitido(): void
    {
        $e = $this->armar_escenario();

        /* `fallida` a propósito, y no `terminada`: así el que frena NO es el freno del upgrade
           abierto sino el cooldown, y además `terminada` dispara el hook del modelo que ALINEA la
           versión del cliente al destino —con eso el cliente quedaría "ya en la versión destino" y
           el test mediría otra omisión, no ésta—. */
        ClientVersionUpgrade::create([
            'client_id'       => $e['panaderia']->id,
            'from_version_id' => $e['cero']->id,
            'to_version_id'   => $e['dos']->id,
            'status'          => 'fallida',
            'scheduled_date'  => now()->toDateString(),
            'created_via'     => ClientVersionUpgrade::CREATED_VIA_CLAUDE,
        ]);

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $this->assertSame(
            'ya se le creó un upgrade a esta versión en las últimas '
                . ClaudeUpgradeBatchController::COOLDOWN_HORAS . ' hs',
            $this->motivo_de($cuerpo['omitidos'], (int) $e['panaderia']->id)
        );
    }

    /** Un cliente inactivo queda omitido salvo que venga `include_inactivos`. */
    public function test_un_cliente_inactivo_queda_omitido_salvo_que_se_lo_pida(): void
    {
        $e = $this->armar_escenario();

        $e['panaderia']->update(['is_active' => false]);

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $this->assertSame(1, $cuerpo['crearian']);
        $this->assertSame(
            'el cliente está inactivo',
            $this->motivo_de($cuerpo['omitidos'], (int) $e['panaderia']->id)
        );

        $con_inactivos = $this->simular($this->cuerpo_del_lote($e, ['include_inactivos' => true]));

        $this->assertSame(2, $con_inactivos['crearian']);
    }

    /** Un cliente sin ninguna ClientApi queda omitido: el upgrade nacería sin poder arrancar. */
    public function test_un_cliente_sin_ninguna_client_api_queda_omitido(): void
    {
        $e = $this->armar_escenario();

        $sin_api = $this->crear_cliente('Kiosco Sin API', $e['cero']->id, true, false);

        $cuerpo = $this->simular([
            'to_version_id' => $e['dos']->id,
            'client_ids'    => [$e['panaderia']->id, $sin_api->id],
        ]);

        $this->assertSame(1, $cuerpo['crearian']);
        $this->assertSame(
            'el cliente no tiene ninguna ClientApi',
            $this->motivo_de($cuerpo['omitidos'], (int) $sin_api->id)
        );
    }

    /**
     * 🔴 Un cliente cuya resolución de versiones aborta sale como OMITIDO y NO voltea el lote.
     *
     * El `abort(422)` de `resolve_confirmed_version_ids()` está envuelto en `try/catch (\Throwable)`
     * justamente para esto: un 422 global dejaría a los otros veinticuatro clientes sin actualización
     * por culpa de uno.
     *
     * ⚠️ Se fuerza con un doble del servicio y no con datos, y eso es a propósito: el lote arma el
     * conjunto de cada cliente desde `candidatesBetween()`, que es la MISMA fuente contra la que el
     * servicio valida, así que por datos ese abort no se puede provocar hoy. Lo que se prueba acá es
     * la resiliencia del lote —que un cliente que explota sale como omitido—, no el servicio.
     */
    public function test_una_version_fuera_del_rango_de_un_cliente_lo_omite_sin_voltear_el_lote(): void
    {
        $e = $this->armar_escenario();

        $id_raro = (int) $e['panaderia']->id;

        $this->app->bind(ClientVersionUpgradeCreationService::class, function () use ($id_raro) {
            return new class($id_raro) extends ClientVersionUpgradeCreationService {
                /** @var int Cliente sobre el que este doble simula el rango roto. */
                private $id_raro;

                /**
                 * @param int $id_raro Cliente que hace abortar la resolución.
                 */
                public function __construct($id_raro)
                {
                    $this->id_raro = $id_raro;
                }

                /**
                 * @param  Client  $client
                 * @param  Version  $to
                 * @param  array<int, int>  $requested_ids
                 * @return array<int, int>
                 */
                public function resolve_confirmed_version_ids(Client $client, Version $to, array $requested_ids): array
                {
                    if ((int) $client->id === $this->id_raro) {
                        abort(422, 'Se enviaron versiones que no pertenecen al rango calculado.');
                    }

                    return parent::resolve_confirmed_version_ids($client, $to, $requested_ids);
                }
            };
        });

        $cuerpo = $this->simular($this->cuerpo_del_lote($e));

        $this->assertSame(1, $cuerpo['crearian'], 'El lote sigue en pie con el otro cliente.');
        $this->assertSame('pidió versiones fuera del rango', $this->motivo_de($cuerpo['omitidos'], $id_raro));
        $this->assertSame((int) $e['ferreteria']->id, (int) $cuerpo['clientes'][0]['client_id']);
    }

    /* ==========================================================================================
     | El alta real
     |========================================================================================= */

    /**
     * 🔴 LA DECISIÓN DE FONDO, VERIFICADA: el lote crea las actualizaciones y NO encola NI UN job.
     * El gate de horario y `allow_deploy_to_active_api` son por cliente; el arranque va uno por uno.
     */
    public function test_el_lote_no_encola_ningun_deployment(): void
    {
        Queue::fake();

        $e     = $this->armar_escenario();
        $token = $this->simular($this->cuerpo_del_lote($e))['confirm_token'];

        $cuerpo = $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $token,
        ]), $this->headers())->assertStatus(201)->json();

        $this->assertSame(2, $cuerpo['creados']);

        Queue::assertNothingPushed();

        foreach ($cuerpo['resultados'] as $resultado) {
            $this->assertSame(
                'POST claude/upgrades/' . $resultado['upgrade_id'] . '/deploy/start',
                $resultado['siguiente_accion']
            );
        }
    }

    /** El alta real deja los upgrades marcados como creados por Claude, con sus seeders y comandos. */
    public function test_el_lote_real_marca_los_upgrades_como_creados_por_claude(): void
    {
        Queue::fake();

        $e     = $this->armar_escenario();
        $token = $this->simular($this->cuerpo_del_lote($e))['confirm_token'];

        $seeders_antes  = UpdateSeeder::count();
        $comandos_antes = UpdateCommand::count();

        $cuerpo = $this->postJson('/api/claude/upgrades/batch', $this->cuerpo_del_lote($e, [
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $token,
            'notes'                => 'Lote de prueba',
        ]), $this->headers())->assertStatus(201)->json();

        $this->assertSame(2, $cuerpo['creados']);
        $this->assertSame([], $cuerpo['fallidos']);
        $this->assertSame([], $cuerpo['no_procesados']);
        $this->assertFalse($cuerpo['abortado']);

        $por_cliente = [];
        foreach ($cuerpo['resultados'] as $resultado) {
            $por_cliente[(int) $resultado['client_id']] = $resultado;
        }

        $this->assertSame(2, $por_cliente[$e['panaderia']->id]['cantidad_de_versiones']);
        $this->assertSame(1, $por_cliente[$e['ferreteria']->id]['cantidad_de_versiones']);

        $upgrade = ClientVersionUpgrade::find($por_cliente[$e['panaderia']->id]['upgrade_id']);

        $this->assertSame(ClientVersionUpgrade::CREATED_VIA_CLAUDE, (string) $upgrade->created_via);
        $this->assertNull($upgrade->created_by_admin_id, 'No lo creó ningún admin: la columna dice la verdad.');
        $this->assertSame('Lote de prueba', (string) $upgrade->notes);
        $this->assertSame(2, $upgrade->confirmed_versions()->count());
        $this->assertNotNull($upgrade->target_client_api_id, 'La API destino sale del default del servicio.');

        /* Guarda: si los conteos no se movieran, el test estaría midiéndose a sí mismo. */
        $this->assertGreaterThan($seeders_antes, UpdateSeeder::count());
        $this->assertGreaterThan($comandos_antes, UpdateCommand::count());
    }

    /** Una versión destino sin publicar no se le instala a nadie, y menos en lote. */
    public function test_una_version_destino_sin_publicar_rechaza_el_lote_entero(): void
    {
        $e       = $this->armar_escenario();
        $borrador = $this->crear_version('4.1.0', false, 'draft');
        $antes   = ClientVersionUpgrade::count();

        $respuesta = $this->postJson('/api/claude/upgrades/batch', [
            'to_version_id' => $borrador->id,
            'client_ids'    => [$e['panaderia']->id],
        ], $this->headers())->assertStatus(422);

        $this->assertStringContainsString('tiene que estar publicada', $this->cuerpo($respuesta));
        $this->assertSame($antes, ClientVersionUpgrade::count());
    }
}
