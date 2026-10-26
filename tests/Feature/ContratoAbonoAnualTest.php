<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\ClientContratoService;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

class ContratoAbonoAnualTest extends BaseDeCobranzas
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
        $lead->company_name = 'Abono anual '.uniqid();
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
            'contract_usuarios_extra' => 2,
            'contract_precio_usuario_extra' => '15000',
            'contract_pago_anual' => true,
            'contract_meses_actualizacion' => 6,
        ];
    }

    private function html($contrato): string
    {
        return view('emails.lead.contract', LeadContractPdfService::datos_de_vista($contrato))->render();
    }

    public function test_el_anual_excluye_usuarios_extra_y_su_total_se_deriva_en_el_servidor(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, array_merge($this->condiciones(), [
            'contract_total_anual' => 1, 'contract_mensualidad_base' => '1',
        ]))->assertOk();
        $lead->refresh();
        $this->assertTrue($lead->contract_pago_anual);
        $this->assertSame(20.0, $lead->contract_descuento_anual);
        $this->assertSame('150000', $lead->contract_mensualidad_base);
        $vista = LeadContractPdfService::datos_de_vista($lead);
        $this->assertEquals(1800000, $vista['abono_anual']['bruto']);
        $this->assertEquals(360000, $vista['abono_anual']['descuento']);
        $this->assertEquals(1440000, $vista['abono_anual']['total']);
        $this->assertEquals(30000, $vista['adicionales_mensuales']);
        $html = $this->html($lead);
        foreach (['1.440.000,00', '1.800.000,00', '360.000,00', '30.000,00', '20,00%', 'TOTAL ANUAL ANTICIPADO', 'ADICIONALES A ABONAR MES A MES', 'no reciben descuento anual', 'no se vuelve a cobrar mensualmente', 'no se ajusta por IPC durante los doce'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertStringNotContainsString('TOTAL MENSUAL', $html);
        $this->assertStringContainsString('al precio por usuario pactado en este contrato, según la cantidad existente cada mes', $html);
        $this->assertStringNotContainsString('los usuarios adicionales que se sumen después de la firma', $html);
    }

    public function test_el_porcentaje_editado_y_cero_llegan_al_cliente_sin_recalcular_condiciones(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        $this->putJson($url, array_merge($this->condiciones(), ['contract_descuento_anual' => 12.5]))->assertOk();
        $this->assertEquals(1575000, LeadContractPdfService::datos_de_vista($lead->fresh())['abono_anual']['total']);
        $client = $this->crear_cliente();
        app(ClientContratoService::class)->copiar_desde_lead($lead->fresh(), $client);
        $client->refresh();
        $this->assertTrue($client->contract_pago_anual);
        $this->assertSame(12.5, $client->contract_descuento_anual);
        $url_cliente = '/api/admin/client/'.$client->id.'/contrato';
        $this->getJson($url_cliente)->assertOk()->assertJsonPath('contrato.contract_descuento_anual', 12.5);
        $this->putJson($url_cliente, ['contract_descuento_anual' => 0])->assertOk()->assertJsonPath('contrato.contract_descuento_anual', 0);
        $this->assertEquals(1800000, LeadContractPdfService::datos_de_vista($client->fresh())['abono_anual']['total']);
        $this->putJson($url_cliente, ['contract_client_name' => 'Conservar anual'])->assertOk();
        $this->assertTrue($client->fresh()->contract_pago_anual);
        $this->assertSame(0.0, $client->fresh()->contract_descuento_anual);
        $this->postJson($url_cliente.'/pdf', ['incluir_firma' => false])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_vaciar_descuento_equivale_a_cero_en_ui_api_y_pdf(): void
    {
        foreach ([null, ''] as $valor) {
            $lead = $this->lead();
            $this->putJson('/api/admin/lead/'.$lead->id, array_merge($this->condiciones(), ['contract_descuento_anual' => $valor]))->assertOk();
            $this->assertSame(0.0, $lead->fresh()->contract_descuento_anual);
            $this->assertEquals(1800000, LeadContractPdfService::datos_de_vista($lead->fresh())['abono_anual']['total']);
        }
    }

    public function test_valida_porcentajes_y_booleanos_sin_escritura_parcial(): void
    {
        $lead = $this->lead();
        $client = $this->crear_cliente();
        foreach ([-1, 101, 20.001, 'NaN', []] as $porcentaje) {
            $datos = array_merge($this->condiciones(), ['contract_descuento_anual' => $porcentaje]);
            $this->putJson('/api/admin/lead/'.$lead->id, $datos)->assertStatus(422);
            $this->putJson('/api/admin/client/'.$client->id.'/contrato', $datos)->assertStatus(422);
        }
        $this->putJson('/api/admin/lead/'.$lead->id, array_merge($this->condiciones(), ['contract_pago_anual' => 'no']))->assertStatus(422);
        $this->assertNull($lead->fresh()->contract_pago_anual);
        $this->assertNull($client->fresh()->contract_pago_anual);
    }

    public function test_el_anual_historico_requiere_base_valida_y_acepta_importes_argentinos(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        foreach ([null, '', [], 'sin precio', 'sinprecio2026', '-100', '1.2.3'] as $base) {
            $this->putJson($url, ['contract_pago_anual' => true, 'contract_mensualidad_base' => $base])->assertStatus(422);
        }
        $this->putJson($url, [
            'contract_pago_anual' => true, 'contract_mensualidad_base' => 'ARS 150.000',
            'contract_precio_usuario_extra' => '15.000,50', 'contract_usuarios_extra' => 2,
        ])->assertOk();
        $vista = LeadContractPdfService::datos_de_vista($lead->fresh());
        $this->assertEquals(1440000, $vista['abono_anual']['total']);
        $this->assertEquals(30001, $vista['adicionales_mensuales']);
        $this->assertStringContainsString('30.001,00', $this->html($lead->fresh()));
    }

    public function test_precio_por_usuario_visible_sin_extras_y_cero_pactado_no_se_reemplaza(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        $this->putJson($url, array_merge($this->condiciones(), ['contract_usuarios_extra' => 0, 'contract_pago_anual' => false]))->assertOk();
        $html = $this->html($lead->fresh());
        $this->assertStringContainsString('Precio por cada usuario extra:', $html);
        $this->assertStringContainsString('ARS 15.000', $html);
        $this->assertStringNotContainsString('TOTAL ANUAL ANTICIPADO', $html);
        $this->putJson($url, ['contract_precio_usuario_extra' => '0', 'contract_usuarios_extra' => 2, 'contract_pago_anual' => true])->assertOk();
        $this->assertSame('0', $lead->fresh()->contract_precio_usuario_extra);
        $this->assertEquals(0, LeadContractPdfService::datos_de_vista($lead->fresh())['adicionales_mensuales']);
        $this->assertEquals(1440000, LeadContractPdfService::datos_de_vista($lead->fresh())['abono_anual']['total']);
    }

    public function test_anual_y_mes_gratis_cubren_doce_meses_pagos_sin_mover_fechas(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, array_merge($this->condiciones(), [
            'contract_mes_gratis' => true, 'contract_fecha_primer_pago_mensual' => '2026-12-17',
            'contract_meses_actualizacion' => 9,
        ]))->assertOk();
        $lead->refresh();
        $this->assertSame('2026-12-17', $lead->contract_fecha_primer_pago_mensual->format('Y-m-d'));
        $this->assertEquals(1440000, LeadContractPdfService::datos_de_vista($lead)['abono_anual']['total']);
        $html = $this->html($lead);
        $this->assertStringContainsString('Los doce (12) meses pagos del abono anual comienzan en el período siguiente al mes bonificado', $html);
        $this->assertStringContainsString('primer pago anual anticipado', $html);
        $this->assertStringContainsString('nueve (9) meses', $html);
    }

    public function test_anual_desactivado_no_reescribe_los_importes_historicos(): void
    {
        $lead = $this->lead();
        $lead->contract_mensualidad_base = '150.000';
        $lead->contract_precio_usuario_extra = null;
        $lead->contract_meses_actualizacion = 3;
        $lead->save();
        $this->putJson('/api/admin/lead/'.$lead->id, ['contract_client_name' => 'Solo el nombre'])->assertOk();
        $lead->refresh();
        $this->assertSame('150.000', $lead->contract_mensualidad_base);
        $this->assertNull($lead->contract_pago_anual);
        $this->assertNull($lead->contract_descuento_anual);
        $this->assertNull($lead->contract_precio_usuario_extra);
        $html = $this->html($lead);
        $this->assertStringNotContainsString('TOTAL ANUAL ANTICIPADO', $html);
        $this->assertStringContainsString('sin precio pactado en este contrato', $html);
        $this->assertStringContainsString('tres (3) meses', $html);
    }

    public function test_no_reinterpreta_un_precio_extra_invalido_y_conserva_cero_explicito(): void
    {
        $lead = $this->lead();
        $client = $this->crear_cliente();
        $url_lead = '/api/admin/lead/'.$lead->id;
        $url_cliente = '/api/admin/client/'.$client->id.'/contrato';
        $this->putJson($url_lead, $this->condiciones())->assertOk();
        $this->putJson($url_cliente, $this->condiciones())->assertOk();
        foreach ([true, false] as $anual) {
            foreach (['-100', 'abc15000', [], null, ''] as $precio) {
                $cambio = ['contract_pago_anual' => $anual, 'contract_precio_usuario_extra' => $precio];
                $this->putJson($url_lead, $cambio)->assertStatus(422);
                $this->putJson($url_cliente, $cambio)->assertStatus(422);
            }
        }
        $this->assertSame('15000', $lead->fresh()->contract_precio_usuario_extra);
        $this->assertSame('15000', $client->fresh()->contract_precio_usuario_extra);
        $this->putJson($url_lead, ['contract_precio_usuario_extra' => 0])->assertOk();
        $this->putJson($url_cliente, ['contract_precio_usuario_extra' => 0])->assertOk();
        $this->assertSame('0', $lead->fresh()->contract_precio_usuario_extra);
        $this->assertSame('0', $client->fresh()->contract_precio_usuario_extra);
    }
}
