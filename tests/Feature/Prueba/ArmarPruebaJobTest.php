<?php

namespace Tests\Feature\Prueba;

use App\Jobs\ArmarPruebaJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use Illuminate\Support\Facades\Http;

/**
 * `ArmarPruebaJob`: las etapas del alta en el VPS, con el runner, Hostinger y el ERP falsificados.
 */
class ArmarPruebaJobTest extends BaseDePruebaGratuita
{
    /** Un alta hecha, con la instancia `asignando` y el job todavía sin correr (la cola está falseada). */
    private function alta(): TrialInstance
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $this->postear_alta()->assertStatus(201);

        return $instancia->fresh();
    }

    public function test_el_camino_feliz_recorre_las_etapas_y_deja_la_prueba_lista(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $client    = Client::find($instancia->client_id);
        $this->zona_antes_y_despues(['prueba-test', 'api-prueba-test', 'tienda-prueba-test', 'api-tienda-prueba-test']);

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->estado, (string) $instancia->error);
        $this->assertSame('lista', $instancia->etapa);
        $this->assertSame(100, (int) $instancia->progreso);
        $this->assertNotNull($instancia->lista_at);
        $this->assertNull($instancia->password_cifrada, 'La contraseña cifrada se borra al terminar.');
        $this->assertSame('exitoso', Lead::find($lead->id)->demo_setup_status);

        /* 1. DNS: un solo PUT con los 4 A records del slug, TTL 300 y overwrite=false. */
        $puts = $this->hostinger->llamadas_de('PUT');
        $this->assertCount(1, $puts);
        $this->assertFalse($puts[0]['body']['overwrite']);
        $this->assertSame(['api-prueba-test', 'prueba-test', 'tienda-prueba-test', 'api-tienda-prueba-test'], array_column($puts[0]['body']['zone'], 'name'));
        $this->assertSame([300, 300, 300, 300], array_column($puts[0]['body']['zone'], 'ttl'));
        $this->assertSame(self::IP_VPS, $puts[0]['body']['zone'][0]['records'][0]['content']);

        /* 2. Las claves del cliente en el .env del ERP. */
        $env = $this->vps->variables['/home/api-prueba-01/empresa-api/.env'];
        $this->assertSame('ADMIN_API_INBOUND_KEY=' . $client->api_key, $env['ADMIN_API_INBOUND_KEY']);
        $this->assertSame('ADMIN_API_OUTBOUND_KEY=' . $client->inbound_api_key, $env['ADMIN_API_OUTBOUND_KEY']);
        $this->assertSame('ADMIN_SYNC_REQUIRE_API_KEY=true', $env['ADMIN_SYNC_REQUIRE_API_KEY']);
        $this->assertSame('ADMIN_API_CLIENT_UUID=' . $client->uuid, $env['ADMIN_API_CLIENT_UUID']);
        $this->assertSame('FOR_USER=prueba', $env['FOR_USER']);

        /* 3 y 5. Los scripts, en orden, con los argumentos escapados. */
        $this->assertSame(['prueba-asignar.sh', 'prueba-ssl.sh'], $this->vps->scripts_corridos());
        $this->assertStringContainsString("bash /root/prueba-gratuita/prueba-asignar.sh 'prueba-01' 'prueba-test' '900100' '--sin-ssl'", $this->vps->comandos_de('prueba-asignar.sh')[0]);
        $this->assertStringContainsString("bash /root/prueba-gratuita/prueba-ssl.sh 'prueba-01' 'prueba-test'", $this->vps->comandos_de('prueba-ssl.sh')[0]);

        /* 4. C1: el demo setup al host del POOL, con la clave del cliente y los tres campos nuevos. */
        $setups = $this->pedidos_al_erp_con('admin-sync/demo-setup');
        $this->assertCount(1, $setups);
        $this->assertSame('https://api-prueba-01.comerciocity.com/api/admin-sync/demo-setup', $setups[0]['url']);
        $this->assertSame([$client->api_key], $setups[0]['headers']['X-Admin-Api-Key']);
        $payload = $setups[0]['body'];
        $this->assertSame('clave-de-prueba-123', $payload['password']);
        $this->assertSame('ferreteria', $payload['rubro']);
        $this->assertSame('ferreteria', $payload['demo_industria']);
        $this->assertSame(7, $payload['prueba']['dias']);
        $this->assertSame('2026-11-10T15:00:00-03:00', $payload['prueba']['vence_at']);
        $this->assertSame('https://videos.test/bienvenida.mp4', $payload['prueba']['video_url']);
        $this->assertSame('+5493410000000', $payload['prueba']['whatsapp_soporte']);
        $this->assertSame('Prueba Test', $payload['company_name']);
        $this->assertSame('https://tienda-prueba-test.comerciocity.com', $payload['online']);
        $this->assertSame($lead->demo_ingreso_token, $payload['demo_ingreso_token']);
        $this->assertSame($lead->demo_eventos_token, $payload['demo_eventos_token']);
        $this->assertSame('https://api-admin.comerciocity.com/public/api/demo-eventos', $payload['demo_eventos_url']);

        /* La contraseña no quedó en ningún comando del VPS. */
        $this->assertStringNotContainsString('clave-de-prueba-123', implode("\n", $this->vps->comandos));

        /* Y la landing ya ve `lista` con la URL de ingreso. */
        $this->getJson('/api/landing/prueba/' . $instancia->fresh()->seguimiento_uuid . '/estado')
            ->assertJson(['estado' => 'lista', 'url_ingreso' => 'https://prueba-test.comerciocity.com/demo/ingreso?t=' . $lead->demo_ingreso_token]);
    }

    public function test_si_el_demo_setup_falla_la_instancia_queda_fallida_en_esa_etapa_y_el_reintento_arranca_de_ahi(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $this->zona_antes_y_despues(['prueba-test', 'api-prueba-test', 'tienda-prueba-test', 'api-tienda-prueba-test']);
        $this->erp['admin-sync/demo-setup'] = Http::response('Fatal error: no anda', 500);

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertSame('demo_setup', $instancia->etapa);
        $this->assertStringContainsString('HTTP 500', (string) $instancia->error);
        $this->assertNotNull($instancia->password_cifrada, 'Con la prueba fallida la contraseña se conserva para reintentar.');
        $this->assertSame('fallido', Lead::find($lead->id)->demo_setup_status);
        $this->assertSame(['prueba-asignar.sh'], $this->vps->scripts_corridos(), 'El SSL no corrió: se frenó antes.');

        $this->getJson('/api/landing/prueba/' . $instancia->fresh()->seguimiento_uuid . '/estado')->assertJson(['estado' => 'fallida']);

        /* Reintento: el ERP ya anda. Arranca en demo_setup (no vuelve a escribir DNS ni alias). */
        $this->erp = [];
        $this->hostinger->limpiar();
        $this->vps->comandos = [];
        $this->vps->variables = [];

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->estado, (string) $instancia->error);
        $this->assertSame([], $this->hostinger->escrituras());
        $this->assertSame([], $this->vps->variables);
        $this->assertSame(['prueba-ssl.sh'], $this->vps->scripts_corridos());
        $this->assertCount(2, $this->pedidos_al_erp_con('admin-sync/demo-setup'));
    }

    public function test_sin_el_interruptor_de_dns_la_instancia_falla_antes_de_tocar_nada(): void
    {
        config(['services.prueba.dns_write_enabled' => false]);
        $instancia = $this->alta();

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_FALLIDA, $instancia->estado);
        $this->assertSame('dns', $instancia->etapa);
        $this->assertStringContainsString('PRUEBA_DNS_WRITE_ENABLED', (string) $instancia->error);
        $this->assertSame([], $this->hostinger->llamadas);
        $this->assertSame([], $this->vps->comandos);
        $this->assertSame([], $this->pedidos_al_erp);
    }

    public function test_un_timeout_del_demo_setup_no_frena_la_prueba_y_deja_sin_confirmar(): void
    {
        $instancia = $this->alta();
        $lead      = Lead::find($instancia->lead_id);
        $this->zona_antes_y_despues(['prueba-test', 'api-prueba-test', 'tienda-prueba-test', 'api-tienda-prueba-test']);
        $this->erp['admin-sync/demo-setup'] = function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        };

        (new ArmarPruebaJob($instancia->id))->handle();

        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNADA, $instancia->estado, (string) $instancia->error);
        $this->assertSame('sin_confirmar', Lead::find($lead->id)->demo_setup_status);
        $this->assertSame(['prueba-asignar.sh', 'prueba-ssl.sh'], $this->vps->scripts_corridos());
    }
}
