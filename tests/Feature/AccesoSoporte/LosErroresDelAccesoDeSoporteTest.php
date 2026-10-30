<?php

namespace Tests\Feature\AccesoSoporte;

use App\Http\Controllers\Api\ClaudeClientAccesoSoporteController;
use App\Models\ClientApi;
use App\Services\ClientAccesoSoporteService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Los estados de error del contrato C3 de `POST claude/clients/{id}/acceso-soporte` (misión `acceso-soporte-firmado`, 30/10/2026): qué le contesta
 * el admin a quien llama según lo que pasó con el cliente.
 *
 *  - Lo que el admin ve ANTES de llamar: `sin_frentes`, `sin_api_key`, `sin_url`, `sin_spa_url` (409) — y no le pega al cliente.
 *  - Lo que contesta el `empresa-api`: 404/405 sin `code` → `version_vieja`; 401 → `clave_rechazada`; 403 con `acceso_soporte_desactivado`;
 *    409 con `dueno_no_resoluble` (todos 409); timeout o conexión → 502 `cliente_no_responde`; un 200 sin token válido o cualquier otra cosa →
 *    502 `respuesta_invalida`.
 *  - 🔴 En NINGÚN error sale la clave del cliente ni el token, aunque el cliente los repita en su cuerpo.
 *
 * El `error` es un CÓDIGO ESTABLE y `mensaje` el texto, con la misma forma que `ClaudeClientAdminSyncController`.
 */
