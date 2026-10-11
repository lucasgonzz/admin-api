<?php

namespace Tests\Feature\CerrarAdminSync;

use App\Models\Client;
use App\Models\ClientApi;
use App\Services\ClientAdminSyncClaveService;
use App\Services\EnvSshService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;
use Tests\Fakes\EnvSshServiceFake;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * La clave de SALIDA (`ADMIN_API_OUTBOUND_KEY`) de `POST claude/clients/{id}/admin-sync/exigir-clave` (misión `cerrar-admin-sync`, 11/10/2026).
 *
 * 🔴 EL HALLAZGO. `AdminReportHelper` y `SupportSyncHelper` de `empresa-api` hacen `(bool) config('services.admin_api.require_api_key')` y, si es
 * true y `services.admin_api.inbound_key` (= `ADMIN_API_OUTBOUND_KEY`) está vacía, devuelven false SIN mandar nada al admin (mensajes de soporte y
 * acuses de lectura de novedades). Prender la exigencia en un `.env` que no tiene esa variable corta esas salidas. El admin hoy no valida esa clave,
 * así que escribirla es inocua; su valor correcto es `clients.inbound_api_key`.
 *
 * Lo que se fija:
 *  1. `prender` la escribe en CADA frente que no la tenga, en la misma tanda respaldo → escritura → cache que las banderas.
 *  2. Si el `.env` ya la tiene con CUALQUIER valor, NO se toca.
 *  3. Si el cliente no tiene `inbound_api_key`, se genera y se GUARDA antes de escribir en el primer servidor, y un `prender` abortado no genera nada.
 *  4. La vuelta atrás NO la restaura; `apagar` tampoco la toca.
 *  5. `ver` informa `salida` por frente (`cargada` | `falta` | `sin_env` | `error`) y su `listo` NO cambia por ella.
 *  6. Ningún valor de clave sale en una respuesta ni en el log.
 *  7. `ADMIN_SYNC_REQUIRE_API_KEY` se clasifica en `true` / `false` solo con el texto exacto; `off`, `no`, `1`, `0`, `banana` son `invalido`.
 *
 * Los `.env` de estos tests NO traen la clave de salida salvo que el caso lo pida (los tests de `ExigirLaClaveDeAdminSyncPorClaudeTest` sí la traen).
 */
class LaClaveDeSalidaAlPrenderAdminSyncTest extends BaseDelCatalogoPorClaude
{
    const SALIDA = 'ADMIN_API_OUTBOUND_KEY';

    /** @var EnvSshServiceFake */
    private $ssh;

    /** @var string La clave (api_key) del cliente. */
    private $clave;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh   = new EnvSshServiceFake();
        $this->clave = 'sk-salida-' . Str::random(30);

