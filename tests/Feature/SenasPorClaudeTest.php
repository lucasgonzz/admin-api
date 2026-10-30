<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Models\Sena;
use App\Models\Venta;
use App\Services\RunUserSetupService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeVentas;

/**
 * Las señas desde Claude (misión senas-en-ventas, 30/10/2026):
 *
 *  - `POST claude/ventas/{ventaId}/senas`
 *  - `POST claude/leads/{id}/senas`
 *  - `GET  claude/leads/{id}/senas`
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **El dry_run por defecto no escribe NADA** (ni la fila de la seña ni un pago en las cuotas),
 *     pero predice lo que la escritura hace: la respuesta del dry_run y la del real coinciden en
 *     las aplicaciones y en la venta resultante.
 *  2. 🔴 **Sin el nombre correcto no se escribe** (`confirm_client_name` para la venta,
 *     `confirm_lead_name` para el lead), y el error no revela el nombre.
 *  3. **Los rechazos de negocio** (seña mayor a lo pendiente, monto cero o negativo, lead ya
 *     promovido, venta inexistente) son 422/404 con la forma `{"error": ...}` y no escriben.
 *  4. **El flujo del lead**: la seña pedida por Claude queda pendiente y se aplica sola al promover.
 *  5. Sin la clave del bloque, 401.
 *
 * El reloj queda clavado el 15/9/2026 (ver `BaseDeCobranzas`).
 */
class SenasPorClaudeTest extends BaseDeVentas
{
    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-senas-por-claude';

    /** Nombre del cliente de las pruebas, con tilde y mayúsculas a propósito. */
    const NOMBRE = 'Ferretería Plazoleta';

    /** Nombre de contacto del lead de las pruebas. */
    const NOMBRE_LEAD = 'Sebastián Prospecto';