class LosErroresDelAccesoDeSoporteTest extends BaseDelAccesoDeSoporte
{
    /* ------------------------------------------------------------------------------------------
     | 1. Lo que el admin ve antes de llamar al cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un cliente sin ninguna `ClientApi` es 409 `sin_frentes`, con o sin `dry_run`, y no se le pega a nadie.
     *
     * @return void
     */
    public function test_un_cliente_sin_frentes_es_409_sin_frentes(): void
    {
        // Aunque tenga un api_url legacy cargado: sin frentes no hay a dónde pedir el acceso.
        $cliente = $this->crear_cliente('Cliente sin frentes', 'sk-sin-frentes-' . Str::random(30), ['api_url' => 'https://legacy.ejemplo.test']);

        Http::fake();

        $real = $this->pedir_de_verdad([], $cliente);

        $real->assertStatus(409);
        $this->assertSame('sin_frentes', $real->json('error'));
        $this->assertNotEmpty($real->json('mensaje'));

        $dry = $this->pedir([], $cliente);

        $dry->assertStatus(409);
        $this->assertSame('sin_frentes', $dry->json('error'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 Sin `api_key` en el admin es 409 `sin_api_key`, ANTES de llamar a nadie: una clave vacía o de solo espacios cuenta como vacía. El mensaje dice
     * cómo se arregla. En dry_run es un 200 con `listo: false` y el mismo motivo, y el frente igual se informa.
     *
     * @return void
     */
    public function test_sin_api_key_es_409_y_no_llama_al_cliente(): void
    {
        Http::fake();

        foreach (['', '   '] as $vacia) {
            $cliente = $this->crear_cliente('Cliente sin clave', $vacia);
            $this->crear_frente($cliente, 'sinclave' . strlen($vacia), 'shared_hosting', null, true);

            $respuesta = $this->pedir_de_verdad([], $cliente);

            $respuesta->assertStatus(409);
            $this->assertSame('sin_api_key', $respuesta->json('error'));
            $this->assertStringContainsString('catalogo/clave', (string) $respuesta->json('mensaje'));
            $this->assertSame('api-sinclave' . strlen($vacia) . '.ejemplo.test', $respuesta->json('frente.api_host'), 'Los errores informan a qué frente se le hubiera pedido.');

            $dry = $this->pedir([], $cliente);

            $dry->assertStatus(200);
            $this->assertFalse($dry->json('listo'));
            $this->assertStringContainsString('catalogo/clave', (string) $dry->json('motivo'));
            $this->assertSame('api-sinclave' . strlen($vacia) . '.ejemplo.test', $dry->json('frente.api_host'));
        }

        Http::assertNothingSent();
    }

    /**
     * Sin una URL de `empresa-api` resoluble es 409 `sin_url`, ANTES de llamar a nadie. En dry_run, un 200 con `listo: false`.
     *
     * @return void
     */
    public function test_sin_url_es_409_y_no_llama_al_cliente(): void
    {
        $cliente = $this->crear_cliente('Cliente sin API', 'sk-sin-url-' . Str::random(30));
        $this->crear_frente_a_medida($cliente, ['url' => ''], true);

        Http::fake();

        $respuesta = $this->pedir_de_verdad([], $cliente);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_url', $respuesta->json('error'));
        $this->assertNull($respuesta->json('frente.api_host'));

        $dry = $this->pedir([], $cliente);

        $dry->assertStatus(200);
        $this->assertFalse($dry->json('listo'));
        $this->assertNotEmpty($dry->json('motivo'));

        Http::assertNothingSent();
    }

    /**
     * Un frente con API válida pero sin `spa_url` es 409 `sin_spa_url`, ANTES de llamar a nadie: no habría a dónde mandar a la persona con el token.
     *
     * @return void
     */
    public function test_sin_spa_url_es_409_y_no_llama_al_cliente(): void
    {
        $cliente = $this->crear_cliente('Cliente sin SPA', 'sk-sin-spa-' . Str::random(30));
        $this->crear_frente_a_medida($cliente, ['spa_url' => null], true);

        Http::fake();

        $respuesta = $this->pedir_de_verdad([], $cliente);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_spa_url', $respuesta->json('error'));
        $this->assertNull($respuesta->json('frente.spa_url'));
        $this->assertSame('api-amedida.ejemplo.test', $respuesta->json('frente.api_host'));

        Http::assertNothingSent();
    }

    /**
     * El orden de los cortes de configuración: primero la clave, después la URL de la API, después el SPA.
     *
     * @return void
     */
    public function test_el_orden_de_los_cortes_es_clave_url_y_spa(): void
    {
        Http::fake();

        // Sin clave NI URL NI SPA: gana sin_api_key.
        $nada = $this->crear_cliente('Cliente sin nada', '');
        $this->crear_frente_a_medida($nada, ['url' => '', 'spa_url' => ''], true);

        $this->assertSame('sin_api_key', $this->pedir_de_verdad([], $nada)->json('error'));

        // Con clave, sin URL NI SPA: gana sin_url.
        $con_clave = $this->crear_cliente('Cliente con clave', 'sk-orden-' . Str::random(30));
        $this->crear_frente_a_medida($con_clave, ['url' => '', 'spa_url' => ''], true);

        $this->assertSame('sin_url', $this->pedir_de_verdad([], $con_clave)->json('error'));

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Lo que contesta el empresa-api del cliente
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un 404 o un 405 SIN `code` —los de Laravel— es 409 `version_vieja`: el cliente todavía no tiene la ruta. El mensaje dice qué hacer (actualizarlo
     * o entrar con el login maestro) y no se rompe nada.
     *
     * @return array<string, array<int, mixed>>
     */
    public function las_respuestas_de_una_version_vieja(): array
    {
        return [
            '404 con el JSON de Laravel'   => [['message' => 'The route api/admin-sync/acceso-soporte could not be found.'], 404],
            '404 con HTML'                 => ['<html><body>Not Found</body></html>', 404],
            '404 vacío'                    => ['', 404],
            '405 con el JSON de Laravel'   => [['message' => 'The POST method is not supported for this route.'], 405],
            '404 con un code que es null'  => [['code' => null, 'message' => 'no existe'], 404],
        ];
    }

    /**
     * @dataProvider las_respuestas_de_una_version_vieja
     *
     * @param mixed $cuerpo Cuerpo que contesta el cliente.
     * @param int   $status HTTP que contesta el cliente.
     *
     * @return void
     */
    public function test_404_o_405_sin_code_es_409_version_vieja($cuerpo, int $status): void
    {
        $this->el_cliente_contesta($cuerpo, $status);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame('version_vieja', $respuesta->json('error'));
        $this->assertStringContainsString('login maestro', (string) $respuesta->json('mensaje'));
        $this->assertStringContainsString('actualiz', mb_strtolower((string) $respuesta->json('mensaje')));
        $this->assertSame('api-doblep.ejemplo.test', $respuesta->json('frente.api_host'));
        $this->assertSinLaClave($respuesta, $this->clave);

        Http::assertSentCount(1);
    }

    /**
     * Un 404 CON `code` no es el de Laravel: es una respuesta de la app del cliente, y no es parte del contrato.
     *
     * @return void
     */
    public function test_un_404_con_code_no_es_version_vieja(): void
    {
        $this->el_cliente_contesta(['code' => 'otra_cosa', 'message' => 'x'], 404);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
        $this->assertStringContainsString('404', (string) $respuesta->json('mensaje'));
    }

    /**
     * 🔴 Un 401 es 409 `clave_rechazada`: el `ADMIN_API_INBOUND_KEY` del cliente no coincide con la clave del admin, o no la tiene. El mensaje apunta a
     * `catalogo/clave`.
     *
     * @return void
     */
    public function test_401_es_409_clave_rechazada(): void
    {
        $this->el_cliente_contesta(['error' => 'unauthorized'], 401);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame('clave_rechazada', $respuesta->json('error'));
        $this->assertStringContainsString('catalogo/clave', (string) $respuesta->json('mensaje'));
        $this->assertSinLaClave($respuesta, $this->clave);

        Http::assertSentCount(1);
    }

    /**
     * Un 403 con `code = acceso_soporte_desactivado` es 409 `acceso_desactivado`.
     *
     * @return void
     */
    public function test_403_con_el_code_de_desactivado_es_409_acceso_desactivado(): void
    {
        $this->el_cliente_contesta(['code' => 'acceso_soporte_desactivado', 'message' => 'El acceso de soporte está desactivado.'], 403);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame('acceso_desactivado', $respuesta->json('error'));
        $this->assertStringContainsString('ACCESO_SOPORTE_HABILITADO', (string) $respuesta->json('mensaje'));

        Http::assertSentCount(1);
    }

    /**
     * Un 403 SIN ese `code` (un WAF, otra capa) no es "acceso desactivado": es una respuesta inesperada.
     *
     * @return void
     */
    public function test_403_sin_el_code_es_502_respuesta_invalida(): void
    {
        $this->el_cliente_contesta('<html>Forbidden por el firewall</html>', 403);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
        $this->assertStringContainsString('403', (string) $respuesta->json('mensaje'));
        $this->assertStringNotContainsString('firewall', $this->cuerpo($respuesta), 'No se repite el cuerpo del cliente.');
    }

    /**
     * Un 409 con `code = dueno_no_resoluble` es 409 `dueno_no_resoluble`: nunca se elige "el primer dueño".
     *
     * @return void
     */
    public function test_409_con_el_code_de_dueno_es_409_dueno_no_resoluble(): void
    {
        $this->el_cliente_contesta(['code' => 'dueno_no_resoluble', 'message' => 'Hay más de un dueño.'], 409);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame('dueno_no_resoluble', $respuesta->json('error'));
        $this->assertStringContainsString('USER_ID', (string) $respuesta->json('mensaje'));

        Http::assertSentCount(1);
    }

    /**
     * Un 409 SIN ese `code` es una respuesta inesperada.
     *
     * @return void
     */
    public function test_409_sin_el_code_es_502_respuesta_invalida(): void
    {
        $this->el_cliente_contesta(['message' => 'Conflicto cualquiera'], 409);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
    }

    /**
     * 🔴 Un timeout o una falla de conexión es 502 `cliente_no_responde`, SIN reintentar (emitir no es idempotente), y el mensaje de la excepción
     * —aunque traiga la clave adentro— sale con la clave tapada.
     *
     * @return void
     */
    public function test_un_timeout_es_502_cliente_no_responde_sin_reintentar_y_sin_filtrar_la_clave(): void
    {
        $clave    = $this->clave;
        $intentos = 0;

        $this->http_falso(['*' => function () use ($clave, &$intentos) {
            $intentos++;

            throw new ConnectionException(
                'cURL error 28: Operation timed out after 15001 milliseconds (X-Admin-Api-Key: ' . $clave . ') for '
                . 'https://api-doblep.ejemplo.test/public/api/admin-sync/acceso-soporte?secreto=DATO-DEL-NEGOCIO'
            );
        }]);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertStringContainsString('timed out', (string) $respuesta->json('mensaje'));
        $this->assertStringContainsString('[clave oculta]', (string) $respuesta->json('mensaje'));
        $this->assertStringNotContainsString('DATO-DEL-NEGOCIO', (string) $respuesta->getContent(), 'La query de la URL no sale.');
        $this->assertSame(1, $intentos, 'Un timeout NO se reintenta.');
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSinElToken($respuesta);
    }

    /**
     * Una falla de conexión cualquiera (DNS, conexión rechazada) es lo mismo.
     *
     * @return void
     */
    public function test_una_falla_de_conexion_es_502_cliente_no_responde(): void
    {
        $this->http_falso(['*' => function () {
            throw new ConnectionException('cURL error 6: Could not resolve host: api-doblep.ejemplo.test');
        }]);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertStringContainsString('Could not resolve host', (string) $respuesta->json('mensaje'));
    }

    /**
     * 🔴 Un 200 que no trae un token válido es 502 `respuesta_invalida`: ni se entrega una URL a medias ni se inventa un vencimiento.
     *
     * @return array<string, array<int, mixed>>
     */
    public function los_200_sin_token_valido(): array
    {
        $bueno = str_repeat('a', 64);

        return [
            'sin token'                      => [['ok' => true, 'vence_en_segundos' => 120]],
            'token nulo'                     => [['ok' => true, 'token' => null, 'vence_en_segundos' => 120]],
            'token vacío'                    => [['ok' => true, 'token' => '', 'vence_en_segundos' => 120]],
            'token de 63'                    => [['ok' => true, 'token' => str_repeat('a', 63), 'vence_en_segundos' => 120]],
            'token de 65'                    => [['ok' => true, 'token' => str_repeat('a', 65), 'vence_en_segundos' => 120]],
            'token con símbolos'             => [['ok' => true, 'token' => str_repeat('a', 63) . '-', 'vence_en_segundos' => 120]],
            'token con salto de línea'       => [['ok' => true, 'token' => $bueno . "\n", 'vence_en_segundos' => 120]],
            'token con una barra'            => [['ok' => true, 'token' => str_repeat('a', 63) . '/', 'vence_en_segundos' => 120]],
            'token que no es texto'          => [['ok' => true, 'token' => 12345, 'vence_en_segundos' => 120]],
            'token que es una lista'         => [['ok' => true, 'token' => [$bueno], 'vence_en_segundos' => 120]],
            'sin vencimiento'                => [['ok' => true, 'token' => $bueno]],
            'vencimiento en texto'           => [['ok' => true, 'token' => $bueno, 'vence_en_segundos' => '120']],
            'vencimiento decimal'            => [['ok' => true, 'token' => $bueno, 'vence_en_segundos' => 120.5]],
            'vencimiento cero'               => [['ok' => true, 'token' => $bueno, 'vence_en_segundos' => 0]],
            'vencimiento negativo'           => [['ok' => true, 'token' => $bueno, 'vence_en_segundos' => -5]],
            'un 200 con una lista'           => [['a', 'b']],
            'un 200 con texto'               => ['OK'],
            'un 200 vacío'                   => [''],
            'un 200 con HTML'                => ['<html><body>Bienvenido</body></html>'],
        ];
    }

    /**
     * @dataProvider los_200_sin_token_valido
     *
     * @param mixed $cuerpo Cuerpo del 200.
     *
     * @return void
     */
    public function test_un_200_sin_token_valido_es_502_respuesta_invalida($cuerpo): void
    {
        $this->el_cliente_contesta($cuerpo, 200);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
        $this->assertArrayNotHasKey('url', $respuesta->json(), 'No se entrega ninguna URL de entrada.');
        $this->assertStringNotContainsString('#t=', $this->cuerpo($respuesta));

        Http::assertSentCount(1);
    }

    /**
     * Cualquier otro status del cliente es 502 `respuesta_invalida` con el número en el mensaje y NADA de su cuerpo. Un 3xx NO se sigue: es un solo
     * pedido (la clave no viaja a donde apunte la redirección).
     *
     * @return array<string, array<int, mixed>>
     */
    public function los_otros_status_del_cliente(): array
    {
        return [
            '500' => [500],
            '502' => [502],
            '503' => [503],
            '429' => [429],
            '422' => [422],
            '301' => [301],
            '302' => [302],
            '307' => [307],
            '204' => [204],
        ];
    }

    /**
     * @dataProvider los_otros_status_del_cliente
     *
     * @param int $status HTTP que contesta el cliente.
     *
     * @return void
     */
    public function test_cualquier_otro_status_es_502_respuesta_invalida_con_el_numero(int $status): void
    {
        $this->el_cliente_contesta(
            ['detalle_interno' => 'TRAZA-DEL-CLIENTE', 'token' => $this->token, 'eco' => $this->clave],
            $status,
            ['Location' => 'https://otro-host.ejemplo.test/robo']
        );

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
        $this->assertStringContainsString('HTTP ' . $status, (string) $respuesta->json('mensaje'));
        $this->assertStringNotContainsString('TRAZA-DEL-CLIENTE', (string) $respuesta->getContent(), 'No se repite el cuerpo del cliente.');
        $this->assertStringNotContainsString('otro-host', (string) $respuesta->getContent());
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSinElToken($respuesta);

        Http::assertSentCount(1);
    }

    /**
     * Un 3xx explica por qué no se sigue.
     *
     * @return void
     */
    public function test_un_3xx_explica_que_no_se_sigue(): void
    {
        $this->el_cliente_contesta('', 302, ['Location' => 'https://otro-host.ejemplo.test/']);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertStringContainsString('redirección', (string) $respuesta->json('mensaje'));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. La forma de los errores
     |----------------------------------------------------------------------------------------- */

    /**
     * Todos los errores de servicio traen `error` (el código), `mensaje`, `client_id` y `frente` con las cuatro claves del contrato, y cada estado
     * del servicio tiene su entrada en el mapa del controlador.
     *
     * @return void
     */
    public function test_los_errores_de_servicio_traen_codigo_mensaje_client_id_y_frente(): void
    {
        $this->el_cliente_contesta(['error' => 'unauthorized'], 401);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame(['error', 'mensaje', 'client_id', 'frente'], array_keys($respuesta->json()));
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertSame(['client_api_id', 'hosting_type', 'api_host', 'spa_url'], array_keys($respuesta->json('frente')));

        $estados = [
            ClientAccesoSoporteService::ESTADO_SIN_API_KEY,
            ClientAccesoSoporteService::ESTADO_SIN_URL,
            ClientAccesoSoporteService::ESTADO_SIN_SPA_URL,
            ClientAccesoSoporteService::ESTADO_VERSION_VIEJA,
            ClientAccesoSoporteService::ESTADO_CLAVE_RECHAZADA,
            ClientAccesoSoporteService::ESTADO_ACCESO_DESACTIVADO,
            ClientAccesoSoporteService::ESTADO_DUENO_NO_RESOLUBLE,
            ClientAccesoSoporteService::ESTADO_NO_RESPONDE,
            ClientAccesoSoporteService::ESTADO_RESPUESTA_INVALIDA,
        ];

        $this->assertEqualsCanonicalizing($estados, array_keys(ClaudeClientAccesoSoporteController::ERRORES_DEL_SERVICIO));

        // Los 409 son de configuración o del contrato; los 502, del cliente que no contesta o contesta mal.
        foreach (ClaudeClientAccesoSoporteController::ERRORES_DEL_SERVICIO as $estado => $error) {
            $esperado = in_array($estado, [ClientAccesoSoporteService::ESTADO_NO_RESPONDE, ClientAccesoSoporteService::ESTADO_RESPUESTA_INVALIDA], true) ? 502 : 409;

            $this->assertSame($esperado, $error[1], 'HTTP del estado ' . $estado);
        }
    }

    /**
     * Un error del admin no deja nada en el cliente: ninguna fila de `client_apis` cambia por pedir un acceso.
     *
     * @return void
     */
    public function test_pedir_un_acceso_no_modifica_ningun_frente(): void
    {
        $antes = ClientApi::query()->where('client_id', $this->cliente->id)->get()->toArray();

        $this->el_cliente_emite();
        $this->pedir_de_verdad()->assertStatus(200);

        $this->el_cliente_contesta(['error' => 'unauthorized'], 401);
        $this->pedir_de_verdad()->assertStatus(409);

        $despues = ClientApi::query()->where('client_id', $this->cliente->id)->get()->toArray();

        $this->assertEquals($antes, $despues);
    }
}
