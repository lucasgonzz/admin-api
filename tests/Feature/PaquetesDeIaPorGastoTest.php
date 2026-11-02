<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiPlan;
use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * El tope de gasto en dólares de los paquetes de IA (misión plan-ia-por-gasto, 2/11/2026), del lado
 * del admin: el ABM, el contrato del PUT con el `empresa-api`, el comando que reenvía los precios y el
 * gasto del mes en la solapa del cliente.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Que las dos claves nuevas viajen con el nombre EXACTO del contrato** (`tope_gasto_usd_mensual`,
 *     `precios_ia` y sus cuatro puntas): una clave renombrada deja al receptor leyendo null sin que nada
 *     avise, y el cliente sigue cortando por tokens creyendo que corta por plata.
 *  2. 🔴 **Que el campo nazca en null y nada cambie.** El valor lo decide Lucas después: hasta entonces
 *     un paquete viaja sin tope de gasto, y las cuatro claves de siempre siguen iguales.
 *  3. 🔴 **Que `--dry-run` no le hable a nadie.** Es lo que se corre para mirar antes de disparar el
 *     barrido contra clientes reales.
 *  4. **Que el gasto del mes sea del mes calendario**, no del rango que se está mirando.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`: el push le pega al `empresa-api` de un cliente real,
 * y el comodín del `setUp()` garantiza que ninguna prueba salga a la red de verdad.
 */
class PaquetesDeIaPorGastoTest extends BaseDelCanal
{
    /**
     * Devuelve el reloj a la normalidad, para no contaminar a las pruebas que siguen.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Admin logueado por Sanctum: las rutas viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de gasto';
        $admin->email    = 'gasto-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Deja un paquete en la base. Por defecto sin tope de gasto, que es como nace en producción.
     *
     * @param string     $nombre
     * @param float|null $tope_gasto
     *
     * @return AiPlan
     */
    private function sembrar_plan(string $nombre = 'Agentes Light', $tope_gasto = null): AiPlan
    {
        return AiPlan::create([
            'nombre'                     => $nombre,
            'precio_usd'                 => 6.41,
            'tope_tokens_mensual'        => 50000000,
            'tope_gasto_usd_mensual'     => $tope_gasto,
            'tope_interacciones_diarias' => 100,
            'activo'                     => true,
            'orden'                      => 1,
        ]);
    }

    /**
     * Crea un cliente con un teléfono propio y, si se pide, con paquete asignado.
     *
     * @param string      $telefono
     * @param AiPlan|null $plan
     *
     * @return Client
     */
    private function cliente_con_plan(string $telefono, $plan = null): Client
    {
        $client = $this->crear_cliente($telefono);

        if ($plan !== null) {
            $client->ai_plan_id = $plan->id;
            $client->save();
        }

        return $client;
    }

    /**
     * Deja una fila de consumo ya espejada en el admin, con entrada y salida.
     *
     * @param Client $client
     * @param string $fecha
     * @param string $modelo
     * @param int    $input_tokens
     * @param int    $output_tokens
     *
     * @return ClientAiTokenUsage
     */
    private function sembrar_consumo(Client $client, string $fecha, string $modelo, int $input_tokens, int $output_tokens = 0): ClientAiTokenUsage
    {
        $fila                              = new ClientAiTokenUsage();
        $fila->client_id                   = $client->id;
        $fila->fecha                       = $fecha;
        $fila->proceso                     = 'chat_mensaje';
        $fila->proveedor                   = 'anthropic';
        $fila->modelo                      = $modelo;
        $fila->llamadas                    = 1;
        $fila->input_tokens                = $input_tokens;
        $fila->output_tokens               = $output_tokens;
        $fila->cache_creation_input_tokens = 0;
        $fila->cache_read_input_tokens     = 0;
        $fila->save();

        return $fila;
    }

    /**
     * El cuerpo del único PUT del plan que salió en esta prueba.
     *
     * @return array<string, mixed>
     */
    private function cuerpo_del_put(): array
    {
        $puts = Http::recorded(function (PedidoHttp $request) {
            return $request->method() === 'PUT' && Str::contains($request->url(), 'admin-sync/plan-ia');
        });

        $this->assertCount(1, $puts);

        // `recorded()` filtra conservando las claves: el único PUT no tiene por qué ser el índice 0.
        return $puts->values()->first()[0]->data();
    }

    /**
     * 🔴 La columna existe y es nullable, que es lo que garantiza que nazca en null en producción.
     *
     * @return void
     */
    public function test_la_columna_existe_y_un_paquete_nuevo_nace_sin_tope_de_gasto(): void
    {
        $this->assertTrue(Schema::hasColumn('ai_plans', 'tope_gasto_usd_mensual'));

        $this->admin_logueado();

        $response = $this->postJson('/api/admin/ai-plan', [
            'nombre'     => 'Sin tope de gasto',
            'precio_usd' => 100,
        ]);

        $response->assertStatus(201);
        $this->assertNull(AiPlan::findOrFail((int) $response->json('ai_plan.id'))->tope_gasto_usd_mensual);
    }

    /**
     * El alta lo guarda con sus centavos y el listado del ABM lo devuelve.
     *
     * @return void
     */
    public function test_el_alta_guarda_el_tope_de_gasto_y_el_listado_lo_devuelve(): void
    {
        $this->admin_logueado();

        $response = $this->postJson('/api/admin/ai-plan', [
            'nombre'                 => 'Con tope de gasto',
            'precio_usd'             => 100,
            'tope_gasto_usd_mensual' => 6.41,
        ]);

        $response->assertStatus(201);
        $this->assertSame('6.41', AiPlan::findOrFail((int) $response->json('ai_plan.id'))->tope_gasto_usd_mensual);

        $topes = [];
        foreach ($this->getJson('/api/admin/ai-plan')->json('ai_plans') as $plan) {
            $topes[$plan['nombre']] = $plan['tope_gasto_usd_mensual'];
        }

        $this->assertSame('6.41', $topes['Con tope de gasto']);
    }

    /**
     * La edición lo pisa y lo vacía. Y 🔴 un PUT que no trae la clave NO lo borra: es el SPA que quedó
     * abierto desde antes del deploy, y no puede quitarle el tope a un paquete sin que nadie lo pida.
     *
     * @return void
     */
    public function test_la_edicion_pisa_vacia_y_sin_la_clave_no_toca_el_tope_de_gasto(): void
    {
        $this->admin_logueado();

        $plan = $this->sembrar_plan('Pro');

        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                 => 'Pro',
            'precio_usd'             => 400,
            'tope_gasto_usd_mensual' => 12.5,
        ])->assertStatus(200);

        $plan->refresh();
        $this->assertSame('12.50', $plan->tope_gasto_usd_mensual);

        // Sin la clave: queda como estaba.
        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'     => 'Pro',
            'precio_usd' => 450,
        ])->assertStatus(200);

        $plan->refresh();
        $this->assertSame('12.50', $plan->tope_gasto_usd_mensual);

        // Con la clave en null: se vacía, que es "sin tope de gasto".
        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                 => 'Pro',
            'precio_usd'             => 450,
            'tope_gasto_usd_mensual' => null,
        ])->assertStatus(200);

        $plan->refresh();
        $this->assertNull($plan->tope_gasto_usd_mensual);
    }

    /**
     * La validación rechaza lo que no es un monto, lo negativo y lo que no entra en `decimal(10,2)`.
     * Sin el techo, el último caso sería un 500 de la base en vez de un 422 que diga qué pasó.
     *
     * @return void
     */
    public function test_la_validacion_rechaza_un_tope_de_gasto_invalido(): void
    {
        $this->admin_logueado();

        $plan = $this->sembrar_plan('Validado');

        foreach ([-1, 'mucho', 100000000] as $invalido) {
            $response = $this->putJson('/api/admin/ai-plan/' . $plan->id, [
                'nombre'                 => 'Validado',
                'precio_usd'             => 100,
                'tope_gasto_usd_mensual' => $invalido,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('tope_gasto_usd_mensual');
        }

        // El cero es válido: es "sin tope de gasto", igual que null, y lo decide el otro lado.
        $this->putJson('/api/admin/ai-plan/' . $plan->id, [
            'nombre'                 => 'Validado',
            'precio_usd'             => 100,
            'tope_gasto_usd_mensual' => 0,
        ])->assertStatus(200);
    }

    /**
     * 🔴 El cuerpo CON paquete y tope lleva las dos claves nuevas con el nombre exacto, el tope como
     * NÚMERO (el cast del modelo lo lee como string) y las cuatro claves de siempre intactas.
     *
     * @return void
     */
    public function test_el_cuerpo_con_paquete_lleva_el_tope_de_gasto_y_los_precios(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $plan   = $this->sembrar_plan('Agentes Light', 6.41);
        $client = $this->cliente_con_plan('+5493411112201', $plan);

        $this->admin_logueado();
        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync')->assertStatus(200);

        $cuerpo = $this->cuerpo_del_put();

        $this->assertSame(6.41, $cuerpo['tope_gasto_usd_mensual']);

        // Las cuatro de siempre, sin renombrar y con sus valores.
        $this->assertSame('Agentes Light', $cuerpo['nombre']);
        $this->assertSame(50000000, $cuerpo['tope_tokens_mensual']);
        $this->assertSame(100, $cuerpo['tope_interacciones_diarias']);
        $this->assertArrayHasKey('tope_busquedas_web_diarias', $cuerpo);

        $this->assertIsArray($cuerpo['precios_ia']);
    }

    /**
     * 🔴 `precios_ia` es la tabla de la config: todos sus modelos, con las cuatro puntas exactas del
     * contrato y los mismos números. Se compara contra la config y no contra un valor escrito acá:
     * lo que se protege es que viaje lo que está cargado, no cuánto vale hoy.
     *
     * @return void
     */
    public function test_precios_ia_es_la_tabla_de_la_config_con_las_cuatro_puntas(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->cliente_con_plan('+5493411112202', $this->sembrar_plan());

        $this->admin_logueado();
        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync')->assertStatus(200);

        $precios = $this->cuerpo_del_put()['precios_ia'];

        $this->assertSame(array_keys(config('ia_precios')), array_keys($precios));

        foreach (config('ia_precios') as $modelo => $tarifa) {
            $this->assertEqualsCanonicalizing(
                ['input', 'output', 'cache_write', 'cache_read'],
                array_keys($precios[$modelo]),
                'Las puntas de ' . $modelo . ' no son las del contrato.'
            );

            foreach (['input', 'output', 'cache_write', 'cache_read'] as $punta) {
                $this->assertEqualsWithDelta($tarifa[$punta], $precios[$modelo][$punta], 0.0000001, $modelo . ' / ' . $punta);
            }
        }
    }

    /**
     * 🔴 Un paquete SIN tope de gasto (el caso de hoy, hasta que Lucas cargue el valor) manda el tope en
     * null, y la clave viaja: del otro lado, null es "sin tope de gasto", todo sigue como hoy.
     *
     * @return void
     */
    public function test_un_paquete_sin_tope_de_gasto_lo_manda_en_null_y_lleva_los_precios(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->cliente_con_plan('+5493411112203', $this->sembrar_plan());

        $this->admin_logueado();
        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync')->assertStatus(200);

        $cuerpo = $this->cuerpo_del_put();

        $this->assertArrayHasKey('tope_gasto_usd_mensual', $cuerpo);
        $this->assertNull($cuerpo['tope_gasto_usd_mensual']);
        $this->assertNotEmpty($cuerpo['precios_ia']);
        $this->assertSame(50000000, $cuerpo['tope_tokens_mensual']);
    }

    /**
     * 🔴 SIN paquete viajan las seis claves en null: es un destope explícito, y las claves tienen que
     * estar (si faltaran, el receptor no tocaría sus columnas y el tope de gasto quedaría colgado).
     *
     * @return void
     */
    public function test_sin_paquete_las_dos_claves_nuevas_van_en_null(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->cliente_con_plan('+5493411112204');

        $this->admin_logueado();
        $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync')->assertStatus(200);

        $cuerpo = $this->cuerpo_del_put();

        foreach (['nombre', 'tope_tokens_mensual', 'tope_interacciones_diarias', 'tope_busquedas_web_diarias', 'tope_gasto_usd_mensual', 'precios_ia'] as $clave) {
            $this->assertArrayHasKey($clave, $cuerpo);
            $this->assertNull($cuerpo[$clave], 'La clave ' . $clave . ' tenía que viajar en null.');
        }
    }

    /**
     * 🔴 Una versión vieja de `empresa-api` no conoce las claves nuevas y contesta 200 igual: el push es
     * un éxito común y corriente. Es la mitad "compatible" del contrato que se puede ver desde este lado.
     *
     * @return void
     */
    public function test_un_receptor_viejo_que_contesta_ok_deja_el_push_en_success(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->cliente_con_plan('+5493411112205', $this->sembrar_plan('Agentes Light', 6.41));

        $this->admin_logueado();
        $response = $this->postJson('/api/admin/client/' . $client->id . '/ai-plan/sync');

        $response->assertStatus(200);
        $this->assertSame('success', $response->json('sincronizacion.estado'));
    }

    /**
     * 🔴 `--dry-run` no le habla a nadie y no toca el estado de sincronización de ningún cliente.
     *
     * @return void
     */
    public function test_el_dry_run_no_le_habla_a_nadie_y_no_toca_el_estado(): void
    {
        $client = $this->cliente_con_plan('+5493411112206', $this->sembrar_plan('Agentes Light', 6.41));

        $this->artisan('ia:sincronizar-precios', ['--dry-run' => true])
            ->expectsOutput(
                'Dry-run: se le reenviaría a ' . Client::where('is_active', true)->whereNotNull('ai_plan_id')->count()
                . ' cliente(s), con los precios de ' . count(config('ia_precios')) . ' modelo(s). No se le mandó nada a nadie.'
            )
            ->assertExitCode(0);

        Http::assertNothingSent();

        $client->refresh();
        $this->assertNull($client->ai_plan_sync_status);
        $this->assertNull($client->ai_plan_synced_at);
    }

    /**
     * El barrido real le llega solo a los activos CON paquete, y deja el desenlace en cada uno.
     *
     * @return void
     */
    public function test_el_comando_le_reenvia_solo_a_los_activos_con_paquete(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $plan = $this->sembrar_plan('Agentes Light', 6.41);

        $con_paquete = $this->cliente_con_plan('+5493411112207', $plan);
        $sin_paquete = $this->cliente_con_plan('+5493411112208');

        $inactivo            = $this->cliente_con_plan('+5493411112209', $plan);
        $inactivo->is_active = false;
        $inactivo->save();

        // Otros clientes que hubiera en la base de testing no cuentan para esta prueba.
        Client::whereNotIn('id', [$con_paquete->id, $sin_paquete->id, $inactivo->id])->update(['is_active' => false]);

        $this->artisan('ia:sincronizar-precios')
            ->expectsOutput('Resumen: 1 sincronizado(s), 0 sin soporte, 0 con falla.')
            ->assertExitCode(0);

        $this->assertSame('success', $con_paquete->refresh()->ai_plan_sync_status);
        $this->assertNull($sin_paquete->refresh()->ai_plan_sync_status);
        $this->assertNull($inactivo->refresh()->ai_plan_sync_status);

        $this->assertNotEmpty($this->cuerpo_del_put()['precios_ia']);
    }

    /**
     * 🔴 Un cliente caído no corta el barrido: el siguiente recibe igual, el primero queda en `failed`
     * con su motivo, y el comando termina bien.
     *
     * @return void
     */
    public function test_el_comando_tolera_la_falla_de_un_cliente_y_sigue_con_los_otros(): void
    {
        config(['services.client_api.retries' => 1]);

        $this->fakear_http([
            'https://api-caida.test/*' => function () {
                throw new ConnectionException('timeout simulado en la prueba');
            },
            '*admin-sync/plan-ia*'     => Http::response(['ok' => true], 200),
        ]);

        $plan = $this->sembrar_plan('Agentes Light', 6.41);

        $caido          = $this->cliente_con_plan('+5493411112210', $plan);
        $caido->api_url = 'https://api-caida.test';
        $caido->save();

        $sano = $this->cliente_con_plan('+5493411112211', $plan);

        Client::whereNotIn('id', [$caido->id, $sano->id])->update(['is_active' => false]);

        $this->artisan('ia:sincronizar-precios')
            ->expectsOutput('Resumen: 1 sincronizado(s), 0 sin soporte, 1 con falla.')
            ->assertExitCode(0);

        $this->assertSame('failed', $caido->refresh()->ai_plan_sync_status);
        $this->assertSame('success', $sano->refresh()->ai_plan_sync_status);
    }

    /**
     * El barrido de cada noche también lleva los precios: es lo que hace que un cambio de la tabla
     * llegue solo en menos de un día, aunque nadie corra `ia:sincronizar-precios`.
     *
     * @return void
     */
    public function test_el_barrido_de_la_noche_tambien_lleva_los_precios(): void
    {
        $this->fakear_http(['*admin-sync/plan-ia*' => Http::response(['ok' => true], 200)]);

        $client = $this->cliente_con_plan('+5493411112212', $this->sembrar_plan());

        $this->artisan('ai-planes:sincronizar', ['--client' => $client->id])->assertExitCode(0);

        $this->assertNotEmpty($this->cuerpo_del_put()['precios_ia']);
    }

    /**
     * 🔴 El gasto del mes es del MES CALENDARIO, aunque el rango que se mira sea otro, y no se come el
     * consumo del mes anterior. 1 millón de entrada de Sonnet 5 a US$ 2 da US$ 2,00; la fila de octubre
     * queda afuera del mes y adentro de los totales del rango.
     *
     * @return void
     */
    public function test_el_gasto_del_mes_es_del_mes_calendario_y_no_del_rango(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'America/Argentina/Buenos_Aires'));

        $this->admin_logueado();

        $client = $this->cliente_con_plan('+5493411112213');

        $this->sembrar_consumo($client, '2026-10-31', 'claude-sonnet-5', 5000000);
        $this->sembrar_consumo($client, '2026-11-01', 'claude-sonnet-5', 1000000);

        $response = $this->getJson('/api/admin/client/' . $client->id . '/tokens?desde=2026-10-01&hasta=2026-11-15');

        $response->assertStatus(200);

        $this->assertSame('2026-11-01', $response->json('gasto_del_mes.desde'));
        $this->assertSame('2026-11-15', $response->json('gasto_del_mes.hasta'));
        $this->assertEqualsWithDelta(2.00, $response->json('gasto_del_mes.costo_usd'), 0.0001);
        $this->assertSame([], $response->json('gasto_del_mes.modelos_sin_precio'));

        // Los totales del rango sí traen las dos filas: 6 millones a US$ 2.
        $this->assertEqualsWithDelta(12.00, $response->json('totales.costo_usd'), 0.0001);
    }

    /**
     * Un modelo sin precio no suma y se nombra, para que la pantalla diga que el número se queda corto.
     * Y un mes sin consumo es un gasto de cero, no "no sé".
     *
     * @return void
     */
    public function test_el_gasto_del_mes_nombra_los_modelos_sin_precio_y_sin_consumo_da_cero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'America/Argentina/Buenos_Aires'));

        $this->admin_logueado();

        $sin_consumo = $this->cliente_con_plan('+5493411112214');

        $vacio = $this->getJson('/api/admin/client/' . $sin_consumo->id . '/tokens');
        $vacio->assertStatus(200);
        $this->assertEqualsWithDelta(0.0, $vacio->json('gasto_del_mes.costo_usd'), 0.0001);
        $this->assertNotNull($vacio->json('gasto_del_mes.costo_usd'));

        $client = $this->cliente_con_plan('+5493411112215');

        $this->sembrar_consumo($client, '2026-11-02', 'claude-sonnet-5', 1000000);
        $this->sembrar_consumo($client, '2026-11-03', 'modelo-inventado-9', 1000000);

        $response = $this->getJson('/api/admin/client/' . $client->id . '/tokens');

        $this->assertEqualsWithDelta(2.00, $response->json('gasto_del_mes.costo_usd'), 0.0001);
        $this->assertSame(['modelo-inventado-9'], $response->json('gasto_del_mes.modelos_sin_precio'));
    }
}
