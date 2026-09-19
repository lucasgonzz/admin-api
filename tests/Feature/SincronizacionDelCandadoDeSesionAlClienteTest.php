<?php

namespace Tests\Feature;

use App\Jobs\SyncClientSessionLockJob;
use App\Models\Admin;
use App\Models\Client;
use App\Services\ClientSessionLockSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El push del candado de sesión por pestaña al empresa-api de cada cliente (misión
 * candado-sesion-por-pestana, 19/9/2026).
 *
 * 🔴 Calcado de `SincronizacionDeHorariosAlClienteTest`, misma estructura y mismas guardas —
 * leer ese archivo primero si algo de acá no queda claro. Lo que estos tests protegen, en orden:
 *
 *  1. Que el push NO corra adentro del request que guarda el interruptor, y que se encole en la
 *     conexión `database` (se afirma la CONEXIÓN explícita, no solo que se despachó: un
 *     `assertPushed` pelado pasaría igual con un `dispatch()` sin `onConnection`).
 *  2. Que el payload viaje `{ bloquear_pestanas_duplicadas: bool }`, tal cual el contrato.
 *  3. Que el 404 —el caso ESPERADO hasta que salga la mitad de empresa-api— se degrade limpio a
 *     `manual_required` sin lanzar excepción y sin pisar `pestanas_synced_at`.
 *  4. Los otros tres desenlaces (success, skipped, failed) y que el job tolere un cliente borrado.
 */
class SincronizacionDelCandadoDeSesionAlClienteTest extends TestCase
{
    use DatabaseTransactions;

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Admin logueado por Sanctum (las rutas admin/* viven bajo auth:sanctum).
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de sincronización de candado';
        $admin->email    = 'sync-candado-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente mínimo con URL y api_key cargadas (los dos cortes previos al HTTP resueltos).
     *
     * @param bool $activo Si el cliente está activo.
     *
     * @return Client
     */
    private function crear_cliente(bool $activo = true): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente sync candado';
        $client->slug            = 'cliente-sync-candado-' . Str::random(8);
        $client->api_url         = 'https://api-cliente-sync-candado.test';
        $client->api_key         = 'clave-api-del-cliente';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = $activo;
        $client->save();

        return $client;
    }

    /**
     * El servicio, resuelto por el contenedor.
     *
     * @return ClientSessionLockSyncService
     */
    private function servicio(): ClientSessionLockSyncService
    {
        return app(ClientSessionLockSyncService::class);
    }

    // ---------------------------------------------------------------------
    // El PUT encola y no llama a nadie adentro del request
    // ---------------------------------------------------------------------

