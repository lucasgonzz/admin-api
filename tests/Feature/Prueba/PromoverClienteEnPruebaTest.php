<?php

namespace Tests\Feature\Prueba;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Http;

/**
 * D6: `promote-to-client` sobre un lead con la prueba en curso CONTRATA, sin user setup y sin reinstalar.
 */
class PromoverClienteEnPruebaTest extends BaseDePruebaGratuita
{
    private function prueba_lista(bool $dormida = false): array
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $uuid      = $this->postear_alta()->json('uuid');
        $lead      = $this->lead_del_alta($uuid);
        $client    = Client::find($lead->promoted_client_id);

        $instancia->refresh();
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100, 'password_cifrada' => null, 'dormida_at' => $dormida ? now() : null]);
        if ($dormida) {
            $client->update(['estado' => PruebaCicloService::CLIENTE_PRUEBA_VENCIDA]);
        }

        return [$instancia->fresh(), $lead, $client->fresh()];
    }

    public function test_promover_un_lead_en_prueba_contrata_sin_user_setup_y_avisa_al_erp(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();
        $apis_antes = ClientApi::where('client_id', $client->id)->count();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/promote-to-client', ['suggested_subdomain' => 'otro-slug'])
            ->assertStatus(200);

        $lead->refresh();
        $client->refresh();
        $this->assertSame('cerrado_ganado', $lead->status);
        $this->assertSame('exitoso', $lead->user_setup_status, 'El candado del user setup lo ve como configurado.');
        $this->assertSame((int) $client->id, (int) $lead->promoted_client_id, 'El mismo cliente: no se creó otro.');
        $this->assertSame(PruebaCicloService::CLIENTE_ACTIVO, $client->estado);
        $this->assertNull($client->trial_ends_at);
        $this->assertSame($apis_antes, ClientApi::where('client_id', $client->id)->count(), 'No se crean ClientApi nuevas.');
        $this->assertSame(TrialInstance::ESTADO_CLIENTE, $instancia->fresh()->estado);

        /* C3 con vence_at null, y NINGÚN user-setup ni demo-setup. */
        $vencimientos = $this->pedidos_al_erp_con('admin-sync/prueba/vencimiento');
        $this->assertCount(1, $vencimientos);
        $this->assertSame('https://api-prueba-test.comerciocity.com/api/admin-sync/prueba/vencimiento', $vencimientos[0]['url']);
        $this->assertNull($vencimientos[0]['body']['vence_at']);
        $this->assertSame([$client->api_key], $vencimientos[0]['headers']['X-Admin-Api-Key']);
        $this->assertSame([], $this->pedidos_al_erp_con('user-setup'));
        $this->assertSame([], $this->pedidos_al_erp_con('demo-setup'));
        $this->assertSame([], $this->vps->comandos, 'Despierta no estaba dormida: nada que levantar.');

        /* Recién ahora Meta ve la compra. */
        $this->assertContains('Purchase', LeadEventoMeta::where('lead_id', $lead->id)->pluck('evento')->all());
    }

    public function test_contratar_una_prueba_vencida_la_despierta(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista(true);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/lead/' . $lead->id . '/promote-to-client')->assertStatus(200);

        $this->assertSame(PruebaCicloService::CLIENTE_ACTIVO, $client->fresh()->estado);
        $this->assertNull($instancia->fresh()->dormida_at);
        $this->assertSame(['prueba-despertar.sh'], $this->vps->scripts_corridos());
    }

    public function test_un_erp_viejo_sin_la_ruta_de_c3_no_frena_la_contratacion(): void
    {
        [$instancia, $lead, $client] = $this->prueba_lista();
        $this->erp['admin-sync/prueba/vencimiento'] = Http::response(['message' => 'Not Found'], 404);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/lead/' . $lead->id . '/promote-to-client')->assertStatus(200);

        $this->assertSame(PruebaCicloService::CLIENTE_ACTIVO, $client->fresh()->estado);
        $this->assertSame('cerrado_ganado', $lead->fresh()->status);
    }
}
