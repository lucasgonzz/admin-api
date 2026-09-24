<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiPlan;
use App\Models\Client;
use App\Services\ClientAiPlanSyncService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Los paquetes de IA: el ABM del catálogo, la asignación a un cliente y el push a su instancia.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Que el PUT lleve las TRES claves EXACTAS del contrato** (`nombre`, `tope_tokens_mensual`,
 *     `tope_interacciones_diarias`). Es el punto donde el proyecto ya se quemó (`manual_tasks` vs
 *     `tareas`): una clave renombrada del lado del admin deja al receptor leyendo null sin que nada
 *     avise, y el cliente se queda sin tope creyendo que lo tiene.
 *  2. 🔴 **Que un 404 sea `no_soportado` y no un fallo.** Es el caso mayoritario mientras el parque
 *     se actualiza; confundirlo con un error llenaría la ficha de rojos que no hay que mirar.
 *  3. **Que la baja de un paquete sea lógica** (`activo=false`), no física: puede estar asignado a
 *     clientes.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`: el push le pega al `empresa-api` de un cliente
 * real, y el comodín del `setUp()` garantiza que ninguna prueba salga a la red de verdad.
 */
class PaquetesDeIaTest extends BaseDelCanal
{
    /**
     * Admin logueado por Sanctum: las rutas viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de planes';
        $admin->email    = 'planes-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Deja un paquete en la base.
     *
     * @param string   $nombre
     * @param int|null $tope_tokens
     * @param int|null $tope_interacciones
     *
     * @return AiPlan
     */
    private function sembrar_plan(string $nombre = 'Básico', $tope_tokens = 20000000, $tope_interacciones = 20): AiPlan
    {
        return AiPlan::create([
            'nombre'                     => $nombre,
            'precio_usd'                 => 100,
            'tope_tokens_mensual'        => $tope_tokens,
            'tope_interacciones_diarias' => $tope_interacciones,
            'activo'                     => true,
            'orden'                      => 1,
        ]);
    }

    /**
     * El alta crea el paquete y el listado lo devuelve.
     *
     * @return void
     */
    public function test_alta_y_listado_de_un_paquete(): void
    {
        $this->admin_logueado();

        $response = $this->postJson('/api/admin/ai-plan', [
            'nombre'                     => 'Intermedio',
            'precio_usd'                 => 200,
            'tope_tokens_mensual'        => 60000000,
            'tope_interacciones_diarias' => 50,
            'orden'                      => 2,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Intermedio', $response->json('ai_plan.nombre'));

        $id = (int) $response->json('ai_plan.id');
        $this->assertDatabaseHas('ai_plans', ['id' => $id, 'nombre' => 'Intermedio', 'activo' => 1]);

        $nombres = [];
        foreach ($this->getJson('/api/admin/ai-plan')->json('ai_plans') as $plan) {
            $nombres[] = $plan['nombre'];
        }

        $this->assertContains('Intermedio', $nombres);
    }

    /**
     * La edición pisa precio y topes.
     *
     * @return void
     */
    public function test_edicion_de_un_paquete(): void
    {
        $this->admin_logueado();

        $plan = $this->sembrar_plan('Pro', 200000000, 150);

        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                     => 'Pro',
            'precio_usd'                 => 450,
            'tope_tokens_mensual'        => 250000000,
            'tope_interacciones_diarias' => 200,
        ])->assertStatus(200);

        $plan->refresh();
        $this->assertSame('450.00', (string) $plan->precio_usd);
        $this->assertSame(250000000, $plan->tope_tokens_mensual);
        $this->assertSame(200, $plan->tope_interacciones_diarias);
    }

    /**
     * 🔴 La baja es LÓGICA: el paquete queda `activo=false` pero sigue en la base.
     *
     * @return void
     */
    public function test_la_baja_es_logica_no_fisica(): void
    {
        $this->admin_logueado();

        $plan = $this->sembrar_plan('Descartable');

        $this->deleteJson('/api/admin/ai-plan/' . $plan->id)->assertStatus(200);

        // Sigue existiendo, pero inactivo: un DELETE físico dejaría clientes apuntando a un id muerto.
        $this->assertDatabaseHas('ai_plans', ['id' => $plan->id, 'activo' => 0]);
    }

    /**
     * Dos paquetes con el mismo nombre no se permiten (idempotencia del seeder y del ABM).
     *
     * @return void
     */
    public function test_nombre_duplicado_da_422(): void
    {
        $this->admin_logueado();

        $this->sembrar_plan('Básico');

        $this->postJson('/api/admin/ai-plan', [
            'nombre'      => 'Básico',
            'precio_usd'  => 100,
        ])->assertStatus(422);
    }

    /**
     * 🔴 Asignar un paquete lo guarda en el cliente y lo PUSHEA con las tres claves exactas.
     *
     * @return void
     */
    public function test_asignar_paquete_guarda_y_pushea_con_las_tres_claves(): void
    {
        $this->admin_logueado();

        // El receptor del cliente confirma el guardado con {ok:true}.
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico', 20000000, 20);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame('success', $response->json('sincronizacion.estado'));
        $this->assertSame((int) $plan->id, $response->json('ai_plan_id'));

        $client->refresh();
        $this->assertSame((int) $plan->id, (int) $client->ai_plan_id);
        $this->assertSame('success', $client->ai_plan_sync_status);
        $this->assertNotNull($client->ai_plan_synced_at);

        // El PUT viajó con el header y las TRES claves exactas del contrato, con los valores del plan.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'PUT'
                && Str::contains($request->url(), 'admin-sync/plan-ia')
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && array_key_exists('nombre', $body)
                && array_key_exists('tope_tokens_mensual', $body)
                && array_key_exists('tope_interacciones_diarias', $body)
                && $body['nombre'] === 'Básico'
                && (int) $body['tope_tokens_mensual'] === 20000000
                && (int) $body['tope_interacciones_diarias'] === 20;
        });
    }

    /**
     * 🔴 Desasignar (ai_plan_id null) manda las tres claves en null: es un destope explícito.
     *
     * @return void
     */
    public function test_desasignar_manda_las_tres_claves_en_null(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        // Arranca con paquete asignado.
        $client->ai_plan_id = $plan->id;
        $client->save();

        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => null,
        ])->assertStatus(200);

        $client->refresh();
        $this->assertNull($client->ai_plan_id);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return Str::contains($request->url(), 'admin-sync/plan-ia')
                && array_key_exists('nombre', $body)
                && $body['nombre'] === null
                && $body['tope_tokens_mensual'] === null
                && $body['tope_interacciones_diarias'] === null;
        });
    }

    /**
     * 🔴 Un 404 del cliente es `no_soportado`, no un fallo, y no estampa la fecha de push exitoso.
     *
     * @return void
     */
    public function test_el_404_del_cliente_es_no_soportado(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response([], 404)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame('no_soportado', $response->json('sincronizacion.estado'));

        $client->refresh();
        // El paquete SÍ se guardó localmente aunque el push no haya sido soportado.
        $this->assertSame((int) $plan->id, (int) $client->ai_plan_id);
        $this->assertSame('no_soportado', $client->ai_plan_sync_status);
        $this->assertNull($client->ai_plan_synced_at);
    }

    /**
     * Un 401 del cliente es `failed` y el mensaje nombra la api_key.
     *
     * @return void
     */
    public function test_el_401_del_cliente_es_failed_y_nombra_la_api_key(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['message' => 'no'], 401)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('sincronizacion.estado'));
        $this->assertStringContainsStringIgnoringCase('api_key', (string) $response->json('sincronizacion.mensaje'));

        $client->refresh();
        $this->assertSame('failed', $client->ai_plan_sync_status);
        $this->assertNull($client->ai_plan_synced_at);
    }

    /**
     * Un timeout (sin respuesta HTTP) es `failed` y no lanza.
     *
     * @return void
     */
    public function test_un_timeout_es_failed_y_no_lanza(): void
    {
        $this->admin_logueado();

        // Sin reintentos, para que el test sea instantáneo.
        config(['services.client_api.retries' => 1]);

        $this->fakear_http(['*admin-sync/plan-ia*' => function () {
            throw new ConnectionException('timeout simulado en la prueba');
        }]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('sincronizacion.estado'));

        $client->refresh();
        $this->assertSame('failed', $client->ai_plan_sync_status);
        $this->assertNull($client->ai_plan_synced_at);
    }

    /**
     * 🔴 Un 200 que NO confirma el guardado (página genérica del hosting) es `failed`, no success.
     *
     * @return void
     */
    public function test_un_200_sin_ok_es_failed(): void
    {
        $this->admin_logueado();

        // 200 pero sin `ok:true`: el HTML genérico de Hostinger saturado.
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response('<html>saturado</html>', 200)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame('failed', $response->json('sincronizacion.estado'));

        $client->refresh();
        $this->assertNull($client->ai_plan_synced_at);
    }

    /**
     * El botón "Sincronizar ahora" reenvía el plan actual sin cambiar la asignación.
     *
     * @return void
     */
    public function test_sincronizar_ahora_reenvia_el_plan_actual(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');
        $client->ai_plan_id = $plan->id;
        $client->save();

        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync');

        $response->assertStatus(200);
        $this->assertSame('success', $response->json('sincronizacion.estado'));
        $this->assertSame((int) $plan->id, $response->json('ai_plan_id'));

        Http::assertSent(function ($request) {
            return Str::contains($request->url(), 'admin-sync/plan-ia')
                && $request->method() === 'PUT'
                && $request->data()['nombre'] === 'Básico';
        });
    }

    /**
     * El comando de barrido pushea a los clientes activos y deja el desenlace en el cliente.
     *
     * @return void
     */
    public function test_el_comando_de_barrido_pushea_a_los_activos(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente('+5493411112233');
        $plan   = $this->sembrar_plan('Básico');
        $client->ai_plan_id = $plan->id;
        $client->save();

        $this->artisan('ai-planes:sincronizar')->assertExitCode(0);

        $client->refresh();
        $this->assertSame('success', $client->ai_plan_sync_status);
        $this->assertNotNull($client->ai_plan_synced_at);
    }

    /**
     * El tope de búsquedas web diarias (misión asistente-fotos-barras-y-compras, 24/9/2026): el alta
     * lo guarda si viene, y si no viene la columna toma su default de 30 (decisión de Lucas).
     *
     * @return void
     */
    public function test_alta_guarda_el_tope_de_busquedas_web_y_sin_el_toma_30(): void
    {
        $this->admin_logueado();

        $con_valor = $this->postJson('/api/admin/ai-plan', [
            'nombre'                     => 'Con búsquedas',
            'precio_usd'                 => 100,
            'tope_busquedas_web_diarias' => 45,
        ]);

        $con_valor->assertStatus(201);
        $this->assertDatabaseHas('ai_plans', [
            'id'                         => (int) $con_valor->json('ai_plan.id'),
            'tope_busquedas_web_diarias' => 45,
        ]);

        $sin_valor = $this->postJson('/api/admin/ai-plan', [
            'nombre'     => 'Sin búsquedas',
            'precio_usd' => 100,
        ]);

        $sin_valor->assertStatus(201);
        $this->assertSame(30, AiPlan::findOrFail((int) $sin_valor->json('ai_plan.id'))->tope_busquedas_web_diarias);

        // El listado del ABM lo devuelve, que es de donde lo lee la pantalla.
        $topes = [];
        foreach ($this->getJson('/api/admin/ai-plan')->json('ai_plans') as $plan) {
            $topes[$plan['nombre']] = $plan['tope_busquedas_web_diarias'];
        }

        $this->assertSame(45, $topes['Con búsquedas']);
        $this->assertSame(30, $topes['Sin búsquedas']);
    }

    /**
     * La edición pisa el tope de búsquedas web, acepta vaciarlo (null = el defecto de empresa) y
     * rechaza un negativo.
     *
     * @return void
     */
    public function test_edicion_del_tope_de_busquedas_web(): void
    {
        $this->admin_logueado();

        $plan = $this->sembrar_plan('Pro', 200000000, 150);

        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                     => 'Pro',
            'precio_usd'                 => 400,
            'tope_busquedas_web_diarias' => 80,
        ])->assertStatus(200);

        $plan->refresh();
        $this->assertSame(80, $plan->tope_busquedas_web_diarias);

        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                     => 'Pro',
            'precio_usd'                 => 400,
            'tope_busquedas_web_diarias' => null,
        ])->assertStatus(200);

        $plan->refresh();
        $this->assertNull($plan->tope_busquedas_web_diarias);

        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                     => 'Pro',
            'precio_usd'                 => 400,
            'tope_busquedas_web_diarias' => -1,
        ])->assertStatus(422);
    }

    /**
     * 🔴 El PUT lleva la CUARTA clave exacta del contrato, `tope_busquedas_web_diarias`, con el valor
     * del paquete asignado, además de las tres de siempre.
     *
     * @return void
     */
    public function test_asignar_paquete_pushea_el_tope_de_busquedas_web(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico', 20000000, 20);
        $plan->tope_busquedas_web_diarias = 30;
        $plan->save();

        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => $plan->id,
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'PUT'
                && Str::contains($request->url(), 'admin-sync/plan-ia')
                && array_key_exists('nombre', $body)
                && array_key_exists('tope_tokens_mensual', $body)
                && array_key_exists('tope_interacciones_diarias', $body)
                && array_key_exists('tope_busquedas_web_diarias', $body)
                && $body['tope_busquedas_web_diarias'] === 30;
        });
    }

    /**
     * 🔴 Sin paquete, la cuarta clave también viaja, en null (del lado de empresa = su defecto de 30).
     *
     * @return void
     */
    public function test_desasignar_manda_el_tope_de_busquedas_web_en_null(): void
    {
        $this->admin_logueado();

        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $client->ai_plan_id = $plan->id;
        $client->save();

        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', [
            'ai_plan_id' => null,
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return Str::contains($request->url(), 'admin-sync/plan-ia')
                && array_key_exists('tope_busquedas_web_diarias', $body)
                && $body['tope_busquedas_web_diarias'] === null;
        });
    }
}
