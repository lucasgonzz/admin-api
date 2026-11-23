<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;

/**
 * E4, ronda 1 de revisión (23/11/2026): la foto del plan de gestión la arma SIEMPRE el servidor desde la fuente
 * de precios. Nunca se toma de lo que mande el cliente HTTP, ni en el cuerpo ni en la query string (un guardado
 * con `?contract_plan_gestion_foto[hasta]=999999` no puede fijarle a un contrato un rango inventado), y ninguna
 * ruta de guardado la acepta como entrada. Además fija que un contrato precargado por el editor (plan Inicial)
 * deja la foto de `inicial` al guardarse.
 */
class ContratoFotoDelPlanNoSeFalsificaTest extends BaseDeCobranzas
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
        $lead->company_name = 'Foto falsa '.uniqid();
        $lead->contact_name = 'Prueba';
        $lead->status = 'nuevo';
        $lead->save();

        return $lead;
    }

    /** @return array<string, mixed> Cuerpo del editor con el plan Inicial precargado. */
    private function cuerpo(?string $plan = 'inicial'): array
    {
        $cuerpo = [
            'contract_modalidad' => 'licencia',
            'contract_sistemas' => ['gestion'],
            'contract_currency' => 'ARS',
            'contract_mensualidad_moneda' => 'ARS',
            'contract_precios_sistemas' => [
                'gestion' => ['licencia' => 628400, 'mensualidad' => 55900],
                'ecommerce' => ['licencia' => 628400, 'mensualidad' => 44900],
                'agentes' => ['licencia' => 628400, 'mensualidad' => 46750],
            ],
            'contract_descuento_conjunto' => 565560,
            'contract_mes_gratis' => false,
            'contract_meses_actualizacion' => 6,
        ];
        if ($plan !== null) {
            $cuerpo['contract_plan_gestion'] = $plan;
        }

        return $cuerpo;
    }

    /** La foto inventada que se intenta colar. */
    private function foto_falsa(): array
    {
        return ['clave' => 'inicial', 'nombre' => 'Inicial', 'desde' => 0, 'hasta' => 999999, 'usuarios' => 1, 'excedente' => 1, 'abono_lista' => 1, 'politica' => 'falsa'];
    }

    private function html($contrato): string
    {
        return view('emails.lead.contract', LeadContractPdfService::datos_de_vista($contrato))->render();
    }

    /** El contrato precargado por el editor (plan Inicial) guarda la foto de la fuente. */
    public function test_guardar_el_contrato_precargado_deja_la_foto_inicial(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->cuerpo('inicial'))->assertOk();
        $foto = $lead->fresh()->contract_plan_gestion_foto;

        $this->assertIsArray($foto);
        $this->assertSame('inicial', $foto['clave']);
        $this->assertSame(200, $foto['hasta']);
    }

    /** La foto en la query de un PUT de lead con plan nuevo no pisa la que arma el servidor. */
    public function test_el_lead_ignora_la_foto_de_la_query_y_del_cuerpo(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id.'?'.http_build_query(['contract_plan_gestion_foto' => $this->foto_falsa()]);
        $cuerpo = $this->cuerpo('inicial') + ['contract_plan_gestion_foto' => $this->foto_falsa()];

        $this->putJson($url, $cuerpo)->assertOk();
        $lead->refresh();

        $this->assertIsArray($lead->contract_plan_gestion_foto);
        $this->assertSame(200, $lead->contract_plan_gestion_foto['hasta'], 'La foto es la de la fuente, no la de la query ni la del cuerpo.');
        $this->assertSame('2026-11-21', $lead->contract_plan_gestion_foto['politica']);
        $this->assertStringContainsString('Plan Inicial (hasta 200 operaciones por mes)', $this->html($lead));
    }

    /** Sin tocar el plan, una foto en la query no se guarda en un contrato que no tenía. */
    public function test_el_lead_sin_plan_en_el_guardado_no_acepta_una_foto_de_la_query(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->cuerpo(null))->assertOk();

        $url = '/api/admin/lead/'.$lead->id.'?'.http_build_query(['contract_plan_gestion_foto' => $this->foto_falsa()]);
        $this->putJson($url, ['contract_client_name' => 'Otro'])->assertOk();

        $this->assertNull($lead->fresh()->contract_plan_gestion_foto);
    }

    /** Lo mismo en la pestaña del cliente, y el PDF no da 500. */
    public function test_el_cliente_ignora_la_foto_de_la_query_y_del_cuerpo(): void
    {
        $client = $this->crear_cliente();
        $base = '/api/admin/client/'.$client->id.'/contrato';

        /* Sin plan en el guardado: nada. */
        $this->putJson($base.'?'.http_build_query(['contract_plan_gestion_foto' => $this->foto_falsa()]), ['contract_client_name' => 'Otro'])->assertOk();
        $this->assertNull($client->fresh()->contract_plan_gestion_foto);

        /* Con plan nuevo: la del servidor. */
        $cuerpo = $this->cuerpo('inicial') + ['contract_plan_gestion_foto' => $this->foto_falsa()];
        $this->putJson($base.'?'.http_build_query(['contract_plan_gestion_foto' => $this->foto_falsa()]), $cuerpo)->assertOk();
        $client->refresh();
        $this->assertSame(200, $client->contract_plan_gestion_foto['hasta']);
        $this->assertStringContainsString('hasta 200 operaciones por mes', $this->html($client));
        $this->postJson($base.'/pdf', ['incluir_firma' => false])->assertOk();
    }

    /** Ninguna otra ruta de guardado del lead toma la foto del cliente HTTP. */
    public function test_el_resto_de_los_guardados_del_lead_no_aceptan_la_foto(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, ['contract_plan_gestion_foto' => $this->foto_falsa(), 'contract_client_name' => 'X'])->assertOk();
        $this->assertNull($lead->fresh()->contract_plan_gestion_foto);
    }
}
