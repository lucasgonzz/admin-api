<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\ClientContratoService;
use App\Services\ContractPreciosService;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

/** Plan de Gestión por ventas mensuales (política del 26/10/2026) en el contrato de leads y clientes. */
class ContratoPlanGestionTest extends BaseDeCobranzas
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin_logueado();
    }

    private function lead(): Lead
    {
        $lead = new Lead();
        $lead->company_name = 'Plan de prueba '.uniqid();
        $lead->contact_name = 'Prueba';
        $lead->status = 'nuevo';
        $lead->save();
        return $lead;
    }

    private function condiciones(string $plan = 'estandar', int $abono = 71500, array $sistemas = ['gestion']): array
    {
        return [
            'contract_modalidad' => 'licencia',
            'contract_sistemas' => $sistemas,
            'contract_currency' => 'USD',
            'contract_mensualidad_moneda' => 'ARS',
            'contract_plan_gestion' => $plan,
            'contract_precios_sistemas' => [
                'gestion' => ['licencia' => 800, 'mensualidad' => $abono],
                'ecommerce' => ['licencia' => 500, 'mensualidad' => 20000],
                'agentes' => ['licencia' => 500, 'mensualidad' => 30000],
            ],
            'contract_descuento_conjunto' => 300,
            'contract_mes_gratis' => false,
            'contract_meses_actualizacion' => 6,
        ];
    }

    private function html($contrato): string
    {
        return view('emails.lead.contract', LeadContractPdfService::datos_de_vista($contrato))->render();
    }

    public function test_los_tres_planes_y_a_medida_se_guardan_y_el_pdf_muestra_nombre_y_limite(): void
    {
        $esperado = [
            ['inicial', 34200, 'Plan Inicial (hasta 300 ventas por mes)'],
            ['estandar', 71500, 'Plan Estándar (hasta 1.500 ventas por mes)'],
            ['pro', 105600, 'Plan Pro (hasta 5.000 ventas por mes)'],
            ['a_medida', 250000, 'Plan A medida (más de 5.000 ventas por mes)'],
        ];
        foreach ($esperado as [$plan, $abono, $texto]) {
            $lead = $this->lead();
            $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones($plan, $abono))->assertOk();
            $lead->refresh();
            $this->assertSame($plan, $lead->contract_plan_gestion);
            $this->assertSame((string) $abono, $lead->contract_mensualidad_base);
            $html = $this->html($lead);
            $this->assertStringContainsString($texto, $html);
            $this->assertStringContainsString(number_format($abono, 2, ',', '.'), $html);
        }
    }

    public function test_el_abono_sigue_siendo_editable_aunque_haya_plan(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('estandar', 65000))->assertOk();
        $lead->refresh();
        $this->assertSame('estandar', $lead->contract_plan_gestion);
        $this->assertSame('65000', $lead->contract_mensualidad_base);
        $this->assertStringContainsString('65.000,00', $this->html($lead));
    }

    public function test_plan_invalido_da_422_y_vacio_es_sin_plan(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        $this->putJson($url, $this->condiciones('premium'))->assertStatus(422);
        $this->putJson($url, $this->condiciones('estandar'))->assertOk();
        $this->putJson($url, array_merge($this->condiciones(), ['contract_plan_gestion' => null]))->assertOk();
        $lead->refresh();
        $this->assertNull($lead->contract_plan_gestion);
        $this->assertStringNotContainsString('Plan Estándar', $this->html($lead));
        $this->assertStringContainsString('Abono del sistema contratado', $this->html($lead));
    }

    public function test_un_guardado_sin_el_campo_no_toca_el_plan_ni_los_contratos_sin_plan_cambian(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        $this->putJson($url, $this->condiciones('pro', 105600))->assertOk();
        $this->putJson($url, ['contract_client_name' => 'Otro nombre'])->assertOk();
        $this->assertSame('pro', $lead->fresh()->contract_plan_gestion);

        // Contrato guardado antes del plan: desglose sin plan, nada se recalcula ni se muestra.
        $viejo = $this->lead();
        $datos = $this->condiciones();
        unset($datos['contract_plan_gestion']);
        $this->putJson('/api/admin/lead/'.$viejo->id, $datos)->assertOk();
        $viejo->refresh();
        $this->assertNull($viejo->contract_plan_gestion);
        $this->assertSame('71500', $viejo->contract_mensualidad_base);
        $this->assertStringNotContainsString('Plan Estándar', $this->html($viejo));
    }

    public function test_el_plan_no_se_muestra_si_gestion_no_esta_elegido_ni_sin_desglose(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('pro', 105600, ['ecommerce']))->assertOk();
        $this->assertStringNotContainsString('Plan Pro', $this->html($lead->fresh()));

        $historico = $this->lead();
        $historico->contract_plan_gestion = 'pro';
        $historico->save();
        $this->assertStringNotContainsString('Plan Pro', $this->html($historico->fresh()));
    }

    public function test_el_plan_se_copia_al_cliente_y_se_guarda_y_devuelve_desde_la_pestana_del_cliente(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('inicial', 34200))->assertOk();
        $client = $this->crear_cliente();
        app(ClientContratoService::class)->copiar_desde_lead($lead->fresh(), $client);
        $this->assertSame('inicial', $client->fresh()->contract_plan_gestion);
        $url = '/api/admin/client/'.$client->id.'/contrato';
        $this->getJson($url)->assertOk()->assertJsonPath('contrato.contract_plan_gestion', 'inicial');
        $this->putJson($url, ['contract_plan_gestion' => 'pro', 'contract_precios_sistemas' => $this->condiciones('pro', 105600)['contract_precios_sistemas']])
            ->assertOk()->assertJsonPath('contrato.contract_plan_gestion', 'pro');
        $this->putJson($url, ['contract_plan_gestion' => 'otro'])->assertStatus(422);
        $this->postJson($url.'/pdf', ['incluir_firma' => false])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Plan Pro (hasta 5.000 ventas por mes)', $this->html($client->fresh()));
    }

    public function test_con_pago_anual_la_fila_de_gestion_conserva_la_referencia_mensual(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, array_merge($this->condiciones('estandar', 71500), ['contract_pago_anual' => true]))->assertOk();
        $html = $this->html($lead->fresh());
        $this->assertStringContainsString('Plan Estándar (hasta 1.500 ventas por mes); referencia mensual cubierta en el pago anual', $html);
        $this->assertStringContainsString('686.400', $html);
    }

    public function test_la_tabla_de_planes_coincide_con_la_politica(): void
    {
        $this->assertSame([34200, 71500, 105600, null], array_column(array_values(ContractPreciosService::PLANES_GESTION), 'abono'));
        $this->assertSame([300, 1500, 5000, null], array_column(array_values(ContractPreciosService::PLANES_GESTION), 'limite'));
    }
}