    /**
     * Guardar el interruptor encola el push en la conexión `database` y no hace ningún HTTP
     * adentro del request.
     *
     * 🔴 Se afirma la CONEXIÓN, no sólo que se despachó, por el mismo motivo que el test gemelo de
     * horarios: `QueueFake::connection()` devuelve `$this` sin mirar el nombre.
     */
    public function test_guardar_el_interruptor_encola_el_push_en_la_conexion_database_y_no_llama_a_nadie()
    {
        Queue::fake();
        Http::fake();

        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson('/api/admin/client/' . $client->id . '/candado-sesion', [
            'bloquear_pestanas_duplicadas' => true,
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('bloquear_pestanas_duplicadas'));

        $encolados = 0;

        Queue::assertPushed(SyncClientSessionLockJob::class, function ($job) use (&$encolados) {
            $encolados++;

            // 🔴 La conexión explícita es TODO el punto: sin esto el push correría inline.
            return $job->connection === 'database';
        });

        $this->assertSame(1, $encolados, 'Se esperaba exactamente un SyncClientSessionLockJob encolado.');

        // El request no habló con nadie: el push quedó encolado, no ejecutado.
        Http::assertNothingSent();

        // El interruptor SÍ quedó guardado (eso no depende del push).
        $this->assertTrue((bool) $client->refresh()->bloquear_pestanas_duplicadas);

        // Y no se escribió ningún estado de sincronización: todavía no se intentó nada.
        $this->assertNull($client->pestanas_sync_status);
    }

    /** Un body sin la clave (o con ella en null) es 422 y no guarda ni encola nada. */
    public function test_un_body_vacio_no_pisa_el_valor_guardado()
    {
        Queue::fake();

        $this->admin_logueado();
        $client = $this->crear_cliente();
        $client->bloquear_pestanas_duplicadas = true;
        $client->save();

        $respuesta = $this->putJson('/api/admin/client/' . $client->id . '/candado-sesion', []);

        $respuesta->assertStatus(422);
        $this->assertSame('payload_vacio', $respuesta->json('error'));

        // El valor guardado no se tocó.
        $this->assertTrue((bool) $client->refresh()->bloquear_pestanas_duplicadas);

        Queue::assertNotPushed(SyncClientSessionLockJob::class);
    }

    /** El endpoint de reintento a mano encola con la misma conexión y no llama a nadie. */
    public function test_el_reintento_a_mano_encola_con_la_misma_conexion()
    {
        Queue::fake();
        Http::fake();

        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->postJson('/api/admin/client/' . $client->id . '/candado-sesion/sync');

        $respuesta->assertStatus(202);
        $this->assertTrue($respuesta->json('encolado'));

        $encolados = 0;

        Queue::assertPushed(SyncClientSessionLockJob::class, function ($job) use (&$encolados) {
            $encolados++;

            return $job->connection === 'database';
        });

        $this->assertSame(1, $encolados, 'Se esperaba exactamente un SyncClientSessionLockJob encolado.');
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // Los cuatro desenlaces del push
    // ---------------------------------------------------------------------

    /**
     * Un 404 del cliente degrada a `manual_required` con el motivo cargado, sin tocar
     * `pestanas_synced_at` y sin lanzar excepción.
     */
    public function test_un_404_del_cliente_degrada_a_manual_required_sin_tocar_la_fecha()
    {
        Http::fake([
            '*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $client = $this->crear_cliente();
        $client->bloquear_pestanas_duplicadas = true;
        $client->save();

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientSessionLockSyncService::ESTADO_MANUAL_REQUIRED, $resultado['status']);

        $client->refresh();
        $this->assertSame('manual_required', (string) $client->pestanas_sync_status);
        $this->assertNotEmpty((string) $client->pestanas_sync_message);
        $this->assertStringContainsString('404', (string) $client->pestanas_sync_message);
        $this->assertNull($client->pestanas_synced_at);

        // Guarda propia: se midió una llamada real al endpoint del contrato, no otra cosa.
        $enviadas = 0;
        Http::assertSent(function ($request) use (&$enviadas) {
            $enviadas++;

            return strpos($request->url(), 'api/admin-sync/session-lock') !== false;
        });
        $this->assertGreaterThanOrEqual(1, $enviadas, 'Se esperaba al menos una llamada HTTP.');
    }

    /**
     * Un 200 del cliente deja `success`, estampa `pestanas_synced_at` y el payload viaja
     * `{ bloquear_pestanas_duplicadas: true }` con la api_key en el header.
     */
    public function test_un_200_del_cliente_deja_success_y_estampa_la_fecha()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = $this->crear_cliente();
        $client->bloquear_pestanas_duplicadas = true;
        $client->save();

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientSessionLockSyncService::ESTADO_SUCCESS, $resultado['status']);
        $this->assertNull($resultado['message']);

        $client->refresh();
        $this->assertSame('success', (string) $client->pestanas_sync_status);
        $this->assertNull($client->pestanas_sync_message);
        $this->assertNotNull($client->pestanas_synced_at);

        $verificadas = 0;

        Http::assertSent(function ($request) use (&$verificadas, $client) {
            if (strpos($request->url(), 'api/admin-sync/session-lock') === false) {
                return false;
            }

            $verificadas++;

            $this->assertSame('PUT', $request->method());
            $this->assertSame($client->api_key, $request->header('X-Admin-Api-Key')[0]);
            $this->assertTrue($request->data()['bloquear_pestanas_duplicadas']);

            return true;
        });

        // Guarda propia: si el closure no hubiera corrido, assertSent pasaría sin verificar nada.
        $this->assertGreaterThanOrEqual(1, $verificadas, 'Se esperaba al menos una llamada verificada.');
    }

    /**
     * Un cliente inactivo se saltea: `skipped` y CERO llamadas HTTP.
     */
    public function test_un_cliente_inactivo_se_saltea_sin_llamar_a_nadie()
    {
        Http::fake();

        $client = $this->crear_cliente(false);

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientSessionLockSyncService::ESTADO_SKIPPED, $resultado['status']);

        $client->refresh();
        $this->assertSame('skipped', (string) $client->pestanas_sync_status);
        $this->assertNotEmpty((string) $client->pestanas_sync_message);
        $this->assertNull($client->pestanas_synced_at);

        Http::assertNothingSent();
    }

    /** Sin api_key el push ni se intenta: `manual_required` y cero llamadas. */
    public function test_sin_api_key_no_se_intenta_el_push()
    {
        Http::fake();

        $client          = $this->crear_cliente();
        $client->api_key = '';
        $client->save();

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientSessionLockSyncService::ESTADO_MANUAL_REQUIRED, $resultado['status']);
        $this->assertSame('manual_required', (string) $client->refresh()->pestanas_sync_status);

        Http::assertNothingSent();
    }

    /** Un 500 del cliente termina en `failed` (no en manual_required) y no lanza excepción. */
    public function test_un_500_del_cliente_termina_en_failed()
    {
        Http::fake([
            '*' => Http::response('explotó', 500),
        ]);

        $client = $this->crear_cliente();

        $resultado = $this->servicio()->sync($client);

        $this->assertSame(ClientSessionLockSyncService::ESTADO_FAILED, $resultado['status']);

        $client->refresh();
        $this->assertSame('failed', (string) $client->pestanas_sync_status);
        $this->assertStringContainsString('500', (string) $client->pestanas_sync_message);
        $this->assertNull($client->pestanas_synced_at);
    }

    /** El job lee el cliente por id al correr y delega en el servicio; un cliente borrado no rompe. */
    public function test_el_job_corre_el_push_y_tolera_un_cliente_inexistente()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $client = $this->crear_cliente();

        $job = new SyncClientSessionLockJob($client->id);
        $job->handle($this->servicio());

        $this->assertSame('success', (string) $client->refresh()->pestanas_sync_status);

        // Con un id que no existe el job se va sin hacer nada: no lanza y no llama a nadie de más.
        $llamadas_antes = 0;
        Http::assertSent(function () use (&$llamadas_antes) {
            $llamadas_antes++;

            return true;
        });

        $job_fantasma = new SyncClientSessionLockJob(999999999);
        $job_fantasma->handle($this->servicio());

        $llamadas_despues = 0;
        Http::assertSent(function () use (&$llamadas_despues) {
            $llamadas_despues++;

            return true;
        });

        $this->assertSame($llamadas_antes, $llamadas_despues, 'El cliente inexistente no puede generar llamadas.');
    }
}
