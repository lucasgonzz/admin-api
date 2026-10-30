<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ComisionPago;
use App\Models\LicenciaCuota;
use App\Models\Venta;
use App\Services\LicenciaCuotaService;
use Tests\Feature\Cobranzas\BaseDeVentas;

/**
 * Pagos y ajustes de las ventas desde Claude (misión cobro-dudoso-y-pagos-ventas, 30/10/2026):
 *
 *  - `POST claude/clients/{id}/cuotas/{cuotaId}/pago`
 *  - `POST claude/clients/{id}/cuotas/{cuotaId}/eliminar`
 *  - `POST claude/ventas/{ventaId}/comision/pagos`
 *  - `POST claude/clients/{id}/cobro-dudoso`
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **El dry_run por defecto no escribe NADA**, pero predice lo que la escritura haría (corre el
 *     mismo servicio adentro de una transacción que se deshace).
 *  2. 🔴 **Sin `confirm_client_name` correcto no se escribe** (422), y el error no revela el nombre.
 *  3. **La cuenta de plata es la del panel**: pagar una cuota sin `monto` paga lo que falta; borrar
 *     una cuota baja el total de la venta y su comisión; el pago de comisión sin `monto` es todo lo
 *     que está a pagar, con la comisión liberada proporcional a lo cobrado.
 *  4. **Los rechazos**: cuota de otro cliente (404), cuota ya pagada, cuota con plata cobrada que se
 *     quiere borrar, venta sin vendedor, nada a pagar.
 *  5. Sin la clave del bloque, 401.
 *
 * El reloj queda clavado el 15/9/2026 (ver `BaseDeCobranzas`).
 */
class PagosYAjustesDeVentasPorClaudeTest extends BaseDeVentas
{
    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-pagos-y-ajustes-de-ventas';

    /** Nombre del cliente de las pruebas, con tilde y mayúsculas a propósito. */
    const NOMBRE = 'Ferretería Rioplatense';

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
     * Un cliente con nombre conocido.
     *
     * @return Client
     */
    private function cliente(): Client
    {
        return $this->crear_cliente(['name' => self::NOMBRE]);
    }

    /**
     * Da de alta una venta por el panel y devuelve su id.
     *
     * @param Client               $client
     * @param array<string, mixed> $datos
     *
     * @return int
     */
    private function venta_de(Client $client, array $datos): int
    {
        $alta = $this->alta_de_venta($client, $datos);
        $alta->assertStatus(201);

        return (int) $alta->json('venta.id');
    }

    /**
     * Venta de USD 2.800,02 en seis cuotas de 466,67, con el 15 % para el vendedor.
     *
     * @param Client $client
     *
     * @return int
     */
    private function venta_de_seis_cuotas(Client $client): int
    {
        return $this->venta_de($client, [
            'productos'       => ['desarrollo'],
            'descripcion'     => 'Desarrollo a medida',
            'monto_total'     => 2800.02,
            'cantidad_cuotas' => 6,
        ]);
    }

    /**
     * Venta de USD 900 en tres cuotas de 300, con el 15 % para el vendedor (comisión total 135).
     *
     * @param Client $client
     *
     * @return int
     */
    private function venta_de_tres_cuotas(Client $client): int
    {
        return $this->venta_de($client, [
            'productos'       => ['agentes'],
            'monto_total'     => 900,
            'cantidad_cuotas' => 3,
        ]);
    }

    /**
     * La cuota número `$numero` de la venta.
     *
     * @param int $venta_id
     * @param int $numero
     *
     * @return LicenciaCuota
     */
    private function cuota(int $venta_id, int $numero): LicenciaCuota
    {
        return $this->cuotas_de($venta_id)->values()->get($numero - 1);
    }

    /* ------------------------------------------------------------------------------------------
     | La puerta
     |------------------------------------------------------------------------------------------ */

