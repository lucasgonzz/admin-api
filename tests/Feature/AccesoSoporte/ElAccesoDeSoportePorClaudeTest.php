<?php

namespace Tests\Feature\AccesoSoporte;

use App\Http\Controllers\Api\ClaudeClientAccesoSoporteController;
use App\Models\ClientApi;
use App\Services\ClientAccesoSoporteService;
use App\Services\ClientEmpresaApiUrlResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `POST claude/clients/{id}/acceso-soporte` (misión `acceso-soporte-firmado`, 30/10/2026): el contrato C3 en el camino feliz y en sus frenos.
 * En orden de importancia:
 *
 *  1. 🔴 **`dry_run` no le pega al cliente y es el default.** Sin parámetros, con `true`, `1` o `"true"`: 200 con el frente y `listo`, y
 *     `Http::assertNothingSent()`. Un valor que no se entiende es 422, nunca "emitir".
 *  2. 🔴 **Emitir exige el nombre del cliente** (`confirm_client_name`), y el error no revela el nombre correcto. Solo con `dry_run = false`.
 *  3. 🔴 **El pedido al cliente es exacto**: POST a la URL de admin-sync del frente, `X-Admin-Api-Key` con la clave del cliente, JSON
 *     `{solicitante, motivo?}`, sin redirecciones y con 15 s.
 *  4. 🔴 **La URL de entrada sale del MISMO frente** al que se le habló, con el token en el FRAGMENTO.
 *  5. Lista cerrada de parámetros y saneo de `solicitante` / `motivo`.
 *
 * Los estados de error del contrato están en `LosErroresDelAccesoDeSoporteTest`; lo que no se filtra, en `ElAccesoDeSoporteNoFiltraSecretosTest`.
 */
