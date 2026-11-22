<?php

namespace Tests\Feature\ResetDatosDelCliente;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Los estados de error del reset de datos (misión `reset-datos-cliente`, contrato C2 del PLAN, criterio CA12): qué le contesta
 * el admin a quien llama según lo que pasó con el cliente.
 *
 *  - Lo que el admin ve ANTES de llamar: cliente sin `api_key` → 409 `sin_api_key` (y no le pega).
 *  - Lo que contesta el `empresa-api`: 401 → 409 `clave_rechazada`; 404 sin `error` → 409 `version_vieja`; un 409/422/500 con
 *    `error` pasa con su código y su status; timeout o conexión → 502 `cliente_no_responde` (el registro queda `sin_respuesta`);
 *    cualquier otra cosa → 502 `respuesta_invalida`.
 *  - 🔴 En NINGÚN caso sale la clave del cliente ni el token: ni en la respuesta, ni en el log, ni en `client_data_resets`,
 *    aunque el cliente los repita en su cuerpo o en el mensaje de la excepción.
 *
 * El `error` es un CÓDIGO ESTABLE y `mensaje` el texto (`message` lo duplica para el interceptor del SPA).
 */
class LosErroresDelResetDeDatosTest extends BaseDelResetDeDatos
{
    /**
     * 🔴 Un 401 del cliente (la clave no coincide con la de su configuración) es 409 `clave_rechazada`, sin reintentar, con `mensaje`
     * y `message` iguales, y el registro queda como `rechazado`.
     *
     * @return void
     */
    public function test_401_del_cliente_es_clave_rechazada(): void
    {
        $this->el_cliente_contesta(['ok' => false, 'error' => 'unauthorized', 'mensaje' => 'Clave inválida'], 401);

        $respuesta = $this->pedir([]);

        $respuesta->assertStatus(409);
        $this->assertSame('clave_rechazada', $respuesta->json('error'));
        $this->assertNotEmpty($respuesta->json('mensaje'));
        $this->assertSame($respuesta->json('mensaje'), $respuesta->json('message'), '`message` duplica `mensaje` para el interceptor del SPA.');
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));
        $this->assertSinLaClave($respuesta, $this->clave);

        Http::assertSentCount(1);

        $fila = $this->registros_del_admin()->last();

        $this->assertNotNull($fila, 'Un rechazo también se registra.');
        $this->assertSame('rechazado', $fila->estado);
        $this->assertSame('clave_rechazada', $fila->codigo);
    }

    /**
     * 🔴 Un 404 sin código de error (la ruta no existe: sistema del cliente anterior al reset) es 409 `version_vieja`, y el
     * mensaje manda a actualizar el sistema del cliente. Un 405 es lo mismo.
     *
     * @return void
     */
    public function test_404_sin_codigo_es_version_vieja(): void
    {
        foreach ([404, 405] as $status) {
            $this->el_cliente_contesta(['message' => 'Not Found'], $status);

            $respuesta = $this->pedir([]);

            $respuesta->assertStatus(409);
            $this->assertSame('version_vieja', $respuesta->json('error'), 'HTTP ' . $status . ' sin `error` del cliente.');
            $this->assertStringContainsString('actualiz', mb_strtolower((string) $respuesta->json('mensaje')));
        }
    }

    /**
     * 🔴 Un 409 de empresa con su código (`comprobantes_con_cae`) pasa con el MISMO código y el mismo status, y el registro lo
     * anota (`rechazado`, `codigo`, `http_status`). Lo mismo con un 422 y con un 500 con código.
     *
     * @return void
     */
    public function test_409_de_empresa_conserva_el_codigo(): void
    {
        $this->el_cliente_contesta(['ok' => false, 'error' => 'comprobantes_con_cae', 'mensaje' => 'Hay comprobantes con CAE', 'detalle' => ['cantidad' => 3]], 409);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(409);
        $this->assertSame('comprobantes_con_cae', $respuesta->json('error'));
        $this->assertStringContainsString('Hay comprobantes con CAE', (string) $respuesta->json('mensaje'));
        $this->assertSame((int) $this->cliente->id, $respuesta->json('client_id'));

        $fila = $this->registros_del_admin()->last();

        $this->assertSame('rechazado', $fila->estado);
        $this->assertSame('comprobantes_con_cae', $fila->codigo);
        $this->assertSame(409, (int) $fila->http_status);

        // Otros códigos de C1 viajan igual, con su status original.
        $casos = [
            [409, 'en_curso'],
            [409, 'varios_duenos'],
            [409, 'confirmacion_invalida'],
            [422, 'validacion'],
            [500, 'respaldo_fallido'],
            [500, 'reset_incompleto'],
        ];

        foreach ($casos as $caso) {
            $this->el_cliente_contesta(['ok' => false, 'error' => $caso[1], 'mensaje' => 'Texto de empresa'], $caso[0]);

            $otro = $this->pedir_de_verdad();

            $otro->assertStatus($caso[0]);
            $this->assertSame($caso[1], $otro->json('error'));
        }
    }

    /**
     * 🔴 Un timeout o una falla de conexión es 502 `cliente_no_responde`, SIN reintentar (el reset no es idempotente); el
     * mensaje sugiere consultar los registros antes de volver a intentar y el registro queda `sin_respuesta`.
     *
     * @return void
     */
    public function test_timeout_es_cliente_no_responde_y_queda_sin_respuesta(): void
    {
        $intentos = 0;

        $this->http_falso(['*' => function () use (&$intentos) {
            $intentos++;

            throw new ConnectionException('cURL error 28: Operation timed out after 900001 milliseconds');
        }]);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('cliente_no_responde', $respuesta->json('error'));
        $this->assertStringContainsString('registros', mb_strtolower((string) $respuesta->json('mensaje')), 'El mensaje manda a consultar los registros antes de reintentar.');
        $this->assertSame(1, $intentos, 'Un timeout NO se reintenta.');
        $this->assertSinLaClave($respuesta, $this->clave);

        $fila = $this->registros_del_admin()->last();

        $this->assertNotNull($fila);
        $this->assertSame('sin_respuesta', $fila->estado);
        $this->assertFalse((bool) $fila->dry_run);
    }

    /**
     * Un 200 que no es JSON (un HTML de mantenimiento, por ejemplo) es 502 `respuesta_invalida`.
     *
     * @return void
     */
    public function test_una_respuesta_que_no_es_json_es_respuesta_invalida(): void
    {
        $this->el_cliente_contesta('<html>En mantenimiento</html>', 200, ['Content-Type' => 'text/html']);

        $respuesta = $this->pedir([]);

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
    }

    /**
     * 🔴 Un cliente sin `api_key` en el admin (vacía o solo espacios) es 409 `sin_api_key`, ANTES de llamar a nadie, tanto
     * simulando como ejecutando.
     *
     * @return void
     */
    public function test_sin_api_key_no_le_pega(): void
    {
        Http::fake();

        foreach (['', '   '] as $vacia) {
            $cliente = $this->crear_cliente('Cliente sin clave ' . strlen($vacia), $vacia);

            $this->crear_frente($cliente, 'sinclave' . strlen($vacia), 'shared_hosting', null, true);

            $simulando = $this->pedir([], $cliente);

            $simulando->assertStatus(409);
            $this->assertSame('sin_api_key', $simulando->json('error'));
            $this->assertNotEmpty($simulando->json('mensaje'));

            $ejecutando = $this->pedir_de_verdad([], $cliente);

            $ejecutando->assertStatus(409);
            $this->assertSame('sin_api_key', $ejecutando->json('error'));
        }

        Http::assertNothingSent();
    }

    /**
     * Un cliente sin ningún frente es 409 `sin_frentes` y no se le pega a nadie; uno que no existe es 404 `cliente_inexistente`.
     *
     * @return void
     */
    public function test_sin_frentes_y_cliente_inexistente(): void
    {
        Http::fake();

        $sin_frentes = $this->crear_cliente('Cliente sin frentes', 'sk-sin-frentes-clave-larga-1234567890');

        $respuesta = $this->pedir([], $sin_frentes);

        $respuesta->assertStatus(409);
        $this->assertSame('sin_frentes', $respuesta->json('error'));

        $inexistente = $this->postJson($this->url_de_claude(987654321), [], $this->headers());

        $inexistente->assertStatus(404);
        $this->assertSame('cliente_inexistente', $inexistente->json('error'));

        Http::assertNothingSent();
    }

    /**
     * Un parámetro que no está en la lista cerrada es 422 `validacion` y no le pega al cliente; sin la clave de ingesta no
     * contesta (401).
     *
     * @return void
     */
    public function test_parametro_desconocido_es_422_y_sin_clave_de_ingesta_es_401(): void
    {
        Http::fake();

        $respuesta = $this->pedir(['dry_run' => true, 'tablas' => ['articles']]);

        $respuesta->assertStatus(422);
        $this->assertSame('validacion', $respuesta->json('error'));

        $this->postJson($this->url_de_claude(), [])->assertStatus(401);

        Http::assertNothingSent();
    }

    /**
     * 🔴 Ningún secreto sale ni se guarda: aunque el cliente repita la clave y el token en su cuerpo (de éxito o de error) o en
     * el mensaje de la excepción, no aparecen en la respuesta, en el log ni en `client_data_resets`.
     *
     * @return void
     */
    public function test_ningun_secreto_en_respuesta_log_ni_registro(): void
    {
        $log   = $this->capturar_el_log();
        $clave = $this->clave;
        $token = $this->token;

        // Lo que el cliente devuelve, en distintas formas, con los dos secretos adentro.
        $casos = [
            'clave rechazada' => [401, ['ok' => false, 'error' => 'unauthorized', 'mensaje' => 'Clave ' . $clave . ' inválida'], 409],
            'freno 409'       => [409, ['ok' => false, 'error' => 'confirmacion_invalida', 'mensaje' => 'Token ' . $token . ' y clave ' . $clave, 'detalle' => ['clave' => $clave, 'token' => $token]], 409],
            'error 500'       => [500, ['ok' => false, 'error' => 'reset_incompleto', 'mensaje' => 'Falló con ' . $clave, 'detalle' => ['respaldo' => 'x', 'token' => $token]], 500],
            'sin json'        => [200, 'Respuesta rara con ' . $clave . ' y ' . $token, 502],
        ];

        foreach ($casos as $nombre => $caso) {
            $this->el_cliente_contesta($caso[1], $caso[0]);

            $respuesta = $this->pedir_de_verdad();

            // El caso tiene que llegar a empresa y volver traducido (sin esto un 404 de ruta pasaría las aserciones sin probar nada).
            $this->assertSame($caso[2], $respuesta->status(), $nombre . ': el status que devuelve el admin.');
            $this->assertNotEmpty($respuesta->json('error'), $nombre . ': trae el código de error.');
            $this->assertSinLaClave($respuesta, $clave, $nombre . ':');
            $this->assertSinElToken($respuesta, $nombre . ':');
        }

        // Un timeout con la clave y el token en el texto de la excepción.
        $this->http_falso(['*' => function () use ($clave, $token) {
            throw new ConnectionException('cURL error 28: timed out (X-Admin-Api-Key: ' . $clave . ') token=' . $token);
        }]);

        $timeout = $this->pedir_de_verdad();

        $this->assertSame(502, $timeout->status(), 'timeout: el status que devuelve el admin.');
        $this->assertSinLaClave($timeout, $clave, 'timeout:');
        $this->assertSinElToken($timeout, 'timeout:');

        // Ni el log ni el registro del admin los guardan.
        $this->assertNotEmpty($this->registros_del_admin(), 'Los intentos quedaron registrados (si no, la aserción de abajo no prueba nada).');
        $this->assertLogSinLaClave($log, $clave);
        $this->assertLogSinElToken($log);
        $this->assertStringNotContainsString($clave, $this->registro_completo_como_texto(), 'client_data_resets lleva la clave del cliente.');
        $this->assertStringNotContainsString($token, $this->registro_completo_como_texto(), 'client_data_resets lleva el token de simulación.');
    }
}
