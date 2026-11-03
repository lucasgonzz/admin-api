<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\FollowupTemplate;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;

/**
 * La bienvenida de Martín al primer `prueba.ingreso` y el aviso a Tomás al `prueba.vencida`, los dos
 * disparados por C2 (`POST api/demo-eventos`) (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Lo que protege, en orden de cuánto duele si se rompe:
 *  1. **La bienvenida sale UNA sola vez por negocio**, por plantilla, por el ticket de soporte del dueño
 *     (el mensaje queda en el hilo como del equipo), con el primer nombre como variable.
 *  2. Sin la plantilla aprobada no sale nada: queda `en_cola`, y el empujón la destraba cuando Meta la aprueba.
 *  3. `prueba.vencida` avisa a Tomás (WhatsApp) una sola vez.
 *  4. Un fallo de la prueba nunca rechaza el lote de eventos (la instancia reintentaría para siempre).
 */
class BienvenidaYAvisosPorEventoTest extends BaseDeLaPrueba
{
    /** @test */
    public function el_primer_ingreso_manda_la_bienvenida_por_el_ticket_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba(true, [PruebaPlantillasService::BIENVENIDA]);

        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client, 'Marcela Gómez');

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response([], 404)]);

        $this->evento($lead, 'prueba.ingreso')->assertStatus(200);

        $this->assertCount(1, $espia->plantillas, 'La bienvenida sale por plantilla.');
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $espia->plantillas[0]['template_name']);
        $this->assertSame((string) $client->phone, $espia->plantillas[0]['to']);
        $this->assertSame(['Marcela'], $espia->plantillas[0]['variables']);
        $this->assertCount(0, $espia->textos, 'Nunca texto libre: la ventana está cerrada.');

        $ticket = SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->first();
        $this->assertNotNull($ticket, 'Se abre el ticket de soporte del dueño.');
        $this->assertSame((string) $client->phone, $ticket->whatsapp_phone);

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->first();
        $this->assertSame('admin', $mensaje->sender_type);
        $this->assertStringContainsString('Hola Marcela, soy Martín', (string) $mensaje->body);
        $this->assertSame('wamid.plantilla.1', $mensaje->whatsapp_message_id);

        $envios = PruebaWhatsappEnvio::where('client_id', $client->id)->get();
        $this->assertCount(1, $envios);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, $envios->first()->estado);
        $this->assertSame((int) $lead->id, (int) $envios->first()->lead_id);
        $this->assertSame((int) $ticket->id, (int) $envios->first()->support_ticket_id);

        /* Un segundo ingreso (el negocio vuelve a entrar) no vuelve a presentar a Martín. */
        $this->evento($lead, 'prueba.ingreso')->assertStatus(200);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->count());
    }

    /** @test */
    public function sin_plantilla_aprobada_queda_en_cola_y_el_empujon_la_destraba(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba(false, [PruebaPlantillasService::BIENVENIDA]);

        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response([], 404)]);

        $this->evento($lead, 'prueba.ingreso')->assertStatus(200);

        $this->assertCount(0, $espia->plantillas);
        $en_cola = PruebaWhatsappEnvio::where('client_id', $client->id)->get();
        $this->assertCount(1, $en_cola);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_EN_COLA, $en_cola->first()->estado);
        /* El motivo guardado es el del modelo: con `activa = false` (así nacen las creadas por API) es `plantilla_inactiva`. */
        $this->assertSame('plantilla_inactiva', (string) $en_cola->first()->detalle);

        /* Meta la aprueba (lo haría templates:sync-meta) y el empujón diario la manda. */
        FollowupTemplate::where('template_name', PruebaPlantillasService::BIENVENIDA)->update(['activa' => true, 'meta_status' => 'APPROVED']);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('bienvenida_enviado', $resultado['estado']);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $espia->plantillas[0]['template_name']);
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('estado', PruebaWhatsappEnvio::ESTADO_EN_COLA)->count(), 'La cola se vacía al salir.');
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->count());
    }

    /** @test */
    public function la_prueba_vencida_avisa_a_tomas_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $closer = $this->admin(true);

        $client = $this->cliente_en_prueba(-1);
        $lead   = $this->lead_en_prueba($client, 'Marcela Gómez');

        $this->evento($lead, 'prueba.vencida')->assertStatus(200);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('+5493415550000', $espia->textos[0]['to']);
        $this->assertStringContainsString('Ferretería de prueba S.R.L. (Marcela Gómez) terminó su prueba gratuita', $espia->textos[0]['body']);
        $this->assertStringContainsString('https://admin.ejemplo.test/pipelines/tablero?pipeline=lead&lead=' . $lead->id, $espia->textos[0]['body']);
        $this->assertCount(0, $espia->plantillas, 'Al negocio no se le manda nada al vencer: lo llama Tomás.');

        $aviso = PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', PruebaWhatsappEnvio::PLANTILLA_AVISO_TOMAS)->first();
        $this->assertNotNull($aviso);
        $this->assertSame('vencida', $aviso->hito);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, $aviso->estado);

        $this->evento($lead, 'prueba.vencida')->assertStatus(200);
        $this->assertCount(1, $espia->textos);
    }

    /** @test */
    public function un_evento_de_la_prueba_de_un_lead_sin_cliente_no_rompe_el_lote(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();

        $client = $this->crear_cliente('+5493417770002', false, 'otra-clave');
        $lead   = $this->lead_en_prueba($client);

        $this->evento($lead, 'prueba.ingreso')->assertStatus(200)->assertJson(['guardados' => 1]);
        $this->evento($lead, 'prueba.venta')->assertStatus(200)->assertJson(['guardados' => 1]);

        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, PruebaWhatsappEnvio::count());
    }
}
