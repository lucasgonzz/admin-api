<?php

namespace Tests\Feature\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Http;

/**
 * «Días de prueba» desde la ficha del cliente y desde el modal del lead (C3 hacia el ERP).
 */
class DiasDePruebaTest extends BaseDePruebaGratuita
{
    private function prueba_lista(): array
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $uuid      = $this->postear_alta()->json('uuid');
        $lead      = Lead::where('uuid', $uuid)->first();
        $client    = Client::find($lead->promoted_client_id);
        $instancia->refresh();
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100]);

        return [$instancia->fresh(), $lead, $client];
    }

    public function test_el_get_de_la_ficha_describe_la_prueba(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/client/' . $client->id . '/prueba')
            ->assertStatus(200)
            ->assertJson([
                'en_prueba'      => true,
                'estado'         => 'prueba',
                'trial_days'     => 7,
                'trial_ends_at'  => '2026-11-10T15:00:00-03:00',
                'dias_restantes' => 7,
                'url'            => 'https://prueba-test.comerciocity.com',
                'lead_id'        => (int) $lead->id,
            ])
            ->assertJsonPath('instancia.nombre', 'prueba-01');

        $otro = Client::create(['name' => 'Común', 'is_active' => true, 'api_url' => '', 'api_key' => 'x', 'inbound_api_key' => 'y', 'slug' => 'comun-' . uniqid()]);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/client/' . $otro->id . '/prueba')
            ->assertStatus(200)->assertJson(['en_prueba' => false, 'estado' => 'activo']);
    }

    public function test_cambiar_los_dias_desde_el_cliente_recalcula_el_vencimiento_y_avisa_al_erp(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();

        $respuesta = $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/client/' . $client->id . '/prueba', ['trial_days' => 14]);

        $respuesta->assertStatus(200)->assertJson(['trial_days' => 14, 'trial_ends_at' => '2026-11-17T15:00:00-03:00'])->assertJsonPath('c3.estado', 'ok');

        $client->refresh();
        $this->assertSame(14, (int) $client->trial_days);
        $this->assertSame('2026-11-17 15:00:00', $client->trial_ends_at);
        $this->assertSame(14, (int) Lead::find($lead->id)->trial_days);
        $this->assertSame('2026-11-17 15:00:00', Lead::find($lead->id)->demo_ingreso_token_expira_at->format('Y-m-d H:i:s'), 'El token de ingreso vence con la prueba.');

        $c3 = $this->pedidos_al_erp_con('admin-sync/prueba/vencimiento');
        $this->assertCount(1, $c3);
        $this->assertSame('POST', $c3[0]['metodo']);
        $this->assertSame('2026-11-17T15:00:00-03:00', $c3[0]['body']['vence_at']);
    }

    public function test_cambiar_los_dias_desde_el_lead_y_un_erp_viejo_se_tolera(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();
        $this->erp['admin-sync/prueba/vencimiento'] = Http::response('', 404);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/lead/' . $lead->id . '/prueba', ['trial_days' => 3])
            ->assertStatus(200)->assertJson(['trial_days' => 3])->assertJsonPath('c3.estado', 'sin_ruta');

        $this->assertSame(3, (int) $client->fresh()->trial_days);
    }

    public function test_extender_una_prueba_vencida_la_vuelve_a_prueba_y_la_despierta(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();
        $client->update(['estado' => PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, 'trial_ends_at' => '2026-11-02 10:00:00']);
        $instancia->update(['dormida_at' => now()]);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/client/' . $client->id . '/prueba', ['trial_days' => 30])->assertStatus(200);

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, $client->fresh()->estado);
        $this->assertNull($instancia->fresh()->dormida_at);
        $this->assertSame(['prueba-despertar.sh'], $this->vps->scripts_corridos());
    }

    public function test_los_dias_fuera_de_rango_o_un_cliente_que_no_esta_en_prueba_dan_422(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/client/' . $client->id . '/prueba', ['trial_days' => 0])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/client/' . $client->id . '/prueba', ['trial_days' => 400])->assertStatus(422);

        $otro = Client::create(['name' => 'Común', 'is_active' => true, 'api_url' => '', 'api_key' => 'x', 'inbound_api_key' => 'y', 'slug' => 'comun-' . uniqid()]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/client/' . $otro->id . '/prueba', ['trial_days' => 7])->assertStatus(422);

        $this->assertSame(7, (int) $client->fresh()->trial_days);
        $this->assertSame([], $this->pedidos_al_erp);
    }
}
