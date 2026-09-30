<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * La solapa "Inteligencia artificial" del cliente (misión modelos-ia-por-cliente, 30/9/2026): el
 * admin lee y cambia EN VIVO, contra `admin-sync/modelos-ia` del `empresa-api` del cliente, qué
 * modelo usa cada tarea.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Las claves EXACTAS del contrato en el PUT** (`asistente`, `whatsapp`, `imagenes`,
 *     `excel`) y que viajen SOLO las que se mandaron: una clave de más le pisaría al dueño el
 *     asistente que acaba de cambiar desde su modal ("gana el último" es por tarea), y una clave
 *     renombrada deja al otro lado contestando 200 sin haber tocado nada.
 *  2. 🔴 **El header `X-Admin-Api-Key` con la `api_key` del cliente**, en GET y en PUT.
 *  3. **Que un 404 sea `no_soportado`** (versión vieja, el caso mayoritario mientras el parque se
 *     actualiza), no un fallo, y que cada otro código nombre su causa.
 *  4. **Que no se persista nada en el admin** (decisión 2 de Lucas).
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`: el comodín garantiza que nada sale a la red.
 */
class ModelosIaDelClienteTest extends BaseDelCanal
{
    /**
     * Admin logueado por Sanctum: las rutas viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de modelos';
        $admin->email    = 'modelos-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Respuesta 200 del contrato, tal como la arma el `empresa-api` (plan, "El contrato").
     *
     * El asistente eligió DeepSeek Flash pero corre Claude Haiku por fallback (falta la clave de
     * DeepSeek en la instalación): es justo el caso que la solapa tiene que poder mostrar.
     *
     * @param string $opcion_whatsapp Opción que el cliente devuelve como elegida para WhatsApp.
     *
     * @return array<string, mixed>
     */
    private function payload_del_cliente(string $opcion_whatsapp = 'deepseek_flash'): array
    {
        $todas = ['deepseek_flash', 'deepseek_pro', 'claude_haiku', 'claude_sonnet', 'claude_opus'];

        return [
            'ok'       => true,
            'opciones' => [
                ['id' => 'deepseek_flash', 'proveedor' => 'deepseek', 'nombre' => 'DeepSeek Flash', 'modelo' => 'deepseek-flash', 'vision' => true, 'disponible' => false],
                ['id' => 'deepseek_pro', 'proveedor' => 'deepseek', 'nombre' => 'DeepSeek Pro', 'modelo' => 'deepseek-v4-pro', 'vision' => false, 'disponible' => false],
                ['id' => 'claude_haiku', 'proveedor' => 'anthropic', 'nombre' => 'Claude Haiku', 'modelo' => 'claude-haiku-4-5-20251001', 'vision' => true, 'disponible' => true],
            ],
            'tareas'   => [
                'asistente' => [
                    'nombre'           => 'Asistente del dueño',
                    'opcion'           => 'deepseek_flash',
                    'opciones_validas' => $todas,
                    'efectiva'         => ['opcion' => 'claude_haiku', 'proveedor' => 'anthropic', 'modelo' => 'claude-haiku-4-5-20251001', 'fallback' => true],
                ],
                'whatsapp'  => [
                    'nombre'           => 'WhatsApp a clientes',
                    'opcion'           => $opcion_whatsapp,
                    'opciones_validas' => $todas,
                    'efectiva'         => ['opcion' => null, 'proveedor' => 'anthropic', 'modelo' => 'claude-sonnet-4-5', 'fallback' => true],
                ],
                'imagenes'  => [
                    'nombre'           => 'Verificación de imágenes',
                    'opcion'           => 'deepseek_flash',
                    'opciones_validas' => ['deepseek_flash', 'claude_haiku', 'claude_sonnet', 'claude_opus'],
                    'efectiva'         => ['opcion' => null, 'proveedor' => 'anthropic', 'modelo' => 'claude-haiku-4-5-20251001', 'fallback' => true],
                ],
                'excel'     => [
                    'nombre'           => 'Importación de Excel',
                    'opcion'           => 'deepseek_pro',
                    'opciones_validas' => $todas,
                    'efectiva'         => null,
                ],
            ],
        ];
    }

    /**
     * 🔴 GET ok: pega con GET, al endpoint del contrato, con el header, y devuelve el payload.
     *
     * @return void
     */
    public function test_get_trae_los_modelos_del_cliente_con_el_header(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response($this->payload_del_cliente(), 200)]);

        $client = $this->crear_cliente();
        $this->crear_client_api($client, 'https://api-ferreteria-de-prueba.test', 'vps');

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $response->assertStatus(200);
        $this->assertSame('success', $response->json('estado'));
        $this->assertNull($response->json('mensaje'));
        $this->assertSame('deepseek_flash', $response->json('datos.tareas.asistente.opcion'));
        $this->assertSame('claude_haiku', $response->json('datos.tareas.asistente.efectiva.opcion'));
        $this->assertTrue($response->json('datos.tareas.asistente.efectiva.fallback'));
        $this->assertNull($response->json('datos.tareas.excel.efectiva'));
        $this->assertCount(3, $response->json('datos.opciones'));

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api-ferreteria-de-prueba.test/api/admin-sync/modelos-ia'
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente');
        });
    }

    /**
     * 🔴 PUT ok: viajan SOLO las claves que se mandaron, con sus nombres exactos, por PUT y con el
     * header. Una clave que no es del contrato no viaja.
     *
     * @return void
     */
    public function test_put_manda_solo_las_tareas_elegidas_con_las_claves_del_contrato(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response($this->payload_del_cliente('claude_sonnet'), 200)]);

        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', [
            'whatsapp' => 'claude_sonnet',
            'excel'    => 'deepseek_pro',
        ]);

        $response->assertStatus(200);
        $this->assertSame('success', $response->json('estado'));
        // La solapa se redibuja con lo que el cliente dice que quedó, no con lo que se mandó.
        $this->assertSame('claude_sonnet', $response->json('datos.tareas.whatsapp.opcion'));

        Http::assertSent(function ($request) {
            $body = $request->data();
            ksort($body);

            return $request->method() === 'PUT'
                && Str::endsWith($request->url(), '/api/admin-sync/modelos-ia')
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $body === ['excel' => 'deepseek_pro', 'whatsapp' => 'claude_sonnet'];
        });
    }

    /**
     * 🔴 Una clave ajena al contrato no viaja, y las cuatro del contrato sí, con su nombre exacto.
     *
     * @return void
     */
    public function test_put_con_las_cuatro_tareas_y_una_clave_ajena(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response($this->payload_del_cliente(), 200)]);

        $client = $this->crear_cliente();

        $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', [
            'asistente' => 'deepseek_flash',
            'whatsapp'  => 'deepseek_flash',
            'imagenes'  => 'deepseek_flash',
            'excel'     => 'deepseek_pro',
            'bot'       => 'claude_opus',
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $body = $request->data();
            ksort($body);

            return $request->method() === 'PUT'
                && $body === [
                    'asistente' => 'deepseek_flash',
                    'excel'     => 'deepseek_pro',
                    'imagenes'  => 'deepseek_flash',
                    'whatsapp'  => 'deepseek_flash',
                ];
        });
    }

    /**
     * Un PUT sin ninguna tarea es un error del pedido al admin (422) y no sale a la red.
     *
     * @return void
     */
    public function test_put_sin_ninguna_tarea_da_422_y_no_llama_al_cliente(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response($this->payload_del_cliente(), 200)]);

        $client = $this->crear_cliente();

        $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['bot' => 'claude_opus'])
            ->assertStatus(422);

        Http::assertNotSent(function ($request) {
            return Str::contains($request->url(), 'admin-sync/modelos-ia');
        });
    }

    /**
     * 🔴 Un 404 del cliente (versión vieja) es `no_soportado`, no un fallo, y no se reintenta.
     *
     * @return void
     */
    public function test_el_404_es_no_soportado_y_no_se_reintenta(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response(['message' => 'Not Found'], 404)]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $response->assertStatus(200);
        $this->assertSame('no_soportado', $response->json('estado'));
        $this->assertStringContainsString('versión anterior', (string) $response->json('mensaje'));
        $this->assertNull($response->json('datos'));

        Http::assertSentCount(1);

        // El PUT también: guardar contra un cliente viejo dice lo mismo, no revienta.
        $put = $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['whatsapp' => 'deepseek_flash']);
        $put->assertStatus(200);
        $this->assertSame('no_soportado', $put->json('estado'));
    }

    /**
     * Un 401 del cliente es `failed` y el mensaje nombra la api_key.
     *
     * @return void
     */
    public function test_el_401_es_failed_y_nombra_la_api_key(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response(['error' => 'unauthorized'], 401)]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('api_key', (string) $response->json('mensaje'));
        $this->assertStringContainsString('ADMIN_API_INBOUND_KEY', (string) $response->json('mensaje'));

        // Un 4xx no se arregla insistiendo.
        Http::assertSentCount(1);
    }

    /**
     * Un 409 (dueño sin resolver en una base compartida) es `failed` y nombra `USER_ID`.
     *
     * @return void
     */
    public function test_el_409_es_failed_y_nombra_el_user_id(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response(['message' => 'No se pudo resolver el dueño.'], 409)]);

        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['excel' => 'deepseek_pro']);

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('USER_ID', (string) $response->json('mensaje'));
        $this->assertStringContainsString('No se pudo resolver el dueño.', (string) $response->json('mensaje'));
    }

    /**
     * 🔴 Un 422 del cliente (opción que no vale para la tarea) es `failed`, con el motivo del cliente
     * en el mensaje y los errores por tarea aparte para marcar la fila.
     *
     * @return void
     */
    public function test_el_422_trae_el_motivo_del_cliente_por_tarea(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors'  => ['imagenes' => ['DeepSeek Pro no ve imágenes: no sirve para verificar imágenes.']],
        ], 422)]);

        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['imagenes' => 'deepseek_pro']);

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('DeepSeek Pro no ve imágenes', (string) $response->json('mensaje'));
        $this->assertStringNotContainsString('The given data was invalid.', (string) $response->json('mensaje'));
        $this->assertSame(
            ['DeepSeek Pro no ve imágenes: no sirve para verificar imágenes.'],
            $response->json('errores.imagenes')
        );
    }

    /**
     * 🔴 Un 200 con HTML (la página genérica del hosting saturado) es `failed`, no success.
     *
     * @return void
     */
    public function test_un_200_con_html_es_failed(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response('<html>cuenta saturada</html>', 200)]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('cuenta saturada', (string) $response->json('mensaje'));
        $this->assertNull($response->json('datos'));
    }

    /**
     * Un 200 con `ok:true` pero sin `tareas` tampoco es el payload del contrato.
     *
     * @return void
     */
    public function test_un_200_sin_tareas_es_failed(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $this->assertSame('failed', $response->json('estado'));
    }

    /**
     * Un timeout es `failed`, no lanza, y NO se reintenta (hay alguien mirando la solapa).
     *
     * @return void
     */
    public function test_un_timeout_es_failed_no_lanza_y_no_se_reintenta(): void
    {
        $this->admin_logueado();

        /* Los intentos se cuentan a mano: un stub que lanza no llega a quedar registrado en el fake
         * (Laravel graba el par pedido/respuesta DESPUÉS de que el stub devuelve), así que
         * `Http::assertSentCount()` daría 0 haya habido uno o tres intentos. */
        $intentos = 0;

        $this->fakear_http(['*admin-sync/modelos-ia*' => function () use (&$intentos) {
            $intentos++;

            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds');
        }]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('No se pudo contactar', (string) $response->json('mensaje'));

        $this->assertSame(1, $intentos);
    }

    /**
     * Un 5xx sí se reintenta (puede ser un parpadeo) y, si sigue, es `failed` con el código real.
     *
     * @return void
     */
    public function test_un_500_se_reintenta_y_termina_failed(): void
    {
        $this->admin_logueado();

        config(['services.client_api.retries' => 2]);

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response('Server Error', 500)]);

        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['whatsapp' => 'deepseek_flash']);

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('HTTP 500', (string) $response->json('mensaje'));

        Http::assertSentCount(2);
    }

    /**
     * 🔴 Un 302 NO se sigue: un solo intento, ningún pedido al destino (la `X-Admin-Api-Key` viajaría
     * a otro host) y `failed` con el destino nombrado para corregir la URL.
     *
     * @return void
     */
    public function test_un_302_no_se_sigue_ni_se_reintenta(): void
    {
        $this->admin_logueado();

        config(['services.client_api.retries' => 3]);

        $intentos_al_cliente = 0;
        $pedidos_al_destino  = 0;
        $payload             = $this->payload_del_cliente();

        $this->fakear_http([
            '*admin-sync/modelos-ia*' => function () use (&$intentos_al_cliente) {
                $intentos_al_cliente++;

                return Http::response('', 302, ['Location' => 'https://estacionamiento.test/cualquier-cosa']);
            },
            '*estacionamiento.test*'  => function () use (&$pedidos_al_destino, $payload) {
                $pedidos_al_destino++;

                return Http::response($payload, 200);
            },
        ]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('HTTP 302', (string) $response->json('mensaje'));
        $this->assertStringContainsString('redirección', (string) $response->json('mensaje'));
        $this->assertSame(1, $intentos_al_cliente);
        $this->assertSame(0, $pedidos_al_destino);

        Http::assertNotSent(function ($request) {
            return Str::contains($request->url(), 'estacionamiento.test');
        });
    }

    /**
     * 🔴 Si el cliente devuelve su api_key en un 200 con HTML, el mensaje la tapa.
     *
     * @return void
     */
    public function test_la_clave_en_un_200_con_html_sale_tapada(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response(
            '<html>Error de configuración: ADMIN_API_INBOUND_KEY=clave-del-cliente</html>',
            200
        )]);

        $client = $this->crear_cliente();

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('[clave oculta]', (string) $response->json('mensaje'));
        $this->assertStringNotContainsString('clave-del-cliente', $response->getContent());
    }

    /**
     * 🔴 Si el cliente devuelve su api_key en un 422, la tapa el mensaje Y los errores por tarea.
     *
     * @return void
     */
    public function test_la_clave_en_un_422_sale_tapada_en_el_mensaje_y_en_los_errores(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors'  => ['whatsapp' => ['La opción no vale para la clave clave-del-cliente.']],
        ], 422)]);

        $client = $this->crear_cliente();

        $response = $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['whatsapp' => 'claude_opus']);

        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('[clave oculta]', (string) $response->json('mensaje'));
        $this->assertStringContainsString('[clave oculta]', (string) $response->json('errores.whatsapp.0'));
        $this->assertStringNotContainsString('clave-del-cliente', $response->getContent());
    }

    /**
     * Un cliente sin api_key no sale a la red y lo dice.
     *
     * @return void
     */
    public function test_sin_api_key_no_sale_a_la_red(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response($this->payload_del_cliente(), 200)]);

        $client = $this->crear_cliente('+5493411234567', true, '');

        $response = $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia');

        $this->assertSame('failed', $response->json('estado'));
        $this->assertStringContainsString('api_key', (string) $response->json('mensaje'));

        Http::assertNothingSent();
    }

    /**
     * 🔴 No se persiste nada en el admin: el cliente queda igual después de un PUT exitoso.
     *
     * @return void
     */
    public function test_no_se_persiste_nada_en_el_admin(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/modelos-ia*' => Http::response($this->payload_del_cliente(), 200)]);

        $client = $this->crear_cliente();
        $antes  = Client::findOrFail($client->id)->getAttributes();

        $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['asistente' => 'deepseek_pro'])
            ->assertStatus(200);

        $this->assertSame($antes, Client::findOrFail($client->id)->getAttributes());
    }

    /**
     * Sin sesión de Sanctum, las dos rutas contestan 401.
     *
     * @return void
     */
    public function test_sin_sesion_da_401(): void
    {
        $client = $this->crear_cliente();

        $this->getJson('/api/admin/client/' . $client->id . '/modelos-ia')->assertStatus(401);
        $this->putJson('/api/admin/client/' . $client->id . '/modelos-ia', ['excel' => 'deepseek_pro'])->assertStatus(401);
    }
}