    /**
     * Setea la clave de ingesta (en el `.env.testing` está vacía y el middleware es fail-closed) y
     * deja un admin logueado, que es el que da de alta las ventas de preparación por el panel.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        // Nada del contrato puede leer el disco real al promover un lead.
        Storage::fake('local');

        $this->admin_logueado();
    }

    /**
     * Headers del bloque `claude/*`.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Un POST al bloque `claude/*`.
     *
     * @param string               $ruta
     * @param array<string, mixed> $cuerpo
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function claude(string $ruta, array $cuerpo = [])
    {
        return $this->postJson('/api/claude/' . $ruta, $cuerpo, $this->headers());
    }

    /**
     * Un GET al bloque `claude/*`.
     *
     * @param string $ruta
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function claude_get(string $ruta)
    {
        return $this->getJson('/api/claude/' . $ruta, $this->headers());
    }

    /**
     * Un cliente con nombre conocido y una venta de USD 1.300 en cuatro cuotas de 325 (el caso de
     * Sebastián), con el 15 % para el vendedor único.
     *
     * @return array{0: Client, 1: int} Cliente e id de la venta.
     */
    private function cliente_con_venta(): array
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => self::NOMBRE]);

        $alta = $this->alta_de_venta($client, ['productos' => ['gestion'], 'monto_total' => 1300, 'cantidad_cuotas' => 4]);
        $alta->assertStatus(201);

        return [$client, (int) $alta->json('venta.id')];
    }

    /**
     * Un lead ganado con contrato de 1.500 en dos cuotas (1.000 y 500), sin promover.
     *
     * @return Lead
     */
    private function crear_lead_sin_promover(): Lead
    {
        $lead = new Lead();
        $lead->contact_name                       = self::NOMBRE_LEAD;
        $lead->company_name                       = 'Lead con seña ' . uniqid();
        $lead->email                              = 'sena-claude@test.local';
        $lead->status                             = 'cerrado_ganado';
        $lead->contract_client_name               = 'Juana Pérez';
        $lead->contract_currency                  = 'USD';
        $lead->contract_precio_licencia           = '1500';
        $lead->contract_fecha_emision             = '2026-09-10';
        $lead->contract_fecha_primer_pago_unico   = '2026-10-05';
        $lead->contract_financiacion              = [
            ['monto' => '1000', 'fecha' => '2026-10-05'],
            ['monto' => '500', 'fecha' => '2026-11-05'],
        ];
        $lead->contract_fecha_primer_pago_mensual = '2026-10-10';
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Foto de las cuotas de una venta (incluye `updated_at`), para comprobar que nada se tocó.
     *
     * @param int $venta_id
     *
     * @return array<int, array<string, mixed>>
     */
    private function foto_de_las_cuotas(int $venta_id): array
    {
        return LicenciaCuota::where('venta_id', $venta_id)->orderBy('id')->get()->map(function (LicenciaCuota $cuota) {
            return [
                'id'           => $cuota->id,
                'estado'       => $cuota->estado,
                'monto_pagado' => $cuota->monto_pagado,
                'fecha_pago'   => $cuota->fecha_pago ? $cuota->fecha_pago->toDateString() : null,
                'observacion'  => $cuota->observacion,
                'updated_at'   => $cuota->updated_at ? $cuota->updated_at->toDateTimeString() : null,
            ];
        })->all();
    }

    /* ------------------------------------------------------------------------------------------
     | La puerta
     |------------------------------------------------------------------------------------------ */

    /**
     * Sin la clave, o con una inválida, 401 y nada escrito en las tres rutas.
     *
     * @return void
     */
    public function test_sin_la_clave_los_tres_endpoints_rechazan(): void
    {
        [, $venta_id] = $this->cliente_con_venta();
        $lead = $this->crear_lead_sin_promover();

        $cuerpo = ['monto' => 100, 'dry_run' => false, 'confirm_client_name' => self::NOMBRE, 'confirm_lead_name' => self::NOMBRE_LEAD];

        foreach (['ventas/' . $venta_id . '/senas', 'leads/' . $lead->id . '/senas'] as $ruta) {
            $this->postJson('/api/claude/' . $ruta, $cuerpo)->assertStatus(401);
            $this->postJson('/api/claude/' . $ruta, $cuerpo, ['X-Claude-Task-Key' => 'otra', 'Accept' => 'application/json'])->assertStatus(401);
        }
        $this->getJson('/api/claude/leads/' . $lead->id . '/senas')->assertStatus(401);

        $this->assertSame(0, Sena::count());
        $this->assertSame(0, LicenciaCuota::where('venta_id', $venta_id)->whereNotNull('monto_pagado')->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Seña en una venta
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 El dry_run por defecto NO escribe nada —ni la seña, ni los pagos de las cuotas, ni siquiera
     * su `updated_at`— y predice lo que la escritura hace. Lo mismo con `dry_run=true` explícito.
     *
     * @return void
     */
    public function test_el_dry_run_por_defecto_no_escribe_y_predice_la_escritura(): void
    {
        [, $venta_id] = $this->cliente_con_venta();
        $antes = $this->foto_de_las_cuotas($venta_id);

        $dry = $this->claude('ventas/' . $venta_id . '/senas', ['monto' => 400]);

        $dry->assertStatus(200);
        $this->assertTrue($dry->json('dry_run'));
        $this->assertFalse($dry->json('escribio'));
        $this->assertNull($dry->json('sena.id'), 'La fila simulada no existe.');
        $this->assertSame(self::NOMBRE, $dry->json('client.name'));
        $this->assertCount(2, $dry->json('aplicaciones'), 'Cubre la primera cuota y parte de la segunda.');
        $this->assertEqualsWithDelta(0.0, $dry->json('venta_antes.resumen.cobrado'), 0.001);
        $this->assertEqualsWithDelta(1300.0, $dry->json('venta_antes.resumen.pendiente'), 0.001);
        $this->assertEqualsWithDelta(400.0, $dry->json('venta_despues.resumen.cobrado'), 0.001);
        $this->assertEqualsWithDelta(900.0, $dry->json('venta_despues.resumen.pendiente'), 0.001);
        $this->assertEqualsWithDelta(400.0, $dry->json('venta_despues.resumen.sena_total'), 0.001);
        $this->assertEqualsWithDelta(60.0, $dry->json('venta_despues.comision.liberada'), 0.001, 'Con 400 cobrados se libera el 400/1300 de 195.');

        // Nada quedó: ni la fila, ni un centavo en las cuotas, ni un updated_at tocado.
        $this->assertSame(0, Sena::count());
        $this->assertSame($antes, $this->foto_de_las_cuotas($venta_id));

        $this->claude('ventas/' . $venta_id . '/senas', ['monto' => 400, 'dry_run' => true])->assertStatus(200);
        $this->assertSame(0, Sena::count(), 'dry_run=true explícito tampoco escribe.');
        $this->assertSame($antes, $this->foto_de_las_cuotas($venta_id));

        // El real hace lo que el dry_run dijo.
        $real = $this->claude('ventas/' . $venta_id . '/senas', ['monto' => 400, 'dry_run' => false, 'confirm_client_name' => self::NOMBRE]);
        $real->assertStatus(201);
        $this->assertFalse($real->json('dry_run'));
        $this->assertTrue($real->json('escribio'));
        $this->assertEquals($dry->json('aplicaciones'), $real->json('sena.aplicaciones'));
        $this->assertEquals($dry->json('venta_despues.resumen'), $real->json('venta.resumen'));
        $this->assertEquals($dry->json('venta_despues.comision'), $real->json('venta.comision'));
    }

    /**
     * 🔴 Con `dry_run=false`: sin `confirm_client_name`, o con otro nombre, 422 sin escribir (y sin
     * revelar el nombre correcto); con el nombre correcto —en otras mayúsculas y con espacios— se
     * escribe, con origen `claude`.
     *
     * @return void
     */
    public function test_la_sena_exige_el_nombre_del_cliente_y_no_lo_revela(): void
    {
        [, $venta_id] = $this->cliente_con_venta();
        $antes = $this->foto_de_las_cuotas($venta_id);
        $ruta = 'ventas/' . $venta_id . '/senas';

        $sin = $this->claude($ruta, ['monto' => 100, 'dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertArrayHasKey('error', $sin->json());
        $this->assertStringNotContainsString(self::NOMBRE, (string) json_encode($sin->json(), JSON_UNESCAPED_UNICODE), 'El error no revela el nombre correcto.');

        $otro = $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_client_name' => 'Otra ferretería']);
        $otro->assertStatus(422);
        $this->assertStringNotContainsString('Plazoleta', $otro->getContent());

        $this->assertSame(0, Sena::count());
        $this->assertSame($antes, $this->foto_de_las_cuotas($venta_id), 'Sin confirmar, no se escribe nada.');

        $real = $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_client_name' => '  ferretería PLAZOLETA ', 'nota' => 'Seña de Sebastián', 'fecha' => '2026-09-12']);
        $real->assertStatus(201);
        $this->assertSame('claude', $real->json('sena.origen'));
        $this->assertSame('Seña de Sebastián', $real->json('sena.nota'));
        $this->assertSame('2026-09-12', $real->json('sena.fecha'));
        $this->assertCount(1, $real->json('ventas'), 'La respuesta trae la lista entera del cliente.');
        $this->assertSame(1, Sena::count());
        $this->assertSame('claude', Sena::firstOrFail()->origen);
        $this->assertEqualsWithDelta(1200.0, $real->json('venta.resumen.pendiente'), 0.001);
    }

    /**
     * 🔴 Una seña mayor a lo pendiente es 422 `{"error": ...}` con el pendiente, tanto en el dry_run
     * como en el real, y no escribe. Monto cero, negativo o ausente, también 422.
     *
     * @return void
     */
    public function test_los_rechazos_de_monto_son_422_y_no_escriben(): void
    {
        [, $venta_id] = $this->cliente_con_venta();
        $antes = $this->foto_de_las_cuotas($venta_id);
        $ruta = 'ventas/' . $venta_id . '/senas';
        $real = ['dry_run' => false, 'confirm_client_name' => self::NOMBRE];

        $dry = $this->claude($ruta, ['monto' => 1300.01]);
        $dry->assertStatus(422);
        $this->assertArrayHasKey('error', $dry->json());
        $this->assertStringContainsString('supera lo pendiente', $dry->json('error'));
        $this->assertEqualsWithDelta(1300.0, $dry->json('pendiente'), 0.001);
        $this->assertSame('USD', $dry->json('moneda'));

        $exceso = $this->claude($ruta, $real + ['monto' => 5000]);
        $exceso->assertStatus(422);
        $this->assertArrayHasKey('error', $exceso->json());

        foreach ([0, -10, 'cien', null] as $monto) {
            $malo = $this->claude($ruta, $real + ['monto' => $monto]);
            $malo->assertStatus(422);
            $this->assertArrayHasKey('error', $malo->json());
        }
        $this->claude($ruta, $real)->assertStatus(422);

        $this->assertSame(0, Sena::count());
        $this->assertSame($antes, $this->foto_de_las_cuotas($venta_id));

        // Justo lo pendiente sí pasa y deja la venta saldada.
        $exacta = $this->claude($ruta, $real + ['monto' => 1300]);
        $exacta->assertStatus(201);
        $this->assertEqualsWithDelta(0.0, $exacta->json('venta.resumen.pendiente'), 0.001);

        // Y una venta saldada no admite más señas, ni siquiera en el dry_run.
        $this->claude($ruta, ['monto' => 1])->assertStatus(422);
    }

    /**
     * Una venta inexistente es 404 con la forma `{"error": ...}`; una venta confirmada con el nombre
     * de OTRO cliente es 422 (el freno del cliente DE LA VENTA) y no escribe.
     *
     * @return void
     */
    public function test_una_venta_inexistente_es_404_y_el_nombre_de_otro_cliente_no_vale(): void
    {
        [, $venta_id] = $this->cliente_con_venta();
        $otro = $this->crear_cliente(['name' => 'Otro Cliente SA']);

        $inexistente = $this->claude('ventas/99999999/senas', ['monto' => 10]);
        $inexistente->assertStatus(404);
        $this->assertArrayHasKey('error', $inexistente->json());

        $ajena = $this->claude('ventas/' . $venta_id . '/senas', ['monto' => 10, 'dry_run' => false, 'confirm_client_name' => $otro->name]);
        $ajena->assertStatus(422);

        $this->assertSame(0, Sena::count());

        // Una ruta con id no numérico ni siquiera matchea.
        $this->claude('ventas/abc/senas', ['monto' => 10])->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Seña en un lead
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 El dry_run por defecto de un lead no deja ninguna fila y muestra el total de señas
     * pendientes antes y después; el real exige `confirm_lead_name` (que el error no revela).
     *
     * @return void
     */
    public function test_la_sena_del_lead_en_dry_run_no_escribe_y_el_real_exige_el_nombre(): void
    {
        $lead = $this->crear_lead_sin_promover();
        $ruta = 'leads/' . $lead->id . '/senas';

        $dry = $this->claude($ruta, ['monto' => 100]);
        $dry->assertStatus(200);
        $this->assertTrue($dry->json('dry_run'));
        $this->assertFalse($dry->json('escribio'));
        $this->assertSame($lead->id, $dry->json('lead.id'));
        $this->assertNull($dry->json('sena.id'));
        $this->assertFalse($dry->json('sena.aplicada'));
        $this->assertEqualsWithDelta(0.0, $dry->json('senas_pendientes_antes'), 0.001);
        $this->assertEqualsWithDelta(100.0, $dry->json('senas_pendientes_despues'), 0.001);
        $this->assertSame(0, Sena::count(), 'El dry_run no deja ninguna fila.');

        $sin = $this->claude($ruta, ['monto' => 100, 'dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertArrayHasKey('error', $sin->json());
        $this->assertStringNotContainsString('Sebastián', (string) json_encode($sin->json(), JSON_UNESCAPED_UNICODE), 'El error no revela el nombre del lead.');

        // Con el nombre del CLIENTE (no el del lead) tampoco vale.
        $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_client_name' => self::NOMBRE_LEAD])->assertStatus(422);
        $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_lead_name' => 'Otra persona'])->assertStatus(422);
        $this->assertSame(0, Sena::count());

        $real = $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_lead_name' => '  SEBASTIÁN prospecto ', 'nota' => 'Dejó seña']);
        $real->assertStatus(201);
        $this->assertTrue($real->json('escribio'));
        $this->assertSame('claude', $real->json('sena.origen'));
        $this->assertFalse($real->json('sena.aplicada'));
        $this->assertEqualsWithDelta(100.0, $real->json('total'), 0.001);
        $this->assertSame(1, Sena::count());
        $this->assertNull(Sena::firstOrFail()->venta_id);
    }

    /**
     * Rechazos del lead: monto cero o negativo (422), lead inexistente (404) y lead sin nombre de
     * contacto (422 que lo dice, sin escribir).
     *
     * @return void
     */
    public function test_los_rechazos_del_lead(): void
    {
        $lead = $this->crear_lead_sin_promover();
        $ruta = 'leads/' . $lead->id . '/senas';
        $real = ['dry_run' => false, 'confirm_lead_name' => self::NOMBRE_LEAD];

        foreach ([0, -5, null] as $monto) {
            $this->claude($ruta, $real + ['monto' => $monto])->assertStatus(422);
        }
        $this->claude('leads/99999999/senas', ['monto' => 10])->assertStatus(404);
        $this->claude_get('leads/99999999/senas')->assertStatus(404);

        $sin_nombre = $this->crear_lead_sin_promover();
        $sin_nombre->contact_name = null;
        $sin_nombre->save();
        $r = $this->claude('leads/' . $sin_nombre->id . '/senas', ['monto' => 10, 'dry_run' => false, 'confirm_lead_name' => '']);
        $r->assertStatus(422);
        $this->assertStringContainsString('NO tiene nombre', $r->json('error'));

        $this->assertSame(0, Sena::count());
    }

    /**
     * 🔴 Un lead ya promovido es 422 —en el dry_run y en el real— y se indica registrar la seña en la
     * venta; no escribe nada.
     *
     * @return void
     */
    public function test_un_lead_ya_promovido_es_422(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente(['name' => self::NOMBRE]);
        $lead = $this->crear_lead_promovido($client);
        $lead->contact_name = self::NOMBRE_LEAD;
        $lead->save();
        $ruta = 'leads/' . $lead->id . '/senas';

        $dry = $this->claude($ruta, ['monto' => 100]);
        $dry->assertStatus(422);
        $this->assertArrayHasKey('error', $dry->json());
        $this->assertStringContainsString('ya fue promovido', $dry->json('error'));

        $real = $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_lead_name' => self::NOMBRE_LEAD]);
        $real->assertStatus(422);

        $this->assertSame(0, Sena::count());
    }

    /**
     * 🔴 El flujo completo: Claude registra la seña en el lead, el GET la ve pendiente, el lead se
     * promueve y la seña se aplica sola a la venta del contrato (queda `aplicada`), con la plata
     * cuadrada en la venta.
     *
     * @return void
     */
    public function test_la_sena_pedida_por_claude_se_aplica_sola_al_promover_el_lead(): void
    {
        $this->crear_vendedor();
        $lead = $this->crear_lead_sin_promover();
        $ruta = 'leads/' . $lead->id . '/senas';

        $this->claude($ruta, ['monto' => 100, 'dry_run' => false, 'confirm_lead_name' => self::NOMBRE_LEAD, 'fecha' => '2026-09-14'])
            ->assertStatus(201);

        $pendiente = $this->claude_get($ruta);
        $pendiente->assertStatus(200);
        $this->assertSame($lead->id, $pendiente->json('lead.id'));
        $this->assertCount(1, $pendiente->json('senas'));
        $this->assertFalse($pendiente->json('senas.0.aplicada'));
        $this->assertEqualsWithDelta(100.0, $pendiente->json('total'), 0.001);
        $this->assertEqualsWithDelta(0.0, $pendiente->json('total_aplicado'), 0.001);

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');
        $venta = Venta::where('client_id', $client->id)->firstOrFail();

        $aplicada = $this->claude_get($ruta);
        $this->assertTrue($aplicada->json('senas.0.aplicada'));
        $this->assertSame($venta->id, $aplicada->json('senas.0.venta_id'));
        $this->assertNotNull($aplicada->json('senas.0.aplicada_at'));
        $this->assertEqualsWithDelta(0.0, $aplicada->json('total'), 0.001);
        $this->assertEqualsWithDelta(100.0, $aplicada->json('total_aplicado'), 0.001);

        $json = $this->venta_de_la_lista($client, $venta->id);
        $this->assertEqualsWithDelta(1500.0, $json['resumen']['total'], 0.001);
        $this->assertEqualsWithDelta(100.0, $json['resumen']['cobrado'], 0.001);
        $this->assertEqualsWithDelta(1400.0, $json['resumen']['pendiente'], 0.001);
        $this->assertEqualsWithDelta(100.0, $json['resumen']['sena_total'], 0.001);

        // Ahora el lead está promovido: una seña nueva va a la venta, no al lead.
        $this->claude($ruta, ['monto' => 50])->assertStatus(422);
        $this->claude('ventas/' . $venta->id . '/senas', ['monto' => 50, 'dry_run' => false, 'confirm_client_name' => $client->name])
            ->assertStatus(201);
    }
}
