<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\PruebaWhatsappEnvio;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request;
use Laravel\Sanctum\Sanctum;

/**
 * El seguimiento de la pestaña «Prueba» del admin: `GET admin/client/{id}/prueba/seguimiento`,
 * `GET admin/lead/{id}/prueba/seguimiento` y el botón «Avisar a Tomás» (misión cruzada
 * prueba-martin-importacion, 3/11/2026). La solapa en sí (`GET/PUT .../prueba`) es de M2.
 */
class PestanaPruebaTest extends BaseDeLaPrueba
{
    /** @test */
    public function la_ficha_del_cliente_trae_dias_hitos_y_envios(): void
    {
        Sanctum::actingAs($this->admin());

        $client = $this->cliente_en_prueba(5, 7);
        $lead   = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'lead_id' => $lead->id, 'plantilla' => 'cc_prueba_bienvenida', 'hito' => null,
            'estado' => 'enviado', 'dia' => now()->toDateString(), 'enviado_at' => now(), 'whatsapp_message_id' => 'wamid.1',
        ]);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso', 'importo', 'articulo_creado']), 200)]);

        $respuesta = $this->getJson('/api/admin/client/' . $client->id . '/prueba/seguimiento');
        $respuesta->assertStatus(200);

        $this->assertTrue($respuesta->json('es_prueba'));
        $this->assertSame('prueba', $respuesta->json('estado'));
        $this->assertSame(7, $respuesta->json('dias'));
        $this->assertSame(6, $respuesta->json('dias_restantes'));
        $this->assertSame('instancia', $respuesta->json('hitos_fuente'));
        $this->assertCount(10, $respuesta->json('hitos_lista'));
        $this->assertSame('ingreso', $respuesta->json('hitos_lista.0.tipo'));
        $this->assertSame('Entró al sistema', $respuesta->json('hitos_lista.0.label'));
        $this->assertNotNull($respuesta->json('hitos_lista.0.at'));
        $this->assertSame('venta', $respuesta->json('proximo_hito'));
        $this->assertTrue($respuesta->json('bienvenida_enviada'));
        $this->assertCount(1, $respuesta->json('envios'));
        $this->assertSame((int) $lead->id, $respuesta->json('lead_id'));
        $this->assertSame('Ferretería de prueba S.R.L.', $respuesta->json('client_nombre'));
        $this->assertStringNotContainsString('clave-del-cliente', (string) $respuesta->getContent());
    }

    /** @test */
    public function el_modal_del_lead_cruza_al_cliente_en_prueba(): void
    {
        Sanctum::actingAs($this->admin());

        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response([], 404)]);

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id . '/prueba/seguimiento');
        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('es_prueba'));
        $this->assertSame((int) $client->id, $respuesta->json('client_id'));
        $this->assertSame('admin', $respuesta->json('hitos_fuente'));
        $this->assertSame('ingreso', $respuesta->json('proximo_hito'));
    }

    /** @test */
    public function un_cliente_que_no_esta_en_prueba_lo_dice(): void
    {
        Sanctum::actingAs($this->admin());

        $client = $this->crear_cliente('+5493417770004', false, 'otra-clave');

        $respuesta = $this->getJson('/api/admin/client/' . $client->id . '/prueba/seguimiento');
        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('es_prueba'));
        Http::assertNothingSent();

        $this->postJson('/api/admin/client/' . $client->id . '/prueba/avisar-a-tomas')->assertStatus(422);
    }

    /** @test */
    public function el_boton_avisa_a_tomas_cada_vez_que_se_aprieta(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        Sanctum::actingAs($this->admin());
        $this->admin(true);

        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);

        $primera = $this->postJson('/api/admin/lead/' . $lead->id . '/prueba/avisar-a-tomas');
        $primera->assertStatus(200);
        $this->assertTrue($primera->json('avisado'));

        $this->postJson('/api/admin/client/' . $client->id . '/prueba/avisar-a-tomas')->assertStatus(200);

        $this->assertCount(2, $espia->textos, 'El aviso manual no tiene tope.');
        $this->assertSame('+5493415550000', $espia->textos[0]['to']);
        $this->assertStringContainsString('Pedido desde el admin', $espia->textos[0]['body']);
        $this->assertSame(2, PruebaWhatsappEnvio::where('client_id', $client->id)->where('hito', 'manual')->count());
    }

    /** @test */
    public function sin_sesion_de_admin_no_contesta(): void
    {
        $client = $this->cliente_en_prueba();

        $this->getJson('/api/admin/client/' . $client->id . '/prueba/seguimiento')->assertStatus(401);
        $this->putJson('/api/admin/client/' . $client->id . '/prueba/cupo-imagenes', ['tope' => 30])->assertStatus(401);
    }

    /** @test */
    public function la_edicion_del_cupo_desde_lead_y_cliente_conserva_el_consumo_del_erp(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->cliente_en_prueba();
        $lead = $this->lead_en_prueba($client);
        $imagenes = ['tope' => 30, 'usadas' => 12, 'restantes' => 18];
        $this->fakear_http([
            '*/admin-sync/prueba/estado' => function () use (&$imagenes) {
                return Http::response(array_merge($this->estado_de_la_instancia([]), ['imagenes' => $imagenes]), 200);
            },
            '*/admin-sync/prueba/cupo-imagenes' => function (Request $request) use (&$imagenes) {
                $this->assertSame('POST', $request->method());
                $this->assertTrue($request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente'));
                $imagenes['tope'] = $request->data()['tope'];
                $imagenes['restantes'] = max(0, $imagenes['tope'] - $imagenes['usadas']);

                return Http::response(['imagenes' => $imagenes], 200);
            },
        ]);

        $ruta_cliente = '/api/admin/client/' . $client->id . '/prueba';
        $ruta_lead = '/api/admin/lead/' . $lead->id . '/prueba';
        $this->getJson($ruta_lead . '/seguimiento')->assertOk()->assertJsonPath('imagenes', $imagenes);

        $this->putJson($ruta_lead . '/cupo-imagenes', ['tope' => 50])
            ->assertOk()->assertJsonPath('imagenes', ['tope' => 50, 'usadas' => 12, 'restantes' => 38]);
        $this->putJson($ruta_cliente . '/cupo-imagenes', ['tope' => 0])
            ->assertOk()->assertJsonPath('imagenes', ['tope' => 0, 'usadas' => 12, 'restantes' => 0]);
        $this->getJson($ruta_cliente . '/seguimiento')->assertOk()->assertJsonPath('imagenes', $imagenes);
        $this->assertSame(7, (int) $client->fresh()->trial_days);
    }

    /** @test */
    public function una_version_vieja_conserva_el_seguimiento_y_no_confirma_la_edicion(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->cliente_en_prueba();
        $this->fakear_http([
            '*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200),
            '*/admin-sync/prueba/cupo-imagenes' => Http::response([], 404),
        ]);

        $ruta = '/api/admin/client/' . $client->id . '/prueba';
        $this->getJson($ruta . '/seguimiento')->assertOk()
            ->assertJsonPath('hitos_fuente', 'instancia')
            ->assertJsonPath('imagenes', null)
            ->assertJsonPath('cupo_imagenes_estado', 'version_vieja');
        $this->putJson($ruta . '/cupo-imagenes', ['tope' => 50])
            ->assertStatus(409)->assertJsonPath('estado', 'version_vieja');
    }

    /** @test */
    public function el_admin_valida_el_cupo_y_la_prueba_antes_de_llamar_al_erp(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->cliente_en_prueba();
        $ruta = '/api/admin/client/' . $client->id . '/prueba/cupo-imagenes';

        foreach ([-1, 10001, 1.5, true, null] as $invalido) {
            $this->putJson($ruta, ['tope' => $invalido])->assertStatus(422)->assertJsonValidationErrors('tope');
        }

        $client->update(['estado' => 'activo']);
        $this->putJson($ruta, ['tope' => 30])->assertStatus(422);
        Http::assertNothingSent();
    }
}