class ElAccesoDeSoportePorClaudeTest extends BaseDelAccesoDeSoporte
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        // Los tests que fijan la hora la sueltan acá, pase lo que pase.
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y el cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * Fail-closed: sin el header de ingesta no contesta, y no le pega a nadie.
     *
     * @return void
     */
    public function test_sin_la_clave_de_ingesta_devuelve_401_y_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $this->postJson($this->url_del_endpoint(), ['dry_run' => false, 'confirm_client_name' => self::NOMBRE])->assertStatus(401);

        Http::assertNothingSent();
    }

    /**
     * Un cliente que no existe es 404 con el CÓDIGO `cliente_inexistente` en `error`.
     *
     * @return void
     */
    public function test_cliente_inexistente_es_404_con_el_codigo(): void
    {
        Http::fake();

        $respuesta = $this->postJson($this->url_del_endpoint(987654321), [], $this->headers());

        $respuesta->assertStatus(404);
        $this->assertSame('cliente_inexistente', $respuesta->json('error'));

        Http::assertNothingSent();
    }

    /**
     * El cliente también se encuentra por uuid.
     *
     * @return void
     */
    public function test_el_cliente_se_encuentra_por_uuid(): void
    {
        Http::fake();

        $this->assertNotEmpty((string) $this->cliente->uuid, 'El cliente de prueba tiene que tener uuid.');

        $respuesta = $this->postJson($this->url_del_endpoint((string) $this->cliente->uuid), [], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 2. dry_run: el default, y no le pega a nadie
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin parámetros es un dry_run: 200 con el frente y `listo`, NO emite ningún token y NO le pega al cliente.
     *
     * @return void
     */
    public function test_sin_parametros_es_un_dry_run_que_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $respuesta = $this->pedir([]);

        $respuesta->assertStatus(200);
        $this->assertSame(['client_id', 'dry_run', 'listo', 'motivo', 'frente'], array_keys($respuesta->json()), 'La forma del contrato del dry_run.');
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertTrue($respuesta->json('listo'));
        $this->assertNull($respuesta->json('motivo'));

        $frente = $this->cliente->active_client_api;

        $this->assertSame([
            'client_api_id' => (int) $frente->id,
            'hosting_type'  => 'shared_hosting',
            'api_host'      => 'api-doblep.ejemplo.test',
            'spa_url'       => 'https://doblep.ejemplo.test',
        ], $respuesta->json('frente'), 'El frente: el host de la API SIN ruta ni /public, y el SPA.');

        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertStringNotContainsString('acceso-soporte#', $this->cuerpo($respuesta), 'Un dry_run no arma ninguna URL de entrada.');

        Http::assertNothingSent();
    }

    /**
     * Los cuatro modos de decir "true" son un dry_run.
     *
     * @return array<string, array<int, mixed>>
     */
    public function los_valores_de_dry_run_true(): array
    {
        return [
            'true'       => [true],
            '"true"'     => ['true'],
            '1'          => [1],
            '"1"'        => ['1'],
            '"TRUE" con espacios' => ['  TRUE '],
        ];
    }

    /**
     * @dataProvider los_valores_de_dry_run_true
     *
     * @param mixed $valor El valor de `dry_run`.
     *
     * @return void
     */
    public function test_dry_run_true_en_todas_sus_formas_no_le_pega_al_cliente($valor): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => $valor]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertTrue($respuesta->json('listo'));

        Http::assertNothingSent();
    }

    /**
     * Un dry_run no pide el nombre: no emite nada, así que no hay nada que frenar. Un nombre equivocado tampoco lo rompe.
     *
     * @return void
     */
    public function test_un_dry_run_no_exige_el_nombre_ni_se_traba_con_uno_equivocado(): void
    {
        Http::fake();

        $this->pedir(['dry_run' => true, 'confirm_client_name' => 'Otro Cliente Cualquiera'])->assertStatus(200);

        Http::assertNothingSent();
    }

    /**
     * 🔴 Un `dry_run` que no se entiende es 422 y NO un "emitir": con `$request->boolean()` un "maybe" valdría false.
     *
     * @return array<string, array<int, mixed>>
     */
    public function los_valores_invalidos_de_dry_run(): array
    {
        return [
            'maybe'       => ['maybe'],
            'si'          => ['si'],
            'yes'         => ['yes'],
            '2'           => [2],
            '-1'          => [-1],
            'falsee'      => ['falsee'],
            'una lista'   => [['x']],
        ];
    }

    /**
     * @dataProvider los_valores_invalidos_de_dry_run
     *
     * @param mixed $valor El valor de `dry_run`.
     *
     * @return void
     */
    public function test_un_dry_run_invalido_es_422_y_no_le_pega_al_cliente($valor): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => $valor, 'confirm_client_name' => self::NOMBRE]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('dry_run', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El freno del nombre (solo con dry_run = false)
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Emitir de verdad sin `confirm_client_name` es 422 y NO le pega al cliente.
     *
     * @return void
     */
    public function test_emitir_sin_confirm_client_name_es_422_y_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => false]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('confirm_client_name', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 Un nombre equivocado es 422, no le pega al cliente, y la respuesta NO revela el nombre correcto (es un freno, no un formulario).
     *
     * @return void
     */
    public function test_un_nombre_equivocado_es_422_y_no_revela_el_nombre_correcto(): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => false, 'confirm_client_name' => 'Otro Cliente']);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('confirm_client_name', $respuesta->json('detalle'));

        $cuerpo = $this->cuerpo($respuesta);

        $this->assertStringNotContainsString('Doblep', $cuerpo, 'La respuesta revela el nombre del cliente.');
        $this->assertStringNotContainsString('Distribuciones', $cuerpo, 'La respuesta revela el nombre del cliente.');
        $this->assertStringNotContainsString(mb_strtolower(self::NOMBRE), mb_strtolower($cuerpo));

        Http::assertNothingSent();
    }

    /**
     * Un `confirm_client_name` que no es un texto (una lista) es 422, nunca un 500 ni una confirmación.
     *
     * @return void
     */
    public function test_un_confirm_client_name_que_no_es_texto_es_422(): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => false, 'confirm_client_name' => ['Doblep Distribuciones']]);

        $respuesta->assertStatus(422);
        $this->assertArrayHasKey('confirm_client_name', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * La comparación del nombre ignora mayúsculas y espacios de los bordes (la regla del freno de todo el bloque).
     *
     * @return void
     */
    public function test_el_nombre_se_compara_sin_mayusculas_ni_espacios_de_los_bordes(): void
    {
        $this->el_cliente_emite();

        $this->pedir(['dry_run' => false, 'confirm_client_name' => '  doblep DISTRIBUCIONES '])->assertStatus(200);

        Http::assertSentCount(1);
    }

    /**
     * Un cliente sin nombre cargado no se puede confirmar: el freno sigue cerrado y lo dice, sin pedir nada.
     *
     * @return void
     */
    public function test_un_cliente_sin_nombre_no_se_puede_confirmar(): void
    {
        $sin_nombre = $this->crear_cliente('Cliente sin nombre', 'sk-sin-nombre-' . Str::random(30), ['name' => '']);
        $this->crear_frente($sin_nombre, 'sinnombre', 'shared_hosting', null, true);

        Http::fake();

        $respuesta = $this->pedir(['dry_run' => false, 'confirm_client_name' => ''], $sin_nombre);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Lista cerrada de parámetros
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Una clave de más es 422 y no se hace nada: ignorarla en silencio dejaría creer que se aplicó.
     *
     * @return void
     */
    public function test_un_parametro_de_mas_es_422_y_no_le_pega_al_cliente(): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => false, 'confirm_client_name' => self::NOMBRE, 'ttl' => 3600]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('ttl', $respuesta->json('detalle'));
        $this->assertStringContainsString('dry_run', (string) $respuesta->json('detalle.ttl'), 'El error lista los parámetros que sí acepta.');

        $this->pedir(['usuario_id' => 1])->assertStatus(422);
        $this->pedir(['pisar_distintas' => true])->assertStatus(422);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 5. Emitir: el pedido que sale hacia el cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Emitir de verdad: el cliente recibe UN POST a la URL de admin-sync de SU frente, con la clave en `X-Admin-Api-Key`, y la respuesta es
     * 200 con la URL de entrada del SPA del MISMO frente y el token en el fragmento.
     *
     * @return void
     */
    public function test_emitir_manda_un_post_firmado_al_frente_y_devuelve_la_url_con_el_token_en_el_fragmento(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 30, 12, 0, 0, 'UTC'));

        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad(['solicitante' => 'codex', 'motivo' => 'Revisar el listado de ventas']);

        $respuesta->assertStatus(200);
        $this->assertSame(
            ['client_id', 'dry_run', 'url', 'vence_en_segundos', 'expira_en', 'usuario_id', 'frente'],
            array_keys($respuesta->json()),
            'La forma del contrato de la emisión.'
        );
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertFalse($respuesta->json('dry_run'));
        $this->assertSame(120, $respuesta->json('vence_en_segundos'));
        $this->assertSame('2026-10-30T12:02:00Z', $respuesta->json('expira_en'), 'ISO-8601 en UTC: ahora + vence_en_segundos.');
        $this->assertSame(12, $respuesta->json('usuario_id'));

        // La URL: el SPA del frente, la ruta del canje y el token en el FRAGMENTO.
        $url = (string) $respuesta->json('url');

        $this->assertSame('https://doblep.ejemplo.test/acceso-soporte#t=' . $this->token, $url);

        $partes = parse_url($url);

        $this->assertSame('t=' . $this->token, $partes['fragment']);
        $this->assertArrayNotHasKey('query', $partes, 'El token NO va en la query: viajaría al servidor y quedaría en los access logs.');

        $this->assertSame([
            'client_api_id' => (int) $this->cliente->active_client_api->id,
            'hosting_type'  => 'shared_hosting',
            'api_host'      => 'api-doblep.ejemplo.test',
            'spa_url'       => 'https://doblep.ejemplo.test',
        ], $respuesta->json('frente'));

        $this->assertSinLaClave($respuesta, $this->clave);

        // El pedido que salió: método, URL exacta, headers y cuerpo.
        $clave = $this->clave;

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($clave) {
            return $request->method() === 'POST'
                && $request->url() === BaseDelAccesoDeSoporte::URL_DE_EMISION
                && $request->hasHeader('X-Admin-Api-Key', $clave)
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $this->cuerpo_enviado($request) === ['solicitante' => 'codex', 'motivo' => 'Revisar el listado de ventas'];
        });
    }

    /**
     * La respuesta que lleva el token sale con `Cache-Control: no-store`.
     *
     * @return void
     */
    public function test_la_respuesta_con_el_token_no_se_guarda_en_ninguna_cache(): void
    {
        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
    }

    /**
     * Sin `solicitante` ni `motivo`, el cliente recibe solicitante `claude` y NO recibe `motivo` (un motivo vacío no viaja).
     *
     * @return void
     */
    public function test_los_defaults_del_pedido_son_solicitante_claude_y_sin_motivo(): void
    {
        $this->el_cliente_emite();

        $this->pedir_de_verdad()->assertStatus(200);

        Http::assertSent(function ($request) {
            return $this->cuerpo_enviado($request) === ['solicitante' => 'claude'];
        });
    }

    /**
     * 🔴 Sin seguir redirecciones y con 15 s: las opciones con las que sale el pedido.
     *
     * @return void
     */
    public function test_el_pedido_sale_sin_redirecciones_y_con_15_segundos(): void
    {
        $opciones = [];

        $this->http_falso(['*' => function ($request, $o) use (&$opciones) {
            $opciones[] = [
                'allow_redirects' => isset($o['allow_redirects']) ? $o['allow_redirects'] : 'sin definir',
                'timeout'         => isset($o['timeout']) ? $o['timeout'] : null,
            ];

            return Http::response($this->emision_valida(), 200);
        }]);

        $this->pedir_de_verdad()->assertStatus(200);

        $this->assertSame([['allow_redirects' => false, 'timeout' => 15]], $opciones);
        $this->assertSame(15, ClientAccesoSoporteService::TIMEOUT_SEGUNDOS);
    }

    /**
     * Un cliente del VPS: la API no lleva `/public` y el SPA es el suyo.
     *
     * @return void
     */
    public function test_un_cliente_del_vps_le_pega_a_su_api_sin_public(): void
    {
        $vps = $this->crear_cliente('Cliente del VPS', 'sk-vps-' . Str::random(30));
        $this->crear_frente($vps, 'delvps', 'vps', 'delvps', true);

        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad([], $vps);

        $respuesta->assertStatus(200);
        $this->assertSame('https://delvps.ejemplo.test/acceso-soporte#t=' . $this->token, $respuesta->json('url'));
        $this->assertSame('vps', $respuesta->json('frente.hosting_type'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-delvps.ejemplo.test/api/admin-sync/acceso-soporte';
        });
    }

    /**
     * Una barra de más en el `spa_url` cargado no deja una URL con `//acceso-soporte`.
     *
     * @return void
     */
    public function test_un_spa_url_con_barra_final_o_espacios_no_duplica_la_barra(): void
    {
        $cliente = $this->crear_cliente('Cliente de barra', 'sk-barra-' . Str::random(30));
        $this->crear_frente_a_medida($cliente, ['spa_url' => '  https://barra.ejemplo.test/  '], true);

        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad([], $cliente);

        $respuesta->assertStatus(200);
        $this->assertSame('https://barra.ejemplo.test/acceso-soporte#t=' . $this->token, $respuesta->json('url'));
        $this->assertSame('https://barra.ejemplo.test', $respuesta->json('frente.spa_url'));
    }

    /* ------------------------------------------------------------------------------------------
     | 6. EL frente: la API y el SPA salen de la MISMA ClientApi
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con dos frentes se usa el ACTIVO —el mismo al que le habla el puente de admin-sync—: la API a la que se le pide el token y el SPA al
     * que se manda a la persona son los de ESE frente, y el otro frente no se toca.
     *
     * @return void
     */
    public function test_con_dos_frentes_usa_el_activo_para_la_api_y_para_el_spa(): void
    {
        $segunda = $this->crear_frente($this->cliente, 'doblep2');

        $this->cliente->active_client_api_id = $segunda->id;
        $this->cliente->save();

        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(200);
        $this->assertSame('https://doblep2.ejemplo.test/acceso-soporte#t=' . $this->token, $respuesta->json('url'));
        $this->assertSame((int) $segunda->id, $respuesta->json('frente.client_api_id'));
        $this->assertSame('api-doblep2.ejemplo.test', $respuesta->json('frente.api_host'));

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-doblep2.ejemplo.test/public/api/admin-sync/acceso-soporte';
        });

        // Es el MISMO frente que elige el resolvedor que usan todas las llamadas salientes del admin.
        $elegido = (new ClientEmpresaApiUrlResolver())->resolve_client_api($this->cliente->fresh());

        $this->assertSame((int) $elegido->id, $respuesta->json('frente.client_api_id'));
    }

    /**
     * 🔴 Si el frente "activo" no tiene una URL de API válida, el resolvedor cae al siguiente que sí la tiene: la API y el SPA son los de ESE
     * frente (y no el SPA del activo roto, que apunta a otra instalación).
     *
     * @return void
     */
    public function test_si_el_activo_no_tiene_api_valida_la_api_y_el_spa_son_los_del_frente_que_gana(): void
    {
        $activo_roto      = $this->cliente->active_client_api;
        $activo_roto->url = '';
        $activo_roto->save();

        $otro = $this->crear_frente($this->cliente, 'doblep3');

        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(200);
        $this->assertSame((int) $otro->id, $respuesta->json('frente.client_api_id'));
        $this->assertSame('https://doblep3.ejemplo.test/acceso-soporte#t=' . $this->token, $respuesta->json('url'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-doblep3.ejemplo.test/public/api/admin-sync/acceso-soporte';
        });
    }

    /**
     * 🔴 Si el frente ganador no tiene `spa_url`, es 409 `sin_spa_url` aunque OTRO frente sí lo tenga: el token solo vale en el frente que lo
     * emitió, y mandar a la persona al SPA de otro frente la dejaría con un "acceso inválido" sin explicación. No se le pega al cliente.
     *
     * @return void
     */
    public function test_si_el_frente_ganador_no_tiene_spa_no_se_cae_al_spa_de_otro_frente(): void
    {
        $activo          = $this->cliente->active_client_api;
        $activo->spa_url = '';
        $activo->save();

        $this->crear_frente($this->cliente, 'doblep4');

        Http::fake();

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame('sin_spa_url', $respuesta->json('error'));
        $this->assertStringNotContainsString('doblep4', $this->cuerpo($respuesta), 'No se usa el SPA de otro frente.');

        Http::assertNothingSent();

        // En dry_run el mismo caso es un 200 con `listo: false` y el motivo, y el frente sin SPA.
        $dry = $this->pedir(['dry_run' => true]);

        $dry->assertStatus(200);
        $this->assertFalse($dry->json('listo'));
        $this->assertNotEmpty($dry->json('motivo'));
        $this->assertNull($dry->json('frente.spa_url'));
        $this->assertSame('api-doblep.ejemplo.test', $dry->json('frente.api_host'));

        Http::assertNothingSent();
    }

    /**
     * Un `spa_url` con query, fragmento o credenciales no sirve: a esa URL se le pega `/acceso-soporte#t=...` y el SPA no la sabría leer.
     *
     * @return array<string, array<int, string>>
     */
    public function los_spa_url_que_no_sirven(): array
    {
        return [
            'con query'        => ['https://raro.ejemplo.test/?x=1'],
            'con fragmento'    => ['https://raro.ejemplo.test/#/inicio'],
            'con credenciales' => ['https://usuario:clave@raro.ejemplo.test'],
            'sin esquema'      => ['raro.ejemplo.test'],
            'con otro esquema' => ['ftp://raro.ejemplo.test'],
            'solo esquema'     => ['https://'],
        ];
    }

    /**
     * @dataProvider los_spa_url_que_no_sirven
     *
     * @param string $spa_url El `spa_url` cargado.
     *
     * @return void
     */
    public function test_un_spa_url_que_no_sirve_es_409_sin_spa_url(string $spa_url): void
    {
        $cliente = $this->crear_cliente('Cliente de spa raro', 'sk-spa-raro-' . Str::random(30));
        $this->crear_frente_a_medida($cliente, ['spa_url' => $spa_url], true);

        Http::fake();

        $respuesta = $this->pedir_de_verdad([], $cliente);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_spa_url', $respuesta->json('error'));

        Http::assertNothingSent();
    }

    /**
     * Ganó el `clients.api_url` legacy (ninguna ClientApi trae una URL válida): no hay un "SPA del mismo frente" y se usa el respaldo
     * histórico del admin (`resolve_spa_url`). El frente se informa sin `client_api_id` ni `hosting_type`.
     *
     * @return void
     */
    public function test_si_gana_el_api_url_legacy_el_spa_sale_del_respaldo_y_el_frente_va_sin_id(): void
    {
        $cliente = $this->crear_cliente('Cliente legacy', 'sk-legacy-' . Str::random(30), ['api_url' => 'https://legacy.ejemplo.test']);
        $this->crear_frente_a_medida($cliente, ['url' => '', 'spa_url' => 'https://legacy-spa.ejemplo.test'], true);

        $this->el_cliente_emite();

        $respuesta = $this->pedir_de_verdad([], $cliente);

        $respuesta->assertStatus(200);
        $this->assertSame('https://legacy-spa.ejemplo.test/acceso-soporte#t=' . $this->token, $respuesta->json('url'));
        $this->assertNull($respuesta->json('frente.client_api_id'));
        $this->assertNull($respuesta->json('frente.hosting_type'));
        $this->assertSame('legacy.ejemplo.test', $respuesta->json('frente.api_host'));

        // El legacy se trata como VPS (no se le agrega /public).
        Http::assertSent(function ($request) {
            return $request->url() === 'https://legacy.ejemplo.test/api/admin-sync/acceso-soporte';
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 7. solicitante y motivo: saneo y límites
     |----------------------------------------------------------------------------------------- */

    /**
     * Lo que le llega al cliente de `solicitante` y `motivo` ya está saneado: los saltos de línea y los caracteres de control son un espacio, los
     * invisibles de formato se van y los espacios se colapsan. No se puede partir ni falsificar una línea de log o una fila de auditoría.
     *
     * @return array<string, array<int, string>>
     */
    public function los_textos_a_sanear(): array
    {
        return [
            'salto de línea'        => ["Claude\nCode", 'Claude Code'],
            'CRLF'                  => ["revisar\r\nfactura", 'revisar factura'],
            'tabulador'             => ["a\tb", 'a b'],
            'control suelto'        => ["a\x01b", 'a b'],
            'separador de línea'    => ["a\u{2028}b", 'a b'],
            'espacios repetidos'    => ['a     b', 'a b'],
            'derecha a izquierda'   => ["ab\u{202E}cd", 'abcd'],
            'bordes'                => ["  hola  ", 'hola'],
        ];
    }

    /**
     * @dataProvider los_textos_a_sanear
     *
     * @param string $entrada  Lo que manda quien llama.
     * @param string $esperado Lo que tiene que recibir el cliente.
     *
     * @return void
     */
    public function test_solicitante_y_motivo_llegan_al_cliente_saneados(string $entrada, string $esperado): void
    {
        $this->el_cliente_emite();

        $this->pedir_de_verdad(['solicitante' => $entrada, 'motivo' => $entrada])->assertStatus(200);

        Http::assertSent(function ($request) use ($esperado) {
            return $this->cuerpo_enviado($request) === ['solicitante' => $esperado, 'motivo' => $esperado];
        });
    }

    /**
     * Un `solicitante` o `motivo` que queda vacío tras el saneo vale lo mismo que no mandarlo: `claude` y sin motivo.
     *
     * @return void
     */
    public function test_un_texto_que_queda_vacio_tras_el_saneo_vale_como_ausente(): void
    {
        $this->el_cliente_emite();

        $this->pedir_de_verdad(['solicitante' => "\x01\x02", 'motivo' => "\n\u{2028}\n"])->assertStatus(200);

        Http::assertSent(function ($request) {
            return $this->cuerpo_enviado($request) === ['solicitante' => 'claude'];
        });
    }

    /**
     * Los límites: 60 y 120 caracteres entran (contados como caracteres, no como bytes); uno más es 422 y NO se recorta en silencio.
     *
     * @return void
     */
    public function test_los_limites_de_solicitante_y_motivo_son_60_y_120_caracteres(): void
    {
        $this->el_cliente_emite();

        $solicitante = str_repeat('ñ', 60);
        $motivo      = str_repeat('é', 120);

        $this->pedir_de_verdad(['solicitante' => $solicitante, 'motivo' => $motivo])->assertStatus(200);

        Http::assertSent(function ($request) use ($solicitante, $motivo) {
            return $this->cuerpo_enviado($request) === ['solicitante' => $solicitante, 'motivo' => $motivo];
        });

        $largo_solicitante = $this->pedir_de_verdad(['solicitante' => str_repeat('a', 61)]);
        $largo_solicitante->assertStatus(422);
        $this->assertArrayHasKey('solicitante', $largo_solicitante->json('detalle'));

        $largo_motivo = $this->pedir_de_verdad(['motivo' => str_repeat('a', 121)]);
        $largo_motivo->assertStatus(422);
        $this->assertArrayHasKey('motivo', $largo_motivo->json('detalle'));

        // Los dos 422 no salieron hacia el cliente: sigue siendo el único pedido, el del caso de los límites exactos.
        Http::assertSentCount(1);
    }

    /**
     * Un `solicitante` o `motivo` que no es un texto (una lista, un objeto) es 422.
     *
     * @return void
     */
    public function test_solicitante_o_motivo_que_no_son_texto_son_422(): void
    {
        Http::fake();

        $a = $this->pedir_de_verdad(['solicitante' => ['claude']]);
        $a->assertStatus(422);
        $this->assertArrayHasKey('solicitante', $a->json('detalle'));

        $b = $this->pedir_de_verdad(['motivo' => ['x' => 'y']]);
        $b->assertStatus(422);
        $this->assertArrayHasKey('motivo', $b->json('detalle'));

        Http::assertNothingSent();
    }

    /**
     * Un byte que no es UTF-8 válido en `solicitante` o `motivo` es 422, nunca un 500 (un solo byte suelto hace fallar el `json_encode`).
     *
     * @return void
     */
    public function test_un_texto_que_no_es_utf8_es_422_y_no_un_500(): void
    {
        Http::fake();

        $respuesta = $this->post(
            $this->url_del_endpoint(),
            ['dry_run' => 'false', 'confirm_client_name' => self::NOMBRE, 'solicitante' => "ok\xFFmal", 'motivo' => "otro\xC3"],
            $this->headers()
        );

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));
        $this->assertArrayHasKey('solicitante', $respuesta->json('detalle'));
        $this->assertArrayHasKey('motivo', $respuesta->json('detalle'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 8. El catálogo dice lo mismo que el controlador
     |----------------------------------------------------------------------------------------- */

    /**
     * Los parámetros que `config/claude_catalog.php` declara para la ruta son EXACTAMENTE la lista cerrada del controlador, y los códigos de
     * error que el controlador puede devolver están todos nombrados en la descripción.
     *
     * @return void
     */
    public function test_el_catalogo_declara_los_mismos_parametros_y_los_mismos_errores(): void
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/clients/{id}/acceso-soporte');

        $this->assertIsArray($endpoint, 'El catálogo no tiene la entrada del acceso de soporte.');
        $this->assertTrue($endpoint['escribe']);
        $this->assertSame('alta', $endpoint['peligrosidad']);
        $this->assertNotEmpty($endpoint['frenos']);

        $declarados = [];
        foreach ($endpoint['parametros'] as $parametro) {
            if (strpos($parametro['nombre'], '(en la ruta)') === false) {
                $declarados[] = $parametro['nombre'];
            }
        }

        $aceptados = ClaudeClientAccesoSoporteController::PARAMETROS_DEL_ACCESO_DE_SOPORTE;

        sort($declarados);
        sort($aceptados);

        $this->assertSame($aceptados, $declarados);

        foreach (ClaudeClientAccesoSoporteController::ERRORES_DEL_SERVICIO as $estado => $error) {
            $this->assertStringContainsString($error[0], $endpoint['para_que'], 'El catálogo no nombra el error ' . $error[0] . '.');
        }

        foreach (['sin_frentes', 'cliente_inexistente', 'validacion'] as $codigo) {
            $this->assertStringContainsString($codigo, $endpoint['para_que']);
        }
    }

    /**
     * El orden de las comprobaciones: cliente → validación → freno del nombre → `sin_frentes`. Un cliente sin frentes con un pedido sin confirmar
     * es 422 (el nombre), no 409.
     *
     * @return void
     */
    public function test_el_orden_es_validacion_nombre_y_recien_despues_sin_frentes(): void
    {
        $sin_frentes = $this->crear_cliente('Cliente sin frentes', 'sk-sin-frentes-' . Str::random(30));

        Http::fake();

        $this->pedir(['dry_run' => false], $sin_frentes)->assertStatus(422);
        $this->pedir(['dry_run' => 'maybe'], $sin_frentes)->assertStatus(422);

        $respuesta = $this->pedir(['dry_run' => false, 'confirm_client_name' => 'Cliente sin frentes'], $sin_frentes);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_frentes', $respuesta->json('error'));

        Http::assertNothingSent();

        $this->assertSame(0, ClientApi::query()->where('client_id', $sin_frentes->id)->count());
    }
}
