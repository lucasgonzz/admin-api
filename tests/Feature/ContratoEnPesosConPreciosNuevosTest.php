<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

/**
 * E4, decisión de Lucas (23/11/2026): el editor de contratos precarga los precios nuevos EN PESOS (licencia de
 * $628.400 por sistema, descuento conjunto del 30 % sólo con los tres, tienda $44.900, agentes por paquete).
 * Esta prueba confirma que la API ya guarda y dibuja bien un contrato con ese cuerpo: moneda `ARS` en la
 * licencia y en la mensualidad, el descuento conjunto de $565.560 y el total de $1.319.640 (los mismos números
 * que `PreciosVigentesSettings` devuelve en `tres_juntos`). Si esto fallara, el SPA no podría precargar pesos.
 */
class ContratoEnPesosConPreciosNuevosTest extends BaseDeCobranzas
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin_logueado();
    }

    /**
     * El cuerpo que arma el editor del SPA con la precarga nueva y los tres sistemas.
     *
     * @return array<string, mixed>
     */
    private function cuerpo_con_los_tres(): array
    {
        return [
            'contract_modalidad' => 'licencia',
            'contract_sistemas' => ['gestion', 'ecommerce', 'agentes'],
            'contract_currency' => 'ARS',
            'contract_mensualidad_moneda' => 'ARS',
            'contract_plan_gestion' => 'inicial',
            'contract_precios_sistemas' => [
                'gestion' => ['licencia' => 628400, 'mensualidad' => 55900],
                'ecommerce' => ['licencia' => 628400, 'mensualidad' => 44900],
                'agentes' => ['licencia' => 628400, 'mensualidad' => 46750],
            ],
            'contract_descuento_conjunto' => 565560,
            'contract_mes_gratis' => false,
            'contract_meses_actualizacion' => 6,
        ];
    }

    public function test_los_tres_en_pesos_guardan_el_total_con_descuento_y_el_pdf_lo_muestra(): void
    {
        $lead = new Lead();
        $lead->company_name = 'En pesos '.uniqid();
        $lead->contact_name = 'Prueba';
        $lead->status = 'nuevo';
        $lead->save();

        $this->putJson('/api/admin/lead/'.$lead->id, $this->cuerpo_con_los_tres())->assertOk();
        $lead->refresh();

        $this->assertSame('ARS', $lead->contract_currency);
        $this->assertSame('1319640', $lead->contract_precio_licencia, 'Total = 3 × 628.400 − 565.560.');
        $this->assertSame('147550', $lead->contract_mensualidad_base, 'Gestión 55.900 + tienda 44.900 + agentes 46.750.');

        $html = view('emails.lead.contract', LeadContractPdfService::datos_de_vista($lead))->render();
        $this->assertStringContainsString('ARS 628.400,00', $html);
        $this->assertStringContainsString('- ARS 565.560,00', $html);
        $this->assertStringContainsString('ARS 1.319.640,00', $html);
        $this->assertStringContainsString('Plan Inicial (hasta 200 operaciones por mes)', $html);
        $this->assertStringContainsString('ARS 46.750,00', $html);
    }

    public function test_con_un_solo_sistema_el_descuento_guardado_no_se_aplica(): void
    {
        $lead = new Lead();
        $lead->company_name = 'Un sistema '.uniqid();
        $lead->contact_name = 'Prueba';
        $lead->status = 'nuevo';
        $lead->save();

        $cuerpo = $this->cuerpo_con_los_tres();
        $cuerpo['contract_sistemas'] = ['gestion'];
        $this->putJson('/api/admin/lead/'.$lead->id, $cuerpo)->assertOk();
        $lead->refresh();

        $this->assertSame('628400', $lead->contract_precio_licencia, 'Con un sistema, el descuento conjunto no se resta.');
        $this->assertSame('55900', $lead->contract_mensualidad_base);
    }
}
