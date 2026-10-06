<?php

namespace Tests\Feature\CatalogoDelClientePorClaude;

use App\Http\Controllers\Api\ClaudeClientCatalogoController;
use App\Models\Client;
use App\Models\ClientApi;
use App\Services\ClientInboundKeySyncService;
use App\Services\EnvSshService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;

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
 *     `listo` es true con al menos un frente `igual` o `escrita` y ninguno en `falta`, `distinta` o
 *     `error`; un `sin_env` no cuenta ni a favor ni en contra.
 *  4. **La forma del contrato con el motor** (`client_id`, `dry_run`, `api_key_en_el_admin`,
 *     `frentes[]`, `listo`) y sus códigos de error (`cliente_inexistente`, `sin_frentes`,
 *     `validacion`).
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
     * leerlo). El primer frente queda como el activo.
     *
     * @param array<int, string> $estados Un estado por frente.
     *
     * @return array{0: Client, 1: array<int, ClientApi>, 2: string} El cliente, sus frentes y la clave.
     */
    private function cliente_con_frentes_en_estado(array $estados): array
    {
        $clave   = Str::random(40);
        $cliente = $this->crear_cliente('Doblep Distribuciones', $clave);
        $frentes = [];

        foreach (array_values($estados) as $indice => $estado) {
            $frente = $this->crear_frente($cliente, 'doblep' . ($indice + 1), 'shared_hosting', null, $indice === 0);

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
        $this->assertSame(['client_api_id', 'hosting_type', 'path', 'estado', 'accion', 'error'], array_keys($fila_uno));
        $this->assertSame('shared_hosting', $fila_uno['hosting_type']);
        $this->assertSame('domains/comerciocity.com/public_html/doblep/api', $fila_uno['path']);
        $this->assertSame('falta', $fila_uno['estado']);
        $this->assertSame('escribir', $fila_uno['accion']);
        $this->assertNull($fila_uno['error']);

        $fila_dos = $this->fila($respuesta, $dos);
        $this->assertSame('distinta', $fila_dos['estado']);
        $this->assertSame('escribir', $fila_dos['accion']);

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
            ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'],
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
     * el otro frente queda escrito.
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
        $this->assertFalse($respuesta->json('listo'));

        $roto = $this->fila($respuesta, $dos);
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
     * Un frente sin `.env` NO se crea (escribir ahí dejaría un archivo en el servidor equivocado) y no
     * cuenta ni a favor ni en contra de `listo`: con el otro frente escrito, el cliente queda listo.
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
     * aplicando, y no se escribe ni se crea nada. Un `sin_env` no cuenta en contra, pero tampoco a
     * favor: sin ningún frente con la clave no hay con qué hablarle al cliente.
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
     * (cómo se los ENCUENTRA), `listo` en dry_run y `listo` aplicando.
     *
     * La regla: al menos un frente `igual` o `escrita`, ninguno en `falta`, `distinta` o `error`; el
     * `sin_env` no cuenta ni a favor ni en contra. En dry_run no se escribió nada, así que un `falta` o
     * `distinta` deja `listo` en false; aplicando, esos mismos quedan `escrita` y cuentan a favor. Un
     * `error` cuenta en contra en los dos casos.
     *
     * @return array<string, array<int, mixed>>
     */
    public function escenarios_de_listo(): array
    {
        return [
            'igual y sin_env'                       => [['igual', 'sin_env'], true, true],
            'sin_env e igual (el orden no importa)' => [['sin_env', 'igual'], true, true],
            'dos igual'                             => [['igual', 'igual'], true, true],
            'todos sin_env'                         => [['sin_env', 'sin_env'], false, false],
            'falta y sin_env'                       => [['falta', 'sin_env'], false, true],
            'distinta y sin_env'                    => [['distinta', 'sin_env'], false, true],
            'igual y falta'                         => [['igual', 'falta'], false, true],
            'igual y distinta'                      => [['igual', 'distinta'], false, true],
            'falta y distinta'                      => [['falta', 'distinta'], false, true],
            'igual y error'                         => [['igual', 'error'], false, false],
            'falta y error'                         => [['falta', 'error'], false, false],
            'sin_env y error'                       => [['sin_env', 'error'], false, false],
            'tres: igual, sin_env y falta'          => [['igual', 'sin_env', 'falta'], false, true],
            'tres: igual, sin_env y error'          => [['igual', 'sin_env', 'error'], false, false],
            'tres: igual, sin_env y sin_env'        => [['igual', 'sin_env', 'sin_env'], true, true],
            'tres: todos sin_env'                   => [['sin_env', 'sin_env', 'sin_env'], false, false],
        ];
    }

    /**
     * @dataProvider escenarios_de_listo
     *
     * @param array<int, string> $estados          Estado en el que se encuentra cada frente.
     * @param bool               $listo_en_dry_run `listo` esperado sin escribir.
     * @param bool               $listo_aplicando  `listo` esperado después de aplicar.
     *
     * @return void
     */
    public function test_la_regla_de_listo(array $estados, bool $listo_en_dry_run, bool $listo_aplicando): void
    {
        [$cliente, $frentes, $clave] = $this->cliente_con_frentes_en_estado($estados);

        $dry = $this->postJson($this->url($cliente), [], $this->headers());

        $dry->assertStatus(200);
        $this->assertSame($listo_en_dry_run, $dry->json('listo'), 'dry_run con ' . implode(', ', $estados));

        foreach ($frentes as $indice => $frente) {
            $this->assertSame($estados[$indice], $this->fila($dry, $frente)['estado'], 'El estado del frente ' . ($indice + 1) . ' en el dry_run.');
        }

        $this->assertSame([], $this->ssh->escrituras, 'El dry_run no escribe.');

        $aplicado = $this->postJson($this->url($cliente), ['dry_run' => false, 'confirm_client_name' => 'Doblep Distribuciones'], $this->headers());

        $aplicado->assertStatus(200);
        $this->assertSame($listo_aplicando, $aplicado->json('listo'), 'aplicando con ' . implode(', ', $estados));

        $this->assertSinLaClave($dry, $clave);
        $this->assertSinLaClave($aplicado, $clave);
    }

    /**
     * Un frente de VPS sin `vps_path` no se puede resolver: queda `error` (con el motivo) y no frena al
     * otro. El resolver de rutas tira, y esa excepción no puede tumbar la respuesta entera.
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

        $this->assertFalse($respuesta->json('listo'));
        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion']);
    }

    /**
     * 🔴 Un frente de shared con el path VACÍO se resuelve a la raíz de la cuenta compartida, donde viven
     * las carpetas de todos los clientes: ahí no se opera, aunque exista un `.env`. Queda `error` y no
     * frena al otro frente.
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
        $this->assertFalse($respuesta->json('listo'));

        $fila = $this->fila($respuesta, $dos);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString('raíz de la cuenta compartida', (string) $fila['error']);

        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras, 'No se escribe en la raíz de la cuenta.');
        $this->assertArrayNotHasKey($dos->id, $this->ssh->backups);
        $this->assertSame($antes, $this->ssh->envs[$dos->id]);

        $this->assertSame('escrita', $this->fila($respuesta, $uno)['accion'], 'El otro frente se escribió igual.');
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
     * igual.
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
        $this->assertFalse($respuesta->json('listo'));

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
        $this->assertFalse($respuesta->json('listo'));

        $fila = $this->fila($respuesta, $malo);
        $this->assertSame('error', $fila['estado']);
        $this->assertSame('ninguna', $fila['accion']);
        $this->assertStringContainsString('vps_path', (string) $fila['error']);

        $this->assertArrayNotHasKey($malo->id, $this->ssh->escrituras);
        $this->assertArrayNotHasKey($malo->id, $this->ssh->backups);
        $this->assertSame('escrita', $this->fila($respuesta, $bueno)['accion']);
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
}
