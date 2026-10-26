<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\ClientContratoService;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

class ContratoPreciosPorSistemaTest extends BaseDeCobranzas
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
        $lead->company_name = 'Contrato de prueba '.uniqid();
        $lead->contact_name = 'Prueba';
        $lead->status = 'nuevo';
        $lead->save();
        return $lead;
    }

    private function condiciones(): array
    {
        return [
            'contract_modalidad' => 'licencia',
            'contract_sistemas' => ['gestion', 'ecommerce', 'agentes'],
            'contract_currency' => 'USD',
            'contract_mensualidad_moneda' => 'ARS',
            'contract_precios_sistemas' => [
                'gestion' => ['licencia' => 800, 'mensualidad' => 100000],
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

    public function test_guarda_desglose_y_deriva_totales_del_lead_sin_confiar_en_el_frontend(): void
    {
        $lead = $this->lead();
        $datos = array_merge($this->condiciones(), ['contract_precio_licencia' => '999', 'contract_mensualidad_base' => '888']);
        $this->putJson('/api/admin/lead/'.$lead->id, $datos)->assertOk();
        $lead->refresh();
        $this->assertSame('1500', $lead->contract_precio_licencia);
        $this->assertSame('150000', $lead->contract_mensualidad_base);
        $this->assertEquals($datos['contract_precios_sistemas'], $lead->contract_precios_sistemas);
        $this->assertFalse($lead->contract_mes_gratis);
        $this->assertSame(300.0, $lead->contract_descuento_conjunto);
        $html = $this->html($lead);
        foreach (['800,00', '500,00', '1.800,00', '300,00', '1.500,00', '100.000,00', '20.000,00', '30.000,00', '150.000', 'seis (6) meses', 'Descuento por contratar los tres sistemas', 'de por vida'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringNotContainsString('Un mes gratis:', $html);
    }

    public function test_los_precios_y_descuento_editados_se_conservan_y_se_copian_al_cliente(): void
    {
        $lead = $this->lead();
        $datos = $this->condiciones();
        $datos['contract_precios_sistemas']['gestion'] = ['licencia' => 900, 'mensualidad' => 110000];
        $datos['contract_descuento_conjunto'] = 250;
        $datos['contract_mes_gratis'] = true;
        $datos['contract_meses_actualizacion'] = 9;
        $this->putJson('/api/admin/lead/'.$lead->id, $datos)->assertOk();
        $lead->refresh();
        $this->assertSame('1650', $lead->contract_precio_licencia);
        $this->assertSame('160000', $lead->contract_mensualidad_base);
        $client = $this->crear_cliente();
        app(ClientContratoService::class)->copiar_desde_lead($lead, $client);
        $client->refresh();
        $this->assertEquals($lead->contract_precios_sistemas, $client->contract_precios_sistemas);
        $this->assertSame(250.0, $client->contract_descuento_conjunto);
        $this->assertTrue($client->contract_mes_gratis);
        $this->assertSame(9, $client->contract_meses_actualizacion);
        $html = $this->html($client);
        $this->assertStringContainsString('Un mes gratis:', $html);
        $this->assertStringContainsString('nueve (9) meses', $html);
        $this->getJson('/api/admin/client/'.$client->id.'/contrato')->assertOk()->assertJsonPath('contrato.contract_mes_gratis', true);
    }

    public function test_sin_los_tres_no_hay_descuento_y_cero_es_un_precio_valido(): void
    {
        $lead = $this->lead();
        $datos = $this->condiciones();
        $datos['contract_sistemas'] = ['gestion', 'agentes'];
        $datos['contract_precios_sistemas']['agentes'] = ['licencia' => 0, 'mensualidad' => 0];
        $this->putJson('/api/admin/lead/'.$lead->id, $datos)->assertOk();
        $lead->refresh();
        $this->assertSame('800', $lead->contract_precio_licencia);
        $this->assertSame('100000', $lead->contract_mensualidad_base);
        $this->assertStringNotContainsString('Descuento por contratar los tres sistemas', $this->html($lead));
        $this->assertStringNotContainsString('ComercioCity E-Commerce', $this->html($lead));
    }

    public function test_guardar_cliente_y_actualizacion_parcial_preservan_el_desglose(): void
    {
        $client = $this->crear_cliente();
        $url = '/api/admin/client/'.$client->id.'/contrato';
        $this->putJson($url, $this->condiciones())->assertOk()->assertJsonPath('contrato.contract_precio_licencia', '1500');
        $this->putJson($url, ['contract_client_name' => 'Otro nombre'])->assertOk();
        $this->assertSame(300.0, $client->fresh()->contract_descuento_conjunto);
        $this->assertFalse($client->fresh()->contract_mes_gratis);
        $this->putJson($url, ['contract_sistemas' => ['gestion']])->assertOk()->assertJsonPath('contrato.contract_precio_licencia', '800');
        $this->postJson($url.'/pdf', ['incluir_firma' => false])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_validacion_impide_importes_invalidos_y_descuento_mayor_al_subtotal(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        foreach ([-1, 1801] as $descuento) {
            $this->putJson($url, array_merge($this->condiciones(), ['contract_descuento_conjunto' => $descuento]))->assertStatus(422);
        }
        $datos = $this->condiciones();
        $datos['contract_precios_sistemas']['gestion']['licencia'] = -20;
        $this->putJson($url, $datos)->assertStatus(422);
        $datos['contract_precios_sistemas']['gestion']['licencia'] = 800.005;
        $this->putJson($url, $datos)->assertStatus(422);
        $datos = $this->condiciones();
        unset($datos['contract_precios_sistemas']['gestion']);
        $this->putJson($url, $datos)->assertStatus(422);
        $this->putJson($url, array_merge($this->condiciones(), ['contract_modalidad' => 'suscripcion']))->assertStatus(422);
        $this->putJson($url, array_merge($this->condiciones(), ['contract_precios_sistemas' => []]))->assertStatus(422);
        $this->putJson($url, array_merge($this->condiciones(), ['contract_sistemas' => ['gestion', ['agentes']]]))->assertStatus(422);
        $client = $this->crear_cliente();
        $url_cliente = '/api/admin/client/'.$client->id.'/contrato';
        $this->putJson($url_cliente, array_merge($this->condiciones(), ['contract_sistemas' => ['gestion', ['agentes']]]))->assertStatus(422);
        $this->putJson($url_cliente, array_merge($this->condiciones(), ['contract_precios_sistemas' => []]))->assertStatus(422);
        $this->assertNull($client->fresh()->contract_precios_sistemas);
        $this->assertNull($lead->fresh()->contract_precios_sistemas);
    }

    public function test_contratos_anteriores_conservan_importes_y_pdf_sin_desglose(): void
    {
        $lead = $this->lead();
        $lead->contract_precio_licencia = '1.200';
        $lead->contract_mensualidad_base = '140000';
        $lead->contract_meses_actualizacion = 3;
        $lead->save();
        $anterior = $this->html($lead);
        $this->putJson('/api/admin/lead/'.$lead->id, ['contract_client_name' => 'Actualizado'])->assertOk();
        $lead->refresh();
        $this->assertSame('1.200', $lead->contract_precio_licencia);
        $this->assertNull($lead->contract_precios_sistemas);
        $this->assertSame(3, $lead->contract_meses_actualizacion);
        $this->assertStringNotContainsString('Licencia individual', $anterior);
        $this->assertStringNotContainsString('Un mes gratis:', $anterior);
    }

    public function test_vaciar_el_descuento_es_cero_en_guardado_y_en_pdf(): void
    {
        foreach ([null, ''] as $valor) {
            $lead = $this->lead();
            $datos = array_merge($this->condiciones(), ['contract_descuento_conjunto' => $valor]);
            $this->putJson('/api/admin/lead/'.$lead->id, $datos)->assertOk();
            $lead->refresh();
            $this->assertSame(0.0, $lead->contract_descuento_conjunto);
            $this->assertSame('1800', $lead->contract_precio_licencia);
            $vista = LeadContractPdfService::datos_de_vista($lead);
            $this->assertSame(0.0, $vista['desglose_precios']['descuento']);
            $this->assertEquals(1800, $vista['desglose_precios']['total']);
        }
    }

    public function test_ipc_fuera_de_rango_no_se_guarda_en_lead(): void
    {
        $lead = $this->lead();
        foreach ([0, 61, 2.5] as $meses) {
            $this->putJson('/api/admin/lead/'.$lead->id, array_merge($this->condiciones(), ['contract_meses_actualizacion' => $meses]))->assertStatus(422);
        }
        $this->assertNull($lead->fresh()->contract_precios_sistemas);
    }

    public function test_cotizador_anterior_no_reemplaza_el_desglose_acordado(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones())->assertOk();
        $this->postJson('/api/admin/lead/'.$lead->id.'/cotizacion/link-pago', [
            'dolar' => 1400, 'sistemas' => [['key' => 'gestion', 'precio_usd' => 200]],
        ])->assertStatus(422);
        $lead->refresh();
        $this->assertSame('1500', $lead->contract_precio_licencia);
        $this->assertNull($lead->contract_cotizacion_link_pago);
        $this->assertEquals($this->condiciones()['contract_precios_sistemas'], $lead->contract_precios_sistemas);
    }
}
