<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Models\Venta;
use App\Services\ClientContratoService;
use App\Services\RunUserSetupService;
use Illuminate\Support\Facades\Storage;

/**
 * La venta que nace al promover un lead con contrato (misión ventas-y-comisiones, 28/10/2026).
 *
 * Lo que estas pruebas protegen:
 *
 *  1. 🔴 **Promover un lead crea UNA venta con las cuotas del contrato enganchadas**, origen
 *     `contrato`, con el lead de origen, los sistemas del contrato, el vendedor único y el
 *     porcentaje por defecto (30 con gestión en Suscripción, 15 si no).
 *  2. **Un cliente que ya tiene cuotas no recibe nada**: ni cuotas ni venta.
 *  3. Con varios vendedores la venta nace sin vendedor (se elige a mano), pero con su porcentaje.
 */
class VentaDesdeElContratoTest extends BaseDeVentas
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mismo motivo que ContratoDelClienteTest: nada del contrato puede leer el disco real.
        Storage::fake('local');
    }

    /**
     * Un lead ganado con contrato: licencia de 1.500 financiada en dos cuotas.
     *
     * @param array<string, mixed> $atributos
     *
     * @return Lead
     */
    private function crear_lead_con_contrato(array $atributos = []): Lead
    {
        $lead = new Lead();
        $lead->contact_name                       = 'Prospecto Ventas';
        $lead->company_name                       = 'Comercio con venta ' . uniqid();
        $lead->email                              = 'venta@test.local';
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

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $lead->save();

        return $lead->refresh();
    }

    /**
     * Promover un lead en Suscripción que compró gestión y e-commerce: una venta de 1.500 con las
     * dos cuotas, el vendedor único y el 30 %.
     *
     * @return void
     */
    public function test_promover_el_lead_crea_la_venta_con_vendedor_y_cuotas_enlazadas(): void
    {
        $vendedor = $this->crear_vendedor();
        $lead = $this->crear_lead_con_contrato([
            'contract_modalidad' => 'suscripcion',
            'contract_sistemas'  => ['ecommerce', 'gestion'],
        ]);

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');
        $this->assertInstanceOf(Client::class, $client);

        $ventas = Venta::where('client_id', $client->id)->get();
        $this->assertCount(1, $ventas, 'La promoción crea exactamente una venta.');

        $venta = $ventas->first();
        $this->assertSame('contrato', $venta->origen);
        $this->assertSame($lead->id, $venta->lead_id);
        $this->assertSame(['gestion', 'ecommerce'], $venta->productos);
        $this->assertEqualsWithDelta(1500.0, (float) $venta->monto_total, 0.001);
        $this->assertSame('USD', $venta->moneda);
        $this->assertSame('2026-09-10', $venta->fecha->toDateString(), 'La fecha de la venta es la de emisión del contrato.');
        $this->assertSame($vendedor->id, $venta->vendedor_admin_id);
        $this->assertEqualsWithDelta(30.0, (float) $venta->comision_porcentaje, 0.001, 'Gestión en Suscripción: 30 %.');
        $this->assertEqualsWithDelta(450.0, (float) $venta->comision_monto, 0.001);

        $cuotas = LicenciaCuota::where('client_id', $client->id)->orderBy('numero')->get();
        $this->assertCount(2, $cuotas);
        foreach ($cuotas as $cuota) {
            $this->assertSame($venta->id, $cuota->venta_id, 'Las cuotas del contrato quedan enganchadas a la venta.');
        }

        // Y se ve en la pestaña Ventas, sin cuotas sueltas.
        $this->admin_logueado();
        $lista = $this->getJson('/api/admin/client/' . $client->id . '/ventas');
        $lista->assertStatus(200);
        $this->assertCount(1, $lista->json('ventas'));
        $this->assertCount(0, $lista->json('cuotas_sin_venta'));
        $this->assertCount(2, $lista->json('ventas.0.cuotas'));
    }

    /**
     * Un contrato histórico (sin modalidad ni sistemas): la venta es del sistema de gestión, al
     * 15 %. Con dos vendedores, sin vendedor.
     *
     * @return void
     */
    public function test_contrato_historico_y_varios_vendedores(): void
    {
        $this->crear_vendedor('Uno');
        $this->crear_vendedor('Otro');
        $lead = $this->crear_lead_con_contrato();

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');

        $venta = Venta::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(['gestion'], $venta->productos, 'Sin sistemas en el contrato, la venta es del sistema de gestión.');
        $this->assertNull($venta->vendedor_admin_id, 'Con dos vendedores no hay uno por defecto.');
        $this->assertEqualsWithDelta(15.0, (float) $venta->comision_porcentaje, 0.001);
        $this->assertEqualsWithDelta(225.0, (float) $venta->comision_monto, 0.001);
    }

    /**
     * Un cliente que ya tiene cuotas no recibe nada: ni cuotas nuevas ni venta.
     *
     * @return void
     */
    public function test_un_cliente_con_cuotas_no_recibe_ni_cuotas_ni_venta(): void
    {
        $this->crear_vendedor();
        $client = $this->crear_cliente([
            'contract_currency'        => 'USD',
            'contract_precio_licencia' => '1500',
        ]);

        LicenciaCuota::create([
            'client_id' => $client->id,
            'numero'    => 1,
            'periodo'   => '2026-08',
            'monto'     => 700,
            'moneda'    => 'USD',
            'estado'    => 'pagada',
            'importado' => true,
        ]);

        $creadas = app(ClientContratoService::class)->generar_cuotas_desde_contrato($client);

        $this->assertSame(0, $creadas);
        $this->assertSame(1, LicenciaCuota::where('client_id', $client->id)->count());
        $this->assertSame(0, Venta::where('client_id', $client->id)->count());

        // Sin cuotas previas, el botón "Generar cuotas desde el contrato" también arma la venta.
        $nuevo = $this->crear_cliente([
            'contract_currency'        => 'USD',
            'contract_precio_licencia' => '1.200',
        ]);
        $this->admin_logueado();
        $this->postJson('/api/admin/client/' . $nuevo->id . '/licencias/desde-contrato')->assertStatus(200);

        $venta = Venta::where('client_id', $nuevo->id)->firstOrFail();
        $this->assertEqualsWithDelta(1200.0, (float) $venta->monto_total, 0.001);
        $this->assertSame('contrato', $venta->origen);
        $this->assertSame(1, LicenciaCuota::where('venta_id', $venta->id)->count());
    }
}
