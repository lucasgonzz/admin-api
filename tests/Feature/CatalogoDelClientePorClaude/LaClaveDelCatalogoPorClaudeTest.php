<?php

namespace Tests\Feature\CatalogoDelClientePorClaude;

use App\Http\Controllers\Api\ClaudeClientCatalogoController;
use App\Models\Client;
use App\Models\ClientApi;
use App\Services\ClientInboundKeySyncService;
use App\Services\EnvSshService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * `POST claude/clients/{id}/catalogo/clave` (C1, misión `implementacion-dos-sistemas`, 6/10/2026): el
 * admin escribe `ADMIN_API_INBOUND_KEY` = `clients.api_key` en el `.env` de cada frente del cliente,
 * para que el motor de `/categorizar` no tenga que traer la clave a la máquina de Lucas.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **La clave del cliente NO aparece en ninguna respuesta, error ni log.** Cada test que devuelve
 *     algo lo afirma contra el VALOR de la clave (`assertSinLaClave`), incluido el caso en que la
 *     excepción de SSH trae la clave adentro de su mensaje.
 *  2. 🔴 **Los frenos**: `dry_run` por defecto (no respalda ni escribe), `confirm_client_name`
 *     obligatorio al aplicar sin revelar el nombre correcto, y `dry_run` estricto (un valor que no
 *     se entiende NO es "aplicar").
 *  3. **Se escribe en TODOS los frentes, con respaldo**, y un frente que falla no frena al otro.
 *     `listo` mira al frente ACTIVO (`es_la_activa`: el MISMO al que le habla el puente): es true cuando
 *     ese frente tiene la clave y ningún frente quedó en `falta` o `distinta` sin escribir; un frente
 *     INACTIVO en `error` o `sin_env` se informa pero no traba. Sin un activo determinable vale la regla
 *     de siempre (al menos uno con la clave y ninguno en `falta`, `distinta` o `error`).
 *  4. **La forma del contrato con el motor** (`client_id`, `dry_run`, `api_key_en_el_admin`,
 *     `frentes[]` —con `es_la_activa` en cada uno—, `listo`) y sus códigos de error
 *     (`cliente_inexistente`, `sin_frentes`, `validacion`).
 *
 * Todo el SSH es un fake en memoria (`EnvSshServiceFake`): ningún test abre una conexión.
 */
class LaClaveDelCatalogoPorClaudeTest extends BaseDelCatalogoPorClaude
{
    /** La variable del `.env` del cliente. */
    const VARIABLE = 'ADMIN_API_INBOUND_KEY';

    /**
     * Reemplazo en memoria del servicio SSH, bindeado en el contenedor para toda la prueba.
     *
     * @var EnvSshServiceFake
     */
    private $ssh;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh = new EnvSshServiceFake();