    /**
     * Sin la clave, o con una inválida, 401 y nada escrito en las cuatro rutas.
     *
     * @return void
     */
    public function test_sin_la_clave_los_cuatro_endpoints_rechazan(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $cuota = $this->cuota($venta_id, 1);

        $rutas = [
            '/api/claude/clients/' . $client->id . '/cuotas/' . $cuota->id . '/pago',
            '/api/claude/clients/' . $client->id . '/cuotas/' . $cuota->id . '/eliminar',
            '/api/claude/ventas/' . $venta_id . '/comision/pagos',
            '/api/claude/clients/' . $client->id . '/cobro-dudoso',
        ];

        foreach ($rutas as $ruta) {
            $this->postJson($ruta, ['dry_run' => false, 'cobro_dudoso' => true])->assertStatus(401);
            $this->postJson($ruta, ['dry_run' => false, 'cobro_dudoso' => true], ['X-Claude-Task-Key' => 'otra', 'Accept' => 'application/json'])
                ->assertStatus(401);
        }

        $this->assertSame(3, LicenciaCuota::where('venta_id', $venta_id)->count());
        $this->assertSame('pendiente', $cuota->fresh()->estado);
        $this->assertFalse((bool) $client->fresh()->cobro_dudoso);
    }

    /* ------------------------------------------------------------------------------------------
     | Pago de una cuota
     |------------------------------------------------------------------------------------------ */

    /**
     * dry_run por defecto: muestra la cuota antes y después (sin `monto`, paga lo que falta) y no
     * escribe nada. Lo mismo con `dry_run=true` explícito.
     *
     * @return void
     */
    public function test_el_pago_de_cuota_en_dry_run_no_escribe(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $cuota = $this->cuota($venta_id, 1);
        $ruta = 'clients/' . $client->id . '/cuotas/' . $cuota->id . '/pago';

        $respuesta = $this->claude($ruta);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertFalse($respuesta->json('escribio'));
        $this->assertEqualsWithDelta(300.0, $respuesta->json('monto'), 0.001, 'Sin monto, paga lo que falta de la cuota.');
        $this->assertSame('pendiente', $respuesta->json('cuota_antes.estado'));
        $this->assertSame('pagada', $respuesta->json('cuota_despues.estado'));
        $this->assertEqualsWithDelta(300.0, $respuesta->json('cuota_despues.monto_pagado'), 0.001);
        $this->assertEqualsWithDelta(300.0, $respuesta->json('venta.resumen.cobrado'), 0.001);
        $this->assertEqualsWithDelta(45.0, $respuesta->json('venta.comision.liberada'), 0.001, 'Cobrar libera la comisión proporcional.');

        $fresca = $cuota->fresh();
        $this->assertSame('pendiente', $fresca->estado);
        $this->assertNull($fresca->monto_pagado);
        $this->assertNull($fresca->fecha_pago);

        $this->claude($ruta, ['dry_run' => true, 'monto' => 100])->assertStatus(200);
        $this->assertNull($cuota->fresh()->monto_pagado, 'dry_run=true explícito tampoco escribe.');
    }

