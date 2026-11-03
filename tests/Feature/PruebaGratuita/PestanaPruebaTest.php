<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\PruebaWhatsappEnvio;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * La pestaña «Prueba» del admin: `GET admin/client/{id}/prueba`, `GET admin/lead/{id}/prueba` y el botón
 * «Avisar a Tomás» (misión cruzada prueba-martin-importacion, 3/11/2026).
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

        $respuesta = $this->getJson('/api/admin/client/' . $client->id . '/prueba');
        $respuesta->assertStatus(200);

        $this->assertTrue($respuesta->json('es_prueba'));
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

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id . '/prueba');
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

        $respuesta = $this->getJson('/api/admin/client/' . $client->id . '/prueba');
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

        $this->getJson('/api/admin/client/' . $client->id . '/prueba')->assertStatus(401);
    }
}
