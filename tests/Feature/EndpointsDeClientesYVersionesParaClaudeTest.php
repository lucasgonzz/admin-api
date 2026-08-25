<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los endpoints `claude/*` de LECTURA de clientes, horarios, versiones y actualizaciones.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que listar clientes no dispare N+1. `Client::$appends` tiene dos accessors que tocan
 *     `client_ecommerce`: serializar el modelo Eloquent en un listado es una consulta por cliente,
 *     y es el error más fácil de reintroducir sin darse cuenta. El test mide el query log con dos
 *     tamaños de página distintos y exige el MISMO número de consultas.
 *  2. Que el `phone` del cliente no se filtre sin `include=contacto`.
 *  3. Que `siguiente_accion` diga el endpoint correcto para cada estado del deployment: es lo que
 *     evita que se prueben endpoints al azar contra un cliente de producción.
 *  4. Que los logs se trunquen y lo declaren: una salida cortada leída como completa es peor que
 *     no tenerla.
 */
class EndpointsDeClientesYVersionesParaClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-ops';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed,
     * así que sin esto todo devolvería 401 y los tests medirían el middleware, no el endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

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
     * Cliente mínimo.
     *
     * @param string      $nombre     Nombre del cliente.
     * @param int|null    $version_id Versión actual.
     * @param string|null $telefono   Teléfono de contacto.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, $version_id = null, $telefono = null): Client
    {
        $client                     = new Client();
        $client->name               = $nombre;
        $client->company_name       = 'Empresa ' . $nombre;
        $client->slug               = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $version_id;
        $client->phone              = $telefono;
        $client->save();

        return $client;
    }

    /**
     * Versión del catálogo.
     *
     * @param string $numero Número de versión.
     * @param string $status draft | published | archived.
     *
     * @return Version
     */
    private function crear_version(string $numero, string $status = 'published'): Version
    {
        $version          = new Version();
        $version->version = $numero . '-' . Str::random(4);
        $version->title   = 'Versión ' . $numero;
        $version->status  = $status;
        $version->save();

        return $version;
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
        $api->url          = 'https://api.ejemplo.test';
        $api->path         = 'ejemplo/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * Upgrade de un cliente.
     *
     * @param Client               $client     Cliente dueño.
     * @param Version              $to         Versión destino.
     * @param array<string, mixed> $atributos  Atributos extra (estado del deployment, pasos, …).
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(Client $client, Version $to, array $atributos = []): ClientVersionUpgrade
    {
        $upgrade = ClientVersionUpgrade::create(array_merge([
            'client_id'      => $client->id,
            'to_version_id'  => $to->id,
            'status'         => 'pendiente',
            'scheduled_date' => now()->toDateString(),
        ], $atributos));

        return $upgrade;
    }

    /**
     * Carga una fila de día con sus rangos. Sin rangos = ese día cerrado.
     *
     * @param Client                        $client  Cliente dueño.
     * @param string                        $day_key Clave del día.
     * @param array<int, array<int, string>> $rangos  Pares [desde, hasta] en 'H:i'.
     *
     * @return ClientScheduleDay
     */
    private function cargar_dia(Client $client, string $day_key, array $rangos = []): ClientScheduleDay
    {
        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = $day_key;
        $dia->save();

        $orden = 0;
        foreach ($rangos as $par) {
            $rango                         = new ClientScheduleRange();
            $rango->client_schedule_day_id = $dia->id;
            $rango->start_time             = $par[0];
            $rango->end_time               = $par[1];
            $rango->sort_order             = $orden;
            $rango->save();
            $orden++;
        }

        return $dia;
    }

    /* ------------------------------------------------------------------------------------------
     | 18) El middleware
     |----------------------------------------------------------------------------------------- */

    /** 18) Sin el header X-Claude-Task-Key, los ocho endpoints de lectura devuelven 401. */
    public function test_sin_clave_de_ingesta_todos_los_endpoints_de_lectura_devuelven_401()
    {
        $rutas = [
            '/api/claude/ops-schema',
            '/api/claude/clients',
            '/api/claude/clients/1',
            '/api/claude/clients/1/schedule',
            '/api/claude/versions',
            '/api/claude/upgrades',
            '/api/claude/upgrades/1',
            '/api/claude/upgrades/1/logs',
        ];

        foreach ($rutas as $ruta) {
            $this->getJson($ruta)->assertStatus(401);
        }
    }

    /** El ops-schema se describe a sí mismo: sin él, todo lo demás hay que adivinarlo. */
    public function test_ops_schema_publica_las_enumeraciones_y_la_maquina_de_estados()
    {
        $respuesta = $this->getJson('/api/claude/ops-schema', $this->headers())->assertStatus(200);

        $cuerpo = $respuesta->json();

        $this->assertSame(
            ['abierto', 'cerrado', 'sin_configurar'],
            $cuerpo['estados_de_horario'],
            'Los estados de INSTANTE son tres y sin_configurar no es cerrado.'
        );
        $this->assertSame(
            ['con_horario', 'cerrado', 'sin_configurar'],
            $cuerpo['estados_de_horario_por_dia'],
            'Los estados de DÍA son otros: con_horario no es abierto.'
        );
        $this->assertCount(8, $cuerpo['day_keys']);
        $this->assertSame('todos', $cuerpo['day_keys'][0]['key']);
        $this->assertNotEmpty($cuerpo['maquina_de_estados']);
        $this->assertNotEmpty($cuerpo['limitaciones']);
        $this->assertArrayHasKey('confirm_client_name', $cuerpo['frenos']);
        $this->assertSame(config('app.timezone'), $cuerpo['timezone']);
    }

    /* ------------------------------------------------------------------------------------------
     | 19-22) El listado de clientes
     |----------------------------------------------------------------------------------------- */

    /** 19) El filtro por versión actual devuelve exactamente los clientes de esa versión. */
    public function test_clientes_filtrados_por_version_actual()
    {
        $vieja = $this->crear_version('1.4.0');
        $nueva = $this->crear_version('1.5.0');

        $uno = $this->crear_cliente('Cliente en la vieja', $vieja->id);
        $dos = $this->crear_cliente('Otro en la vieja', $vieja->id);
        $tres = $this->crear_cliente('Cliente en la nueva', $nueva->id);

        $respuesta = $this->getJson('/api/claude/clients?current_version_id=' . $vieja->id, $this->headers())
            ->assertStatus(200);

        $ids = array_column($respuesta->json('data'), 'id');

        $this->assertContains($uno->id, $ids);
        $this->assertContains($dos->id, $ids);
        $this->assertNotContains($tres->id, $ids);

        /* El string de versión resuelve contra versions.version y da lo mismo. */
        $por_string = $this->getJson('/api/claude/clients?current_version=' . $vieja->version, $this->headers())
            ->assertStatus(200);

        $ids_string = array_column($por_string->json('data'), 'id');
        $this->assertContains($uno->id, $ids_string);
        $this->assertNotContains($tres->id, $ids_string);

        /* Y la versión que no existe devuelve 422, no una lista vacía que parece un dato. */
        $this->getJson('/api/claude/clients?current_version=no-existe-9.9.9', $this->headers())
            ->assertStatus(422);
    }

    /** 20) El teléfono del cliente es PII: no viaja sin include=contacto. */
    public function test_el_telefono_del_cliente_no_viaja_sin_include_contacto()
    {
        $client = $this->crear_cliente('Cliente con teléfono', null, '5493411234567');

        $sin_include = $this->getJson('/api/claude/clients?client_ids=' . $client->id, $this->headers())
            ->assertStatus(200);

        $fila = $sin_include->json('data.0');
        $this->assertSame($client->id, $fila['id']);
        $this->assertArrayNotHasKey('phone', $fila, 'phone es PII y solo viaja con include=contacto.');

        $con_include = $this->getJson(
            '/api/claude/clients?client_ids=' . $client->id . '&include=contacto',
            $this->headers()
        )->assertStatus(200);

        $this->assertSame('5493411234567', $con_include->json('data.0.phone'));
    }

    /** 21) El cursor no repite ni saltea: dos páginas seguidas dan el conjunto completo. */
    public function test_el_cursor_por_id_no_repite_ni_saltea_clientes()
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->crear_cliente('Cliente de cursor ' . $i)->id;
        }

        $filtro = '&client_ids=' . implode(',', $ids);

        $primera = $this->getJson('/api/claude/clients?limit=2' . $filtro, $this->headers())->assertStatus(200);
        $this->assertSame(2, $primera->json('count'));
        $this->assertTrue($primera->json('has_more'));

        $cursor = $primera->json('next_after_id');
        $this->assertNotNull($cursor);

        $segunda = $this->getJson('/api/claude/clients?limit=2&after_id=' . $cursor . $filtro, $this->headers())
            ->assertStatus(200);

        $ids_primera = array_column($primera->json('data'), 'id');
        $ids_segunda = array_column($segunda->json('data'), 'id');

        $this->assertSame([], array_intersect($ids_primera, $ids_segunda), 'El cursor no puede repetir filas.');
        $this->assertSame(
            array_slice($ids, 0, 4),
            array_merge($ids_primera, $ids_segunda),
            'El cursor no puede saltear filas: las dos páginas son los cuatro primeros ids, en orden.'
        );
    }

    /**
     * 22) 🔴 El listado no dispara N+1.
     *
     * Se mide con dos tamaños de página distintos y se exige el MISMO número de consultas: un tope
     * fijo se puede satisfacer por casualidad, pero que el costo no cambie con la cantidad de
     * clientes es exactamente la propiedad que se quiere. Se piden TODOS los includes, que es el
     * caso más caro.
     */
    public function test_listar_clientes_no_dispara_una_consulta_por_cliente()
    {
        $version = $this->crear_version('2.0.0');

        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $client = $this->crear_cliente('Cliente sin N mas uno ' . $i, $version->id, '54934100000' . $i);
            $this->crear_api($client);
            $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
            $this->crear_upgrade($client, $version);
            $ids[] = $client->id;
        }

        /* ⚠️ `include[]=` y NO `include=a&include=b`: PHP colapsa las claves repetidas sin
           corchetes y se queda con la última, así que la segunda forma dejaría el test midiendo un
           solo include y pasando en falso. */
        $filtro = '&client_ids=' . implode(',', $ids)
            . '&include[]=apis&include[]=schedule&include[]=upgrades_recientes&include[]=contacto';

        $consultas_dos  = $this->contar_consultas('/api/claude/clients?limit=2' . $filtro);
        $consultas_seis = $this->contar_consultas('/api/claude/clients?limit=6' . $filtro);

        /* Guarda contra el test que se mide a sí mismo: si los includes no se hubieran resuelto,
           el listado costaría UNA sola consulta y la igualdad de abajo pasaría sin medir nada. */
        $this->assertGreaterThan(
            1,
            $consultas_dos,
            'Con los cuatro includes pedidos tiene que haber más de una consulta: si hay una sola, '
            . 'los includes no se resolvieron y este test no está midiendo nada.'
        );

        $this->assertSame(
            $consultas_dos,
            $consultas_seis,
            'El costo del listado no puede crecer con la cantidad de clientes: eso es N+1. '
            . 'Con 2 clientes fueron ' . $consultas_dos . ' consultas y con 6, ' . $consultas_seis . '.'
        );
    }

    /**
     * Cuenta las consultas que dispara una request.
     *
     * @param string $url URL a pedir.
     *
     * @return int
     */
    private function contar_consultas(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url, $this->headers())->assertStatus(200);

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $consultas;
    }

    /* ------------------------------------------------------------------------------------------
     | 23) El catálogo de versiones
     |----------------------------------------------------------------------------------------- */

    /** 23) Solo `published` por defecto: es la lista de lo que efectivamente se puede pedir. */
    public function test_versiones_devuelve_solo_publicadas_por_defecto()
    {
        $publicada = $this->crear_version('3.0.0', 'published');
        $borrador  = $this->crear_version('3.1.0', 'draft');

        $por_defecto = $this->getJson('/api/claude/versions', $this->headers())->assertStatus(200);
        $ids         = array_column($por_defecto->json('data'), 'id');

        $this->assertContains($publicada->id, $ids);
        $this->assertNotContains($borrador->id, $ids, 'Un draft no se puede pedir como destino: no se lista.');
        $this->assertSame('published', $por_defecto->json('status_usado'));

        $todas     = $this->getJson('/api/claude/versions?status=all', $this->headers())->assertStatus(200);
        $ids_todas = array_column($todas->json('data'), 'id');

        $this->assertContains($borrador->id, $ids_todas);
    }

    /* ------------------------------------------------------------------------------------------
     | 24) Horarios de un cliente
     |----------------------------------------------------------------------------------------- */

    /** 24) El endpoint de horarios devuelve estado_ahora, proximo_cierre y el timezone que usó. */
    public function test_horarios_de_un_cliente_devuelven_estado_proximo_cierre_y_timezone()
    {
        $client = $this->crear_cliente('Cliente con horarios');
        /* Abierto todos los días de 8 a 13 y de 16 a 21: el cierre del día son las 21, no las 13. */
        $this->cargar_dia($client, 'todos', [['08:00', '13:00'], ['16:00', '21:00']]);

        $respuesta = $this->getJson('/api/claude/clients/' . $client->id . '/schedule', $this->headers())
            ->assertStatus(200);

        $cuerpo = $respuesta->json();

        $this->assertSame(config('app.timezone'), $cuerpo['timezone']);
        $this->assertContains($cuerpo['estado_ahora'], ['abierto', 'cerrado'], 'Con horarios cargados nunca es sin_configurar.');
        $this->assertNotNull($cuerpo['proximo_cierre'], 'Con rangos todos los días siempre hay un próximo cierre.');
        $this->assertNull($cuerpo['proximo_cierre_motivo']);
        $this->assertStringContainsString('21:00:00', $cuerpo['proximo_cierre'], 'El cierre del día es el hasta MAYOR.');
        $this->assertCount(7, $cuerpo['resueltos']);
        $this->assertSame('todos', $cuerpo['dias_cargados'][0]['dia']);
        $this->assertCount(8, $cuerpo['day_keys']);

        /* También se resuelve por uuid, no solo por id. */
        $this->getJson('/api/claude/clients/' . $client->uuid . '/schedule', $this->headers())->assertStatus(200);
    }

    /**
     * 🔴 Un cliente sin ninguna fila de horario es `sin_configurar`, NUNCA `cerrado`, y el motivo
     * del próximo cierre nulo lo dice. Es el dato con el que se decide arrancar el post-cierre.
     */
    public function test_cliente_sin_horarios_es_sin_configurar_y_no_cerrado()
    {
        $client = $this->crear_cliente('Cliente sin horarios');

        $cuerpo = $this->getJson('/api/claude/clients/' . $client->id . '/schedule', $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame('sin_configurar', $cuerpo['estado_ahora']);
        $this->assertNull($cuerpo['proximo_cierre']);
        $this->assertSame('sin_configurar', $cuerpo['proximo_cierre_motivo'], 'Un null sin motivo no se puede interpretar.');
        $this->assertSame([], $cuerpo['dias_cargados']);
    }

    /** La ficha de un cliente trae APIs, horarios resueltos y los upgrades recientes. */
    public function test_ficha_de_un_cliente_trae_apis_horarios_y_upgrades()
    {
        $version = $this->crear_version('4.0.0');
        $client  = $this->crear_cliente('Cliente de ficha', $version->id, '5493419999999');
        $api     = $this->crear_api($client);

        $client->active_client_api_id = $api->id;
        $client->save();

        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $upgrade = $this->crear_upgrade($client, $version);

        $cuerpo = $this->getJson('/api/claude/clients/' . $client->uuid, $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame($client->id, $cuerpo['client']['id']);
        $this->assertSame($version->version, $cuerpo['client']['current_version']);
        $this->assertCount(1, $cuerpo['client']['apis']);
        $this->assertTrue($cuerpo['client']['apis'][0]['es_la_activa']);
        $this->assertCount(7, $cuerpo['client']['schedule']['resueltos_proximos_dias']);
        $this->assertSame($upgrade->id, $cuerpo['client']['upgrades_recientes'][0]['id']);
        $this->assertNotNull($cuerpo['client']['schedule']['proximo_cierre']);

        $this->getJson('/api/claude/clients/999999999', $this->headers())->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | 25-26) Upgrades y logs
     |----------------------------------------------------------------------------------------- */

    /**
     * 25) 🔴 Los logs se truncan y lo declaran.
     *
     * Sin esto, la salida cruda de `npm run build` no entra en la ventana de contexto; y una línea
     * cortada sin marcar se leería como si fuera completa.
     */
    public function test_los_logs_se_truncan_y_declaran_el_largo_original()
    {
        $version = $this->crear_version('5.0.0');
        $client  = $this->crear_cliente('Cliente de logs', $version->id);
        $upgrade = $this->crear_upgrade($client, $version, ['deployment_status' => 'running']);

        $linea_larga = str_repeat('x', 500);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'info',
            'line'                      => $linea_larga,
        ]);
        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'compile_spa',
            'level'                     => 'error',
            'line'                      => 'npm ERR! el build se cayó',
        ]);

        $cuerpo = $this->getJson(
            '/api/claude/upgrades/' . $upgrade->id . '/logs?max_line_chars=50',
            $this->headers()
        )->assertStatus(200)->json();

        $this->assertSame(2, $cuerpo['count']);
        $this->assertSame(50, $cuerpo['max_line_chars']);

        $primera = $cuerpo['data'][0];
        $this->assertTrue($primera['truncada']);
        $this->assertSame(500, $primera['largo_original']);
        $this->assertSame(50, mb_strlen($primera['line']));

        $segunda = $cuerpo['data'][1];
        $this->assertFalse($segunda['truncada'], 'Una línea corta no se marca como truncada.');
        $this->assertArrayNotHasKey('largo_original', $segunda);

        /* El filtro por nivel solo trae los errores. */
        $solo_errores = $this->getJson(
            '/api/claude/upgrades/' . $upgrade->id . '/logs?level=error',
            $this->headers()
        )->assertStatus(200)->json();

        $this->assertSame(1, $solo_errores['count']);
        $this->assertSame('error', $solo_errores['data'][0]['level']);
    }

    /**
     * 26) 🔴 `siguiente_accion` dice el endpoint correcto para cada estado del deployment.
     *
     * Es la máquina de estados aplicada al upgrade concreto, y es lo que evita probar endpoints al
     * azar contra un cliente de producción hasta que uno devuelva 200.
     */
    public function test_siguiente_accion_para_cada_estado_del_deployment()
    {
        $version = $this->crear_version('6.0.0');
        $client  = $this->crear_cliente('Cliente de la máquina de estados', $version->id);

        /* Nunca arrancó → arrancar el pre-cierre. */
        $nuevo = $this->crear_upgrade($client, $version);
        $this->assertStringEndsWith(
            '/deploy/start',
            $this->siguiente_accion_de($nuevo->id),
            'Un upgrade que nunca arrancó se arranca con deploy/start.'
        );

        /* Pausado sin crons marcados → marcar los crons. */
        $sin_crons = $this->crear_upgrade($client, $version, ['deployment_status' => 'paused']);
        $this->assertStringEndsWith('/mark-crons', $this->siguiente_accion_de($sin_crons->id));

        /* Pausado con crons marcados → arrancar el post-cierre. */
        $con_crons = $this->crear_upgrade($client, $version, [
            'deployment_status'   => 'paused',
            'crons_supervisor_at' => now(),
        ]);
        $this->assertStringEndsWith('/deploy/start-post-closure', $this->siguiente_accion_de($con_crons->id));

        /* Tareas post-cierre terminadas → etapa final de configuración. */
        $post_tasks = $this->crear_upgrade($client, $version, ['deployment_status' => 'paused_post_tasks']);
        $this->assertStringEndsWith('/deploy/configure-system', $this->siguiente_accion_de($post_tasks->id));

        /* Corriendo → no hay endpoint que llamar, hay que esperar. */
        $corriendo = $this->crear_upgrade($client, $version, [
            'deployment_status'     => 'running',
            'deployment_started_at' => now(),
        ]);
        $cuerpo = $this->getJson('/api/claude/upgrades/' . $corriendo->id, $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertNull($cuerpo['siguiente_accion']['endpoint'], 'Con running no se llama a ningún endpoint: se espera.');
        $this->assertNotEmpty($cuerpo['siguiente_accion']['motivo']);
    }

    /**
     * El endpoint de un upgrade dado, devolviendo el endpoint de `siguiente_accion`.
     *
     * @param int $upgrade_id Id del upgrade.
     *
     * @return string
     */
    private function siguiente_accion_de(int $upgrade_id): string
    {
        return (string) $this->getJson('/api/claude/upgrades/' . $upgrade_id, $this->headers())
            ->assertStatus(200)
            ->json('siguiente_accion.endpoint');
    }

    /** La ficha del upgrade trae conteos (no filas), señales de salud y el horario del cliente. */
    public function test_ficha_de_un_upgrade_trae_conteos_salud_y_horario()
    {
        $desde   = $this->crear_version('7.0.0');
        $hasta   = $this->crear_version('7.1.0');
        $client  = $this->crear_cliente('Cliente de la ficha del upgrade', $desde->id);
        $api     = $this->crear_api($client);

        $client->active_client_api_id = $api->id;
        $client->save();

        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $upgrade = $this->crear_upgrade($client, $hasta, [
            'from_version_id'        => $desde->id,
            'target_client_api_id'   => $api->id,
            'deployment_status'      => 'paused',
            'deployment_started_at'  => now()->subMinutes(3),
            'sistema_actualizado_at' => now()->subMinutes(2),
            'created_via'            => ClientVersionUpgrade::CREATED_VIA_CLAUDE,
        ]);

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'run_migrations',
            'level'                     => 'success',
            'line'                      => 'migraciones ok',
        ]);

        $cuerpo = $this->getJson('/api/claude/upgrades/' . $upgrade->uuid, $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame($upgrade->id, $cuerpo['upgrade']['id']);
        $this->assertSame('claude', $cuerpo['upgrade']['created_via']);
        $this->assertSame($desde->version, $cuerpo['upgrade']['from_version']);
        $this->assertSame($hasta->version, $cuerpo['upgrade']['to_version']);
        $this->assertSame($client->id, $cuerpo['upgrade']['client']['id']);
        $this->assertTrue($cuerpo['upgrade']['target_client_api']['es_la_activa']);

        $this->assertNotNull($cuerpo['steps']['sistema_actualizado_at']);
        $this->assertNull($cuerpo['steps']['crons_supervisor_at']);

        /* Conteos, no filas: un upgrade puede tener decenas de seeders y comandos. */
        $this->assertSame(0, $cuerpo['seeders']['total']);
        $this->assertSame(0, $cuerpo['comandos']['manuales']);

        $this->assertSame(1, $cuerpo['logs']['total']);
        $this->assertNull($cuerpo['logs']['ultimo_error']);

        /* Recién arrancó y tiene un log: no está colgado. Y jobs_en_cola es un entero medido. */
        $this->assertFalse($cuerpo['salud']['deployment_stale']);
        $this->assertIsInt($cuerpo['salud']['jobs_en_cola']);

        $this->assertContains($cuerpo['horario_cliente']['estado_ahora'], ['abierto', 'cerrado']);

        $this->getJson('/api/claude/upgrades/999999999', $this->headers())->assertStatus(404);
    }

    /** El listado de upgrades filtra por cliente y por actividad del deployment. */
    public function test_listado_de_upgrades_filtra_por_cliente_y_por_activos()
    {
        $version = $this->crear_version('8.0.0');
        $uno     = $this->crear_cliente('Cliente de upgrades uno', $version->id);
        $dos     = $this->crear_cliente('Cliente de upgrades dos', $version->id);

        $quieto   = $this->crear_upgrade($uno, $version);
        $corriendo = $this->crear_upgrade($uno, $version, ['deployment_status' => 'running']);
        $ajeno    = $this->crear_upgrade($dos, $version);

        $del_cliente = $this->getJson('/api/claude/upgrades?client_id=' . $uno->id, $this->headers())
            ->assertStatus(200)
            ->json();

        $ids = array_column($del_cliente['data'], 'id');
        $this->assertContains($quieto->id, $ids);
        $this->assertContains($corriendo->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
        $this->assertSame($uno->name, $del_cliente['data'][0]['client_name']);

        $activos = $this->getJson('/api/claude/upgrades?client_id=' . $uno->id . '&activos=1', $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame([$corriendo->id], array_column($activos['data'], 'id'));

        /* activos=0 tiene que incluir los que NUNCA arrancaron (deployment_status NULL): un
           whereNotIn pelado los dejaría afuera en silencio. */
        $inactivos = $this->getJson('/api/claude/upgrades?client_id=' . $uno->id . '&activos=0', $this->headers())
            ->assertStatus(200)
            ->json();

        $this->assertSame([$quieto->id], array_column($inactivos['data'], 'id'));
    }
}