    /**
     * Con `dry_run=false`: sin `confirm_client_name`, o con otro nombre, 422 sin escribir (y sin
     * revelar el nombre correcto); con el nombre correcto —en otras mayúsculas y con espacios— se
     * escribe.
     *
     * @return void
     */
    public function test_el_pago_de_cuota_exige_el_nombre_del_cliente(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $cuota = $this->cuota($venta_id, 1);
        $ruta = 'clients/' . $client->id . '/cuotas/' . $cuota->id . '/pago';

        $sin = $this->claude($ruta, ['dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertArrayHasKey('error', $sin->json());
        $this->assertStringNotContainsString(self::NOMBRE, $sin->getContent(), 'El error no revela el nombre correcto.');

        $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => 'Otra ferretería'])->assertStatus(422);

        $this->assertNull($cuota->fresh()->monto_pagado, 'Sin confirmar, no se escribe nada.');
        $this->assertSame('pendiente', $cuota->fresh()->estado);

        $real = $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => '  ferretería RIOPLATENSE ']);
        $real->assertStatus(200);
        $this->assertFalse($real->json('dry_run'));
        $this->assertTrue($real->json('escribio'));
        $this->assertSame('pagada', $real->json('cuota.estado'));
        $this->assertCount(1, $real->json('ventas'), 'La respuesta trae la lista entera del cliente.');
    }

    /**
     * Sin `monto` paga lo que falta y la cuota queda `pagada`, con la fecha dada. Un pago parcial la
     * deja `parcial`, y el siguiente sin `monto` paga el resto. Una cuota ya pagada es 422.
     *
     * @return void
     */
    public function test_el_pago_de_cuota_paga_lo_que_falta_y_acumula_los_parciales(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $cuota = $this->cuota($venta_id, 1);
        $ruta = 'clients/' . $client->id . '/cuotas/' . $cuota->id . '/pago';
        $real = ['dry_run' => false, 'confirm_client_name' => self::NOMBRE];

        // Parcial: entran 100 de 300.
        $parcial = $this->claude($ruta, $real + ['monto' => 100, 'fecha_pago' => '2026-09-10', 'observacion' => 'Seña']);
        $parcial->assertStatus(200);
        $this->assertSame('parcial', $parcial->json('cuota.estado'));
        $this->assertEqualsWithDelta(100.0, $parcial->json('cuota.monto_pagado'), 0.001);
        $this->assertSame('2026-09-10', $parcial->json('cuota.fecha_pago'));
        $this->assertSame('Seña', $parcial->json('cuota.observacion'));

        // Sin monto: lo que falta (200), y queda pagada.
        $resto = $this->claude($ruta, $real);
        $resto->assertStatus(200);
        $this->assertSame('pagada', $resto->json('cuota.estado'));
        $this->assertEqualsWithDelta(300.0, $resto->json('cuota.monto_pagado'), 0.001);
        $this->assertEqualsWithDelta(300.0, $resto->json('venta.resumen.cobrado'), 0.001);

        $this->assertDatabaseHas('licencia_cuotas', ['id' => $cuota->id, 'estado' => 'pagada', 'monto_pagado' => 300.00]);

        // Una cuota ya pagada no recibe otro pago: ni con monto ni sin él, ni en dry_run.
        $otra_vez = $this->claude($ruta, $real);
        $otra_vez->assertStatus(422);
        $this->assertArrayHasKey('error', $otra_vez->json());
        $this->claude($ruta, ['monto' => 50])->assertStatus(422);
        $this->assertEqualsWithDelta(300.0, $cuota->fresh()->monto_pagado, 0.001, 'No se duplicó el cobro.');
    }

    /**
     * `completa=true` da la cuota por pagada aunque lo cobrado no llegue a su monto.
     *
     * @return void
     */
    public function test_el_pago_de_cuota_puede_darla_por_completa(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $cuota = $this->cuota($venta_id, 1);

        $respuesta = $this->claude('clients/' . $client->id . '/cuotas/' . $cuota->id . '/pago', [
            'dry_run'             => false,
            'confirm_client_name' => self::NOMBRE,
            'monto'               => 250,
            'completa'            => true,
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('pagada', $respuesta->json('cuota.estado'));
        $this->assertEqualsWithDelta(250.0, $respuesta->json('cuota.monto_pagado'), 0.001);
    }

    /**
     * Una cuota de OTRO cliente es 404 (no se le cobra a un cliente una cuota ajena), igual que una
     * cuota o un cliente inexistentes; y un monto inválido es 422. Nada escribe.
     *
     * @return void
     */
    public function test_el_pago_de_cuota_de_otro_cliente_es_404(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $otro = $this->crear_cliente(['name' => 'Otro cliente']);
        $venta_ajena = $this->venta_de_tres_cuotas($otro);
        $cuota_ajena = $this->cuota($venta_ajena, 1);
        $real = ['dry_run' => false, 'confirm_client_name' => self::NOMBRE];

        $this->claude('clients/' . $client->id . '/cuotas/' . $cuota_ajena->id . '/pago', $real)->assertStatus(404);
        $this->claude('clients/' . $client->id . '/cuotas/999999999/pago', $real)->assertStatus(404);
        $this->claude('clients/999999999/cuotas/' . $cuota_ajena->id . '/pago', $real)->assertStatus(404);

        $propia = $this->cuota($this->venta_de_tres_cuotas($client), 1);
        $this->claude('clients/' . $client->id . '/cuotas/' . $propia->id . '/pago', $real + ['monto' => -5])->assertStatus(422);
        $this->claude('clients/' . $client->id . '/cuotas/' . $propia->id . '/pago', $real + ['monto' => 'mucho'])->assertStatus(422);

        $this->assertNull($cuota_ajena->fresh()->monto_pagado);
        $this->assertNull($propia->fresh()->monto_pagado);
    }

    /* ------------------------------------------------------------------------------------------
     | Eliminar una cuota
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 El caso real (Golden-Breeze): venta de 2.800,02 en seis cuotas; borrar la sexta, de 466,67,
     * deja el total en 2.333,35 y la comisión en lo que corresponde a ese total. El dry_run muestra
     * el antes y el después sin escribir.
     *
     * @return void
     */
    public function test_eliminar_una_cuota_baja_el_total_y_la_comision_de_la_venta(): void
    {
        $vendedor = $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_seis_cuotas($client);
        $sexta = $this->cuota($venta_id, 6);
        $this->assertEqualsWithDelta(466.67, (float) $sexta->monto, 0.001);
        $ruta = 'clients/' . $client->id . '/cuotas/' . $sexta->id . '/eliminar';

        // dry_run por defecto: antes y después, y no se borra nada.
        $simulado = $this->claude($ruta);
        $simulado->assertStatus(200);
        $this->assertTrue($simulado->json('dry_run'));
        $this->assertFalse($simulado->json('escribio'));
        $this->assertEqualsWithDelta(2800.02, $simulado->json('venta_antes.monto_total'), 0.001);
        $this->assertEqualsWithDelta(2333.35, $simulado->json('venta_despues.monto_total'), 0.001);
        $this->assertEqualsWithDelta(round(2800.02 * 0.15, 2), $simulado->json('venta_antes.comision_monto'), 0.001);
        $this->assertEqualsWithDelta(round(2333.35 * 0.15, 2), $simulado->json('venta_despues.comision_monto'), 0.001);
        $this->assertCount(6, $simulado->json('venta_antes.cuotas'));
        $this->assertCount(5, $simulado->json('venta_despues.cuotas'));

        $this->assertSame(6, LicenciaCuota::where('venta_id', $venta_id)->count(), 'El dry_run no borra.');
        $this->assertEqualsWithDelta(2800.02, (float) Venta::find($venta_id)->monto_total, 0.001);

        // Sin el nombre, 422 y no se borra.
        $this->claude($ruta, ['dry_run' => false])->assertStatus(422);
        $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => 'Otro'])->assertStatus(422);
        $this->assertSame(6, LicenciaCuota::where('venta_id', $venta_id)->count());

        // Con el nombre, se borra y la venta baja.
        $real = $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => self::NOMBRE]);
        $real->assertStatus(200);
        $this->assertTrue($real->json('escribio'));
        $this->assertEqualsWithDelta(2333.35, $real->json('venta.monto_total'), 0.001);
        $this->assertSame($sexta->id, $real->json('cuota_eliminada.id'));
        $this->assertCount(1, $real->json('ventas'));

        $this->assertSame(5, LicenciaCuota::where('venta_id', $venta_id)->count());
        $venta = Venta::find($venta_id);
        $this->assertEqualsWithDelta(2333.35, (float) $venta->monto_total, 0.001);
        $this->assertEqualsWithDelta(round(2333.35 * 0.15, 2), (float) $venta->comision_monto, 0.001);
        $this->assertSame($vendedor->id, (int) $venta->vendedor_admin_id);
    }

    /**
     * 🔴 Una cuota con plata cobrada NO se elimina: ni una parcial ni una pagada. Es 422, y no se
     * borra nada ni cambia la venta.
     *
     * @return void
     */
    public function test_eliminar_se_niega_si_la_cuota_tiene_plata_cobrada(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $real = ['dry_run' => false, 'confirm_client_name' => self::NOMBRE];

        $parcial = $this->cuota($venta_id, 1);
        $pagada = $this->cuota($venta_id, 2);
        $this->claude('clients/' . $client->id . '/cuotas/' . $parcial->id . '/pago', $real + ['monto' => 50])->assertStatus(200);
        $this->claude('clients/' . $client->id . '/cuotas/' . $pagada->id . '/pago', $real)->assertStatus(200);

        foreach ([$parcial, $pagada] as $cuota) {
            $ruta = 'clients/' . $client->id . '/cuotas/' . $cuota->id . '/eliminar';

            // Ni en dry_run: el rechazo se anuncia antes de simular.
            $this->claude($ruta)->assertStatus(422)->assertJsonStructure(['error']);
            $this->claude($ruta, $real)->assertStatus(422)->assertJsonStructure(['error']);
        }

        $this->assertSame(3, LicenciaCuota::where('venta_id', $venta_id)->count());
        $this->assertEqualsWithDelta(900.0, (float) Venta::find($venta_id)->monto_total, 0.001);
    }

    /**
     * La cuota de otro cliente es 404; una cuota suelta (sin venta) se puede borrar y el dry_run
     * dice `venta_antes: null`.
     *
     * @return void
     */
    public function test_eliminar_cuota_ajena_es_404_y_una_suelta_se_borra(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $otro = $this->crear_cliente(['name' => 'Otro cliente']);
        $ajena = $this->cuota($this->venta_de_tres_cuotas($otro), 1);
        $real = ['dry_run' => false, 'confirm_client_name' => self::NOMBRE];

        $this->claude('clients/' . $client->id . '/cuotas/' . $ajena->id . '/eliminar', $real)->assertStatus(404);
        $this->assertNotNull(LicenciaCuota::find($ajena->id));

        $suelta = app(LicenciaCuotaService::class)->crear($client, ['monto' => 100, 'moneda' => 'USD']);
        $ruta = 'clients/' . $client->id . '/cuotas/' . $suelta->id . '/eliminar';

        $simulado = $this->claude($ruta);
        $simulado->assertStatus(200);
        $this->assertNull($simulado->json('venta_antes'));
        $this->assertNull($simulado->json('venta_despues'));
        $this->assertNotNull(LicenciaCuota::find($suelta->id));

        $this->claude($ruta, $real)->assertStatus(200);
        $this->assertNull(LicenciaCuota::find($suelta->id));
    }

    /* ------------------------------------------------------------------------------------------
     | Pago de comisión
     |------------------------------------------------------------------------------------------ */

    /**
     * dry_run por defecto: muestra el pago (sin `monto`, todo lo que está a pagar) y la comisión
     * antes y después, y no escribe ningún pago. La comisión liberada sale proporcional a lo cobrado:
     * 150 cobrados de 900 con el 15 % (135 de comisión) liberan 22,50.
     *
     * @return void
     */
    public function test_el_pago_de_comision_en_dry_run_no_escribe_y_usa_lo_liberado(): void
    {
        $vendedor = $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);

        // El cliente paga la mitad de la primera cuota: cobrado 150 → liberada 135 × 150/900 = 22,50.
        $this->claude('clients/' . $client->id . '/cuotas/' . $this->cuota($venta_id, 1)->id . '/pago', [
            'dry_run' => false, 'confirm_client_name' => self::NOMBRE, 'monto' => 150,
        ])->assertStatus(200);

        $respuesta = $this->claude('ventas/' . $venta_id . '/comision/pagos');

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('dry_run'));
        $this->assertFalse($respuesta->json('escribio'));
        $this->assertEqualsWithDelta(22.5, $respuesta->json('comision_antes.liberada'), 0.001);
        $this->assertEqualsWithDelta(22.5, $respuesta->json('comision_antes.a_pagar'), 0.001);
        $this->assertEqualsWithDelta(0.0, $respuesta->json('comision_antes.pagada'), 0.001);
        $this->assertEqualsWithDelta(22.5, $respuesta->json('pago.monto'), 0.001, 'Sin monto, paga todo lo que está a pagar.');
        $this->assertSame($vendedor->id, $respuesta->json('pago.vendedor_admin_id'));
        $this->assertFalse($respuesta->json('excede'));
        $this->assertEqualsWithDelta(22.5, $respuesta->json('comision_despues.pagada'), 0.001);
        $this->assertEqualsWithDelta(0.0, $respuesta->json('comision_despues.a_pagar'), 0.001);

        $this->assertSame(0, ComisionPago::where('venta_id', $venta_id)->count(), 'El dry_run no registra ningún pago.');
    }

    /**
     * El pago real: exige el nombre del cliente DE LA VENTA, registra todo lo que está a pagar, y un
     * segundo pago sin `monto` es 422 porque ya no queda nada.
     *
     * @return void
     */
    public function test_el_pago_de_comision_exige_el_nombre_registra_y_no_paga_dos_veces(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $this->claude('clients/' . $client->id . '/cuotas/' . $this->cuota($venta_id, 1)->id . '/pago', [
            'dry_run' => false, 'confirm_client_name' => self::NOMBRE,
        ])->assertStatus(200);

        $ruta = 'ventas/' . $venta_id . '/comision/pagos';

        $sin = $this->claude($ruta, ['dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertStringNotContainsString(self::NOMBRE, $sin->getContent());
        $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => 'Otro'])->assertStatus(422);
        $this->assertSame(0, ComisionPago::where('venta_id', $venta_id)->count());

        // Primera cuota cobrada entera: 135 × 300/900 = 45.
        $real = $this->claude($ruta, [
            'dry_run' => false, 'confirm_client_name' => self::NOMBRE, 'fecha' => '2026-09-14', 'nota' => 'Septiembre',
        ]);
        $real->assertStatus(201);
        $this->assertTrue($real->json('escribio'));
        $this->assertEqualsWithDelta(45.0, $real->json('pago.monto'), 0.001);
        $this->assertFalse($real->json('excede'));
        $this->assertEqualsWithDelta(45.0, $real->json('venta.comision.pagada'), 0.001);
        $this->assertEqualsWithDelta(0.0, $real->json('venta.comision.a_pagar'), 0.001);
        $this->assertCount(1, $real->json('ventas'));

        $this->assertDatabaseHas('comision_pagos', [
            'venta_id' => $venta_id, 'monto' => 45.00, 'moneda' => 'USD', 'nota' => 'Septiembre',
        ]);

        // Ya no queda nada a pagar: 422, tanto en dry_run como en real.
        $this->claude($ruta)->assertStatus(422)->assertJsonStructure(['error']);
        $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => self::NOMBRE])->assertStatus(422);
        $this->assertSame(1, ComisionPago::where('venta_id', $venta_id)->count());
    }

    /**
     * Un `monto` explícito mayor a lo liberado es un adelanto: se registra y `excede` es true.
     *
     * @return void
     */
    public function test_el_pago_de_comision_con_monto_mayor_a_lo_liberado_marca_excede(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de_tres_cuotas($client);
        $ruta = 'ventas/' . $venta_id . '/comision/pagos';

        // Todavía no se cobró nada: no hay nada liberado ni a pagar, pero un adelanto explícito se permite.
        $this->claude($ruta)->assertStatus(422);

        $dry = $this->claude($ruta, ['monto' => 20]);
        $dry->assertStatus(200);
        $this->assertTrue($dry->json('excede'));

        $real = $this->claude($ruta, ['dry_run' => false, 'confirm_client_name' => self::NOMBRE, 'monto' => 20]);
        $real->assertStatus(201);
        $this->assertTrue($real->json('excede'));
        $this->assertEqualsWithDelta(20.0, $real->json('venta.comision.pagada'), 0.001);
    }

    /**
     * Una venta SIN vendedor es 422 (no hay a quién pagarle) en dry_run y en real, con o sin
     * `monto`; una venta inexistente, 404.
     *
     * @return void
     */
    public function test_el_pago_de_comision_de_una_venta_sin_vendedor_es_422(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $venta_id = $this->venta_de($client, [
            'productos'         => ['agentes'],
            'monto_total'       => 900,
            'cantidad_cuotas'   => 3,
            'vendedor_admin_id' => null,
        ]);
        $this->assertNull(Venta::find($venta_id)->vendedor_admin_id);

        $ruta = 'ventas/' . $venta_id . '/comision/pagos';
        $real = ['dry_run' => false, 'confirm_client_name' => self::NOMBRE];

        $this->claude($ruta)->assertStatus(422)->assertJsonStructure(['error']);
        $this->claude($ruta, ['monto' => 10])->assertStatus(422);
        $this->claude($ruta, $real)->assertStatus(422);
        $this->claude($ruta, $real + ['monto' => 10])->assertStatus(422);
        $this->assertSame(0, ComisionPago::where('venta_id', $venta_id)->count());

        $this->claude('ventas/999999999/comision/pagos', $real)->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Cobro dudoso
     |------------------------------------------------------------------------------------------ */

    /**
     * El endpoint de Claude: el dry_run muestra la marca y el resumen antes y después (la deuda pasa
     * de `por_cobrar` a `en_espera`) sin escribir; sin el nombre no se escribe; con el nombre se
     * marca, y después se apaga.
     *
     * @return void
     */
    public function test_cobro_dudoso_por_claude_con_sus_frenos(): void
    {
        $this->crear_vendedor();
        $client = $this->cliente();
        $this->venta_de_tres_cuotas($client);
        $ruta = 'clients/' . $client->id . '/cobro-dudoso';

        $simulado = $this->claude($ruta, ['cobro_dudoso' => true, 'motivo' => 'No contesta']);
        $simulado->assertStatus(200);
        $this->assertTrue($simulado->json('dry_run'));
        $this->assertFalse($simulado->json('escribio'));
        $this->assertFalse($simulado->json('marca_antes.cobro_dudoso'));
        $this->assertTrue($simulado->json('marca_despues.cobro_dudoso'));
        $this->assertSame('2026-09-15', $simulado->json('marca_despues.cobro_dudoso_desde'));
        $this->assertSame('No contesta', $simulado->json('marca_despues.cobro_dudoso_motivo'));
        $this->assertEqualsWithDelta(900.0, $simulado->json('resumen_por_moneda_antes.USD.por_cobrar'), 0.001);
        $this->assertEqualsWithDelta(0.0, $simulado->json('resumen_por_moneda_antes.USD.en_espera'), 0.001);
        $this->assertEqualsWithDelta(0.0, $simulado->json('resumen_por_moneda_despues.USD.por_cobrar'), 0.001);
        $this->assertEqualsWithDelta(900.0, $simulado->json('resumen_por_moneda_despues.USD.en_espera'), 0.001);
        $this->assertEqualsWithDelta(900.0, $simulado->json('resumen_por_moneda_despues.USD.pendiente'), 0.001);
        $this->assertFalse((bool) Client::find($client->id)->cobro_dudoso, 'El dry_run no escribe.');

        $this->claude($ruta, ['cobro_dudoso' => true, 'dry_run' => false])->assertStatus(422);
        $this->claude($ruta, ['cobro_dudoso' => true, 'dry_run' => false, 'confirm_client_name' => 'Otro'])->assertStatus(422);
        $this->assertFalse((bool) Client::find($client->id)->cobro_dudoso);

        $real = $this->claude($ruta, [
            'cobro_dudoso' => true, 'motivo' => 'No contesta', 'dry_run' => false, 'confirm_client_name' => self::NOMBRE,
        ]);
        $real->assertStatus(200);
        $this->assertTrue($real->json('escribio'));
        $this->assertTrue($real->json('cobro_dudoso'));
        $this->assertSame('2026-09-15', $real->json('cobro_dudoso_desde'));
        $this->assertEqualsWithDelta(900.0, $real->json('ventas.0.resumen.en_espera'), 0.001);

        $fila = Client::find($client->id);
        $this->assertTrue((bool) $fila->cobro_dudoso);
        $this->assertSame('No contesta', $fila->cobro_dudoso_motivo);

        $apagado = $this->claude($ruta, ['cobro_dudoso' => false, 'dry_run' => false, 'confirm_client_name' => self::NOMBRE]);
        $apagado->assertStatus(200);
        $this->assertFalse($apagado->json('cobro_dudoso'));
        $this->assertNull(Client::find($client->id)->cobro_dudoso_desde);

        // `cobro_dudoso` es obligatorio; cliente inexistente es 404.
        $this->claude($ruta, [])->assertStatus(422);
        $this->claude('clients/999999999/cobro-dudoso', ['cobro_dudoso' => true])->assertStatus(404);
    }
}
