<?php

namespace Tests\Feature\AgentesIa;

use App\Models\Admin;
use App\Models\AiPlan;
use App\Models\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Activación automática de «Agentes de IA» al asignarle un plan de IA a un cliente (misión
 * `agentes-ia-extension-unica`, 22/10/2026): `POST api/admin/client/{id}/ai-plan`.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 Es BEST-EFFORT: si la puerta `agentes-ia` del cliente falla (versión vieja, clave rechazada, red caída,
 *     5xx, sin dueño), el plan IGUAL queda asignado y empujado, y el resultado viaja en `agentes_ia`.
 *  2. Se activa (PUT `{activa: true}`) cuando el cliente pasa de ningún plan a uno, o de un plan a otro distinto.
 *  3. NO se activa si se reasigna el mismo plan ni al quitarlo.
 *  4. 🔴 Quitar el plan NO desactiva la extensión: no sale ningún PUT con `activa: false`.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()` (Factory limpia + comodín 200 vacío) y su `crear_cliente()` con
 * `api_url` legacy (`https://api-ferreteria-de-prueba.test`) y clave `clave-del-cliente`.
 */
class ActivacionAlAsignarUnPlanDeIaTest extends BaseDelCanal
{
    /**
     * Admin logueado por Sanctum.
     *
     * @return void
     */
    private function admin_logueado(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de planes';
        $admin->email    = 'planes-agentes-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);
    }

    /**
     * Deja un paquete en la base.
     *
     * @param string $nombre Nombre único del paquete.
     *
     * @return AiPlan
     */
    private function sembrar_plan(string $nombre): AiPlan
    {
        return AiPlan::create([
            'nombre'                     => $nombre,
            'precio_usd'                 => 100,
            'tope_tokens_mensual'        => 20000000,
            'tope_interacciones_diarias' => 20,
            'activo'                     => true,
            'orden'                      => 1,
        ]);
    }

    /**
     * El cliente confirma el plan y se comporta como la puerta de agentes-ia indique.
     *
     * @param mixed $puerta Respuesta de `agentes-ia` (una `Http::response()` o un callable que lanza).
     *
     * @return void
     */
    private function el_cliente_contesta($puerta): void
    {
        $this->fakear_http([
            '*admin-sync/plan-ia*'    => Http::response(['ok' => true], 200),
            '*admin-sync/agentes-ia*' => $puerta,
        ]);
    }

    /**
     * @param Client $client  Cliente.
     * @param mixed  $plan_id Id del plan o null.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function asignar(Client $client, $plan_id)
    {
        return $this->postJson('/api/admin/client/' . $client->id . '/ai-plan', ['ai_plan_id' => $plan_id]);
    }

    /**
     * @return int Cantidad de pedidos a la puerta agentes-ia que salieron.
     */
    private function pedidos_a_agentes_ia(): int
    {
        $cuenta = 0;

        // `Http::recorded()` y no `assertSent()`: este último falla si no salió NINGÚN pedido, y acá "cero" es válido.
        foreach (Http::recorded() as $par) {
            if (Str::contains($par[0]->url(), 'admin-sync/agentes-ia')) {
                $cuenta++;
            }
        }

        return $cuenta;
    }