        $this->app->instance(EnvSshService::class, $this->ssh);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

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
        return $this->postJson('/api/claude/clients/' . $cliente->id . '/admin-sync/exigir-clave', $pedido, $this->headers());
    }

    /**
     * Pedido de `prender` con la confirmación del nombre.
     *
     * @return array<string, string>
     */
    private function prender(): array
    {
        return ['accion' => 'prender', 'confirm_client_name' => 'Doblep Distribuciones'];
    }

    /**
     * Un cliente con dos frentes de shared y su `.env` SIN la clave de salida.
     *
     * @param string|null $inbound `clients.inbound_api_key` (null = una de 20; '' = vacía).
     * @param string      $extra_uno   Líneas de más en el `.env` del frente activo.
     * @param string      $extra_dos   Líneas de más en el `.env` del segundo.
     *
     * @return array{0: Client, 1: ClientApi, 2: ClientApi}
     */
    private function cliente(?string $inbound = null, string $extra_uno = '', string $extra_dos = ''): array
    {
        $cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave, ['inbound_api_key' => $inbound === null ? 'inbound-' . Str::random(20) : $inbound]);
        $uno     = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);
        $dos     = $this->crear_frente($cliente, 'doblep2');

        $base = "APP_ENV=production\nUSER_ID=" . $cliente->user_id . "\nADMIN_API_INBOUND_KEY=" . $this->clave . "\n";

        $this->ssh->envs[$uno->id] = $base . $extra_uno;
        $this->ssh->envs[$dos->id] = $base . $extra_dos;

        return [$cliente->refresh(), $uno, $dos];
    }

    /**
     * Falsea al cliente: con la clave contesta 200, sin ella 401.
     *
     * @param int $con_clave Status con la clave.
     *
     * @return void
     */
    private function el_cliente_contesta(int $con_clave = 200): void
    {
        $clave = $this->clave;

        Http::swap(new HttpFactorySinSalida());

        Http::fake(function ($pedido) use ($clave, $con_clave) {
            return $pedido->hasHeader('X-Admin-Api-Key', $clave)
                ? Http::response(['ok' => true], $con_clave)
                : Http::response(['message' => 'Unauthorized'], 401);
        });
    }

    /**
     * El valor de una variable en el `.env` en memoria de un frente (null si no está).
     *
     * @param ClientApi $frente   Frente.
     * @param string    $variable Variable.
     *
     * @return string|null
     */
    private function valor_en(ClientApi $frente, string $variable)
    {
        $env = $this->ssh->parse_env_content($this->ssh->envs[$frente->id]);

        return array_key_exists($variable, $env) ? $env[$variable] : null;
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

    /* ------------------------------------------------------------------------------------------
     | prender
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 `prender` escribe `ADMIN_API_OUTBOUND_KEY = clients.inbound_api_key` en CADA frente que no la tiene, JUNTO con las dos banderas (una sola
     * escritura, respaldo antes y borrado del cache después), y la clave no sale en la respuesta ni en el log.
     *
     * @return void
     */
    public function test_prender_escribe_la_clave_de_salida_donde_falta(): void
    {
        [$cliente, $uno, $dos] = $this->cliente('inbound-del-cliente-0123456789');
        $registro              = $this->capturar_el_log();

        $this->el_cliente_contesta();

        $respuesta = $this->exigir($cliente, $this->prender());

        $respuesta->assertStatus(200);
        $this->assertSame('prendido', $respuesta->json('estado'));
        $this->assertTrue($respuesta->json('listo'));

        foreach ([$uno, $dos] as $frente) {
            $this->assertSame('inbound-del-cliente-0123456789', $this->valor_en($frente, self::SALIDA));
            $this->assertSame('true', $this->valor_en($frente, 'ADMIN_SYNC_REQUIRE_API_KEY'));

            /* Una sola escritura con las tres, y el orden de siempre. */
            $this->assertSame(
                ['ADMIN_SYNC_REQUIRE_API_KEY' => 'true', 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY' => 'true', self::SALIDA => 'inbound-del-cliente-0123456789'],
                $this->ssh->escrituras[$frente->id]
            );
            $this->assertContains($frente->id . ' backup', $this->ssh->operaciones);
            $this->assertSame(1, $this->ssh->caches_limpiados[$frente->id]);
        }

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('cargada', $frentes[$uno->id]['salida']);
        $this->assertSame('cargada', $frentes[$dos->id]['salida']);

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSinLaClave($respuesta, 'inbound-del-cliente-0123456789');
        $this->assertLogSinLaClave($registro, 'inbound-del-cliente-0123456789');
        $this->assertLogSinLaClave($registro, $this->clave);
    }

    /**
     * 🔴 Si el `.env` del frente YA tiene la variable con cualquier valor, NO se toca (puede haber una integración con otra clave): se informa
     * `cargada` y el valor no sale. Un frente sin ella sí la recibe.
     *
     * @return void
     */
    public function test_prender_no_pisa_una_clave_de_salida_que_ya_esta(): void
    {
        [$cliente, $uno, $dos] = $this->cliente('inbound-del-cliente-0123456789', "ADMIN_API_OUTBOUND_KEY=la-clave-de-otra-integracion\n");

        $this->el_cliente_contesta();

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('prendido', $respuesta->json('estado'));

        $this->assertSame('la-clave-de-otra-integracion', $this->valor_en($uno, self::SALIDA), 'No se pisa.');
        $this->assertArrayNotHasKey(self::SALIDA, $this->ssh->escrituras[$uno->id]);
        $this->assertSame('inbound-del-cliente-0123456789', $this->valor_en($dos, self::SALIDA), 'El que no la tenía la recibe.');

        $this->assertSame('cargada', $this->frentes_de($respuesta)[$uno->id]['salida']);
        $this->assertSinLaClave($respuesta, 'la-clave-de-otra-integracion');
    }

    /**
     * Un frente con las dos banderas ya en `true` pero SIN la clave de salida no es `ya_estaba`: se le escribe la clave (y solo eso cambia de valor).
     *
     * @return void
     */
    public function test_un_frente_que_ya_exige_pero_no_tiene_la_clave_de_salida_la_recibe(): void
    {
        [$cliente, $uno] = $this->cliente('inbound-del-cliente-0123456789', "ADMIN_SYNC_REQUIRE_API_KEY=true\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n");

        $this->el_cliente_contesta();

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('escrito', $this->frentes_de($respuesta)[$uno->id]['accion']);
        $this->assertSame('inbound-del-cliente-0123456789', $this->valor_en($uno, self::SALIDA));
    }

    /**
     * 🔴 Si el cliente no tiene `inbound_api_key` (vacía o solo espacios), `prender` la genera (40 caracteres), la GUARDA y escribe esa misma en
     * los dos frentes. Se guarda ANTES de escribir en el primer servidor.
     *
     * @return void
     */
    public function test_prender_genera_y_guarda_la_inbound_api_key_si_esta_vacia(): void
    {
        foreach (['', '   '] as $vacia) {
            $this->ssh = new EnvSshServiceFake();
            $this->app->instance(EnvSshService::class, $this->ssh);

            [$cliente, $uno, $dos] = $this->cliente($vacia);

            $this->el_cliente_contesta();

            $respuesta = $this->exigir($cliente, $this->prender());

            $this->assertSame('prendido', $respuesta->json('estado'), 'inbound_api_key="' . $vacia . '"');

            $guardada = (string) Client::query()->where('id', $cliente->id)->value('inbound_api_key');

            $this->assertSame(40, strlen($guardada));
            $this->assertSame($guardada, $this->valor_en($uno, self::SALIDA));
            $this->assertSame($guardada, $this->valor_en($dos, self::SALIDA));
            $this->assertSinLaClave($respuesta, $guardada);
        }
    }

    /**
     * Si TODOS los frentes ya tienen la clave de salida, no se genera nada aunque el cliente no tenga `inbound_api_key`.
     *
     * @return void
     */
    public function test_no_genera_nada_si_ningun_frente_la_necesita(): void
    {
        [$cliente] = $this->cliente('', "ADMIN_API_OUTBOUND_KEY=una\n", "ADMIN_API_OUTBOUND_KEY=otra\n");

        $this->el_cliente_contesta();

        $this->assertSame('prendido', $this->exigir($cliente, $this->prender())->json('estado'));
        $this->assertSame('', trim((string) Client::query()->where('id', $cliente->id)->value('inbound_api_key')));
    }

    /**
     * 🔴 Un `prender` ABORTADO por una precondición no genera ni guarda nada.
     *
     * @return void
     */
    public function test_un_prender_abortado_no_genera_la_clave_de_salida(): void
    {
        [$cliente, $uno, $dos] = $this->cliente('');

        $this->ssh->envs[$dos->id] = "APP_ENV=production\nUSER_ID=" . $cliente->user_id . "\n";
        Http::fake();

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('abortado', $respuesta->json('estado'));
        $this->assertSame('', trim((string) Client::query()->where('id', $cliente->id)->value('inbound_api_key')));
        $this->assertSame([], $this->ssh->operaciones);
    }

    /**
     * Si la base no guarda la clave de salida generada, `prender` aborta SIN escribir en ningún servidor y sin filtrar lo que dijo la base.
     *
     * @return void
     */
    public function test_si_la_base_no_guarda_la_clave_de_salida_aborta_sin_escribir(): void
    {
        [$cliente] = $this->cliente('');

        $this->app->bind(ClientAdminSyncClaveService::class, function ($app) {
            return new class($app->make(EnvSshService::class)) extends ClientAdminSyncClaveService {
                protected function guardar_la_clave_de_salida_si_sigue_vacia(Client $client, $nueva)
                {
                    throw new \RuntimeException('SQLSTATE[HY000] UPDATE clients SET inbound_api_key = ' . $nueva);
                }
            };
        });

        Http::fake();

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('abortado', $respuesta->json('estado'));
        $this->assertStringContainsString('clave_de_salida', (string) $respuesta->json('motivo'));
        $this->assertStringNotContainsString('SQLSTATE', $this->cuerpo($respuesta));
        $this->assertSame([], $this->ssh->operaciones);
        Http::assertNothingSent();
    }

    /**
     * 🔴 La vuelta atrás NO restaura la clave de salida: las banderas vuelven a lo que había, la clave queda escrita, y el `motivo` lo dice.
     *
     * @return void
     */
    public function test_la_reversion_deja_la_clave_de_salida_y_lo_dice(): void
    {
        [$cliente, $uno, $dos] = $this->cliente('inbound-del-cliente-0123456789');

        $this->el_cliente_contesta(401);

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('revertido', $respuesta->json('estado'));

        foreach ([$uno, $dos] as $frente) {
            $this->assertSame('false', $this->valor_en($frente, 'ADMIN_SYNC_REQUIRE_API_KEY'), 'Las banderas vuelven.');
            $this->assertSame('inbound-del-cliente-0123456789', $this->valor_en($frente, self::SALIDA), 'La salida se queda.');
        }

        $this->assertStringContainsString('ADMIN_API_OUTBOUND_KEY', (string) $respuesta->json('motivo'));
        $this->assertStringContainsString('NO se revierte', (string) $respuesta->json('motivo'));
        $this->assertSame('cargada', $this->frentes_de($respuesta)[$uno->id]['salida']);
        $this->assertSinLaClave($respuesta, 'inbound-del-cliente-0123456789');
    }

    /**
     * Sin haber escrito la clave de salida (ya estaba), el `motivo` de una reversión no habla de ella.
     *
     * @return void
     */
    public function test_el_motivo_de_la_reversion_no_la_nombra_si_no_la_escribio(): void
    {
        [$cliente] = $this->cliente('inbound-del-cliente-0123456789', "ADMIN_API_OUTBOUND_KEY=una\n", "ADMIN_API_OUTBOUND_KEY=otra\n");

        $this->el_cliente_contesta(401);

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('revertido', $respuesta->json('estado'));
        $this->assertStringNotContainsString('ADMIN_API_OUTBOUND_KEY', (string) $respuesta->json('motivo'));
    }

    /**
     * `apagar` no toca la clave de salida: no la escribe donde falta ni la saca donde está.
     *
     * @return void
     */
    public function test_apagar_no_toca_la_clave_de_salida(): void
    {
        [$cliente, $uno, $dos] = $this->cliente('inbound-del-cliente-0123456789', "ADMIN_API_OUTBOUND_KEY=la-que-habia\n");

        Http::fake();

        $respuesta = $this->exigir($cliente, ['accion' => 'apagar', 'confirm_client_name' => 'Doblep Distribuciones']);

        $this->assertSame('apagado', $respuesta->json('estado'));
        $this->assertSame('la-que-habia', $this->valor_en($uno, self::SALIDA));
        $this->assertNull($this->valor_en($dos, self::SALIDA), 'No la agrega.');
        $this->assertArrayNotHasKey(self::SALIDA, $this->ssh->escrituras[$uno->id]);
        $this->assertArrayNotHasKey(self::SALIDA, $this->ssh->escrituras[$dos->id]);
    }

    /* ------------------------------------------------------------------------------------------
     | ver
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 `ver` informa `salida` por frente: `cargada`, `falta`, `sin_env` y `error`; no escribe nada, no muestra ningún valor, y su `listo` sigue
     * siendo "todos los frentes con la clave igual" (la salida no suma una precondición).
     *
     * @return void
     */
    public function test_ver_informa_la_salida_de_cada_frente_y_no_cambia_el_listo(): void
    {
        [$cliente, $uno, $dos] = $this->cliente('inbound-del-cliente-0123456789', "ADMIN_API_OUTBOUND_KEY=valor-secreto-de-la-salida\n");

        $respuesta = $this->exigir($cliente);

        $this->assertSame('visto', $respuesta->json('estado'));
        $this->assertTrue($respuesta->json('listo'), 'Los dos frentes tienen la clave igual aunque uno no tenga la salida.');

        $frentes = $this->frentes_de($respuesta);
        $this->assertSame('cargada', $frentes[$uno->id]['salida']);
        $this->assertSame('falta', $frentes[$dos->id]['salida']);

        $this->assertSame([], $this->ssh->operaciones);
        $this->assertSinLaClave($respuesta, 'valor-secreto-de-la-salida');
        $this->assertSinLaClave($respuesta, 'inbound-del-cliente-0123456789');

        /* Vacía cuenta como faltante. */
        $this->ssh->envs[$dos->id] .= "ADMIN_API_OUTBOUND_KEY=\n";

        $this->assertSame('falta', $this->frentes_de($this->exigir($cliente))[$dos->id]['salida']);
    }

    /**
     * `salida` es `sin_env` cuando el frente no tiene `.env` y `error` cuando no se pudo leer.
     *
     * @return void
     */
    public function test_ver_informa_sin_env_y_error_en_la_salida(): void
    {
        $cliente = $this->crear_cliente('Doblep Distribuciones', $this->clave);
        $uno     = $this->crear_frente($cliente, 'doblep', 'shared_hosting', null, true);
        $sinenv  = $this->crear_frente($cliente, 'doblep2');
        $roto    = $this->crear_frente($cliente, 'doblep3');

        $this->ssh->envs[$uno->id]            = "USER_ID=" . $cliente->user_id . "\n";
        $this->ssh->envs[$roto->id]           = "USER_ID=" . $cliente->user_id . "\n";
        $this->ssh->fallan_al_leer[$roto->id] = 'SSH caído';

        $frentes = $this->frentes_de($this->exigir($cliente->refresh()));

        $this->assertSame('falta', $frentes[$uno->id]['salida']);
        $this->assertSame('sin_env', $frentes[$sinenv->id]['salida']);
        $this->assertSame('error', $frentes[$roto->id]['salida']);
    }

    /* ------------------------------------------------------------------------------------------
     | La clasificación de ADMIN_SYNC_REQUIRE_API_KEY
     |----------------------------------------------------------------------------------------- */

    /**
     * Valores de `ADMIN_SYNC_REQUIRE_API_KEY` y cómo se clasifican: solo el texto exacto `true`/`false` (con o sin paréntesis, sin importar
     * mayúsculas) es `true`/`false`; vacío es `sin_definir`; cualquier otra cosa es `invalido`.
     *
     * @return array<string, array<int, string>>
     */
    public function los_valores_de_la_exigencia(): array
    {
        return [
            'true'       => ['true', 'true'],
            'TRUE'       => ['TRUE', 'true'],
            '(true)'     => ['(true)', 'true'],
            '(TRUE)'     => ['(TRUE)', 'true'],
            'false'      => ['false', 'false'],
            'False'      => ['False', 'false'],
            '(false)'    => ['(false)', 'false'],
            'vacía'      => ['', 'sin_definir'],
            'off'        => ['off', 'invalido'],
            'no'         => ['no', 'invalido'],
            'yes'        => ['yes', 'invalido'],
            'on'         => ['on', 'invalido'],
            '1'          => ['1', 'invalido'],
            '0'          => ['0', 'invalido'],
            'banana'     => ['banana', 'invalido'],
            'null'       => ['null', 'invalido'],
        ];
    }

    /**
     * @dataProvider los_valores_de_la_exigencia
     *
     * @param string $valor    Lo que hay en el `.env`.
     * @param string $esperado Cómo lo informa `ver`.
     *
     * @return void
     */
    public function test_ver_clasifica_la_exigencia_general_solo_con_true_o_false_exactos(string $valor, string $esperado): void
    {
        [$cliente, $uno] = $this->cliente('inbound-del-cliente-0123456789', 'ADMIN_SYNC_REQUIRE_API_KEY=' . $valor . "\n");

        $this->assertSame($esperado, $this->frentes_de($this->exigir($cliente))[$uno->id]['exigencia']);
    }

    /**
     * Un valor `invalido` no cuenta como "ya exige": `prender` lo reescribe en `true`.
     *
     * @return void
     */
    public function test_prender_reescribe_una_exigencia_invalida(): void
    {
        [$cliente, $uno] = $this->cliente('inbound-del-cliente-0123456789', "ADMIN_SYNC_REQUIRE_API_KEY=off\nADMIN_SYNC_SETUP_REQUIRE_API_KEY=true\n");

        $this->el_cliente_contesta();

        $respuesta = $this->exigir($cliente, $this->prender());

        $this->assertSame('escrito', $this->frentes_de($respuesta)[$uno->id]['accion']);
        $this->assertSame('true', $this->valor_en($uno, 'ADMIN_SYNC_REQUIRE_API_KEY'));
    }

    /**
     * La bandera del user setup se lee en `empresa-api` con `filter_var` (acepta `1`, `on` y `yes` como verdaderos): se informan igual, y lo que no
     * se reconoce se muestra como `invalido`.
     *
     * @return void
     */
    public function test_ver_clasifica_la_exigencia_del_setup_como_filter_var(): void
    {
        foreach ([['1', 'true'], ['on', 'true'], ['yes', 'true'], ['true', 'true'], ['0', 'false'], ['off', 'false'], ['false', 'false'], ['quizas', 'invalido'], ['', 'sin_definir']] as $caso) {
            [$cliente, $uno] = $this->cliente('inbound-del-cliente-0123456789', 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY=' . $caso[0] . "\n");

            $this->assertSame($caso[1], $this->frentes_de($this->exigir($cliente))[$uno->id]['exigencia_setup'], 'valor "' . $caso[0] . '"');
        }
    }
}
