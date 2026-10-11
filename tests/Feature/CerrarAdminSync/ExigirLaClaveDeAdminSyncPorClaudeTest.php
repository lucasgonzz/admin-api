<?php

namespace Tests\Feature\CerrarAdminSync;

use App\Http\Controllers\Api\ClaudeClientAdminSyncController;
use App\Models\Client;
use App\Models\ClientApi;
use App\Services\ClientAdminSyncClaveService;
use App\Services\EnvSshService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;
use Tests\Fakes\EnvSshServiceFake;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * `POST claude/clients/{id}/admin-sync/exigir-clave` (A4, misión `cerrar-admin-sync`, 10/10/2026): mira (`ver`), prende (`prender`) o apaga
 * (`apagar`) `ADMIN_SYNC_REQUIRE_API_KEY` y `ADMIN_SYNC_SETUP_REQUIRE_API_KEY` en el `.env` de cada frente del cliente, con prueba y vuelta atrás.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **`ver` NO escribe nunca** (ni respaldo, ni variable, ni borrado de cache) y no le pega al cliente. Es el default.
 *  2. 🔴 **Las precondiciones de `prender` son todas o nada**: sin `api_key`, sin frente activo, con un frente que no esté en `clave: igual`
 *     (falta, distinta, sin .env, con error) o con un `.env` de otro dueño, NO se escribe nada en ningún frente y no se llama al cliente.
 *  3. 🔴 **El camino feliz escribe las dos variables en TODOS los frentes**, con el respaldo ANTES de escribir y el borrado del cache DESPUÉS.
 *  4. 🔴 **La prueba manda**: con la clave que da 401 (o 5xx, o falla de red) REVIERTE todo lo escrito; sin la clave que da 200 informa
 *     `sin_efecto` y NO revierte.
 *  5. 🔴 **La clave no sale por ningún lado**, ni en la respuesta, ni en un error (aunque la excepción de SSH la traiga adentro), ni en el log.
 *  6. `apagar` deja las dos variables en `false` explícito; la confirmación por nombre es obligatoria para escribir.
 *
 * Todo el SSH es un fake en memoria (`EnvSshServiceFake`) y todo el HTTP un `Http::fake()` sobre `HttpFactorySinSalida`: ninguna prueba abre una conexión.
 */
class ExigirLaClaveDeAdminSyncPorClaudeTest extends BaseDelCatalogoPorClaude
{
    /** La variable de la exigencia general. */
    const EXIGENCIA = 'ADMIN_SYNC_REQUIRE_API_KEY';

    /** La variable de la exigencia del user setup. */
    const EXIGENCIA_SETUP = 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY';

    /** La URL de la prueba, en el frente activo (shared: la API vive bajo `/public`). */
    const URL_DE_LA_PRUEBA = 'https://api-doblep.ejemplo.test/public/api/admin-sync/mensualidad-info';

    /** La de respaldo. */
    const URL_DE_RESPALDO = 'https://api-doblep.ejemplo.test/public/api/admin-sync/employees';

    /** @var EnvSshServiceFake Reemplazo en memoria del servicio SSH. */
    private $ssh;

    /** @var string La clave del cliente de los tests. */
    private $clave;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh   = new EnvSshServiceFake();
        $this->clave = 'sk-exigir-' . Str::random(30);