    /**
     * 🔴 De ningún plan a uno: se empuja el plan y DESPUÉS se activa la extensión, y la respuesta lo dice.
     *
     * @return void
     */
    public function test_al_pasar_de_ningun_plan_a_uno_se_activa_agentes_ia(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertSame((int) $plan->id, $respuesta->json('ai_plan_id'));
        $this->assertSame('success', $respuesta->json('sincronizacion.estado'));
        $this->assertSame('ok', $respuesta->json('agentes_ia.estado'));
        $this->assertTrue($respuesta->json('agentes_ia.activa'));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && Str::contains($request->url(), 'admin-sync/agentes-ia')
                && $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente')
                && $request->data() === ['activa' => true];
        });

        // Y el orden: primero el plan, después la extensión.
        $urls = [];
        foreach (Http::recorded() as $par) {
            $urls[] = $par[0]->url();
        }
        $this->assertStringContainsString('admin-sync/plan-ia', $urls[0]);
        $this->assertStringContainsString('admin-sync/agentes-ia', $urls[1]);
    }

    /**
     * De un plan a otro distinto: también se activa.
     *
     * @return void
     */
    public function test_al_pasar_de_un_plan_a_otro_distinto_se_activa_agentes_ia(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        $client = $this->crear_cliente();
        $basico = $this->sembrar_plan('Básico');
        $pro    = $this->sembrar_plan('Pro');

        $client->ai_plan_id = $basico->id;
        $client->save();

        $respuesta = $this->asignar($client, $pro->id);

        $respuesta->assertStatus(200);
        $this->assertSame('ok', $respuesta->json('agentes_ia.estado'));
        $this->assertSame(1, $this->pedidos_a_agentes_ia());
    }

    /**
     * Reasignar el MISMO plan no vuelve a activar nada (para eso está el botón de la ficha).
     *
     * @return void
     */
    public function test_reasignar_el_mismo_plan_no_toca_agentes_ia(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $client->ai_plan_id = $plan->id;
        $client->save();

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('agentes_ia'));
        $this->assertSame(0, $this->pedidos_a_agentes_ia());
    }

    /**
     * 🔴 Quitar el plan NO desactiva la extensión: ningún pedido a la puerta agentes-ia, y menos uno con `false`.
     *
     * @return void
     */
    public function test_quitar_el_plan_no_desactiva_agentes_ia(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => false], 200));

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $client->ai_plan_id = $plan->id;
        $client->save();

        $respuesta = $this->asignar($client, null);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('ai_plan_id'));
        $this->assertNull($respuesta->json('agentes_ia'));
        $this->assertNull($client->refresh()->ai_plan_id);

        // El destope del plan sí viajó; a agentes-ia no salió nada.
        Http::assertSent(function (Request $request) {
            return Str::contains($request->url(), 'admin-sync/plan-ia');
        });
        $this->assertSame(0, $this->pedidos_a_agentes_ia());
    }

    /**
     * 🔴 Best-effort: con la puerta caída de la forma que sea, el plan queda asignado, empujado, y el resultado
     * explica por qué no se activó.
     *
     * @return void
     */
    public function test_si_la_puerta_falla_el_plan_igual_queda_asignado_y_se_informa_el_motivo(): void
    {
        $casos = [
            'version_vieja'   => Http::response('', 404),
            'version_vieja_2' => Http::response('', 405),
            'clave_rechazada' => Http::response(['error' => 'unauthorized'], 401),
            'sin_conexion_5'  => Http::response('boom', 500),
            'sin_conexion_9'  => Http::response(['error' => 'sin dueno'], 409),
            'sin_conexion_b'  => Http::response('<html>hosting</html>', 200),
        ];

        $esperado = [
            'version_vieja'   => 'version_vieja',
            'version_vieja_2' => 'version_vieja',
            'clave_rechazada' => 'clave_rechazada',
            'sin_conexion_5'  => 'sin_conexion',
            'sin_conexion_9'  => 'sin_conexion',
            'sin_conexion_b'  => 'sin_conexion',
        ];

        foreach ($casos as $nombre => $puerta) {
            $this->admin_logueado();
            $this->el_cliente_contesta($puerta);

            $client = $this->crear_cliente();
            $plan   = $this->sembrar_plan('Plan ' . $nombre . ' ' . Str::random(5));

            $respuesta = $this->asignar($client, $plan->id);

            $respuesta->assertStatus(200);
            $this->assertSame($esperado[$nombre], $respuesta->json('agentes_ia.estado'), $nombre);
            $this->assertNull($respuesta->json('agentes_ia.activa'), $nombre);
            $this->assertNotSame('', (string) $respuesta->json('agentes_ia.mensaje'), $nombre);

            // El plan quedó asignado y empujado a pesar de todo.
            $this->assertSame((int) $plan->id, $respuesta->json('ai_plan_id'), $nombre);
            $this->assertSame('success', $respuesta->json('sincronizacion.estado'), $nombre);
            $client->refresh();
            $this->assertSame((int) $plan->id, (int) $client->ai_plan_id, $nombre);
            $this->assertSame('success', $client->ai_plan_sync_status, $nombre);
        }
    }

    /**
     * 🔴 Best-effort con una caída de red (la excepción de la conexión no cruza hacia la asignación).
     *
     * @return void
     */
    public function test_si_la_puerta_corta_la_conexion_el_plan_igual_queda_asignado(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertSame('sin_conexion', $respuesta->json('agentes_ia.estado'));
        $this->assertSame((int) $plan->id, $respuesta->json('ai_plan_id'));
        $this->assertSame((int) $plan->id, (int) $client->refresh()->ai_plan_id);
    }

    /**
     * 🔴 Si el servicio de agentes IA lanzara una excepción inesperada, la asignación sigue andando.
     *
     * @return void
     */
    public function test_una_excepcion_inesperada_al_activar_no_rompe_la_asignacion(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        // Un resolver que explota: simula un fallo del lado del admin (base, configuración) justo al activar.
        $this->app->bind(\App\Services\ClientAgentesIaService::class, function () {
            return new \App\Services\ClientAgentesIaService(new class extends \App\Services\ClientEmpresaApiUrlResolver {
                public function resolve_base_url(Client $client, ?\App\Models\ClientVersionUpgrade $upgrade = null): string
                {
                    throw new \RuntimeException('el resolver explotó');
                }
            });
        });

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertSame('sin_conexion', $respuesta->json('agentes_ia.estado'));
        $this->assertSame((int) $plan->id, $respuesta->json('ai_plan_id'));
        $this->assertSame((int) $plan->id, (int) $client->refresh()->ai_plan_id);
        $this->assertSame('success', $client->ai_plan_sync_status);
    }

    /**
     * Cliente sin clave en el admin: el plan no se puede empujar ni la extensión activar, pero la asignación se
     * guarda y el resultado dice `sin_clave`.
     *
     * @return void
     */
    public function test_cliente_sin_clave_asigna_el_plan_y_dice_sin_clave(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        $client = $this->crear_cliente('+5493411234567', true, '');
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertSame('sin_clave', $respuesta->json('agentes_ia.estado'));
        $this->assertSame((int) $plan->id, (int) $client->refresh()->ai_plan_id);
        $this->assertSame(0, $this->pedidos_a_agentes_ia());
    }

    /**
     * 🔴 Si el push del plan falló por TRANSPORTE (el cliente no contestó), la activación NO se intenta (no suma otro
     * timeout) y la respuesta lo dice; el plan igual queda asignado.
     *
     * @return void
     */
    public function test_si_el_push_del_plan_falla_por_transporte_no_se_intenta_activar(): void
    {
        $this->admin_logueado();
        $this->fakear_http([
            '*admin-sync/plan-ia*'    => function () {
                throw new ConnectionException('cURL error 28: timeout');
            },
            '*admin-sync/agentes-ia*' => Http::response(['ok' => true, 'activa' => true], 200),
        ]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertSame('failed', $respuesta->json('sincronizacion.estado'));
        $this->assertSame('sin_conexion', $respuesta->json('agentes_ia.estado'));
        $this->assertNull($respuesta->json('agentes_ia.activa'));
        $this->assertStringContainsString('No se intentó activar Agentes de IA', (string) $respuesta->json('agentes_ia.mensaje'));
        $this->assertSame(0, $this->pedidos_a_agentes_ia());
        $this->assertSame((int) $plan->id, (int) $client->refresh()->ai_plan_id);
    }

    /**
     * Si el push del plan falló por versión vieja (404: el cliente SÍ contestó), la activación se intenta igual.
     *
     * @return void
     */
    public function test_si_el_push_del_plan_da_404_se_intenta_activar_igual(): void
    {
        $this->admin_logueado();
        $this->fakear_http([
            '*admin-sync/plan-ia*'    => Http::response('', 404),
            '*admin-sync/agentes-ia*' => Http::response('', 404),
        ]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $respuesta->assertStatus(200);
        $this->assertSame('no_soportado', $respuesta->json('sincronizacion.estado'));
        $this->assertSame('version_vieja', $respuesta->json('agentes_ia.estado'));
        $this->assertSame(1, $this->pedidos_a_agentes_ia());
    }

    /**
     * Un 4xx funcional del push del plan (401) también es una respuesta del cliente: se intenta activar.
     *
     * @return void
     */
    public function test_si_el_push_del_plan_da_401_se_intenta_activar_igual(): void
    {
        $this->admin_logueado();
        $this->fakear_http([
            '*admin-sync/plan-ia*'    => Http::response(['error' => 'unauthorized'], 401),
            '*admin-sync/agentes-ia*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $respuesta = $this->asignar($client, $plan->id);

        $this->assertSame('clave_rechazada', $respuesta->json('agentes_ia.estado'));
        $this->assertSame(1, $this->pedidos_a_agentes_ia());
    }

    /**
     * 🔴 El log de la activación fallida no lleva la clave ni la query de las URLs (mismo criterio que el servicio).
     *
     * @return void
     */
    public function test_el_log_de_una_excepcion_inesperada_no_lleva_la_clave_ni_la_query(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        \Illuminate\Support\Facades\Log::spy();

        $this->app->bind(\App\Services\ClientAgentesIaService::class, function () {
            return new \App\Services\ClientAgentesIaService(new class extends \App\Services\ClientEmpresaApiUrlResolver {
                public function resolve_base_url(Client $client, ?\App\Models\ClientVersionUpgrade $upgrade = null): string
                {
                    throw new \RuntimeException('falló https://api-x.test/api/admin-sync/agentes-ia?token=QUERYSECRETA con clave-del-cliente');
                }
            });
        });

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $this->asignar($client, $plan->id)->assertStatus(200);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto) {
            $error = (string) ($contexto['error'] ?? '');

            return strpos($mensaje, 'activación automática') !== false
                && $error !== ''
                && strpos($error, 'QUERYSECRETA') === false
                && strpos($error, 'clave-del-cliente') === false;
        })->once();
    }

    /**
     * El "Sincronizar ahora" del plan no activa nada: reenvía el plan y punto.
     *
     * @return void
     */
    public function test_sincronizar_ahora_no_toca_agentes_ia(): void
    {
        $this->admin_logueado();
        $this->el_cliente_contesta(Http::response(['ok' => true, 'activa' => true], 200));

        $client = $this->crear_cliente();
        $plan   = $this->sembrar_plan('Básico');

        $client->ai_plan_id = $plan->id;
        $client->save();

        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync')->assertStatus(200);

        $this->assertSame(0, $this->pedidos_a_agentes_ia());
    }
}
