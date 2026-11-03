<?php

namespace Tests\Feature\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use Carbon\Carbon;

/**
 * D4 y D7: vencer (dormir), despertar y borrar con respaldo.
 */
class VencerYBorrarPruebasTest extends BaseDePruebaGratuita
{
    /** Una prueba lista, con fecha de vencimiento elegida. */
    private function prueba_lista(string $vence_at): array
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $uuid      = $this->postear_alta()->json('uuid');
        $lead      = Lead::where('uuid', $uuid)->first();
        $client    = Client::find($lead->promoted_client_id);

        $instancia->refresh();
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100, 'password_cifrada' => null]);
        $client->update(['trial_ends_at' => $vence_at]);

        return [$instancia->fresh(), $lead, $client->fresh()];
    }

    public function test_pruebas_vencer_duerme_las_vencidas_y_deja_en_paz_a_las_vigentes(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista('2026-11-03 14:00:00');

        $vigente = $this->crear_instancia_libre('prueba-02', 900200);
        $vigente->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'client_id' => 77777, 'lead_id' => 77777, 'alias_slug' => 'vigente']);
        Client::create(['id' => 77777, 'name' => 'Vigente', 'is_active' => true, 'estado' => 'prueba', 'trial_ends_at' => '2026-11-09 10:00:00', 'api_url' => '', 'api_key' => 'x', 'inbound_api_key' => 'y', 'slug' => 'vigente-' . uniqid()]);

        $this->artisan('pruebas:vencer')->assertExitCode(0);

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, Client::find($client->id)->estado);
        $this->assertNotNull($instancia->fresh()->dormida_at);
        $this->assertSame(["bash /root/prueba-gratuita/prueba-dormir.sh 'prueba-01'"], $this->vps->comandos);

        $this->assertSame('prueba', Client::find(77777)->estado);
        $this->assertNull($vigente->fresh()->dormida_at);

        /* Una segunda corrida no vuelve a tocar el VPS. */
        $this->artisan('pruebas:vencer')->assertExitCode(0);
        $this->assertCount(1, $this->vps->comandos);
    }

    public function test_el_evento_prueba_vencida_por_c2_tambien_duerme_la_instancia(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista('2026-11-10 15:00:00');

        $this->postJson('/api/demo-eventos', ['tipo' => 'prueba.vencida', 'ocurrido_at' => '2026-11-10T15:00:01-03:00'], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token])
            ->assertStatus(200)
            ->assertJson(['guardados' => 1, 'hitos' => 1]);

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, Client::find($client->id)->estado);
        $this->assertNotNull($instancia->fresh()->dormida_at);
        $this->assertSame(['prueba-dormir.sh'], $this->vps->scripts_corridos());
    }

    public function test_despertar_y_dormir_desde_el_panel(): void
    {
        [$instancia] = $this->prueba_lista('2026-11-10 15:00:00');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/prueba/instancias/' . $instancia->id . '/dormir')
            ->assertStatus(200)->assertJsonPath('instancia.dormida', true);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/prueba/instancias/' . $instancia->id . '/despertar')
            ->assertStatus(200)->assertJsonPath('instancia.dormida', false);

        $this->assertSame(['prueba-dormir.sh', 'prueba-despertar.sh'], $this->vps->scripts_corridos());
    }

    public function test_pruebas_borrar_respalda_da_de_baja_borra_el_dns_y_cierra_al_lead(): void
    {
        /* Vencida hace 31 días: pasa la gracia de 30. */
        [$instancia, $lead, $client] = $this->prueba_lista('2026-10-03 10:00:00');
        $client->update(['estado' => PruebaCicloService::CLIENTE_PRUEBA_VENCIDA]);
        $instancia->update(['dormida_at' => Carbon::now()->subDays(31)]);

        $this->vps->responder('prueba-borrar.sh', ['ok' => true, 'respaldo' => '/root/respaldos-pruebas/prueba-test-20261103-1500', 'etapas' => []]);
        /* Zona: antes con los 8 (slug + pool); después del primer DELETE sin los del slug; después del segundo sin ninguno. */
        $todos = ['api-prueba-test', 'prueba-test', 'tienda-prueba-test', 'api-tienda-prueba-test', 'api-prueba-01', 'prueba-01', 'tienda-prueba-01', 'api-tienda-prueba-01'];
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            $this->zona($todos),
            $this->zona(array_slice($todos, 4)),
            $this->zona(array_slice($todos, 4)),
            $this->zona([]),
        ], 'GET');
        $this->hostinger->responder('/api/dns/v1/zones/', [], 'DELETE');
        $this->hostinger->responder('/api/dns/v1/snapshots/', [], 'GET');

        $this->artisan('pruebas:borrar')->assertExitCode(0);

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_BORRADA, $instancia->estado);
        $this->assertSame('/root/respaldos-pruebas/prueba-test-20261103-1500', $instancia->respaldo_ruta);
        $this->assertNotNull($instancia->borrada_at);
        $this->assertSame(["bash /root/prueba-gratuita/prueba-borrar.sh 'prueba-01' 'prueba-test'"], $this->vps->comandos);

        $deletes = $this->hostinger->llamadas_de('DELETE');
        $this->assertCount(2, $deletes);
        $this->assertSame(array_slice($todos, 0, 4), array_column($deletes[0]['body']['filters'], 'name'));
        $this->assertSame(['A', 'A', 'A', 'A'], array_column($deletes[0]['body']['filters'], 'type'));
        $this->assertSame(array_slice($todos, 4), array_column($deletes[1]['body']['filters'], 'name'));

        $client->refresh();
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_BORRADA, $client->estado);
        $this->assertFalse((bool) $client->is_active);
        $this->assertSame('cerrado_perdido', Lead::find($lead->id)->status);
    }

    public function test_pruebas_borrar_no_toca_una_prueba_vencida_hace_menos_de_la_gracia(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista('2026-11-01 10:00:00');
        $client->update(['estado' => PruebaCicloService::CLIENTE_PRUEBA_VENCIDA]);

        $this->artisan('pruebas:borrar')->assertExitCode(0);

        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->fresh()->estado);
        $this->assertSame([], $this->vps->comandos);
    }

    public function test_una_instancia_de_un_cliente_que_contrato_no_se_borra(): void
    {
        [$instancia] = $this->prueba_lista('2026-10-01 10:00:00');
        $instancia->update(['estado' => TrialInstance::ESTADO_CLIENTE]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/prueba/instancias/' . $instancia->id . '/borrar')
            ->assertStatus(422);

        $this->assertSame([], $this->vps->comandos);
    }
}