        $this->app->instance(EnvSshService::class, $this->ssh);
    }

    /* ------------------------------------------------------------------------------------------
     | Escenarios y ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * La URL de A4 para un cliente.
     *
     * @param Client|int|string $cliente Cliente, id o uuid.
     *
     * @return string
     */
    private function url($cliente): string
    {
        $id = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/claude/clients/' . $id . '/admin-sync/exigir-clave';
    }

    /**
     * Llama a A4.
     *
     * @param Client               $cliente Cliente.
     * @param array<string, mixed> $pedido  Cuerpo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function exigir(Client $cliente, array $pedido = [])
    {
        return $this->postJson($this->url($cliente), $pedido, $this->headers());
    }

    /**
     * El contenido del `.env` de un frente, con la clave de inbound, el dueño del cliente y la clave de salida
     * (`ADMIN_API_OUTBOUND_KEY`) YA cargada: los casos de esa variable —que `prender` la escribe si falta, que no la pisa, que la
     * reversión la deja— están en `LaClaveDeSalidaAlPrenderAdminSyncTest`, con `.env` que no la traen.
     *
     * @param Client      $cliente  Cliente.
     * @param string|null $inbound  Valor de `ADMIN_API_INBOUND_KEY` (null = la clave del cliente; '' = sin la variable).
     * @param string      $extra    Líneas de más.
     *
     * @return string
     */
    private function env_de(Client $cliente, ?string $inbound = null, string $extra = ''): string
    {
        $linea_de_clave = $inbound === '' ? '' : 'ADMIN_API_INBOUND_KEY=' . ($inbound === null ? $cliente->api_key : $inbound) . "\n";

        return "APP_ENV=production\nUSER_ID=" . $cliente->user_id . "\nADMIN_API_OUTBOUND_KEY=salida-ya-cargada-en-el-env\n" . $linea_de_clave . $extra;
    }

    /**
     * Un cliente con sus DOS frentes de shared (el primero activo), cada uno con su `.env` en memoria.
     *
     * @param array<int, string|null> $envs Contenido de cada `.env` (null = el normal, con la clave igual).
     *
     * @return array{0: Client, 1: ClientApi, 2: ClientApi}
     */
    private function cliente_con_dos_frentes(array $envs = []): array
    {
        $cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);
        $uno     = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);
        $dos     = $this->crear_frente($cliente, 'doblep2');

        $this->ssh->envs[$uno->id] = isset($envs[0]) ? $envs[0] : $this->env_de($cliente);
        $this->ssh->envs[$dos->id] = isset($envs[1]) ? $envs[1] : $this->env_de($cliente);

        return [$cliente->refresh(), $uno, $dos];
    }

    /**
     * Falsea al cliente según lo que pase en cada pedido a `mensualidad-info` / `employees`.
     *
     * @param int|null $con_clave Status con la clave (null = lanza una falla de red).
     * @param int|null $sin_clave Status sin la clave (null = lanza una falla de red).
     * @param int      $respaldo  Status de `employees` (con la clave), si se llega a pedir.
     *
     * @return \stdClass Con `pedidos`: lista de `['url', 'con_clave']`.
     */
    private function el_cliente_contesta(?int $con_clave, ?int $sin_clave, int $respaldo = 200): \stdClass
    {
        $registro          = new \stdClass();
        $registro->pedidos = [];
        $clave             = $this->clave;

        Http::swap(new HttpFactorySinSalida());

        Http::fake(function ($pedido) use ($registro, $con_clave, $sin_clave, $respaldo, $clave) {
            $con = $pedido->hasHeader('X-Admin-Api-Key', $clave);

            $registro->pedidos[] = ['url' => $pedido->url(), 'metodo' => $pedido->method(), 'con_clave' => $con];

            if (strpos($pedido->url(), '/employees') !== false) {
                return Http::response(['empleados' => []], $respaldo);
            }

            $status = $con ? $con_clave : $sin_clave;

            if ($status === null) {
                throw new ConnectionException('cURL error 28: Operation timed out (X-Admin-Api-Key: ' . $clave . ') for ' . $pedido->url() . '?dato=PRIVADO');
            }

            return Http::response($status === 401 ? ['message' => 'Unauthorized'] : ['ok' => true], $status);
        });

        return $registro;
    }

    /**
     * Los valores de las dos variables en el `.env` en memoria de un frente (null = la variable no está).
     *
     * @param ClientApi $frente Frente.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function exigencias_en(ClientApi $frente): array
    {
        $env = $this->ssh->parse_env_content($this->ssh->envs[$frente->id]);

        return [
            array_key_exists(self::EXIGENCIA, $env) ? $env[self::EXIGENCIA] : null,
            array_key_exists(self::EXIGENCIA_SETUP, $env) ? $env[self::EXIGENCIA_SETUP] : null,
        ];
    }

    /**
     * Los frentes de una respuesta indexados por `client_api_id`.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function frentes_de($respuesta): array
    {
        $por_id = [];

        foreach ($respuesta->json('frentes') as $fila) {
            $por_id[$fila['client_api_id']] = $fila;
        }

        return $por_id;
    }

    /**
     * Afirma que no se tocó NADA en ningún servidor: ni respaldo, ni escritura, ni borrado de cache.
     *
     * @return void
     */
    private function assertNoSeTocoNada(): void
    {
        $this->assertSame([], $this->ssh->operaciones, 'Se tocó un servidor: ' . implode(', ', $this->ssh->operaciones));
        $this->assertSame([], $this->ssh->escrituras);
        $this->assertSame([], $this->ssh->backups);
        $this->assertSame([], $this->ssh->caches_limpiados);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta, la validación y los errores del admin
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin el header de ingesta no contesta, y no toca nada.
     *
     * @return void
     */
    public function test_sin_la_clave_de_ingesta_devuelve_401(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();
        Http::fake();

        $this->postJson($this->url($cliente), ['accion' => 'prender'])->assertStatus(401);

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
    }

    /**
     * 404 `cliente_inexistente`; 409 `sin_frentes`; 422 `validacion` por acción desconocida, parámetro de más o nombre que no es texto.
     *
     * @return void
     */
    public function test_los_errores_del_admin_tienen_su_codigo(): void
    {
        Http::fake();

        $inexistente = $this->postJson($this->url(987654321), [], $this->headers());
        $inexistente->assertStatus(404);
        $this->assertSame('cliente_inexistente', $inexistente->json('error'));

        $sin_frentes = $this->exigir($this->crear_cliente('Sin APIs', $this->clave));
        $sin_frentes->assertStatus(409);
        $this->assertSame('sin_frentes', $sin_frentes->json('error'));

        [$cliente] = $this->cliente_con_dos_frentes();

        foreach ([['accion' => 'borrar'], ['accion' => ['prender']], ['accion' => 'PRENDER ya'], ['otra' => 1], ['confirm_client_name' => ['x']]] as $pedido) {
            $respuesta = $this->exigir($cliente, $pedido);
            $respuesta->assertStatus(422);
            $this->assertSame('validacion', $respuesta->json('error'));
        }

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
    }

    /**
     * `prender` y `apagar` exigen `confirm_client_name`: sin él, o con otro nombre, es 422 y no se toca nada; el error no revela el nombre.
     *
     * @return void
     */
    public function test_prender_y_apagar_exigen_confirmar_el_nombre(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();
        Http::fake();

        foreach (['prender', 'apagar'] as $accion) {
            foreach ([[], ['confirm_client_name' => 'Otro Cliente']] as $extra) {
                $respuesta = $this->exigir($cliente, array_merge(['accion' => $accion], $extra));

                $respuesta->assertStatus(422);
                $this->assertSame('validacion', $respuesta->json('error'));
                $this->assertArrayHasKey('confirm_client_name', $respuesta->json('detalle'));
                $this->assertStringNotContainsString('Doblep', $this->cuerpo($respuesta), 'El error revela el nombre del cliente.');
            }
        }

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 2. ver
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 `ver` es el default: informa la clave y las dos exigencias de cada frente, marca el activo, NO pide confirmación y NO toca nada
     * (ni respaldo, ni escritura, ni cache) ni le pega al cliente.
     *
     * @return void
     */
    public function test_ver_informa_cada_frente_y_no_escribe_nada(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes([null, null]);

        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=false\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n";
        Http::fake();

        $respuesta = $this->exigir($cliente);

        $respuesta->assertStatus(200);
        $this->assertSame('ver', $respuesta->json('accion'));
        $this->assertSame('visto', $respuesta->json('estado'));
        $this->assertTrue($respuesta->json('listo'), 'Los dos frentes tienen la clave igual: está en condiciones de prenderse.');
        $this->assertSame((int) $cliente->id, $respuesta->json('client_id'));
        $this->assertNull($respuesta->json('prueba'));

        $frentes = $this->frentes_de($respuesta);

        $this->assertSame('igual', $frentes[$uno->id]['clave']);
        $this->assertSame('false', $frentes[$uno->id]['exigencia']);
        $this->assertSame('true', $frentes[$uno->id]['exigencia_setup']);
        $this->assertTrue($frentes[$uno->id]['es_la_activa']);
        $this->assertSame('shared_hosting', $frentes[$uno->id]['hosting_type']);

        $this->assertSame('igual', $frentes[$dos->id]['clave']);
        $this->assertSame('sin_definir', $frentes[$dos->id]['exigencia']);
        $this->assertSame('sin_definir', $frentes[$dos->id]['exigencia_setup']);
        $this->assertFalse($frentes[$dos->id]['es_la_activa']);

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * `ver` con un frente de cada estado de la clave (`falta`, `distinta`, `sin_env`, `error`): un frente que falla NO frena la lectura
     * de los demás, `listo` es false, y NINGÚN valor de clave sale en la respuesta (ni la del admin ni la que tiene un `.env` distinto).
     *
     * @return void
     */
    public function test_ver_con_un_frente_de_cada_estado_no_frena_y_no_filtra_ninguna_clave(): void
    {
        $cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);
        $igual   = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);
        $falta   = $this->crear_frente($cliente, 'doblep2');
        $dist    = $this->crear_frente($cliente, 'doblep3');
        $sinenv  = $this->crear_frente($cliente, 'doblep4');
        $roto    = $this->crear_frente($cliente, 'doblep5');

        $otra_clave = 'otra-clave-secreta-del-env-' . Str::random(10);

        $this->ssh->envs[$igual->id]  = $this->env_de($cliente);
        $this->ssh->envs[$falta->id]  = $this->env_de($cliente, '');
        $this->ssh->envs[$dist->id]   = $this->env_de($cliente, $otra_clave);
        $this->ssh->envs[$roto->id]   = $this->env_de($cliente);

        $this->ssh->fallan_al_leer[$roto->id] = 'No se pudo leer el .env (SSH caído, usuario con ' . $this->clave . ')';

        Http::fake();

        $respuesta = $this->exigir($cliente->refresh(), ['accion' => 'ver']);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('listo'));

        $frentes = $this->frentes_de($respuesta);

        $this->assertSame('igual', $frentes[$igual->id]['clave']);
        $this->assertSame('falta', $frentes[$falta->id]['clave']);
        $this->assertSame('distinta', $frentes[$dist->id]['clave']);
        $this->assertSame('sin_env', $frentes[$sinenv->id]['clave']);
        $this->assertSame('error', $frentes[$roto->id]['clave']);
        $this->assertNull($frentes[$roto->id]['exigencia']);
        $this->assertStringContainsString('[clave oculta]', (string) $frentes[$roto->id]['error']);

        $this->assertNoSeTocoNada();
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSinLaClave($respuesta, $otra_clave);
    }

    /**
     * `ver` reconoce los valores raros de una exigencia: vacía es `sin_definir`, un texto que no es booleano es `invalido`.
     *
     * @return void
     */
    public function test_ver_clasifica_el_valor_de_las_exigencias(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes();

        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=quizas\n";

        $frente = $this->frentes_de($this->exigir($cliente))[$uno->id];

        $this->assertSame('sin_definir', $frente['exigencia']);
        $this->assertSame('invalido', $frente['exigencia_setup']);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. prender: las precondiciones, todas o nada
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un frente que no está en `clave: igual` aborta `prender` SIN escribir en NINGUNO (tampoco en el que sí estaba bien) ni llamar al
     * cliente, y el motivo dice cuál frente y por qué.
     *
     * @return array<string, array<int, string>>
     */
    public function los_estados_de_clave_que_abortan(): array
    {
        return [
            'falta'    => ['falta'],
            'distinta' => ['distinta'],
            'sin_env'  => ['sin_env'],
            'error'    => ['error'],
        ];
    }

    /**
     * @dataProvider los_estados_de_clave_que_abortan
     *
     * @param string $estado El estado del SEGUNDO frente.
     *
     * @return void
     */
    public function test_un_frente_que_no_esta_igual_aborta_sin_escribir_en_ninguno(string $estado): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        switch ($estado) {
            case 'falta':
                $this->ssh->envs[$dos->id] = $this->env_de($cliente, '');
                break;
            case 'distinta':
                $this->ssh->envs[$dos->id] = $this->env_de($cliente, 'una-clave-distinta-de-otro-' . Str::random(8));
                break;
            case 'sin_env':
                unset($this->ssh->envs[$dos->id]);
                break;
            default:
                $this->ssh->fallan_al_leer[$dos->id] = 'SSH caído';
        }

        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'doblep distribuciones']);

        $respuesta->assertStatus(200);
        $this->assertSame('abortado', $respuesta->json('estado'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertStringContainsString('frente ' . $dos->id . ' con clave ' . $estado, (string) $respuesta->json('motivo'));
        $this->assertStringContainsString('No se escribió nada', (string) $respuesta->json('motivo'));

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('ninguna', $frentes[$uno->id]['accion']);
        $this->assertSame('ninguna', $frentes[$dos->id]['accion']);

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * 🔴 Un cliente sin `api_key` (vacía o solo espacios) aborta: no hay clave que exigir.
     *
     * @return void
     */
    public function test_un_cliente_sin_api_key_aborta(): void
    {
        foreach (['', '   '] as $vacia) {
            $this->ssh->envs = [];

            $cliente = $this->crear_cliente('Doblep Distribuciones', $vacia);
            $uno     = $this->crear_frente($cliente, 'sinclave' . strlen($vacia), 'shared_hosting', null, true);

            $this->ssh->envs[$uno->id] = "APP_ENV=production\nUSER_ID=" . $cliente->user_id . "\n";

            Http::fake();

            $respuesta = $this->exigir($cliente->refresh(), ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

            $respuesta->assertStatus(200);
            $this->assertSame('abortado', $respuesta->json('estado'));
            $this->assertStringContainsString('sin_api_key', (string) $respuesta->json('motivo'));
        }

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
    }

    /**
     * 🔴 Un `.env` con un `USER_ID` que no es el del cliente (`dueno_distinto`) aborta, sin escribir en ningún frente.
     *
     * @return void
     */
    public function test_un_env_de_otro_dueno_aborta(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $this->ssh->envs[$dos->id] = "APP_ENV=production\nUSER_ID=999999999\nADMIN_API_INBOUND_KEY=" . $this->clave . "\n";

        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('abortado', $respuesta->json('estado'));
        $this->assertStringContainsString('dueno_distinto', (string) $respuesta->json('motivo'));
        $this->assertStringContainsString('frente ' . $dos->id, (string) $respuesta->json('motivo'));

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * 🔴 Sin un frente activo contra el cual probar (ningún frente con una URL válida) aborta: no se prende lo que no se puede verificar.
     *
     * @return void
     */
    public function test_sin_frente_activo_aborta(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        ClientApi::whereIn('id', [$uno->id, $dos->id])->update(['url' => '']);
        Http::fake();

        $respuesta = $this->exigir($cliente->refresh(), ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('abortado', $respuesta->json('estado'));
        $this->assertStringContainsString('sin_frente_activo', (string) $respuesta->json('motivo'));

        $this->assertNoSeTocoNada();
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 4. prender: el camino feliz
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El camino feliz: escribe las dos variables en `true` en LOS DOS frentes, con el respaldo antes de escribir y el borrado del config
     * cacheado después; la prueba con clave entra (200) y sin clave da 401: `prendido`, `listo`.
     *
     * @return void
     */
    public function test_prender_escribe_las_dos_variables_en_los_dos_frentes_y_prueba(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();
        $registro              = $this->capturar_el_log();
        $cliente_http          = $this->el_cliente_contesta(200, 401);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => '  DOBLEP distribuciones ']);

        $respuesta->assertStatus(200);
        $this->assertSame('prender', $respuesta->json('accion'));
        $this->assertSame('prendido', $respuesta->json('estado'));
        $this->assertTrue($respuesta->json('listo'));
        $this->assertNull($respuesta->json('motivo'));

        /* Las dos variables en TODOS los frentes. */
        $this->assertSame(['true', 'true'], $this->exigencias_en($uno));
        $this->assertSame(['true', 'true'], $this->exigencias_en($dos));

        $frentes = $this->frentes_de($respuesta);

        foreach ([$uno, $dos] as $frente) {
            $this->assertSame('escrito', $frentes[$frente->id]['accion']);
            $this->assertSame('true', $frentes[$frente->id]['exigencia']);
            $this->assertSame('true', $frentes[$frente->id]['exigencia_setup']);
            $this->assertSame('igual', $frentes[$frente->id]['clave']);
            $this->assertNull($frentes[$frente->id]['error']);
        }

        /* El ORDEN en cada frente: respaldo, escritura, cache. */
        foreach ([$uno, $dos] as $frente) {
            $propias = array_values(array_filter($this->ssh->operaciones, function ($op) use ($frente) {
                return strpos($op, $frente->id . ' ') === 0;
            }));

            $this->assertSame([$frente->id . ' backup', $frente->id . ' escribir', $frente->id . ' limpiar'], $propias);
        }

        $this->assertSame([self::EXIGENCIA => 'true', self::EXIGENCIA_SETUP => 'true'], $this->ssh->escrituras[$uno->id]);

        /* La clave de inbound no se tocó. */
        $this->assertStringContainsString('ADMIN_API_INBOUND_KEY=' . $this->clave, $this->ssh->envs[$uno->id]);

        /* La prueba: SOLO contra el frente activo, con la clave y sin la clave. */
        $this->assertCount(2, $cliente_http->pedidos);
        $this->assertSame(self::URL_DE_LA_PRUEBA, $cliente_http->pedidos[0]['url']);
        $this->assertTrue($cliente_http->pedidos[0]['con_clave']);
        $this->assertSame(self::URL_DE_LA_PRUEBA, $cliente_http->pedidos[1]['url']);
        $this->assertFalse($cliente_http->pedidos[1]['con_clave']);

        $this->assertSame(200, $respuesta->json('prueba.con_clave.status'));
        $this->assertSame(401, $respuesta->json('prueba.sin_clave.status'));
        $this->assertSame('cerrada', $respuesta->json('prueba.sin_clave.veredicto'));

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /**
     * Un frente que ya tiene las dos variables en `true` no se respalda ni se vuelve a escribir (`ya_estaba`), pero la prueba corre igual.
     *
     * @return void
     */
    public function test_un_frente_que_ya_exige_no_se_vuelve_a_escribir(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=true\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n";
        $cliente_http = $this->el_cliente_contesta(200, 401);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('prendido', $respuesta->json('estado'));

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('ya_estaba', $frentes[$uno->id]['accion']);
        $this->assertSame('escrito', $frentes[$dos->id]['accion']);

        $this->assertArrayNotHasKey($uno->id, $this->ssh->backups);
        $this->assertArrayNotHasKey($uno->id, $this->ssh->escrituras);
        $this->assertCount(2, $cliente_http->pedidos);
    }

    /**
     * Una versión que no tiene `mensualidad-info` (404) se prueba con `employees` antes de darla por pasada: si `employees` entra, pasa.
     *
     * @return void
     */
    public function test_ante_un_404_se_prueba_con_employees(): void
    {
        [$cliente] = $this->cliente_con_dos_frentes();
        $http      = $this->el_cliente_contesta(404, 401, 200);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('prendido', $respuesta->json('estado'));
        $this->assertSame(self::URL_DE_RESPALDO, $http->pedidos[1]['url']);
        $this->assertTrue($http->pedidos[1]['con_clave']);
        $this->assertSame('api/admin-sync/employees', $respuesta->json('prueba.con_clave.ruta'));
    }

    /**
     * Si `employees` TAMBIÉN da 401, revierte: el 404 de `mensualidad-info` no era "versión vieja", era la clave que no entra.
     *
     * @return void
     */
    public function test_si_employees_da_401_ante_un_404_revierte(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();
        $this->el_cliente_contesta(404, 401, 401);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido', $respuesta->json('estado'));
        $this->assertSame([true, true], [$this->exigencias_en($uno)[0] === 'false', $this->exigencias_en($dos)[0] === 'false']);
    }

    /**
     * Si las DOS rutas dan 404 (una versión sin ninguna) la prueba con clave pasa, y sin clave el 404 no prueba el cierre: `sin_verificar`.
     *
     * @return void
     */
    public function test_dos_404_pasan_pero_no_se_puede_verificar_el_cierre(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes();
        $this->el_cliente_contesta(404, 404, 404);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('sin_verificar', $respuesta->json('estado'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertSame('sin_verificar', $respuesta->json('prueba.sin_clave.veredicto'));
        $this->assertSame(['true', 'true'], $this->exigencias_en($uno), 'No revierte: la clave entra.');
    }

    /* ------------------------------------------------------------------------------------------
     | 5. prender: la prueba y la vuelta atrás
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 La prueba CON la clave da 401: REVIERTE. Restaura los valores previos de las dos variables en LOS DOS frentes (lo que estaba, y
     * `false` explícito lo que no estaba), borra el config cacheado otra vez, y devuelve `revertido` con el motivo. Sin la prueba sin clave.
     *
     * @return void
     */
    public function test_la_prueba_con_clave_que_da_401_revierte_los_dos_frentes(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        /* El primero tenía una variable en false y la otra sin definir; el segundo, ninguna. */
        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=false\n";
        $registro                   = $this->capturar_el_log();
        $http                       = $this->el_cliente_contesta(401, 401);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $respuesta->assertStatus(200);
        $this->assertSame('revertido', $respuesta->json('estado'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertStringContainsString('CON la clave', (string) $respuesta->json('motivo'));
        $this->assertStringContainsString('HTTP 401', (string) $respuesta->json('motivo'));

        /* Restauradas: lo que había, y `false` explícito donde no había nada. */
        $this->assertSame(['false', 'false'], $this->exigencias_en($uno));
        $this->assertSame(['false', 'false'], $this->exigencias_en($dos));

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('revertido', $frentes[$uno->id]['accion']);
        $this->assertSame('revertido', $frentes[$dos->id]['accion']);
        $this->assertSame('false', $frentes[$uno->id]['exigencia']);

        /* Cada frente: backup, escribir, limpiar, escribir (la vuelta), limpiar. */
        $this->assertSame(2, $this->ssh->caches_limpiados[$uno->id]);
        $this->assertSame(2, $this->ssh->caches_limpiados[$dos->id]);

        /* Solo se hizo la prueba con clave: no se sigue con la de sin clave. */
        $this->assertCount(1, $http->pedidos);
        $this->assertTrue($http->pedidos[0]['con_clave']);

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /**
     * Una variable que ya tenía un valor propio (por ejemplo `false` y `true` distintos) vuelve EXACTAMENTE a ese valor.
     *
     * @return void
     */
    public function test_la_reversion_devuelve_el_valor_previo_de_cada_variable(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes();

        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=false\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n";
        $this->el_cliente_contesta(401, 401);

        $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones'])->assertStatus(200);

        $this->assertSame(['false', 'true'], $this->exigencias_en($uno));
    }

    /**
     * Un 500 del cliente, un 3xx o una falla de red en la prueba con clave también REVIERTEN (y la falla de red no filtra la clave ni la
     * query de la URL).
     *
     * @return array<string, array<int, int|null>>
     */
    public function las_pruebas_con_clave_que_revierten(): array
    {
        return [
            '500'           => [500],
            '302'           => [302],
            '403'           => [403],
            'falla de red'  => [null],
        ];
    }

    /**
     * @dataProvider las_pruebas_con_clave_que_revierten
     *
     * @param int|null $status Lo que contesta el cliente con la clave (null: falla de red).
     *
     * @return void
     */
    public function test_otras_fallas_de_la_prueba_con_clave_tambien_revierten(?int $status): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();
        $registro              = $this->capturar_el_log();
        $this->el_cliente_contesta($status, 401);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido', $respuesta->json('estado'));
        $this->assertSame(['false', 'false'], $this->exigencias_en($uno));
        $this->assertSame(['false', 'false'], $this->exigencias_en($dos));

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertLogSinLaClave($registro, $this->clave);
        $this->assertStringNotContainsString('PRIVADO', $this->cuerpo($respuesta), 'La query de la URL no sale.');
    }

    /**
     * 🔴 Con la clave entra pero SIN la clave también da 200: queda escrito, `sin_efecto`, `listo` false y NO revierte (config cacheado o
     * una versión del cliente que no valida).
     *
     * @return void
     */
    public function test_sin_clave_que_da_200_informa_sin_efecto_y_no_revierte(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();
        $this->el_cliente_contesta(200, 200);

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $respuesta->assertStatus(200);
        $this->assertSame('sin_efecto', $respuesta->json('estado'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertStringContainsString('NO tiene efecto', (string) $respuesta->json('motivo'));
        $this->assertSame('sin_efecto', $respuesta->json('prueba.sin_clave.veredicto'));
        $this->assertSame(200, $respuesta->json('prueba.sin_clave.status'));

        $this->assertSame(['true', 'true'], $this->exigencias_en($uno), 'No revierte.');
        $this->assertSame(['true', 'true'], $this->exigencias_en($dos));
        $this->assertSame('escrito', $this->frentes_de($respuesta)[$uno->id]['accion']);
    }

    /**
     * Sin la clave, un 422 (la ruta se ejecutó y se quejó de un parámetro) también es `sin_efecto`; un 500 o una falla de red es
     * `sin_verificar`. Ninguno revierte.
     *
     * @return void
     */
    public function test_sin_clave_con_422_es_sin_efecto_y_con_500_o_red_es_sin_verificar(): void
    {
        foreach ([[422, 'sin_efecto'], [500, 'sin_verificar'], [null, 'sin_verificar']] as $i => $caso) {
            $this->ssh = new EnvSshServiceFake();
            $this->app->instance(EnvSshService::class, $this->ssh);

            $cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);
            $uno     = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);

            $this->ssh->envs[$uno->id] = $this->env_de($cliente);
            $this->el_cliente_contesta(200, $caso[0]);

            $respuesta = $this->exigir($cliente->refresh(), ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

            $this->assertSame($caso[1], $respuesta->json('estado'), 'Caso ' . $i);
            $this->assertSame(['true', 'true'], $this->exigencias_en($uno), 'Caso ' . $i . ': no revierte.');
        }
    }

    /**
     * 🔴 Si la escritura falla en el SEGUNDO frente, se restaura el primero y se aborta: `revertido`, sin llegar a la prueba.
     *
     * @return void
     */
    public function test_una_escritura_que_falla_revierte_lo_ya_escrito(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        /* Una falla pasajera: la escritura de ida del segundo frente falla y la vuelta atrás ya anda. */
        $this->ssh->escrituras_que_fallan_y_despues_andan[$dos->id] = 1;
        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido', $respuesta->json('estado'));
        $this->assertFalse($respuesta->json('listo'));

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('revertido', $frentes[$uno->id]['accion']);
        $this->assertSame('revertido', $frentes[$dos->id]['accion'], 'El frente que falló al escribir también se restaura: el .env pudo haber cambiado.');

        $this->assertSame(['false', 'false'], $this->exigencias_en($uno));
        $this->assertSame(['false', 'false'], $this->exigencias_en($dos));
        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * 🔴 Si la escritura falla y la restauración de ese frente TAMBIÉN (el servidor sigue caído), el estado es `revertido_con_fallas`, el
     * frente lo dice con su error (sin la clave) y el primero sí se restauró.
     *
     * @return void
     */
    public function test_si_la_reversion_tambien_falla_el_estado_lo_dice(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $this->ssh->fallan_al_escribir[$dos->id] = 'La escritura no quedó aplicada (clave ' . $this->clave . ')';
        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido_con_fallas', $respuesta->json('estado'));
        $this->assertFalse($respuesta->json('listo'));
        $this->assertStringContainsString('revisalo a mano', (string) $respuesta->json('motivo'));

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('revertido', $frentes[$uno->id]['accion']);
        $this->assertSame('reversion_fallo', $frentes[$dos->id]['accion']);
        $this->assertStringContainsString('[clave oculta]', (string) $frentes[$dos->id]['error']);

        $this->assertSame(['false', 'false'], $this->exigencias_en($uno));
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * Si falla el RESPALDO del segundo frente, ese frente no se tocó (no se restaura nada ahí) y se restaura solo el primero.
     *
     * @return void
     */
    public function test_un_respaldo_que_falla_deja_ese_frente_sin_tocar(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $this->ssh->fallan_al_respaldar[$dos->id] = 'cp: sin espacio';
        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido', $respuesta->json('estado'));
        $this->assertSame('revertido', $this->frentes_de($respuesta)[$uno->id]['accion']);
        $this->assertSame('fallo', $this->frentes_de($respuesta)[$dos->id]['accion']);
        $this->assertArrayNotHasKey($dos->id, $this->ssh->escrituras, 'El frente con el respaldo caído no se escribe.');
        $this->assertSame([null, null], $this->exigencias_en($dos));
    }

    /**
     * Si el borrado del config cacheado falla DESPUÉS de escribir, el frente se restaura igual (el `.env` pudo haber cambiado).
     *
     * @return void
     */
    public function test_un_cache_que_no_se_borra_revierte_ese_frente_tambien(): void
    {
        $cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);
        $uno     = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);

        $this->ssh->envs[$uno->id] = $this->env_de($cliente);

        /* Falla siempre: también la vuelta atrás, que es el caso `revertido_con_fallas`. */
        $this->ssh->fallan_al_limpiar_el_cache[$uno->id] = 'rm: permiso denegado';
        Http::fake();

        $respuesta = $this->exigir($cliente->refresh(), ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido_con_fallas', $respuesta->json('estado'));
        $this->assertStringContainsString('revisalo a mano', (string) $respuesta->json('motivo'));
        $this->assertSame('reversion_fallo', $this->frentes_de($respuesta)[$uno->id]['accion']);
        $this->assertFalse($respuesta->json('listo'));
    }

    /* ------------------------------------------------------------------------------------------
     | 6. apagar
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 `apagar` deja las dos variables en `false` EXPLÍCITO en los dos frentes (no las borra), respalda antes de escribir, borra el cache y
     * no necesita la clave ni le pega al cliente.
     *
     * @return void
     */
    public function test_apagar_deja_las_dos_variables_en_false_explicito(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=true\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n";
        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'apagar', 'confirm_client_name' => 'Doblep Distribuciones']);

        $respuesta->assertStatus(200);
        $this->assertSame('apagar', $respuesta->json('accion'));
        $this->assertSame('apagado', $respuesta->json('estado'));
        $this->assertTrue($respuesta->json('listo'));

        $this->assertSame(['false', 'false'], $this->exigencias_en($uno));
        $this->assertSame(['false', 'false'], $this->exigencias_en($dos), 'Aunque no estuvieran, quedan en false explícito.');
        $this->assertStringContainsString('ADMIN_SYNC_REQUIRE_API_KEY=false', $this->ssh->envs[$dos->id]);

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('escrito', $frentes[$uno->id]['accion']);
        $this->assertSame('false', $frentes[$uno->id]['exigencia']);

        $this->assertSame([$uno->id => 1, $dos->id => 1], $this->ssh->caches_limpiados);
        $this->assertSame(2, count($this->ssh->backups));
        Http::assertNothingSent();
        $this->assertSinLaClave($respuesta, $this->clave);
    }

    /**
     * `apagar` también tiene que poder hacerse sin `api_key` en el admin (justo cuando la clave está mal).
     *
     * @return void
     */
    public function test_apagar_funciona_aunque_la_clave_este_mal(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes([null, null]);

        $this->ssh->envs[$dos->id] = $this->env_de($cliente, 'una-clave-distinta-' . Str::random(8), "ADMIN_SYNC_REQUIRE_API_KEY=true\n");
        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'apagar', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('apagado', $respuesta->json('estado'));
        $this->assertSame('false', $this->exigencias_en($dos)[0]);
    }

    /**
     * Un frente que no se puede leer (o de otro dueño) aborta `apagar` sin escribir en ninguno; una escritura que falla restaura lo escrito.
     *
     * @return void
     */
    public function test_apagar_aborta_si_no_puede_leer_un_frente_y_revierte_si_una_escritura_falla(): void
    {
        [$cliente, $uno, $dos] = $this->cliente_con_dos_frentes();

        $this->ssh->fallan_al_leer[$dos->id] = 'SSH caído';
        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'apagar', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('abortado', $respuesta->json('estado'));
        $this->assertStringContainsString('frente ' . $dos->id, (string) $respuesta->json('motivo'));
        $this->assertNoSeTocoNada();

        /* Ahora todos se leen, pero el segundo falla al escribir: se restaura el primero a lo que tenía. */
        unset($this->ssh->fallan_al_leer[$dos->id]);

        $this->ssh->envs[$uno->id] .= "ADMIN_SYNC_REQUIRE_API_KEY=true\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n";
        $this->ssh->escrituras_que_fallan_y_despues_andan[$dos->id] = 1;

        $segundo = $this->exigir($cliente, ['accion' => 'apagar', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('revertido', $segundo->json('estado'));
        $this->assertSame(['true', 'true'], $this->exigencias_en($uno), 'Vuelve a lo que tenía.');
    }

    /* ------------------------------------------------------------------------------------------
     | 7. El log y el catálogo
     |----------------------------------------------------------------------------------------- */

    /**
     * El log de una operación que escribe lleva ids, estado y acciones — nunca una clave — y `ver` no deja ninguna línea.
     *
     * @return void
     */
    public function test_el_log_lleva_ids_y_estados_y_nunca_la_clave(): void
    {
        [$cliente, $uno] = $this->cliente_con_dos_frentes();
        $registro        = $this->capturar_el_log();

        $this->exigir($cliente);

        $this->assertSame([], array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientAdminSyncClaveService') === 0;
        })), '`ver` no deja rastro en el log.');

        $this->el_cliente_contesta(200, 401);

        $this->exigir($cliente, ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones'])->assertStatus(200);

        $propias = array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientAdminSyncClaveService') === 0;
        }));

        $this->assertCount(1, $propias);
        $this->assertSame('prender', $propias[0]['contexto']['accion']);
        $this->assertSame('prendido', $propias[0]['contexto']['estado']);
        $this->assertSame((int) $cliente->id, $propias[0]['contexto']['client_id']);
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /**
     * Los parámetros que `config/claude_catalog.php` declara para A4 son EXACTAMENTE la lista cerrada del controlador.
     *
     * @return void
     */
    public function test_el_catalogo_declara_los_mismos_parametros(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/clients/{id}/admin-sync/exigir-clave');

        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada de A4.');
        $this->assertTrue($endpoint['escribe']);
        $this->assertSame('alta', $endpoint['peligrosidad']);

        $declarados = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') === false) {
                $declarados[] = $parametro['nombre'];
            }
        }

        $aceptados = ClaudeClientAdminSyncController::PARAMETROS_DE_EXIGIR_CLAVE;

        sort($declarados);
        sort($aceptados);

        $this->assertSame($aceptados, $declarados);

        foreach ([ClientAdminSyncClaveService::ESTADO_PRENDIDO, ClientAdminSyncClaveService::ESTADO_REVERTIDO, ClientAdminSyncClaveService::ESTADO_SIN_EFECTO, ClientAdminSyncClaveService::ESTADO_ABORTADO] as $estado) {
            $this->assertStringContainsString($estado, $endpoint['para_que']);
        }
    }
}