        $this->app->instance(EnvSshService::class, $this->ssh);
    }

    /**
     * La URL de C1 para un cliente.
     *
     * @param Client|int|string $cliente Cliente, id o uuid.
     *
     * @return string
     */
    private function url($cliente): string
    {
        $id = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/claude/clients/' . $id . '/catalogo/clave';
    }

    /**
     * Un cliente con sus DOS frentes de shared hosting (como los deja `PromoteLeadToClientService`),
     * cada uno con su `.env` en memoria.
     *
     * @param string                $nombre Nombre del cliente.
     * @param string|null           $clave  Clave del cliente (null = una de 40 caracteres).
     * @param array<int, string>    $envs   Contenido del `.env` de cada frente (índice 0 y 1).
     *
     * @return array{0: Client, 1: ClientApi, 2: ClientApi}
     */
    private function cliente_con_dos_frentes(string $nombre = 'Doblep Distribuciones', ?string $clave = null, array $envs = []): array
    {
        $cliente = $this->crear_cliente($nombre, $clave);
        $uno     = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);
        $dos     = $this->crear_frente($cliente, 'doblep2');

        $this->ssh->envs[$uno->id] = isset($envs[0]) ? $envs[0] : "APP_ENV=production\nDB_DATABASE=doblep\n";
        $this->ssh->envs[$dos->id] = isset($envs[1]) ? $envs[1] : "APP_ENV=production\nDB_DATABASE=doblep\n";

        return [$cliente, $uno, $dos];
    }

    /**
     * Un cliente con un frente por cada estado pedido, con su `.env` en memoria dispuesto para que el
     * frente se ENCUENTRE en ese estado: `igual` (ya tiene la clave), `falta` (no tiene la variable),
     * `distinta` (tiene otro valor), `sin_env` (el servidor no tiene `.env`) y `error` (el SSH se cae al
     * leerlo). El frente `$indice_activo` queda como el activo (el primero por defecto). Con `null` ningún
     * frente es el activo NI tiene una URL válida (ni el cliente una legacy): el resolver no puede
     * determinar a cuál le habla el admin, que es el caso en que `listo` vuelve a la regla de siempre.
     *
     * @param array<int, string> $estados       Un estado por frente.
     * @param int|null           $indice_activo Índice (desde 0) del frente activo; null: no determinable.
     *
     * @return array{0: Client, 1: array<int, ClientApi>, 2: string} El cliente, sus frentes y la clave.
     */
    private function cliente_con_frentes_en_estado(array $estados, $indice_activo = 0): array
    {
        $clave   = Str::random(40);
        $cliente = $this->crear_cliente('Doblep Distribuciones', $clave);
        $frentes = [];

        foreach (array_values($estados) as $indice => $estado) {
            $frente = $this->crear_frente($cliente, 'doblep' . ($indice + 1), 'shared_hosting', null, $indice_activo !== null && $indice === $indice_activo);

            if ($indice_activo === null) {
                /* Sin una URL válida en ningún frente, el resolver no tiene a quién elegir. */
                $frente->url = '';
                $frente->save();
            }

            switch ($estado) {
                case 'igual':
                    $this->ssh->envs[$frente->id] = "APP_ENV=production\nADMIN_API_INBOUND_KEY=" . $clave . "\n";
                    break;

                case 'falta':
                    $this->ssh->envs[$frente->id] = "APP_ENV=production\n";
                    break;

                case 'distinta':
                    $this->ssh->envs[$frente->id] = "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n";
                    break;

                case 'sin_env':
                    /* El servidor no tiene .env en esa carpeta: no se carga nada. */
                    break;

                case 'error':
                    $this->ssh->envs[$frente->id]           = "APP_ENV=production\n";
                    $this->ssh->fallan_al_leer[$frente->id] = 'SSH caído en la prueba';
                    break;

                default:
                    $this->fail('Estado de frente desconocido en el test: ' . $estado);
            }

            $frentes[] = $frente;
        }

        return [$cliente, $frentes, $clave];
    }

    /**
     * El valor de una variable en el `.env` en memoria de un frente (parseado como lo parsea el admin).
     *
     * @param ClientApi $frente Frente.
     * @param string    $clave  Nombre de la variable.
     *
     * @return string|null
     */
    private function valor_en_el_env(ClientApi $frente, string $clave)
    {
        $env = $this->ssh->parse_env_content($this->ssh->envs[$frente->id]);

        return isset($env[$clave]) ? $env[$clave] : null;
    }

    /**
     * La fila de un frente en la respuesta.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta de C1.
     * @param ClientApi                        $frente    Frente.
     *
     * @return array<string, mixed>
     */
    private function fila($respuesta, ClientApi $frente): array
    {
        foreach ((array) $respuesta->json('frentes') as $fila) {
            if ((int) $fila['client_api_id'] === (int) $frente->id) {
                return $fila;
            }
        }

        $this->fail('La respuesta no trae el frente ' . $frente->id . '.');
    }

    /**
     * 🔴 `es_la_activa` es un booleano en TODAS las filas y es true solo en la del frente activo (en ninguna
     * si no se puede determinar cuál es).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta     Respuesta de C1.
     * @param array<int, ClientApi>             $frentes       Los frentes del cliente, en orden.
     * @param int|null                          $indice_activo Índice (desde 0) del activo; null: ninguno.
     * @param string                            $donde         Para el mensaje de la falla.
     *
     * @return void
     */
    private function assertSoloEsLaActiva($respuesta, array $frentes, $indice_activo, string $donde): void
    {
        foreach ($frentes as $indice => $frente) {
            $this->assertSame(
                $indice_activo !== null && $indice === $indice_activo,
                $this->fila($respuesta, $frente)['es_la_activa'],
                '`es_la_activa` del frente ' . ($indice + 1) . ' (' . $donde . ').'
            );
        }
    }

    /**
     * El host al que le pega el puente de catálogo de un cliente, o null si el puente no tiene a quién
     * hablarle (409 `sin_url`: no sale ningún pedido).
     *
     * Falsea el HTTP con una fábrica limpia (los stubs de `Http::fake()` se acumulan y los pedidos de una
     * llamada anterior no tienen que contar en esta) y, igual que el resto de los tests, nada sale a
     * internet.
     *
     * @param Client $cliente Cliente.
     *
     * @return string|null
     */
    private function a_quien_le_pega_el_puente(Client $cliente): ?string
    {
        Http::swap(new HttpFactorySinSalida());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $respuesta = $this->postJson('/api/claude/clients/' . $cliente->id . '/catalogo/puente', ['metodo' => 'GET', 'ruta' => '/resumen'], $this->headers());
        $pedidos   = Http::recorded();

        if ($pedidos->isEmpty()) {
            $respuesta->assertStatus(409);
            $this->assertSame('sin_url', $respuesta->json('error'));

            return null;
        }

        $respuesta->assertStatus(200);
        $this->assertCount(1, $pedidos);

        return (string) parse_url($pedidos->first()[0]->url(), PHP_URL_HOST);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y los errores del admin
     |----------------------------------------------------------------------------------------- */

    /**
     * El bloque es fail-closed: sin el header de ingesta no contesta.
     *
     * @return void
     */
    public function test_sin_la_clave_de_ingesta_devuelve_401(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        $this->postJson($this->url($cliente), [])->assertStatus(401);

        $this->assertSame([], $this->ssh->escrituras);
    }

    /**
     * 🔴 Un cliente que no existe es 404 con el CÓDIGO `cliente_inexistente` en `error`. Es lo que
     * distingue, del lado del motor, "este admin conoce la ruta" de "este admin es viejo": el 404 de
     * una ruta que no existe en Laravel NO trae `error`.
     *
     * @return void
     */
    public function test_cliente_inexistente_es_404_con_el_codigo_y_la_ruta_inexistente_no_lo_trae(): void
    {
        $inexistente = $this->postJson($this->url(987654321), [], $this->headers());

        $inexistente->assertStatus(404);
        $this->assertSame('cliente_inexistente', $inexistente->json('error'));
        $this->assertNotSame('', (string) $inexistente->json('mensaje'));

        $por_uuid = $this->postJson($this->url((string) Str::uuid()), [], $this->headers());
        $por_uuid->assertStatus(404);
        $this->assertSame('cliente_inexistente', $por_uuid->json('error'));

        $sin_ruta = $this->postJson('/api/claude/clients/1/catalogo/ruta-que-no-existe', [], $this->headers());
        $sin_ruta->assertStatus(404);
        $this->assertNull($sin_ruta->json('error'), 'El 404 de una ruta inexistente NO lleva `error`: es lo que el motor lee como "admin viejo".');
    }

    /**
     * Un cliente sin ninguna API cargada es 409 `sin_frentes`, y no genera ninguna clave.
     *
     * @return void
     */
    public function test_cliente_sin_frentes_es_409_y_no_genera_clave(): void
    {
        $cliente = $this->crear_cliente('Doblep Distribuciones', '');

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(409);
        $this->assertSame('sin_frentes', $respuesta->json('error'));
        $this->assertSame('', trim((string) $cliente->fresh()->api_key), 'Sin frentes donde escribirla, no se genera ninguna clave.');
    }

    /**
     * 🔴 Bytes que no son UTF-8 en la URL (`%FF` en el `{id}`) o en el nombre de un parámetro de más son
     * un 404 y un 422 `validacion`, no un 500: el controlador repite el nombre del parámetro en el
     * mensaje, y `json_encode` falla con un solo byte inválido. Lo comparten C1 y C2.
     *
     * @return void
     */
    public function test_bytes_que_no_son_utf8_en_la_url_o_en_un_parametro_de_mas_no_dan_500(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        $this->postJson('/api/claude/clients/%FF%FE/catalogo/clave', [], $this->headers())->assertStatus(404);

        $de_mas = $this->postJson($this->url($cliente) . '?%FF%FE=1', [], $this->headers());

        $de_mas->assertStatus(422);
        $this->assertSame('validacion', $de_mas->json('error'));
        $this->assertCount(1, $de_mas->json('detalle'));

        $this->assertSame([], $this->ssh->escrituras);
    }

    /**
     * El cliente se resuelve también por uuid, como en el resto del bloque.
     *
     * @return void
     */
    public function test_el_cliente_se_resuelve_por_uuid(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        $respuesta = $this->postJson($this->url($cliente->uuid), [], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame((int) $cliente->id, $respuesta->json('client_id'));
    }

    /* ------------------------------------------------------------------------------------------
     | 2. dry_run: el default no escribe
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin `dry_run` el default es TRUE: lee, dice qué escribiría y no respalda ni escribe una línea.
     * Y la respuesta tiene EXACTAMENTE la forma del contrato.
     *
     * @return void
     */
    public function test_el_dry_run_es_el_default_y_no_respalda_ni_escribe(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave, [
            "APP_ENV=production\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n",
        ]);

        $antes_uno = $this->ssh->envs[$uno->id];
        $antes_dos = $this->ssh->envs[$dos->id];

        $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

        $respuesta->assertStatus(200);

        /* La forma del contrato. */
        $this->assertSame(['client_id', 'dry_run', 'api_key_en_el_admin', 'frentes', 'listo'], array_keys($respuesta->json()));
        $this->assertSame((int) $cliente->id, $respuesta->json('client_id'));
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertSame('presente', $respuesta->json('api_key_en_el_admin'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertCount(2, $respuesta->json('frentes'));

        $fila_uno = $this->fila($respuesta, $uno);
        $this->assertSame(['client_api_id', 'hosting_type', 'path', 'es_la_activa', 'estado', 'accion', 'error'], array_keys($fila_uno));
        $this->assertSame('shared_hosting', $fila_uno['hosting_type']);
        $this->assertSame('domains/comerciocity.com/public_html/doblep/api', $fila_uno['path']);
        $this->assertTrue($fila_uno['es_la_activa'], '`es_la_activa` es un booleano: true en el frente al que le habla el admin (acá, la API activa del cliente).');
        $this->assertSame('falta', $fila_uno['estado']);
        $this->assertSame('escribir', $fila_uno['accion']);
        $this->assertNull($fila_uno['error']);

        /* El frente con OTRA clave no se pisaría sin `pisar_distintas`: el dry_run lo dice igual que aplicar. */
        $fila_dos = $this->fila($respuesta, $dos);
        $this->assertFalse($fila_dos['es_la_activa'], 'Y false en el otro.');
        $this->assertSame('distinta', $fila_dos['estado']);
        $this->assertSame('ninguna', $fila_dos['accion']);
        $this->assertSame('tiene otra clave; para reemplazarla, pisar_distintas: true', $fila_dos['error']);

        /* Lo que importa: ni respaldo, ni escritura, ni un byte cambiado. */
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertSame($antes_uno, $this->ssh->envs[$uno->id]);
        $this->assertSame($antes_dos, $this->ssh->envs[$dos->id]);

        /* 🔴 Ni la clave del admin ni el valor viejo del .env salen en la respuesta. */
        $this->assertSinLaClave($respuesta, $clave);
        $this->assertStringNotContainsString('otro-valor-viejo-1234567890', $this->cuerpo($respuesta));
    }

    /**
     * Todas las formas de decir "true" son dry_run y no escriben.
     *
     * @return array<string, array<int, mixed>>
     */
    public function formas_de_dry_run_verdadero(): array
    {
        return [
            'true booleano' => [true],
            'texto true'    => ['true'],
            'uno'           => [1],
            'texto uno'     => ['1'],
            'vacío (null)'  => [''],
            'null'          => [null],
        ];
    }

    /**
     * @dataProvider formas_de_dry_run_verdadero
     *
     * @param mixed $valor Valor de `dry_run`.
     *
     * @return void
     */
    public function test_las_formas_de_dry_run_verdadero_no_escriben($valor): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => $valor], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
    }

    /**
     * 🔴 Un `dry_run` que no se entiende es 422 y NO "aplicar". Con `$request->boolean()` un "maybe"
     * valdría false, que es el lado peligroso del interruptor.
     *
     * @return array<string, array<int, mixed>>
     */
    public function valores_de_dry_run_que_no_se_entienden(): array
    {
        return [
            'maybe'     => ['maybe'],
            'si'        => ['si'],
            'dos'       => [2],
            'una lista' => [[true]],
            'negativo'  => [-1],
        ];
    }

    /**
     * @dataProvider valores_de_dry_run_que_no_se_entienden
     *
     * @param mixed $valor Valor de `dry_run`.
     *
     * @return void
     */
    public function test_un_dry_run_que_no_se_entiende_es_422_y_no_aplica($valor): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => $valor, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('dry_run', $respuesta->json('detalle'));
        $this->assertSame([], $this->ssh->escrituras, 'Un dry_run ilegible no puede terminar escribiendo.');
        $this->assertSame([], $this->ssh->backups);
    }

    /**
     * La lista de parámetros es cerrada: una clave de más es 422 `validacion` y no se hace nada.
     *
     * @return void
     */
    public function test_un_parametro_de_mas_es_422_y_no_hace_nada(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false, 'force' => true, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertSame(['force'], array_keys($respuesta->json('detalle')));
        $this->assertSame([], $this->ssh->escrituras);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El freno del nombre
     |----------------------------------------------------------------------------------------- */

    /**
     * Aplicar sin `confirm_client_name` es 422 y no escribe.
     *
     * @return void
     */
    public function test_aplicar_sin_confirm_client_name_es_422_y_no_escribe(): void
    {
        $clave = Str::random(40);

        [$cliente] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave);

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('confirm_client_name', $respuesta->json('detalle'));
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertSinLaClave($respuesta, $clave);
    }

    /**
     * 🔴 Aplicar con el nombre equivocado es 422, no escribe NADA y la respuesta no revela el nombre
     * correcto: es un freno, no un formulario a completar.
     *
     * @return void
     */
    public function test_aplicar_con_el_nombre_equivocado_es_422_no_escribe_y_no_revela_el_nombre(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave);

        $antes_uno = $this->ssh->envs[$uno->id];
        $antes_dos = $this->ssh->envs[$dos->id];

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Otro Cliente S.A.'],
            $this->headers()
        );

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('confirm_client_name', $respuesta->json('detalle'));

        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertSame($antes_uno, $this->ssh->envs[$uno->id]);
        $this->assertSame($antes_dos, $this->ssh->envs[$dos->id]);

        $texto = mb_strtolower($this->cuerpo($respuesta));
        $this->assertStringNotContainsString('doblep', $texto, 'El error no puede revelar el nombre correcto del cliente.');
        $this->assertSinLaClave($respuesta, $clave);
    }

    /**
     * El nombre se compara con recorte y sin distinguir mayúsculas, como en `PUT .../schedule`.
     *
     * @return void
     */
    public function test_el_nombre_se_compara_con_recorte_y_sin_mayusculas(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes('Doblep Distribuciones');

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => '  DOBLEP distribuciones '],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'));
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Aplicar
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Aplicar escribe la clave en los DOS frentes, con respaldo de cada `.env`, deja el resto del
     * archivo como estaba, contesta `listo: true` y NO lleva la clave en la respuesta.
     *
     * El segundo frente tenía OTRA clave (`distinta`): por eso el pedido lleva `pisar_distintas: true`,
     * que es lo único que autoriza a reemplazarla.
     *
     * @return void
     */
    public function test_aplicar_escribe_en_los_dos_frentes_con_respaldo(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave, [
            "APP_ENV=production\nDB_DATABASE=doblep\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\nDB_DATABASE=doblep\n",
        ]);

        $registro = $this->capturar_el_log();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones', 'pisar_distintas' => true],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('dry_run'));
        $this->assertSame('presente', $respuesta->json('api_key_en_el_admin'));
        $this->assertTrue($respuesta->json('listo'));

        foreach ([$uno, $dos] as $frente) {
            $fila = $this->fila($respuesta, $frente);

            $this->assertSame('escrita', $fila['accion']);
            $this->assertNull($fila['error']);

            $this->assertSame([self::VARIABLE => $clave], $this->ssh->escrituras[$frente->id], 'Se escribe SOLO la variable, con la clave del cliente.');
            $this->assertArrayHasKey($frente->id, $this->ssh->backups, 'Se respalda el .env del frente antes de escribir.');
            $this->assertSame($clave, $this->valor_en_el_env($frente, self::VARIABLE));
            $this->assertSame('production', $this->valor_en_el_env($frente, 'APP_ENV'), 'El resto del .env queda como estaba.');
            $this->assertSame('doblep', $this->valor_en_el_env($frente, 'DB_DATABASE'));
        }

        $this->assertSame('falta', $this->fila($respuesta, $uno)['estado'], '`estado` es lo que se ENCONTRÓ antes de actuar.');
        $this->assertSame('distinta', $this->fila($respuesta, $dos)['estado']);

        /* 🔴 Ni en la respuesta ni en el log. */
        $this->assertSinLaClave($respuesta, $clave);
        $this->assertStringNotContainsString('otro-valor-viejo-1234567890', $this->cuerpo($respuesta));
        $this->assertLogSinLaClave($registro, $clave);
        $this->assertStringNotContainsString('otro-valor-viejo-1234567890', implode("\n", $registro->lineas));
    }

    /**
     * Aplicar deja una línea en el log con ids, estados y acciones; nunca valores.
     *
     * @return void
     */
    public function test_aplicar_deja_constancia_en_el_log_sin_valores(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave);

        $registro = $this->capturar_el_log();

        $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers())
            ->assertStatus(200);

        $propias = array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientInboundKeySyncService') === 0;
        }));

        $this->assertCount(1, $propias);
        $this->assertSame((int) $cliente->id, $propias[0]['contexto']['client_id']);
        $this->assertTrue($propias[0]['contexto']['listo']);
        $this->assertSame((int) $uno->id, $propias[0]['contexto']['frentes'][0]['client_api_id']);
        $this->assertSame('escrita', $propias[0]['contexto']['frentes'][0]['accion']);
        $this->assertTrue($propias[0]['contexto']['frentes'][0]['es_la_activa'], 'El log dice cuál era el frente activo.');
        $this->assertFalse($propias[0]['contexto']['frentes'][1]['es_la_activa']);

        $this->assertLogSinLaClave($registro, $clave);
    }

    /**
     * Es idempotente: un frente que ya tiene la clave igual no se respalda ni se toca, y el pedido
     * contesta `listo: true` tanto en dry_run como aplicando. La clave entre comillas en el `.env`
     * cuenta como igual (el admin la parsea como la parsea phpdotenv).
     *
     * @return void
     */
    public function test_un_frente_que_ya_tiene_la_clave_no_se_toca(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave, [
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=" . $clave . "\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY='" . $clave . "'\n",
        ]);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertTrue($dry->json('listo'));
        $this->assertSame('igual', $this->fila($dry, $uno)['estado']);
        $this->assertSame('igual', $this->fila($dry, $dos)['estado']);
        $this->assertSame('ninguna', $this->fila($dry, $uno)['accion']);

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertTrue($aplicado->json('listo'));
        $this->assertSame('ninguna', $this->fila($aplicado, $uno)['accion']);
        $this->assertSame('ninguna', $this->fila($aplicado, $dos)['accion']);

        $this->assertSame([], $this->ssh->escrituras, 'Un frente igual no se escribe.');
        $this->assertSame([], $this->ssh->backups, 'Un frente igual no se respalda.');

        $this->assertSinLaClave($dry, $clave);
        $this->assertSinLaClave($aplicado, $clave);
    }

    /**
     * Una variable vacía en el `.env` es `falta`, no `distinta`.
     *
     * @return void
     */
    public function test_una_variable_vacia_en_el_env_es_falta(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes('Doblep Distribuciones', null, [
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=\n",
        ]);

        $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

        $this->assertSame('falta', $this->fila($respuesta, $uno)['estado']);
    }

    /* ------------------------------------------------------------------------------------------
     | 5. Un frente que falla no frena al otro
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un frente con el SSH caído queda `estado: error` (el mensaje de la excepción tapado aunque
     * traiga la clave adentro), `listo: false`, y el OTRO frente se escribe igual.
     *
     * @return void
     */
    public function test_un_frente_con_ssh_caido_no_frena_al_otro_y_no_filtra_la_clave(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave);

        $this->ssh->fallan_al_leer[$uno->id] = 'Connection closed prematurely (exec cat ... ADMIN_API_INBOUND_KEY=' . $clave . ')';

        $registro = $this->capturar_el_log();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('listo'));

        $caido = $this->fila($respuesta, $uno);
        $this->assertSame('error', $caido['estado']);
        $this->assertSame('ninguna', $caido['accion'], 'Si ni siquiera se pudo leer, no se intentó escribir.');
        $this->assertStringContainsString('[clave oculta]', (string) $caido['error']);
        $this->assertArrayNotHasKey($uno->id, $this->ssh->escrituras);

        $sano = $this->fila($respuesta, $dos);
        $this->assertSame('escrita', $sano['accion']);
        $this->assertSame($clave, $this->valor_en_el_env($dos, self::VARIABLE), 'El frente sano se escribió igual.');

        $this->assertSinLaClave($respuesta, $clave);
        $this->assertLogSinLaClave($registro, $clave);
    }

    /**
     * Un frente que falla AL ESCRIBIR queda `estado: error` + `accion: fallo`, sin filtrar la clave, y
     * el otro frente queda escrito. Acá el que falla es el INACTIVO (`$dos`): se informa con su motivo
     * pero NO traba `listo`, porque el puente no le habla; si fallara el activo, `listo` sería false (ver
     * `test_un_fallo_de_ssh_traba_listo_solo_si_es_en_el_frente_activo`).
     *
     * @return void
     */
    public function test_un_frente_que_falla_al_escribir_queda_en_fallo_y_el_otro_se_escribe(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave);

        $this->ssh->fallan_al_escribir[$dos->id] = 'La escritura no quedó aplicada para ADMIN_API_INBOUND_KEY=' . $clave . '. Revisá permisos.';

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'El que falló es el frente inactivo: se informa, pero no traba listo.');

        $roto = $this->fila($respuesta, $dos);
        $this->assertFalse($roto['es_la_activa']);
        $this->assertSame('error', $roto['estado']);
        $this->assertSame('fallo', $roto['accion']);
        $this->assertStringContainsString('[clave oculta]', (string) $roto['error']);

        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion']);
        $this->assertSame($clave, $this->valor_en_el_env($uno, self::VARIABLE));
        $this->assertNull($this->valor_en_el_env($dos, self::VARIABLE), 'El frente que falló quedó como estaba.');

        $this->assertSinLaClave($respuesta, $clave);
    }

    /**
     * 🔴 Si el RESPALDO falla, ese frente NO se escribe: queda `estado: error` + `accion: fallo` con el
     * motivo (sin la clave, aunque el mensaje la traiga), no se le toca ni un byte al `.env` y el otro
     * frente se escribe igual. Escribir sin respaldo es dejar un `.env` modificado sin a dónde volver.
     *
     * @return void
     */
    public function test_si_el_respaldo_falla_ese_frente_no_se_escribe_y_los_demas_siguen(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave);

        $this->ssh->fallan_al_respaldar[$uno->id] = 'No se pudo crear el backup del .env en /home/x/.env.bak-1 (disco lleno) ADMIN_API_INBOUND_KEY=' . $clave;

        $antes    = $this->ssh->envs[$uno->id];
        $registro = $this->capturar_el_log();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('listo'));

        $roto = $this->fila($respuesta, $uno);
        $this->assertSame('error', $roto['estado']);
        $this->assertSame('fallo', $roto['accion']);
        $this->assertStringContainsString('backup', (string) $roto['error']);
        $this->assertStringContainsString('[clave oculta]', (string) $roto['error']);

        $this->assertArrayNotHasKey($uno->id, $this->ssh->backups, 'Un respaldo que falla no deja nada.');
        $this->assertArrayNotHasKey($uno->id, $this->ssh->escrituras, 'Sin respaldo no se escribe.');
        $this->assertSame($antes, $this->ssh->envs[$uno->id], 'El .env del frente sin respaldo no cambió ni un byte.');

        $sano = $this->fila($respuesta, $dos);
        $this->assertSame('escrita', $sano['accion'], 'El otro frente se respaldó y se escribió igual.');
        $this->assertArrayHasKey($dos->id, $this->ssh->backups);
        $this->assertSame($clave, $this->valor_en_el_env($dos, self::VARIABLE));

        $this->assertSinLaClave($respuesta, $clave);
        $this->assertLogSinLaClave($registro, $clave);
    }

    /**
     * Un frente sin `.env` NO se crea (escribir ahí dejaría un archivo en el servidor equivocado) y, siendo
     * el inactivo, no traba `listo`: con el frente activo escrito, el cliente queda listo.
     *
     * @return void
     */
    public function test_un_frente_sin_env_no_se_crea_y_no_cuenta_ni_a_favor_ni_en_contra(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        unset($this->ssh->envs[$dos->id]);

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'El frente escrito alcanza: el que nunca se instaló no cuenta en contra.');

        $sin_env = $this->fila($respuesta, $dos);
        $this->assertSame('sin_env', $sin_env['estado']);
        $this->assertSame('ninguna', $sin_env['accion']);
        $this->assertNotNull($sin_env['error'], 'Un frente sin .env dice por qué no se tocó.');

        $this->assertArrayNotHasKey($dos->id, $this->ssh->envs, 'No se crea ningún .env.');
        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras);
        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion']);
    }

    /**
     * 🔴 Un frente `igual` y otro `sin_env` dan `listo: true`: la segunda carpeta de un cliente de shared
     * que nunca se instaló no puede dejarlo sin `listo` para siempre cuando el frente que sirve tráfico
     * ya tiene la clave (el motor quedaría en el ciclo "corré clave --aplicar"). Ni en dry_run ni
     * aplicando se escribe nada: el `igual` ya está y el `sin_env` no se crea.
     *
     * @return void
     */
    public function test_un_frente_igual_y_otro_sin_env_dan_listo(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave, [
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=" . $clave . "\n",
        ]);

        unset($this->ssh->envs[$dos->id]);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertTrue($dry->json('listo'));
        $this->assertSame('igual', $this->fila($dry, $uno)['estado']);
        $this->assertSame('sin_env', $this->fila($dry, $dos)['estado']);

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertTrue($aplicado->json('listo'));
        $this->assertSame('ninguna', $this->fila($aplicado, $uno)['accion']);
        $this->assertSame('ninguna', $this->fila($aplicado, $dos)['accion']);

        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertArrayNotHasKey($dos->id, $this->ssh->envs, 'No se crea ningún .env.');

        $this->assertSinLaClave($dry, $clave);
        $this->assertSinLaClave($aplicado, $clave);
    }

    /**
     * 🔴 Con TODOS los frentes `sin_env` no hay nada que esté listo: `listo: false`, en dry_run y
     * aplicando, y no se escribe ni se crea nada. El frente ACTIVO sin `.env` deja `listo` en false (el
     * puente le habla a un frente sin clave) y, sin ningún frente con la clave, no hay con qué hablarle
     * al cliente.
     *
     * @return void
     */
    public function test_con_todos_los_frentes_sin_env_no_hay_nada_listo(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        unset($this->ssh->envs[$uno->id], $this->ssh->envs[$dos->id]);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertFalse($dry->json('listo'));
        $this->assertSame('sin_env', $this->fila($dry, $uno)['estado']);
        $this->assertSame('sin_env', $this->fila($dry, $dos)['estado']);

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertFalse($aplicado->json('listo'));

        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertSame([], $this->ssh->envs, 'No se crea ningún .env.');
    }

    /**
     * La regla de `listo` en todas las combinaciones que importan. Cada fila: los estados de los frentes
     * (cómo se los ENCUENTRA), cuál es el frente ACTIVO (índice desde 0; null = no se puede determinar,
     * porque ningún frente tiene una URL válida), `listo` en dry_run, `listo` aplicando SIN
     * `pisar_distintas` y `listo` aplicando CON `pisar_distintas: true`.
     *
     * 🔴 La regla con un frente activo determinado: ESE frente tiene que tener la clave (`igual`, o
     * `escrita` aplicando) y ningún frente puede quedar en `falta` o `distinta` sin escribir. Un frente
     * INACTIVO en `error` o `sin_env` se informa pero no traba (el puente no le habla); el ACTIVO en
     * `error` o `sin_env` deja `listo` en false. Sin un activo determinable vale la regla de siempre: al
     * menos un frente `igual` o `escrita` y ninguno en `falta`, `distinta` o `error`, con el `sin_env`
     * neutral. En dry_run no se escribió nada, así que un `falta` o `distinta` (activo o no) deja `listo`
     * en false. Aplicando, un `falta` queda `escrita` y cuenta a favor; un `distinta` solo se escribe si
     * se pidió pisarla, y sin eso queda sin escribir y en contra.
     *
     * @return array<string, array<int, mixed>>
     */
    public function escenarios_de_listo(): array
    {
        return [
            /* El activo es el primero y los inactivos están sanos o sin .env: lo que ya valía antes. */
            'activo igual, inactivo sin_env'                        => [['igual', 'sin_env'], 0, true, true, true],
            'dos igual'                                             => [['igual', 'igual'], 0, true, true, true],
            'activo falta, inactivo sin_env'                        => [['falta', 'sin_env'], 0, false, true, true],
            'activo distinta, inactivo sin_env'                     => [['distinta', 'sin_env'], 0, false, false, true],
            'activo igual, inactivo falta'                          => [['igual', 'falta'], 0, false, true, true],
            'activo igual, inactivo distinta'                       => [['igual', 'distinta'], 0, false, false, true],
            'activo falta, inactivo distinta'                       => [['falta', 'distinta'], 0, false, false, true],
            'dos distinta'                                          => [['distinta', 'distinta'], 0, false, false, true],
            'tres: activo igual, sin_env y falta'                   => [['igual', 'sin_env', 'falta'], 0, false, true, true],
            'tres: activo igual, sin_env y distinta'                => [['igual', 'sin_env', 'distinta'], 0, false, false, true],
            'tres: activo igual, sin_env y sin_env'                 => [['igual', 'sin_env', 'sin_env'], 0, true, true, true],

            /* 🔴 Lo que cambia con el frente activo: un inactivo en error o sin_env se informa y no traba. */
            'activo igual, inactivo en error'                       => [['igual', 'error'], 0, true, true, true],
            'activo falta, inactivo en error'                       => [['falta', 'error'], 0, false, true, true],
            'activo distinta, inactivo en error'                    => [['distinta', 'error'], 0, false, false, true],
            'tres: activo igual, sin_env y error'                   => [['igual', 'sin_env', 'error'], 0, true, true, true],
            'inactivo en error, activo igual'                       => [['error', 'igual'], 1, true, true, true],
            'inactivo sin_env, activo igual (el orden no importa)'  => [['sin_env', 'igual'], 1, true, true, true],
            'inactivo falta, activo igual'                          => [['falta', 'igual'], 1, false, true, true],
            'inactivo distinta, activo igual'                       => [['distinta', 'igual'], 1, false, false, true],
            'inactivo en error, activo falta'                       => [['error', 'falta'], 1, false, true, true],
            'inactivo en error, activo distinta'                    => [['error', 'distinta'], 1, false, false, true],
            'tres: activo en el medio, error y sin_env alrededor'   => [['error', 'igual', 'sin_env'], 1, true, true, true],
            'tres: activo al final, igual, error y falta'           => [['igual', 'error', 'falta'], 2, false, true, true],

            /* 🔴 El activo en error o sin_env deja listo en false, esté como esté el otro. */
            'todos sin_env'                                         => [['sin_env', 'sin_env'], 0, false, false, false],
            'tres: todos sin_env'                                   => [['sin_env', 'sin_env', 'sin_env'], 0, false, false, false],
            'activo sin_env, inactivo igual'                        => [['sin_env', 'igual'], 0, false, false, false],
            'activo en error, inactivo igual'                       => [['error', 'igual'], 0, false, false, false],
            'activo en error, inactivo falta'                       => [['error', 'falta'], 0, false, false, false],
            'activo en error, inactivo distinta'                    => [['error', 'distinta'], 0, false, false, false],
            'activo sin_env, inactivo en error'                     => [['sin_env', 'error'], 0, false, false, false],
            'activo en error, inactivo sin_env'                     => [['error', 'sin_env'], 0, false, false, false],
            'inactivo igual, activo en error'                       => [['igual', 'error'], 1, false, false, false],
            'inactivo igual, activo sin_env'                        => [['igual', 'sin_env'], 1, false, false, false],
            'tres: activo al final en error, igual y sin_env antes' => [['igual', 'sin_env', 'error'], 2, false, false, false],
            'tres: activo al final sin_env, igual y error antes'    => [['igual', 'error', 'sin_env'], 2, false, false, false],

            /* 🔴 Sin un activo determinable vale la regla de siempre: un error traba y el sin_env es neutral. */
            'sin activo: igual y sin_env'                           => [['igual', 'sin_env'], null, true, true, true],
            'sin activo: sin_env e igual'                           => [['sin_env', 'igual'], null, true, true, true],
            'sin activo: dos igual'                                 => [['igual', 'igual'], null, true, true, true],
            'sin activo: todos sin_env'                             => [['sin_env', 'sin_env'], null, false, false, false],
            'sin activo: falta y sin_env'                           => [['falta', 'sin_env'], null, false, true, true],
            'sin activo: igual y falta'                             => [['igual', 'falta'], null, false, true, true],
            'sin activo: igual y distinta'                          => [['igual', 'distinta'], null, false, false, true],
            'sin activo: igual y error (el error traba)'            => [['igual', 'error'], null, false, false, false],
            'sin activo: error e igual'                             => [['error', 'igual'], null, false, false, false],
            'sin activo: falta y error'                             => [['falta', 'error'], null, false, false, false],
            'sin activo: tres: igual, sin_env y error'              => [['igual', 'sin_env', 'error'], null, false, false, false],
            'sin activo: tres: igual, sin_env y sin_env'            => [['igual', 'sin_env', 'sin_env'], null, true, true, true],
        ];
    }

    /**
     * @dataProvider escenarios_de_listo
     *
     * @param array<int, string> $estados                  Estado en el que se encuentra cada frente.
     * @param int|null           $indice_activo            Índice (desde 0) del frente activo; null: no determinable.
     * @param bool               $listo_en_dry_run         `listo` esperado sin escribir.
     * @param bool               $listo_aplicando          `listo` esperado después de aplicar SIN `pisar_distintas`.
     * @param bool               $listo_pisando_distintas  `listo` esperado después de aplicar CON `pisar_distintas: true`.
     *
     * @return void
     */
    public function test_la_regla_de_listo(array $estados, $indice_activo, bool $listo_en_dry_run, bool $listo_aplicando, bool $listo_pisando_distintas): void
    {
        $donde = implode(', ', $estados) . ' (activo: ' . ($indice_activo === null ? 'ninguno' : (string) ($indice_activo + 1)) . ')';

        [$cliente, $frentes, $clave] = $this->cliente_con_frentes_en_estado($estados, $indice_activo);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertSame($listo_en_dry_run, $dry->json('listo'), 'dry_run con ' . $donde);
        $this->assertSoloEsLaActiva($dry, $frentes, $indice_activo, 'dry_run con ' . $donde);

        foreach ($frentes as $indice => $frente) {
            $fila = $this->fila($dry, $frente);

            $this->assertSame($estados[$indice], $fila['estado'], 'El estado del frente ' . ($indice + 1) . ' en el dry_run.');
            $this->assertSame(
                $this->accion_esperada_en_dry_run($estados[$indice], false),
                $fila['accion'],
                'La acción del frente ' . ($indice + 1) . ' (' . $estados[$indice] . ') en el dry_run sin pisar_distintas.'
            );
        }

        $this->assertSame([], $this->ssh->escrituras, 'El dry_run no escribe.');

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertSame($listo_aplicando, $aplicado->json('listo'), 'aplicando con ' . $donde);
        $this->assertSoloEsLaActiva($aplicado, $frentes, $indice_activo, 'aplicando con ' . $donde);

        $this->assertElDryRunPredijoLoQueHizoAplicar($dry, $aplicado, $frentes, 'sin pisar_distintas, con ' . $donde);

        $this->assertSinLaClave($dry, $clave);
        $this->assertSinLaClave($aplicado, $clave);

        /* Con `pisar_distintas: true`: otro cliente con los mismos estados (el aplicado de arriba ya escribió
           lo suyo). En dry_run el campo no cambia nada; aplicando, autoriza a reemplazar la clave distinta. */
        [$otro, $frentes_del_otro, $clave_del_otro] = $this->cliente_con_frentes_en_estado($estados, $indice_activo);

        $dry_con_el_campo = $this->postJson($this->url($otro), ['pisar_distintas' => true], $this->headers());

        $this->assertSame($listo_en_dry_run, $dry_con_el_campo->json('listo'), 'dry_run con pisar_distintas y ' . $donde);
        $this->assertSoloEsLaActiva($dry_con_el_campo, $frentes_del_otro, $indice_activo, 'dry_run con pisar_distintas y ' . $donde);

        foreach ($frentes_del_otro as $indice => $frente) {
            $this->assertSame(
                $this->accion_esperada_en_dry_run($estados[$indice], true),
                $this->fila($dry_con_el_campo, $frente)['accion'],
                'La acción del frente ' . ($indice + 1) . ' (' . $estados[$indice] . ') en el dry_run con pisar_distintas.'
            );
        }

        $pisando = $this->postJson(
            $this->url($otro),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones', 'pisar_distintas' => true],
            $this->headers()
        );

        $pisando->assertStatus(200);
        $this->assertSame($listo_pisando_distintas, $pisando->json('listo'), 'pisando distintas con ' . $donde);
        $this->assertSoloEsLaActiva($pisando, $frentes_del_otro, $indice_activo, 'pisando distintas con ' . $donde);

        $this->assertElDryRunPredijoLoQueHizoAplicar($dry_con_el_campo, $pisando, $frentes_del_otro, 'con pisar_distintas, con ' . $donde);

        $this->assertSinLaClave($pisando, $clave_del_otro);
    }

    /* ------------------------------------------------------------------------------------------
     | `listo` según el frente ACTIVO (`es_la_activa`)
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un frente INACTIVO que no se puede leer (`error`) se informa con su motivo pero NO traba `listo`: el
     * puente no le habla, y si trabara, un SSH caído o una carpeta vieja mal cargada dejarían al cliente sin
     * `listo` para siempre (`--aplicar` no lo arregla) y `/categorizar` quedaría frenado por un frente al que
     * nunca se le pide nada. Vale en dry_run y aplicando, y no se escribe nada (el activo ya tiene la clave).
     *
     * @return void
     */
    public function test_un_frente_inactivo_en_error_no_traba_listo_pero_se_informa_con_su_error(): void
    {
        [$cliente, [$activo, $inactivo], $clave] = $this->cliente_con_frentes_en_estado(['igual', 'error']);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertTrue($dry->json('listo'), 'dry_run: el inactivo en error no traba.');

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertTrue($aplicado->json('listo'), 'Aplicando: el inactivo en error no traba.');

        foreach ([$dry, $aplicado] as $respuesta) {
            $fila_activo   = $this->fila($respuesta, $activo);
            $fila_inactivo = $this->fila($respuesta, $inactivo);

            $this->assertTrue($fila_activo['es_la_activa']);
            $this->assertSame('igual', $fila_activo['estado']);

            $this->assertFalse($fila_inactivo['es_la_activa']);
            $this->assertSame('error', $fila_inactivo['estado'], 'El inactivo se informa como siempre.');
            $this->assertSame('ninguna', $fila_inactivo['accion']);
            $this->assertStringContainsString('SSH caído en la prueba', (string) $fila_inactivo['error'], 'Y con su motivo.');
        }

        $this->assertSame([], $this->ssh->escrituras);

        $this->assertSinLaClave($dry, $clave);
        $this->assertSinLaClave($aplicado, $clave);
    }

    /**
     * 🔴 El frente ACTIVO en `error` deja `listo` en false aunque el otro frente esté `igual`: el puente le
     * habla al activo, y ahí no hay clave que se pueda confirmar.
     *
     * @return void
     */
    public function test_el_frente_activo_en_error_deja_listo_en_false_aunque_el_otro_este_igual(): void
    {
        [$cliente, [$activo, $inactivo]] = $this->cliente_con_frentes_en_estado(['error', 'igual']);

        foreach (['dry_run' => [], 'aplicando' => ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones']] as $modo => $pedido) {
            $respuesta = $this->postJson($this->url($cliente), $pedido, $this->headers());

            $respuesta->assertStatus(200);
            $this->assertFalse($respuesta->json('listo'), $modo);

            $this->assertTrue($this->fila($respuesta, $activo)['es_la_activa'], $modo);
            $this->assertSame('error', $this->fila($respuesta, $activo)['estado'], $modo);

            $this->assertFalse($this->fila($respuesta, $inactivo)['es_la_activa'], $modo);
            $this->assertSame('igual', $this->fila($respuesta, $inactivo)['estado'], $modo);
        }
    }

    /**
     * 🔴 Un frente INACTIVO en `falta` SÍ traba `listo` en dry_run (quedaría a medias: el día que lo activen
     * no tiene la clave), y aplicando se escribe y `listo` pasa a true.
     *
     * @return void
     */
    public function test_un_frente_inactivo_en_falta_deja_listo_en_false_en_dry_run_y_aplicando_lo_deja_en_true(): void
    {
        [$cliente, [$activo, $inactivo], $clave] = $this->cliente_con_frentes_en_estado(['igual', 'falta']);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertFalse($dry->json('listo'), 'dry_run: el inactivo en falta todavía no tiene la clave.');
        $this->assertSame('falta', $this->fila($dry, $inactivo)['estado']);
        $this->assertSame('escribir', $this->fila($dry, $inactivo)['accion']);
        $this->assertSame([], $this->ssh->escrituras);

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertTrue($aplicado->json('listo'), 'Aplicando, el inactivo en falta se escribe y el cliente queda listo.');
        $this->assertSame('escrita', $this->fila($aplicado, $inactivo)['accion']);
        $this->assertSame($clave, $this->valor_en_el_env($inactivo, self::VARIABLE));
        $this->assertArrayNotHasKey($activo->id, $this->ssh->escrituras, 'El activo ya tenía la clave: no se toca.');

        $this->assertElDryRunPredijoLoQueHizoAplicar($dry, $aplicado, [$activo, $inactivo], 'inactivo en falta');
        $this->assertSinLaClave($dry, $clave);
        $this->assertSinLaClave($aplicado, $clave);
    }

    /**
     * 🔴 Si no se puede determinar cuál es el frente activo (ningún frente tiene una URL válida y el cliente
     * no tiene una legacy: el puente tampoco sabría a quién hablarle), `listo` vuelve a la regla de siempre y
     * NINGUNA fila trae `es_la_activa` en true: un `error` traba, el `sin_env` es neutral y con todos
     * `sin_env` no hay nada listo.
     *
     * @return void
     */
    public function test_sin_un_frente_activo_determinable_vale_la_regla_de_siempre(): void
    {
        $casos = [
            'igual y sin_env'                => [['igual', 'sin_env'], true],
            'sin_env e igual'                => [['sin_env', 'igual'], true],
            'igual y error (el error traba)' => [['igual', 'error'], false],
            'error e igual'                  => [['error', 'igual'], false],
            'todos sin_env'                  => [['sin_env', 'sin_env'], false],
        ];

        foreach ($casos as $nombre => $caso) {
            [$estados, $listo] = $caso;

            [$cliente, $frentes] = $this->cliente_con_frentes_en_estado($estados, null);

            $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

            $respuesta->assertStatus(200);
            $this->assertSame($listo, $respuesta->json('listo'), $nombre);
            $this->assertSoloEsLaActiva($respuesta, $frentes, null, $nombre);

            $this->assertNull($this->a_quien_le_pega_el_puente($cliente), 'El puente tampoco tiene a quién hablarle: ' . $nombre);
        }
    }

    /**
     * Qué se le cambia al cliente de `test_es_la_activa_es_el_frente_al_que_le_pega_el_puente` (arranca con dos
     * frentes sanos, `doblep1` y `doblep2`, con URL, y ninguna API activa cargada).
     *
     * @return array<string, array<int, string>>
     */
    public function escenarios_de_a_quien_le_habla_el_admin(): array
    {
        return [
            'la API activa del cliente'                                   => ['activa_del_cliente'],
            'sin API activa cargada: la primera con URL válida'           => ['sin_activa'],
            'la API activa sin URL válida: cae a la primera que la tiene' => ['activa_sin_url'],
            'ningún frente con URL válida: gana el valor legacy'          => ['legacy'],
            'ninguna URL en ningún lado'                                  => ['ninguna_url'],
        ];
    }

    /**
     * 🔴 `es_la_activa` no es una copia de la regla del puente: es LA MISMA. El frente que C1 marca como activo
     * es exactamente aquel al que le pega el puente (los dos salen de `ClientEmpresaApiUrlResolver`); y si el
     * puente le habla a algo que no es un frente (el valor legacy `clients.api_url`) o no tiene a quién, C1 no
     * marca ninguno.
     *
     * Cubre los casos donde la ficha (`claude/clients/{id}`, que mira solo `clients.active_client_api_id`) y
     * el resolver NO coinciden: sin API activa cargada, y con una activa sin URL válida.
     *
     * @dataProvider escenarios_de_a_quien_le_habla_el_admin
     *
     * @param string $escenario Qué se le cambia al cliente.
     *
     * @return void
     */
    public function test_es_la_activa_es_el_frente_al_que_le_pega_el_puente(string $escenario): void
    {
        $cliente = $this->crear_cliente();
        $uno     = $this->crear_frente($cliente, 'doblep1');
        $dos     = $this->crear_frente($cliente, 'doblep2');

        $this->ssh->envs[$uno->id] = "APP_ENV=production\n";
        $this->ssh->envs[$dos->id] = "APP_ENV=production\n";

        $marcado        = null;
        $host_esperado  = null;

        switch ($escenario) {
            case 'activa_del_cliente':
                $cliente->active_client_api_id = $dos->id;
                $cliente->save();

                $marcado = $dos;
                break;

            case 'sin_activa':
                /* `active_client_api_id` queda en null: el resolver sigue con la primera ClientApi con URL válida. */
                $marcado = $uno;
                break;

            case 'activa_sin_url':
                $cliente->active_client_api_id = $dos->id;
                $cliente->save();

                $dos->url = '';
                $dos->save();

                $this->assertSame((int) $dos->id, (int) $cliente->fresh()->active_client_api_id, 'La ficha del cliente diría que el activo es doblep2.');

                $marcado = $uno;
                break;

            case 'legacy':
                $uno->url = '';
                $uno->save();
                $dos->url = '';
                $dos->save();

                $cliente->api_url = 'https://api-legado.' . self::DOMINIO;
                $cliente->save();

                $host_esperado = 'api-legado.' . self::DOMINIO;
                break;

            case 'ninguna_url':
                $uno->url = '';
                $uno->save();
                $dos->url = '';
                $dos->save();
                break;

            default:
                $this->fail('Escenario desconocido en el test: ' . $escenario);
        }

        if ($marcado !== null) {
            $host_esperado = parse_url($marcado->url, PHP_URL_HOST);
        }

        $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

        $respuesta->assertStatus(200);

        $marcados = [];

        foreach ([$uno, $dos] as $frente) {
            if ($this->fila($respuesta, $frente)['es_la_activa'] === true) {
                $marcados[] = (int) $frente->id;
            }
        }

        $this->assertSame($marcado === null ? [] : [(int) $marcado->id], $marcados, 'Los frentes marcados con es_la_activa en "' . $escenario . '".');
        $this->assertSame($host_esperado, $this->a_quien_le_pega_el_puente($cliente), 'A quién le pega el puente en "' . $escenario . '".');
    }

    /**
     * Las formas de dejar a un frente sin poder tocarse (la guarda se dispara ANTES de leer ningún `.env`), con
     * lo que tiene que decir el motivo.
     *
     * @return array<string, array<int, string>>
     */
    public function formas_de_dejar_sin_tocar_a_un_frente(): array
    {
        return [
            'path de shared vacío'                => ['path_vacio', 'raíz de la cuenta compartida'],
            'path de shared con segmentos de más' => ['path_con_puntos', 'segmentos vacíos'],
            'vps sin vps_path'                    => ['vps_sin_carpeta', 'vps_path'],
            'vps_path que no es un nombre simple' => ['vps_con_barra', 'vps_path'],
            'USER_ID de otro dueño'               => ['otro_dueno', 'dueno_distinto'],
        ];
    }

    /**
     * Rompe a un frente de la forma pedida (ver `formas_de_dejar_sin_tocar_a_un_frente()`).
     *
     * @param string    $forma   Una de las formas del proveedor.
     * @param Client    $cliente Cliente dueño del frente.
     * @param ClientApi $frente  Frente a romper.
     *
     * @return void
     */
    private function dejar_sin_tocar(string $forma, Client $cliente, ClientApi $frente): void
    {
        switch ($forma) {
            case 'path_vacio':
                $frente->path = '';
                $frente->save();
                break;

            case 'path_con_puntos':
                $frente->path = 'x/..';
                $frente->save();
                break;

            case 'vps_sin_carpeta':
                $frente->hosting_type = 'vps';
                $frente->vps_path     = null;
                $frente->save();
                break;

            case 'vps_con_barra':
                $frente->hosting_type = 'vps';
                $frente->vps_path     = 'a/b';
                $frente->save();
                break;

            case 'otro_dueno':
                $this->ssh->envs[$frente->id] = "APP_ENV=production\nUSER_ID=" . ((int) $cliente->user_id + 1) . "\n";
                break;

            default:
                $this->fail('Forma de romper un frente desconocida en el test: ' . $forma);
        }
    }

    /**
     * 🔴 Un frente que no se puede tocar (carpeta que no se identifica, `.env` de otro dueño) traba `listo`
     * SOLO si es el activo: ahí el puente le habla a un frente sin clave. Si es el inactivo se informa con su
     * motivo y no traba. En los dos casos no se respalda ni se escribe nada en él, y el otro frente se escribe.
     *
     * @dataProvider formas_de_dejar_sin_tocar_a_un_frente
     *
     * @param string $forma Cómo se rompe el frente.
     * @param string $frase Lo que tiene que decir el motivo.
     *
     * @return void
     */
    public function test_un_frente_que_no_se_puede_tocar_traba_listo_solo_si_es_el_activo(string $forma, string $frase): void
    {
        /* Roto el frente ACTIVO. */
        [$cliente, $activo, $inactivo] = $this->cliente_con_dos_frentes();

        $this->dejar_sin_tocar($forma, $cliente, $activo);

        $antes = $this->ssh->envs[$activo->id];

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('listo'), 'Roto el frente ACTIVO (' . $forma . ').');

        $fila = $this->fila($respuesta, $activo);
        $this->assertTrue($fila['es_la_activa']);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString($frase, (string) $fila['error']);

        $this->assertArrayNotHasKey($activo->id, $this->ssh->escrituras);
        $this->assertArrayNotHasKey($activo->id, $this->ssh->backups);
        $this->assertSame($antes, $this->ssh->envs[$activo->id]);
        $this->assertSame('escrita', $this->fila($respuesta, $inactivo)['accion'], 'El frente sano se escribió igual.');

        /* Roto el frente INACTIVO. */
        [$otro, $sano, $roto] = $this->cliente_con_dos_frentes();

        $this->dejar_sin_tocar($forma, $otro, $roto);

        $antes_del_roto = $this->ssh->envs[$roto->id];

        $respuesta = $this->postJson($this->url($otro), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'Roto el frente INACTIVO (' . $forma . '): se informa, pero no traba.');

        $fila = $this->fila($respuesta, $roto);
        $this->assertFalse($fila['es_la_activa']);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString($frase, (string) $fila['error'], 'Se informa con su motivo.');

        $this->assertArrayNotHasKey($roto->id, $this->ssh->escrituras);
        $this->assertArrayNotHasKey($roto->id, $this->ssh->backups);
        $this->assertSame($antes_del_roto, $this->ssh->envs[$roto->id]);

        $this->assertTrue($this->fila($respuesta, $sano)['es_la_activa']);
        $this->assertSame('escrita', $this->fila($respuesta, $sano)['accion']);
    }

    /**
     * Los fallos de SSH que puede tener un frente, con la propiedad del fake que los provoca.
     *
     * @return array<string, array<int, string>>
     */
    public function fallos_de_ssh_de_un_frente(): array
    {
        return [
            'no se puede leer el .env'      => ['fallan_al_leer'],
            'no se puede respaldar el .env' => ['fallan_al_respaldar'],
            'no se puede escribir el .env'  => ['fallan_al_escribir'],
        ];
    }

    /**
     * 🔴 Un fallo de SSH (al leer, al respaldar o al escribir) traba `listo` SOLO si es en el frente activo: en
     * el inactivo queda `estado: error` con su motivo, pero el cliente sigue listo.
     *
     * @dataProvider fallos_de_ssh_de_un_frente
     *
     * @param string $falla Propiedad de `EnvSshServiceFake` que provoca el fallo.
     *
     * @return void
     */
    public function test_un_fallo_de_ssh_traba_listo_solo_si_es_en_el_frente_activo(string $falla): void
    {
        /* Falla en el frente ACTIVO. */
        [$cliente, $activo, $inactivo] = $this->cliente_con_dos_frentes();

        $this->ssh->{$falla}[$activo->id] = 'SSH caído en la prueba';

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('listo'), 'Falla en el ACTIVO (' . $falla . ').');
        $this->assertTrue($this->fila($respuesta, $activo)['es_la_activa']);
        $this->assertSame('error', $this->fila($respuesta, $activo)['estado']);
        $this->assertSame('escrita', $this->fila($respuesta, $inactivo)['accion']);

        /* Falla en el INACTIVO. */
        [$otro, $sano, $roto] = $this->cliente_con_dos_frentes();

        $this->ssh->{$falla}[$roto->id] = 'SSH caído en la prueba';

        $respuesta = $this->postJson($this->url($otro), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'Falla en el INACTIVO (' . $falla . '): se informa, pero no traba.');
        $this->assertFalse($this->fila($respuesta, $roto)['es_la_activa']);
        $this->assertSame('error', $this->fila($respuesta, $roto)['estado']);
        $this->assertTrue($this->fila($respuesta, $sano)['es_la_activa']);
        $this->assertSame('escrita', $this->fila($respuesta, $sano)['accion']);
    }

    /**
     * Un frente de VPS sin `vps_path` no se puede resolver: queda `error` (con el motivo) y no frena al
     * otro. El resolver de rutas tira, y esa excepción no puede tumbar la respuesta entera. Es el frente
     * INACTIVO, así que tampoco traba `listo`.
     *
     * @return void
     */
    public function test_un_frente_de_vps_sin_vps_path_queda_en_error_y_no_frena_al_otro(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes();

        $vps = $this->crear_frente($cliente, 'doblep-vps', 'vps', null);

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);

        $fila = $this->fila($respuesta, $vps);
        $this->assertSame('vps', $fila['hosting_type']);
        $this->assertNull($fila['path']);
        $this->assertSame('error', $fila['estado']);
        $this->assertStringContainsString('vps_path', (string) $fila['error']);

        $this->assertTrue($respuesta->json('listo'), 'El frente roto es el inactivo: se informa, pero no traba listo.');
        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion']);
    }

    /**
     * 🔴 Un frente de shared con el path VACÍO se resuelve a la raíz de la cuenta compartida, donde viven
     * las carpetas de todos los clientes: ahí no se opera, aunque exista un `.env`. Queda `error` y no
     * frena al otro frente; siendo el inactivo, tampoco traba `listo`.
     *
     * @return void
     */
    public function test_un_frente_de_shared_con_el_path_vacio_no_se_toca(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $dos->path = '';
        $dos->save();

        $antes = $this->ssh->envs[$dos->id];

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'El frente roto es el inactivo: se informa, pero no traba listo.');

        $fila = $this->fila($respuesta, $dos);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString('raíz de la cuenta compartida', (string) $fila['error']);

        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras, 'No se escribe en la raíz de la cuenta.');
        $this->assertArrayNotHasKey($dos->id, $this->ssh->backups);
        $this->assertSame($antes, $this->ssh->envs[$dos->id]);

        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion'], 'El otro frente se escribió igual.');
    }

    /* ------------------------------------------------------------------------------------------
     | `pisar_distintas`: un frente con OTRA clave no se reemplaza si no se pidió
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Aplicando SIN `pisar_distintas`, un frente cuyo `.env` ya tiene OTRA clave NO se escribe: queda
     * `estado: distinta` (lo que se encontró), `accion: ninguna`, el motivo EXACTO en `error` y `listo`
     * en false. No se respalda, su `.env` no cambia ni un byte, y el valor viejo no sale en la respuesta.
     * El otro frente (sin la variable) se escribe igual.
     *
     * @return void
     */
    public function test_aplicando_sin_pisar_distintas_un_frente_con_otra_clave_no_se_escribe(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave, [
            "APP_ENV=production\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n",
        ]);

        $antes = $this->ssh->envs[$dos->id];

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('listo'));

        $distinta = $this->fila($respuesta, $dos);
        $this->assertSame('distinta', $distinta['estado']);
        $this->assertSame('ninguna', $distinta['accion']);
        $this->assertSame('tiene otra clave; para reemplazarla, pisar_distintas: true', $distinta['error']);

        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras, 'No se reemplaza una clave que alguien puede estar usando.');
        $this->assertArrayNotHasKey($dos->id, $this->ssh->backups);
        $this->assertSame($antes, $this->ssh->envs[$dos->id]);

        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion'], 'Un frente sin la variable se escribe siempre.');

        $this->assertSinLaClave($respuesta, $clave);
        $this->assertStringNotContainsString('otro-valor-viejo-1234567890', $this->cuerpo($respuesta));
    }

    /**
     * Con `pisar_distintas: true` el frente `distinta` se respalda y se escribe, como siempre, y `listo`
     * es true. Los `igual` no se tocan y los `falta` se escriben, con o sin el campo.
     *
     * @return void
     */
    public function test_con_pisar_distintas_un_frente_con_otra_clave_se_respalda_y_se_escribe(): void
    {
        $clave = Str::random(40);

        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', $clave, [
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=" . $clave . "\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n",
        ]);

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones', 'pisar_distintas' => true],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'));

        $this->assertSame('ninguna', $this->fila($respuesta, $uno)['accion'], 'El frente igual no se toca.');
        $this->assertArrayNotHasKey($uno->id, $this->ssh->backups);

        $pisada = $this->fila($respuesta, $dos);
        $this->assertSame('distinta', $pisada['estado'], '`estado` sigue diciendo lo que se encontró.');
        $this->assertSame('escrita', $pisada['accion']);
        $this->assertNull($pisada['error']);

        $this->assertSame($clave, $this->valor_en_el_env($dos, self::VARIABLE));
        $this->assertArrayHasKey($dos->id, $this->ssh->backups, 'Antes de reemplazar la clave vieja se respalda el .env.');

        $this->assertSinLaClave($respuesta, $clave);
        $this->assertStringNotContainsString('otro-valor-viejo-1234567890', $this->cuerpo($respuesta));
    }

    /**
     * 🔴 El dry_run PREDICE lo mismo que haría aplicar con los mismos parámetros. Sin `pisar_distintas` (o en
     * false) el frente `distinta` sale `accion: ninguna` con el motivo exacto en `error`; con
     * `pisar_distintas: true` sale `accion: escribir`. En los dos `listo` es false (un dry_run nunca está
     * listo con un `distinta`), el frente `falta` siempre sale `escribir`, y no se respalda ni se escribe nada.
     *
     * @return void
     */
    public function test_el_dry_run_predice_lo_que_haria_aplicar_con_y_sin_pisar_distintas(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', null, [
            "APP_ENV=production\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n",
        ]);

        $sin_el_campo = $this->postJson($this->url($cliente), [], $this->headers());
        $en_false     = $this->postJson($this->url($cliente), ['pisar_distintas' => false], $this->headers());
        $en_true      = $this->postJson($this->url($cliente), ['pisar_distintas' => true], $this->headers());

        foreach ([$sin_el_campo, $en_false] as $respuesta) {
            $respuesta->assertStatus(200);
            $this->assertTrue($respuesta->json('dry_run'));
            $this->assertFalse($respuesta->json('listo'));

            $distinta = $this->fila($respuesta, $dos);
            $this->assertSame('distinta', $distinta['estado']);
            $this->assertSame('ninguna', $distinta['accion'], 'Sin pisar_distintas, aplicar no la escribiría.');
            $this->assertSame('tiene otra clave; para reemplazarla, pisar_distintas: true', $distinta['error']);
        }

        $this->assertSame($sin_el_campo->json('frentes'), $en_false->json('frentes'), 'Sin el campo y en false es lo mismo.');

        $en_true->assertStatus(200);
        $this->assertTrue($en_true->json('dry_run'));
        $this->assertFalse($en_true->json('listo'), 'Un dry_run nunca está listo con un distinta.');

        $pisaria = $this->fila($en_true, $dos);
        $this->assertSame('distinta', $pisaria['estado']);
        $this->assertSame('escribir', $pisaria['accion']);
        $this->assertNull($pisaria['error']);

        $this->assertNotSame($sin_el_campo->json('frentes'), $en_true->json('frentes'));

        /* El frente sin la variable se escribe con o sin el campo: no depende de él. */
        foreach ([$sin_el_campo, $en_false, $en_true] as $respuesta) {
            $this->assertSame('falta', $this->fila($respuesta, $uno)['estado']);
            $this->assertSame('escribir', $this->fila($respuesta, $uno)['accion']);
            $this->assertNull($this->fila($respuesta, $uno)['error']);
        }

        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
    }

    /**
     * Lo que dice el dry_run de un frente, según cómo se lo encuentra y si se autoriza a pisar.
     *
     * @param string $estado  `igual` | `falta` | `distinta` | `sin_env` | `error`.
     * @param bool   $pisando Si el pedido lleva `pisar_distintas: true`.
     *
     * @return string `escribir` o `ninguna`.
     */
    private function accion_esperada_en_dry_run(string $estado, bool $pisando): string
    {
        if ($estado === 'falta') {
            return 'escribir';
        }

        if ($estado === 'distinta') {
            return $pisando ? 'escribir' : 'ninguna';
        }

        return 'ninguna';
    }

    /**
     * 🔴 El dry_run predijo lo que de verdad hizo aplicar con los mismos parámetros: cada frente sale igual
     * (mismo estado, mismo motivo, mismo path) salvo que `escribir` pasa a ser `escrita`.
     *
     * @param \Illuminate\Testing\TestResponse $dry      Respuesta del dry_run.
     * @param \Illuminate\Testing\TestResponse $aplicado Respuesta de aplicar, sobre los mismos frentes.
     * @param array<int, ClientApi>              $frentes  Los frentes del cliente.
     * @param string                             $donde    Para el mensaje de la falla.
     *
     * @return void
     */
    private function assertElDryRunPredijoLoQueHizoAplicar($dry, $aplicado, array $frentes, string $donde): void
    {
        foreach ($frentes as $indice => $frente) {
            $previsto = $this->fila($dry, $frente);
            $real     = $this->fila($aplicado, $frente);

            $previsto['accion'] = $previsto['accion'] === 'escribir' ? 'escrita' : $previsto['accion'];

            $this->assertSame($previsto, $real, 'El dry_run no predijo lo que hizo aplicar en el frente ' . ($indice + 1) . ' (' . $donde . ').');
        }
    }

    /**
     * Valores de `pisar_distintas` que SÍ se entienden, con lo que significan.
     *
     * @return array<string, array<int, mixed>>
     */
    public function formas_de_pisar_distintas_que_se_entienden(): array
    {
        return [
            'true booleano'  => [true, true],
            'texto true'     => ['true', true],
            'uno'            => [1, true],
            'texto uno'      => ['1', true],
            'false booleano' => [false, false],
            'texto false'    => ['false', false],
            'cero'           => [0, false],
            'texto cero'     => ['0', false],
            'vacío'          => ['', false],
            'null'           => [null, false],
        ];
    }

    /**
     * @dataProvider formas_de_pisar_distintas_que_se_entienden
     *
     * @param mixed $valor    Valor de `pisar_distintas`.
     * @param bool  $se_pisa  Si eso autoriza a reemplazar la clave distinta.
     *
     * @return void
     */
    public function test_pisar_distintas_se_lee_estricto_y_por_defecto_es_false($valor, bool $se_pisa): void
    {
        [$cliente, , $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', null, [
            "APP_ENV=production\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n",
        ]);

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones', 'pisar_distintas' => $valor],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertSame($se_pisa ? 'escrita' : 'ninguna', $this->fila($respuesta, $dos)['accion']);
    }

    /**
     * 🔴 Un `pisar_distintas` que no se entiende es 422 y NO un `true` (que es el lado peligroso: reemplazar
     * una clave que alguien puede estar usando), igual que `dry_run`. No se escribe nada.
     *
     * @return void
     */
    public function test_un_pisar_distintas_que_no_se_entiende_es_422_y_no_escribe(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();

        foreach (['maybe', 'si', 2, -1, [true]] as $valor) {
            $respuesta = $this->postJson(
                $this->url($cliente),
                ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones', 'pisar_distintas' => $valor],
                $this->headers()
            );

            $respuesta->assertStatus(422);
            $this->assertSame('validacion', $respuesta->json('error'), json_encode($valor));
            $this->assertArrayHasKey('pisar_distintas', $respuesta->json('detalle'), json_encode($valor));
        }

        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
    }

    /**
     * Con la clave del admin vacía, aplicando SIN `pisar_distintas`: la clave se genera y se guarda (como
     * siempre), pero un frente que ya tenía otra no se reemplaza, y `listo` queda en false. El frente sin
     * clave sí recibe la nueva.
     *
     * @return void
     */
    public function test_con_la_clave_vacia_y_un_frente_con_otra_clave_se_genera_pero_no_se_pisa(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', '', [
            "APP_ENV=production\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=otro-valor-viejo-1234567890\n",
        ]);

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertSame('generada', $respuesta->json('api_key_en_el_admin'));
        $this->assertFalse($respuesta->json('listo'));

        $generada = (string) $cliente->fresh()->api_key;

        $this->assertSame(40, strlen($generada));
        $this->assertSame($generada, $this->valor_en_el_env($uno, self::VARIABLE));
        $this->assertSame('otro-valor-viejo-1234567890', $this->valor_en_el_env($dos, self::VARIABLE), 'La clave que ya tenía el otro frente sigue ahí.');
        $this->assertSame('ninguna', $this->fila($respuesta, $dos)['accion']);
        $this->assertSinLaClave($respuesta, $generada);
    }

    /**
     * Paths de shared que `ClientApiPathResolver` resuelve a OTRO lado del que parecen, con la frase que
     * tiene que decir el motivo.
     *
     * El resolver CONCATENA el path que cargó una persona, sin normalizarlo: `x/..` cae en la raíz de la
     * cuenta compartida (donde viven las carpetas de todos los clientes), `../x` sale de ella, `./` es la
     * raíz y `a//b` no es lo que alguien quiso escribir. Vacío, `/` y `//` los frena la guarda de la
     * raíz; el resto, la de los segmentos.
     *
     * @return array<string, array<int, string>>
     */
    public function paths_de_shared_que_no_se_pueden_identificar(): array
    {
        $raiz      = 'raíz de la cuenta compartida';
        $segmentos = 'segmentos vacíos';

        return [
            'vacío'                       => ['', $raiz],
            'solo una barra'              => ['/', $raiz],
            'varias barras'               => ['//', $raiz],
            'un punto'                    => ['.', $segmentos],
            'punto y barra'               => ['./', $segmentos],
            'dos puntos'                  => ['..', $segmentos],
            'subir un nivel adentro'      => ['x/..', $segmentos],
            'subir un nivel y bajar'      => ['x/../y', $segmentos],
            'subir antes de bajar'        => ['../x', $segmentos],
            'subir dos niveles'           => ['a/../..', $segmentos],
            'un punto adentro'            => ['a/./b', $segmentos],
            'barra doble adentro'         => ['a//b', $segmentos],
            'solo espacios'               => ['   ', $segmentos],
            'un segmento de solo espacios' => ['a/ /b', $segmentos],
        ];
    }

    /**
     * 🔴 Un frente de shared con un path que no se puede identificar queda `estado: error` con el motivo y
     * NO se toca: ni se respalda ni se escribe su `.env` (aunque exista uno). El otro frente se escribe
     * igual. Siendo el INACTIVO, no traba `listo` (si fuera el activo sí: ver
     * `test_un_frente_que_no_se_puede_tocar_traba_listo_solo_si_es_el_activo`).
     *
     * @dataProvider paths_de_shared_que_no_se_pueden_identificar
     *
     * @param string $path  `client_apis.path` del frente roto.
     * @param string $frase Lo que tiene que decir el motivo.
     *
     * @return void
     */
    public function test_un_path_de_shared_que_no_se_puede_identificar_no_se_toca(string $path, string $frase): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $dos->path = $path;
        $dos->save();

        $antes = $this->ssh->envs[$dos->id];

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'El frente roto es el inactivo: se informa, pero no traba listo.');

        $fila = $this->fila($respuesta, $dos);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString($frase, (string) $fila['error']);

        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras, 'No se escribe en una carpeta que no se puede identificar.');
        $this->assertArrayNotHasKey($dos->id, $this->ssh->backups);
        $this->assertSame($antes, $this->ssh->envs[$dos->id]);

        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion'], 'El otro frente se escribió igual.');
    }

    /**
     * Paths de shared que SÍ sirven: las barras de los extremos no cambian dónde cae la carpeta, y un
     * nombre con puntos o guiones (o que EMPIEZA con dos puntos) no es un segmento `.` ni `..`.
     *
     * @return array<string, array<int, string>>
     */
    public function paths_de_shared_que_si_sirven(): array
    {
        return [
            'normal'                                  => ['doblep/api'],
            'con barra al final'                      => ['doblep/api/'],
            'con barra al principio'                  => ['/doblep/api'],
            'con punto y guion en el nombre'          => ['dob.lep-2/api'],
            'un segmento que empieza con dos puntos'  => ['..oculta/api'],
            'un segmento de tres puntos'              => ['.../api'],
        ];
    }

    /**
     * La guarda de los segmentos no frena lo que sirve.
     *
     * @dataProvider paths_de_shared_que_si_sirven
     *
     * @param string $path `client_apis.path` del frente.
     *
     * @return void
     */
    public function test_un_path_de_shared_que_sirve_se_escribe(string $path): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes();

        $uno->path = $path;
        $uno->save();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);

        $fila = $this->fila($respuesta, $uno);
        $this->assertSame('escrita', $fila['accion']);
        $this->assertNull($fila['error']);
        $this->assertTrue($respuesta->json('listo'));
    }

    /**
     * `vps_path` que no son un nombre simple: se pegan entre `/home/api-` y `/empresa-api`, y con una barra
     * o un `..` adentro la carpeta cae fuera de `/home/api-<nombre>`.
     *
     * @return array<string, array<int, string>>
     */
    public function vps_paths_que_no_son_un_nombre_simple(): array
    {
        return [
            'con una barra'        => ['a/b'],
            'subiendo niveles'     => ['x/../..'],
            'dos puntos'           => ['..'],
            'un punto'             => ['.'],
            'con barra invertida'  => ['a\\b'],
        ];
    }

    /**
     * 🔴 Un frente de VPS con un `vps_path` que no es un nombre simple queda `estado: error` y no se toca.
     * Siendo el INACTIVO, no traba `listo`.
     *
     * @dataProvider vps_paths_que_no_son_un_nombre_simple
     *
     * @param string $vps_path `client_apis.vps_path` del frente roto.
     *
     * @return void
     */
    public function test_un_vps_path_que_no_es_un_nombre_simple_no_se_toca(string $vps_path): void
    {
        $cliente = $this->crear_cliente();
        $bueno   = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);
        $malo    = $this->crear_frente($cliente, 'doblep-vps', 'vps', $vps_path);

        $this->ssh->envs[$bueno->id] = "APP_ENV=production\n";
        $this->ssh->envs[$malo->id]  = "APP_ENV=production\n";

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'), 'El frente roto es el inactivo: se informa, pero no traba listo.');

        $fila = $this->fila($respuesta, $malo);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString('vps_path', (string) $fila['error']);

        $this->assertArrayNotHasKey($malo->id, $this->ssh->escrituras);
        $this->assertArrayNotHasKey($malo->id, $this->ssh->backups);
        $this->assertSame('escrita', $this->fila($respuesta, $bueno)['accion']);
    }

    /**
     * 🔴 Un `.env` con un `USER_ID` que NO es el de este cliente (`clients.user_id`) es el sistema de OTRO
     * dueño —una carpeta mal cargada en el admin—: ese frente queda `estado: error` con el motivo
     * `dueno_distinto` y no se respalda ni se escribe, ni en dry_run ni aplicando. El otro frente (cuyo
     * `.env` sí es de este cliente) se escribe igual. El ajeno es el INACTIVO: aplicando no traba `listo`
     * (en dry_run sigue en false porque el activo todavía no tiene la clave); si fuera el activo sí (ver
     * `test_un_frente_que_no_se_puede_tocar_traba_listo_solo_si_es_el_activo`).
     *
     * @return void
     */
    public function test_un_env_de_otro_dueno_no_se_toca(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', null, [
            "APP_ENV=production\nUSER_ID=1200\n",
            "APP_ENV=production\nUSER_ID=999900\nDB_DATABASE=de_otro\n",
        ]);

        $cliente->user_id = 1200;
        $cliente->save();

        $antes = $this->ssh->envs[$dos->id];

        /* En dry_run ya lo dice: el que mira antes de aplicar se entera. */
        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertFalse($dry->json('listo'));
        $this->assertSame('falta', $this->fila($dry, $uno)['estado']);

        $ajeno = $this->fila($dry, $dos);
        $this->assertSame('error', $ajeno['estado']);
        $this->assertSame('ninguna', $ajeno['accion']);
        $this->assertStringContainsString('dueno_distinto', (string) $ajeno['error']);
        $this->assertStringContainsString('999900', (string) $ajeno['error']);
        $this->assertStringContainsString('1200', (string) $ajeno['error']);

        /* Aplicando: el ajeno no se toca; el propio sí se escribe. */
        $aplicado = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $aplicado->assertStatus(200);
        $this->assertTrue($aplicado->json('listo'), 'El ajeno es el frente inactivo: se informa, pero no traba listo.');

        $fila = $this->fila($aplicado, $dos);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);

        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras, 'No se le escribe la clave al sistema de otro dueño.');
        $this->assertArrayNotHasKey($dos->id, $this->ssh->backups);
        $this->assertSame($antes, $this->ssh->envs[$dos->id]);

        $this->assertSame('escrita', $this->fila($aplicado, $uno)['accion']);
    }

    /**
     * El `USER_ID` del `.env` que COINCIDE con el del cliente no frena nada, escrito como se escriba
     * (con comillas, con espacios adentro de las comillas o después del valor), y tampoco frena un `.env`
     * que no trae `USER_ID` (los de base propia no lo necesitan) o que lo trae vacío.
     *
     * @return array<string, array<int, string>>
     */
    public function envs_que_son_de_este_cliente(): array
    {
        return [
            'igual'                              => ["APP_ENV=production\nUSER_ID=1200\n"],
            'entre comillas dobles'              => ["APP_ENV=production\nUSER_ID=\"1200\"\n"],
            'entre comillas simples con espacios' => ["APP_ENV=production\nUSER_ID=' 1200 '\n"],
            'con espacios después del valor'     => ["APP_ENV=production\nUSER_ID=1200   \n"],
            'sin USER_ID'                        => ["APP_ENV=production\nDB_DATABASE=propia\n"],
            'USER_ID vacío'                      => ["APP_ENV=production\nUSER_ID=\n"],
            'USER_ID solo con espacios'          => ["APP_ENV=production\nUSER_ID=\"   \"\n"],
        ];
    }

    /**
     * @dataProvider envs_que_son_de_este_cliente
     *
     * @param string $env Contenido del `.env` del frente.
     *
     * @return void
     */
    public function test_un_env_de_este_cliente_o_sin_user_id_se_escribe(string $env): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes('Doblep Distribuciones', null, [$env]);

        $cliente->user_id = 1200;
        $cliente->save();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);

        $fila = $this->fila($respuesta, $uno);
        $this->assertSame('escrita', $fila['accion']);
        $this->assertNull($fila['error']);
    }

    /**
     * La comparación es de TEXTO: `01200` no es `1200`. Y un cliente sin `user_id` cargado en el admin con
     * un `.env` que sí trae `USER_ID` también es `dueno_distinto` (no hay con qué verificar que sea suyo), y
     * el mensaje dice que no tiene uno cargado.
     *
     * @return void
     */
    public function test_el_user_id_se_compara_como_texto_y_un_cliente_sin_user_id_con_env_que_lo_trae_es_error(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes('Doblep Distribuciones', null, [
            "APP_ENV=production\nUSER_ID=01200\n",
        ]);

        $cliente->user_id = 1200;
        $cliente->save();

        $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

        $this->assertSame('error', $this->fila($respuesta, $uno)['estado'], '01200 no es 1200: se compara como texto.');
        $this->assertStringContainsString('dueno_distinto', (string) $this->fila($respuesta, $uno)['error']);

        /* Sin user_id en el admin. */
        $cliente->user_id = null;
        $cliente->save();

        $this->ssh->envs[$uno->id] = "APP_ENV=production\nUSER_ID=1200\n";

        $sin_user_id = $this->postJson($this->url($cliente), [], $this->headers());

        $fila = $this->fila($sin_user_id, $uno);
        $this->assertSame('error', $fila['estado']);
        $this->assertStringContainsString('dueno_distinto', (string) $fila['error']);
        $this->assertStringContainsString('sin cargar', (string) $fila['error']);
    }

    /**
     * Un frente de VPS con su `vps_path` se resuelve a la carpeta del VPS y se escribe.
     *
     * @return void
     */
    public function test_un_frente_de_vps_se_resuelve_a_su_carpeta(): void
    {
        $cliente = $this->crear_cliente();
        $vps     = $this->crear_frente($cliente, 'doblep', 'vps', 'doblep', true);

        $this->ssh->envs[$vps->id] = "APP_ENV=production\n";

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('listo'));
        $this->assertSame('/home/api-doblep/empresa-api', $this->fila($respuesta, $vps)['path']);
        $this->assertSame('vps', $this->fila($respuesta, $vps)['hosting_type']);
    }

    /* ------------------------------------------------------------------------------------------
     | 6. La clave del admin vacía
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con la clave del admin vacía, `dry_run` dice `falta`, NO genera ni guarda nada, y ningún
     * frente puede estar `igual` (una clave vacía no es igual a nada).
     *
     * @return void
     */
    public function test_con_la_clave_vacia_el_dry_run_dice_falta_y_no_guarda_nada(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', '', [
            "APP_ENV=production\n",
            "APP_ENV=production\nADMIN_API_INBOUND_KEY=lo-que-hubiera-1234567890\n",
        ]);

        $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame('falta', $respuesta->json('api_key_en_el_admin'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertSame('falta', $this->fila($respuesta, $uno)['estado']);
        $this->assertSame('distinta', $this->fila($respuesta, $dos)['estado'], 'Contra una clave vacía, lo que haya en el .env es distinto.');

        $this->assertSame('', trim((string) $cliente->fresh()->api_key), 'El dry_run no genera ninguna clave.');
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertStringNotContainsString('lo-que-hubiera-1234567890', $this->cuerpo($respuesta));
    }

    /**
     * 🔴 Con la clave del admin vacía y `dry_run=false`, la GENERA (40 caracteres), la guarda en el
     * cliente, la escribe en los dos frentes y dice `generada` — sin devolverla. La segunda llamada ya
     * la ve `presente`, con los frentes `igual` y sin escribir nada.
     *
     * @return void
     */
    public function test_con_la_clave_vacia_aplicando_la_genera_la_guarda_y_la_escribe(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes('Doblep Distribuciones', '');

        $registro = $this->capturar_el_log();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $this->assertSame('generada', $respuesta->json('api_key_en_el_admin'));
        $this->assertTrue($respuesta->json('listo'));

        $generada = (string) $cliente->fresh()->api_key;
        $this->assertSame(40, strlen($generada), 'La clave generada tiene 40 caracteres, como la del alta del cliente.');

        $this->assertSame($generada, $this->valor_en_el_env($uno, self::VARIABLE));
        $this->assertSame($generada, $this->valor_en_el_env($dos, self::VARIABLE));

        /* 🔴 La clave generada no sale ni en la respuesta ni en el log. */
        $this->assertSinLaClave($respuesta, $generada);
        $this->assertLogSinLaClave($registro, $generada);

        /* Segunda llamada: ya está, y no se vuelve a escribir. */
        $this->ssh->escrituras = [];
        $this->ssh->backups    = [];

        $otra = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $otra->assertStatus(200);
        $this->assertSame('presente', $otra->json('api_key_en_el_admin'));
        $this->assertTrue($otra->json('listo'));
        $this->assertSame('igual', $this->fila($otra, $uno)['estado']);
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame($generada, (string) $cliente->fresh()->api_key, 'La clave guardada no cambia en la segunda llamada.');
        $this->assertSinLaClave($otra, $generada);
    }

    /**
     * 🔴 Si el UPDATE que guarda la clave GENERADA falla, la `QueryException` de Laravel arma su mensaje
     * con el SQL y sus BINDINGS —o sea, con la clave nueva en claro—, y ese texto no puede llegar a la
     * respuesta de error ni al log (el handler imprime también la cadena de `previous`). El servicio la
     * atrapa y el endpoint contesta un 500 con código estable `clave_no_guardada` y un texto fijo.
     *
     * El doble hace fallar el guardado con una `QueryException` REAL cuyos bindings llevan la clave que
     * se intentaba guardar; el test lee esa clave del doble y la busca en la respuesta y en el log.
     *
     * @return void
     */
    public function test_si_falla_el_guardado_de_la_clave_generada_no_sale_ni_en_la_respuesta_ni_en_el_log(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes('Doblep Distribuciones', '');

        $doble = new class($this->ssh) extends ClientInboundKeySyncService {
            /** @var string|null La clave que intentó guardar (para buscarla en la respuesta y el log). */
            public $clave_intentada;

            protected function guardar_si_sigue_vacia(Client $client, $nueva)
            {
                $this->clave_intentada = $nueva;

                throw new QueryException(
                    'update `clients` set `api_key` = ?, `updated_at` = ? where `id` = ? and (`api_key` is null or TRIM(api_key) = \'\')',
                    [$nueva, '2026-10-06 22:00:00', $client->id],
                    new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
                );
            }
        };

        $this->app->instance(ClientInboundKeySyncService::class, $doble);

        $registro = $this->capturar_el_log();

        $respuesta = $this->postJson(
            $this->url($cliente),
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
            $this->headers()
        );

        $respuesta->assertStatus(500);
        $this->assertSame('clave_no_guardada', $respuesta->json('error'));
        $this->assertNotSame('', trim((string) $respuesta->json('mensaje')));

        $clave = (string) $doble->clave_intentada;
        $this->assertSame(40, strlen($clave), 'El doble intentó guardar una clave de verdad.');

        /* 🔴 La clave no está en la respuesta ni en el log. */
        $this->assertSinLaClave($respuesta, $clave);
        $this->assertLogSinLaClave($registro, $clave);

        /* Y tampoco el texto de la base: es un 500 con texto fijo. */
        foreach (['SQLSTATE', 'Deadlock', 'update `clients`', 'bindings'] as $texto_de_la_base) {
            $this->assertStringNotContainsString($texto_de_la_base, $this->cuerpo($respuesta));
            $this->assertStringNotContainsString($texto_de_la_base, implode("\n", $registro->lineas));
        }

        /* Queda constancia del fallo en el log, con la clase del error y sin su mensaje. */
        $propias = array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientInboundKeySyncService: no se pudo guardar la clave generada') === 0;
        }));

        $this->assertCount(1, $propias);
        $this->assertSame('error', $propias[0]['nivel']);
        $this->assertSame((int) $cliente->id, $propias[0]['contexto']['client_id']);
        $this->assertSame(QueryException::class, $propias[0]['contexto']['excepcion']);

        /* No se escribió nada en ningún servidor y el cliente sigue sin clave. */
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertSame('', trim((string) $cliente->fresh()->api_key));
    }

    /**
     * Una clave que son solo espacios cuenta como vacía y también se genera.
     *
     * @return void
     */
    public function test_una_clave_de_solo_espacios_cuenta_como_vacia(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes('Doblep Distribuciones', '   ');

        $respuesta = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame('generada', $respuesta->json('api_key_en_el_admin'));
        $this->assertSame(40, strlen((string) $cliente->fresh()->api_key));
    }

    /* ------------------------------------------------------------------------------------------
     | 7. El catálogo dice lo mismo que el controlador
     |----------------------------------------------------------------------------------------- */

    /**
     * Los parámetros que `config/claude_catalog.php` declara para C1 son EXACTAMENTE la lista cerrada
     * del controlador: un parámetro que está en una y no en la otra es uno que Claude no encuentra o
     * que el catálogo promete y el controlador rechaza.
     *
     * @return void
     */
    public function test_el_catalogo_declara_los_mismos_parametros_que_acepta_el_controlador(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/clients/{id}/catalogo/clave');

        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada de C1.');
        $this->assertTrue($endpoint['escribe']);

        $declarados = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') === false) {
                $declarados[] = $parametro['nombre'];
            }
        }

        $aceptados = ClaudeClientCatalogoController::PARAMETROS_DE_LA_CLAVE;

        sort($declarados);
        sort($aceptados);

        $this->assertSame($aceptados, $declarados);
    }

    /**
     * El catálogo describe la forma de cada frente de la respuesta con las MISMAS claves, y en el mismo orden,
     * que la respuesta de verdad: un campo que está en una y no en la otra (como `es_la_activa`) es uno que
     * Claude no encuentra, o que el catálogo promete y el endpoint no trae.
     *
     * @return void
     */
    public function test_el_catalogo_describe_el_frente_con_las_mismas_claves_que_la_respuesta(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/clients/{id}/catalogo/clave');

        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada de C1.');
        $this->assertSame(1, preg_match('/frentes: \[\{([^}]*)\}\]/', (string) $endpoint['para_que'], $coincidencia), 'El catálogo no describe la forma de `frentes`.');

        $documentadas = [];

        foreach (explode(', ', $coincidencia[1]) as $campo) {
            $documentadas[] = explode(':', $campo)[0];
        }

        [$cliente] = $this->cliente_con_dos_frentes();

        $respuesta = $this->postJson($this->url($cliente), [], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame($documentadas, array_keys($respuesta->json('frentes.0')));
        $this->assertStringContainsString('es_la_activa', (string) $endpoint['para_que']);
    }
}
