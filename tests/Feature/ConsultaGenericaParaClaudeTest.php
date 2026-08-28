<?php

namespace Tests\Feature;

use App\Services\ClaudeQueryService;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Version;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los frenos de la lectura genérica de Claude (`GET claude/query`).
 *
 * Este test existe por un motivo puntual y no por completitud. `claude/query` es el único endpoint
 * del bloque que puede leer CUALQUIER tabla del admin nombrándola por config, y el admin guarda las
 * dos api keys con las que se le habla al empresa-api de cada cliente, las credenciales SSH de todos
 * los hostings, los valores del `.env` de clientes reales y los tokens que abren formularios y PDFs
 * fuera de `auth:sanctum`. Lo que se protege acá, en orden de importancia:
 *
 *  1. 🔴 Que NO se pueda escribir por acá. Un POST, PUT, PATCH o DELETE sobre `claude/query` tiene
 *     que devolver 405. La ruta se registra sólo con `Route::get` y el controlador no tiene ningún
 *     método más que `index_json`: el test es la reja que avisa si mañana alguien agrega uno.
 *     Una escritura genérica saltearía todos los frenos que este repo tiene escritos endpoint por
 *     endpoint —confirm_client_name, dry_run por defecto, el gate de horario, los dos umbrales del
 *     vencimiento— y arrancaría deploys SSH sobre negocios reales.
 *  2. 🔴 Que la proyección sea una lista blanca POSITIVA de verdad: que `clients.api_key` no viaje
 *     por ningún camino, ni pidiéndola en `fields`, ni por un include, ni por la búsqueda por texto.
 *  3. 🔴 Que la segunda reja funcione: un config que declare una columna prohibida devuelve 422 y no
 *     sirve NADA, y ningún modelo del config real la pisa.
 *  4. Que un include no dispare una consulta por fila. Es la regla del bloque y se mide, no se
 *     promete.
 *  5. Que todo lo que el config declara exista de verdad en la base, y que ningún nombre de filtro
 *     pise un parámetro reservado.
 */
class ConsultaGenericaParaClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-query';

    /**
     * Valor de api key que se siembra para buscarlo después en el cuerpo crudo de la respuesta.
     *
     * 🔴 SIN ACENTOS a propósito: `getContent()` devuelve el JSON con los no-ASCII escapados
     * (`Ferrería` viaja como `Ferrería`), así que un assertStringNotContainsString sobre un
     * valor con acentos pasaría siempre y no verificaría nada. Una api key real tampoco los tiene,
     * así que acá el cuerpo crudo sirve. Igual se chequea también contra `cuerpo()`, que
     * re-serializa con JSON_UNESCAPED_UNICODE.
     */
    const API_KEY_SEMBRADA = 'clave-api-secretisima-9f3a2b1c';

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
     * Copiado de ActualizacionDelEcommercePorClaudeTest por el mismo motivo que allá: `getContent()`
     * escapa los no-ASCII y arruina cualquier comparación de texto con acentos, en los dos sentidos.
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
     * Cliente del admin, con api key y teléfono sembrados.
     *
     * @param string $nombre  Nombre del negocio.
     * @param array  $extras  Columnas adicionales a pisar.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, array $extras = []): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->company_name    = 'Empresa ' . $nombre;
        $client->slug            = Str::slug($nombre !== '' ? $nombre : 'sin-nombre') . '-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = self::API_KEY_SEMBRADA;
        $client->inbound_api_key = self::API_KEY_SEMBRADA . '-inbound';
        $client->phone           = '1122334455';
        $client->is_active       = true;

        foreach ($extras as $columna => $valor) {
            $client->{$columna} = $valor;
        }

        $client->save();

        return $client;
    }

    /**
     * Versión publicada.
     *
     * @param string $numero Número de versión.
     *
     * @return Version
     */
    private function crear_version(string $numero): Version
    {
        $version            = new Version();
        $version->version   = $numero;
        $version->title     = 'Versión ' . $numero;
        $version->status    = 'published';
        $version->is_hotfix = false;
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
        $api->url          = 'https://api-' . $client->slug . '.test';
        $api->path         = '/home/' . $client->slug . '/api';
        $api->spa_url      = 'https://' . $client->slug . '.test';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * N clientes, cada uno con su versión actual y su ClientApi, para las mediciones de páginas.
     *
     * Que TODOS tengan la relación cargada no es adorno: si algunas filas tuvieran la clave en null,
     * el include se saltearía la consulta agregada para esa página y la comparación de "3 filas
     * contra 30" mediría otra cosa.
     *
     * @param int $cantidad Cuántos crear.
     *
     * @return array<int, int> Ids creados, en orden.
     */
    private function crear_clientes_con_relaciones(int $cantidad): array
    {
        $version = $this->crear_version('9.9.' . random_int(100, 999));
        $ids     = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $cliente = $this->crear_cliente('Negocio de prueba ' . $i . ' ' . Str::random(5), [
                'current_version_id' => $version->id,
            ]);

            $this->crear_api($cliente);

            $ids[] = (int) $cliente->id;
        }

        return $ids;
    }

    /**
     * Consulta genérica.
     *
     * @param array<string, mixed> $parametros Query string.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function consultar(array $parametros)
    {
        return $this->getJson('/api/claude/query?' . http_build_query($parametros), $this->headers());
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y la ausencia de escritura
     |----------------------------------------------------------------------------------------- */

    /**
     * El middleware es fail-closed: sin el header no contesta.
     *
     * @return void
     */
    public function test_sin_clave_la_consulta_generica_devuelve_401(): void
    {
        $this->getJson('/api/claude/query?model=client')->assertStatus(401);
    }

    /**
     * 🔴 La garantía mecánica del "sin escritura genérica": los cuatro verbos de escritura devuelven
     * 405 porque la ruta se registra SÓLO con Route::get.
     *
     * No alcanza con que el controlador no tenga un método de escritura hoy: lo que este test cuida
     * es que nadie registre mañana un `Route::post('query', ...)` sin darse cuenta de lo que
     * significa. Una escritura por nombre de modelo saltearía todos los frenos que están escritos de
     * a uno en ClaudeUpgradeOpsController y arrancaría deploys SSH sobre negocios reales.
     *
     * @return void
     */
    public function test_no_hay_escritura_generica_sobre_query(): void
    {
        $this->postJson('/api/claude/query', ['model' => 'client'], $this->headers())->assertStatus(405);
        $this->putJson('/api/claude/query', ['model' => 'client'], $this->headers())->assertStatus(405);
        $this->patchJson('/api/claude/query', ['model' => 'client'], $this->headers())->assertStatus(405);
        $this->deleteJson('/api/claude/query', ['model' => 'client'], $this->headers())->assertStatus(405);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El corazón: la lista blanca positiva
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un modelo que no está en la lista blanca no devuelve ni una fila, y el 422 dice cuáles hay.
     *
     * `client_ssh_credential` está excluido a propósito: su columna `password` tiene cast encrypted
     * y son las credenciales de TODOS los hostings.
     *
     * @return void
     */
    public function test_un_modelo_fuera_de_la_lista_blanca_no_devuelve_ni_una_fila(): void
    {
        $respuesta = $this->consultar(['model' => 'client_ssh_credential']);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('data', $respuesta->json());
        $this->assertContains('client', $respuesta->json('modelos_disponibles'));
        $this->assertNotContains('client_ssh_credential', $respuesta->json('modelos_disponibles'));
    }

    /**
     * 🔴 La api key del cliente no aparece NUNCA, por ningún camino.
     *
     * Se prueban los cuatro caminos por los que una columna podría colarse: la consulta pelada,
     * pedirla explícitamente en `fields`, traerla de arriba con un include, y buscarla por texto.
     *
     * @return void
     */
    public function test_la_api_key_del_cliente_no_aparece_nunca_en_la_respuesta(): void
    {
        $version = $this->crear_version('8.8.' . random_int(100, 999));
        $cliente = $this->crear_cliente('Ferretería del Centro', ['current_version_id' => $version->id]);
        $this->crear_api($cliente);

        $intentos = [
            ['model' => 'client', 'ids' => (string) $cliente->id],
            ['model' => 'client', 'ids' => (string) $cliente->id, 'fields' => 'id,name,api_key'],
            ['model' => 'client', 'ids' => (string) $cliente->id, 'include' => 'version_actual,apis,contacto'],
            ['model' => 'client_api', 'client_id' => (string) $cliente->id, 'include' => 'cliente'],
        ];

        foreach ($intentos as $parametros) {
            $respuesta = $this->consultar($parametros);

            $this->assertContains(
                $respuesta->getStatusCode(),
                [200, 422],
                'La consulta ' . json_encode($parametros) . ' devolvió un estado inesperado.'
            );

            $this->assertStringNotContainsString(
                self::API_KEY_SEMBRADA,
                (string) $respuesta->getContent(),
                'La api key viajó en el cuerpo crudo de: ' . json_encode($parametros)
            );

            $this->assertStringNotContainsString(
                self::API_KEY_SEMBRADA,
                $this->cuerpo($respuesta),
                'La api key viajó en el cuerpo decodificado de: ' . json_encode($parametros)
            );
        }

        /*
         * ⚠️ El caso `q` va aparte, y el motivo lo encontró este mismo test al escribirlo: la
         * respuesta ECO-DEVUELVE el término buscado en `filtros_aplicados`, así que un
         * assertStringNotContainsString sobre el cuerpo entero falla siempre aunque no se haya
         * filtrado nada. Lo que hay que verificar acá es lo otro: que buscar por la api key no
         * DEVUELVA la fila. `busqueda` del modelo `client` son name, company_name y slug —api_key no
         * está—, así que la búsqueda no la encuentra, y `data` tiene que venir vacío.
         */
        $por_texto = $this->consultar(['model' => 'client', 'q' => self::API_KEY_SEMBRADA]);
        $por_texto->assertStatus(200);
        $this->assertSame([], $por_texto->json('data'), 'La búsqueda por texto encontró una fila usando la api key: api_key entró en `busqueda`.');
        $this->assertStringNotContainsString(
            self::API_KEY_SEMBRADA,
            (string) json_encode($por_texto->json('data'), JSON_UNESCAPED_UNICODE),
            'La api key viajó adentro de las filas devueltas por la búsqueda por texto.'
        );
    }

    /**
     * El teléfono es PII y viaja sólo con include=contacto.
     *
     * @return void
     */
    public function test_el_telefono_del_cliente_no_viaja_sin_include_contacto(): void
    {
        $cliente = $this->crear_cliente('Panadería Rosa ' . Str::random(5));

        $sin = $this->consultar(['model' => 'client', 'ids' => (string) $cliente->id]);
        $sin->assertStatus(200);
        $this->assertStringNotContainsString('1122334455', $this->cuerpo($sin));
        $this->assertNotContains('phone', $sin->json('columnas'));

        $con = $this->consultar(['model' => 'client', 'ids' => (string) $cliente->id, 'include' => 'contacto']);
        $con->assertStatus(200);
        $this->assertStringContainsString('1122334455', $this->cuerpo($con));
        $this->assertContains('phone', $con->json('columnas'));
        $this->assertContains('contacto', $con->json('includes_aplicados'));
    }

    /**
     * 🔴 `fields` sólo ACHICA la proyección.
     *
     * Los dos casos que importan: una columna que no está en el config (api_key) y una columna que
     * está pero es opt-in y no se pidió (phone). Si `fields` pudiera agrandar, el opt-in no sería
     * opt-in.
     *
     * @return void
     */
    public function test_fields_no_puede_agrandar_la_proyeccion(): void
    {
        $cliente = $this->crear_cliente('Kiosco Norte ' . Str::random(5));

        $this->consultar(['model' => 'client', 'fields' => 'api_key'])->assertStatus(422);
        $this->consultar(['model' => 'client', 'fields' => 'setup_data'])->assertStatus(422);
        $this->consultar(['model' => 'client', 'fields' => 'phone'])->assertStatus(422);

        $achicada = $this->consultar(['model' => 'client', 'ids' => (string) $cliente->id, 'fields' => 'id,name']);
        $achicada->assertStatus(200);
        $this->assertSame(['id', 'name'], $achicada->json('columnas'));
        $this->assertSame(['id', 'name'], array_keys($achicada->json('data.0')));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. La segunda reja: columnas prohibidas
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El test que rompe el build el día que alguien agregue un modelo mal.
     *
     * Recorre el config ENTERO —columnas base, columnas opt-in y columnas de cada relación— con el
     * mismo método que usa el endpoint en runtime. Una relación es tan capaz de filtrar un secreto
     * como la tabla principal.
     *
     * @return void
     */
    public function test_ningun_modelo_del_config_declara_una_columna_prohibida(): void
    {
        $servicio = app(ClaudeQueryService::class);
        $modelos  = (array) config('claude_query.modelos');

        $this->assertNotEmpty($modelos, 'El config de claude_query quedó vacío.');

        foreach ($modelos as $clave => $definicion) {
            $ofensoras = $servicio->columnas_prohibidas_declaradas((array) $definicion);

            $this->assertSame(
                [],
                $ofensoras,
                'El modelo "' . $clave . '" declara columnas prohibidas: ' . implode(', ', $ofensoras)
            );
        }
    }

    /**
     * 🔴 La reja de runtime: un config con una columna prohibida devuelve 422 y no sirve NADA.
     *
     * Se inyecta un modelo trucho con `config([...])` para probar la reja sin ensuciar el config
     * real. Fail-closed: se corta la consulta entera en vez de servir el resto sin esa columna,
     * porque servir "todo menos esa" dejaría al que escribió mal el config sin enterarse.
     *
     * @return void
     */
    public function test_un_config_con_una_columna_prohibida_no_devuelve_nada(): void
    {
        $this->crear_cliente('Trucho ' . Str::random(5));

        config(['claude_query.modelos.modelo_trucho' => [
            'tabla'           => 'clients',
            'descripcion'     => 'Modelo inventado por el test para probar la reja.',
            'columnas'        => ['id', 'name', 'api_key'],
            'clave_de_cursor' => 'id',
            'orden_default'   => 'asc',
            'limite_default'  => 10,
            'limite_max'      => 10,
            'filtros'         => [],
            'relaciones'      => [],
        ]]);

        $respuesta = $this->consultar(['model' => 'modelo_trucho']);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('data', $respuesta->json());
        $this->assertContains('api_key', $respuesta->json('columnas_prohibidas'));
        $this->assertStringNotContainsString(self::API_KEY_SEMBRADA, (string) $respuesta->getContent());
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Filtros, enumeraciones y límites
     |----------------------------------------------------------------------------------------- */

    /**
     * Un filtro no declarado es 422 con la lista de los válidos.
     *
     * Ignorarlo en silencio devolvería una lista más larga de la esperada y parecería un problema de
     * datos, no un filtro mal escrito.
     *
     * @return void
     */
    public function test_un_filtro_no_declarado_devuelve_422_con_los_validos(): void
    {
        $respuesta = $this->consultar(['model' => 'client', 'nombre_inventado' => 'x']);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('data', $respuesta->json());
        $this->assertContains('is_active', $respuesta->json('filtros_validos'));
    }

    /**
     * 🔴 Un valor fuera de la enumeración es 422 con la lista real.
     *
     * Y el caso elegido no es al azar: `pending` es exactamente el valor que NO existe en
     * `client_version_upgrades.status`. La enumeración real es
     * pendiente | listo_para_actualizar | actualizandose | terminada | fallida.
     *
     * @return void
     */
    public function test_un_valor_fuera_de_la_enumeracion_devuelve_422(): void
    {
        $respuesta = $this->consultar(['model' => 'client_version_upgrade', 'status' => 'pending']);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('data', $respuesta->json());
        $this->assertContains('pendiente', $respuesta->json('valores_validos'));
        $this->assertContains('fallida', $respuesta->json('valores_validos'));
        $this->assertNotContains('pending', $respuesta->json('valores_validos'));
    }

    /**
     * El límite se recorta al tope duro en vez de fallar.
     *
     * @return void
     */
    public function test_el_limite_se_recorta_al_tope_en_vez_de_fallar(): void
    {
        $respuesta = $this->consultar(['model' => 'client', 'limit' => 99999]);

        $respuesta->assertStatus(200);
        $this->assertSame(500, $respuesta->json('limite.aplicado'));
        $this->assertSame(500, $respuesta->json('limite.max'));
        $this->assertSame(99999, $respuesta->json('limite.pedido'));
    }

    /**
     * La paginación por cursor no repite ni saltea filas.
     *
     * @return void
     */
    public function test_la_paginacion_por_cursor_no_repite_ni_saltea_filas(): void
    {
        $ids = $this->crear_clientes_con_relaciones(5);

        $vistos   = [];
        $after_id = null;
        $vueltas  = 0;

        do {
            $parametros = ['model' => 'client', 'ids' => implode(',', $ids), 'limit' => 2, 'order' => 'asc'];
            if ($after_id !== null) {
                $parametros['after_id'] = $after_id;
            }

            $respuesta = $this->consultar($parametros);
            $respuesta->assertStatus(200);

            foreach ((array) $respuesta->json('data') as $fila) {
                $vistos[] = (int) $fila['id'];
            }

            $after_id = $respuesta->json('next_after_id');
            $vueltas++;
        } while ($respuesta->json('has_more') === true && $vueltas < 10);

        sort($ids);
        sort($vistos);

        $this->assertSame($ids, $vistos, 'La paginación por cursor repitió o salteó filas.');
        $this->assertSame(count($vistos), count(array_unique($vistos)));
    }

    /* ------------------------------------------------------------------------------------------
     | 5. El include no puede costar una consulta por fila
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Se MIDE, no se promete: la misma consulta con 3 filas y con 30 tiene que costar el mismo
     * número de consultas.
     *
     * Se piden los dos tipos de relación a la vez (belongs_to y has_many) para que ninguno de los
     * dos caminos pueda tener un N+1 escondido. La página se acota con el filtro `ids` sobre los
     * clientes creados por el test: si entraran filas preexistentes sin la relación cargada, el
     * include se saltearía su consulta agregada y la comparación mediría otra cosa.
     *
     * @return void
     */
    public function test_un_include_no_dispara_una_consulta_por_fila(): void
    {
        $ids = $this->crear_clientes_con_relaciones(30);

        $consultas = function (int $limite) use ($ids): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $respuesta = $this->consultar([
                'model'   => 'client',
                'ids'     => implode(',', $ids),
                'include' => 'version_actual,apis',
                'limit'   => $limite,
                'order'   => 'asc',
            ]);

            $cantidad = count(DB::getQueryLog());
            DB::disableQueryLog();

            $respuesta->assertStatus(200);
            $this->assertCount($limite, $respuesta->json('data'));
            $this->assertNotNull($respuesta->json('data.0.version_actual'));
            $this->assertNotEmpty($respuesta->json('data.0.apis'));

            return $cantidad;
        };

        $con_tres    = $consultas(3);
        $con_treinta = $consultas(30);

        $this->assertSame(
            $con_tres,
            $con_treinta,
            'Una página de 30 filas costó ' . $con_treinta . ' consultas y una de 3 costó ' . $con_tres . ': hay un N+1.'
        );
    }

    /* ------------------------------------------------------------------------------------------
     | 6. Coherencia del config con la base y con los parámetros del endpoint
     |----------------------------------------------------------------------------------------- */

    /**
     * Ningún nombre de filtro puede pisar un parámetro reservado.
     *
     * Si un modelo declarara un filtro llamado `limit`, el filtro nunca se aplicaría —el endpoint
     * leería el parámetro como tamaño de página— y el que consulta no tendría forma de darse cuenta.
     *
     * @return void
     */
    public function test_ningun_nombre_de_filtro_pisa_un_parametro_reservado(): void
    {
        foreach ((array) config('claude_query.modelos') as $clave => $definicion) {
            foreach (array_keys((array) $definicion['filtros']) as $filtro) {
                $this->assertNotContains(
                    $filtro,
                    ClaudeQueryService::PARAMETROS_RESERVADOS,
                    'El modelo "' . $clave . '" declara un filtro llamado "' . $filtro . '", que es un parámetro reservado del endpoint.'
                );
            }
        }
    }

    /**
     * 🔴 Toda columna declarada existe de verdad en la base.
     *
     * Una columna inventada no rompe al arrancar: rompe en runtime, con un SQL error 500, la primera
     * vez que alguien consulte ese modelo. Este test la agarra en el build. Cubre las cuatro
     * superficies: la proyección, las opt-in, la columna de cada filtro y las columnas y claves de
     * cada relación.
     *
     * @return void
     */
    public function test_toda_columna_declarada_existe_en_la_base(): void
    {
        foreach ((array) config('claude_query.modelos') as $clave => $definicion) {
            $tabla = $definicion['tabla'];

            $this->assertTrue(Schema::hasTable($tabla), 'El modelo "' . $clave . '" apunta a la tabla inexistente "' . $tabla . '".');

            $reales = Schema::getColumnListing($tabla);

            $declaradas = (array) $definicion['columnas'];
            foreach ((array) (isset($definicion['columnas_opt_in']) ? $definicion['columnas_opt_in'] : []) as $extras) {
                $declaradas = array_merge($declaradas, (array) $extras);
            }
            foreach ((array) (isset($definicion['busqueda']) ? $definicion['busqueda'] : []) as $columna) {
                $declaradas[] = $columna;
            }
            foreach ((array) $definicion['filtros'] as $filtro) {
                $declaradas[] = $filtro['columna'];
            }
            $declaradas[] = $definicion['clave_de_cursor'];

            foreach (array_unique($declaradas) as $columna) {
                $this->assertContains($columna, $reales, 'El modelo "' . $clave . '" declara "' . $columna . '", que no existe en ' . $tabla . '.');
            }

            foreach ((array) (isset($definicion['relaciones']) ? $definicion['relaciones'] : []) as $nombre => $relacion) {
                $this->assertContains($relacion['clave_local'], $reales, 'La relación "' . $clave . '.' . $nombre . '" usa una clave local inexistente.');

                $this->assertTrue(Schema::hasTable($relacion['tabla']), 'La relación "' . $clave . '.' . $nombre . '" apunta a una tabla inexistente.');

                $reales_relacion = Schema::getColumnListing($relacion['tabla']);

                foreach (array_merge((array) $relacion['columnas'], [$relacion['clave_externa']]) as $columna) {
                    $this->assertContains($columna, $reales_relacion, 'La relación "' . $clave . '.' . $nombre . '" declara "' . $columna . '", que no existe en ' . $relacion['tabla'] . '.');
                }
            }
        }
    }
}
