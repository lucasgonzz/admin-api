<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaEncuestaService;
use App\Services\Prueba\PruebaEnsayoSinWhatsappService;
use App\Services\Prueba\PruebaSettings;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Un cliente de ENSAYO (`clients.es_ensayo`) no recibe NADA por WhatsApp (Lucas, 12/11/2026, misión
 * ensayo-sin-mision-automatica): el mismo texto queda en la conversación del sistema del negocio, el registro de
 * `prueba_whatsapp_envios` queda con `canal = sistema` y el correo sale igual. Un cliente que no es de ensayo sigue
 * exactamente como antes.
 *
 * El espía de `WhatsappSendService` (de `BaseDelCanal`) cuenta las llamadas a Kapso: tienen que ser cero.
 */
class EnsayoSinWhatsappTest extends BaseDeLaPrueba
{
    /** @var \App\Services\WhatsappSendService */
    private $espia;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        Mail::fake();
        $this->espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba(true);
    }

    /**
     * Un negocio en prueba, de ensayo o no, con su lead.
     *
     * @param bool $ensayo
     * @param int  $dias_restantes
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(bool $ensayo, int $dias_restantes = 5): array
    {
        $client = $this->cliente_en_prueba($dias_restantes);
        $client->email     = 'duena@test.local';
        $client->es_ensayo = $ensayo;
        $client->save();

        return [$client, $this->lead_en_prueba($client)];
    }

    /** Deja registrada la bienvenida de ayer (por el sistema, como la deja un ensayo). */
    private function con_bienvenida_de_ayer(Client $client, Lead $lead): void
    {
        PruebaWhatsappEnvio::create([
            'client_id'  => $client->id,
            'lead_id'    => $lead->id,
            'canal'      => PruebaWhatsappEnvio::CANAL_SISTEMA,
            'plantilla'  => PruebaPlantillasService::BIENVENIDA,
            'estado'     => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia'        => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
            'enviado_at' => now()->subDay(),
        ]);
    }

    /** Afirma que no hubo ninguna llamada a Kapso/Meta. */
    private function sin_whatsapp(): void
    {
        $this->assertSame([], $this->espia->plantillas, 'Ninguna plantilla de WhatsApp al cliente de ensayo.');
        $this->assertSame([], $this->espia->textos, 'Ningún texto de WhatsApp al cliente de ensayo.');
    }

    /** La conversación del sistema del dueño (exclusiva del sistema) y sus mensajes del equipo. */
    private function conversacion_del_sistema(Client $client): SupportTicket
    {
        $ticket = SupportTicket::where('client_id', $client->id)->where('source', 'erp')->firstOrFail();
        $this->assertSame((int) $client->user_id, (int) $ticket->client_user_id);
        $this->assertNotNull($ticket->sistema_exclusivo_at, 'La ve el sistema del negocio en Agentes → Soporte.');
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->count(), 'No se abre ticket de WhatsApp.');

        return $ticket;
    }

    /** @test */
    public function la_bienvenida_de_un_ensayo_queda_en_el_sistema_y_por_correo_sin_whatsapp(): void
    {
        [$client, $lead] = $this->negocio(true);

        $resultado = app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->sin_whatsapp();
        $ticket  = $this->conversacion_del_sistema($client);
        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->firstOrFail();
        $this->assertStringContainsString('Marcela', $mensaje->body, 'El texto de la plantilla con sus variables.');
        $this->assertNotNull($mensaje->synced_to_client_at);
        $this->assertNull($mensaje->whatsapp_message_id);

        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->whereIn('plantilla', PruebaPlantillasService::BIENVENIDAS)->firstOrFail();
        $this->assertSame(PruebaWhatsappEnvio::CANAL_SISTEMA, $envio->canal);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, $envio->estado);
        $this->assertSame((int) $mensaje->id, (int) $envio->support_message_id);

        /* Una segunda vez no la repite ni abre otra conversación. */
        $this->assertSame('ya_enviada', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame(1, SupportTicket::where('client_id', $client->id)->count());
        $this->sin_whatsapp();
    }

    /** @test */
    public function el_paso_diario_de_un_ensayo_queda_en_el_sistema_con_su_correo_y_respeta_el_tope_del_dia(): void
    {
        [$client, $lead] = $this->negocio(true);
        $this->con_bienvenida_de_ayer($client, $lead);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso', 'importo']), 200)]);

        $servicio  = app(PruebaWhatsappService::class);
        $resultado = $servicio->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->sin_whatsapp();
        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'cc_prueba_paso_articulo_creado')->firstOrFail();
        $this->assertSame(PruebaWhatsappEnvio::CANAL_SISTEMA, $envio->canal);
        $this->assertSame('articulo_creado', $envio->hito);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $this->conversacion_del_sistema($client)->id)->count());
        Mail::assertSent(ComercioCityMail::class);

        /* El mensaje en el sistema gasta el tope del día igual que un WhatsApp. */
        $this->assertSame('ya_hoy', $servicio->empujar($client, $lead)['estado']);
        $this->sin_whatsapp();
    }

    /** @test */
    public function el_aviso_del_dia_5_de_un_ensayo_queda_en_el_sistema(): void
    {
        /* Vence en 2 días y media hora con una prueba de 7: va por el día 5. */
        [$client, $lead] = $this->negocio(true, 2);
        $this->con_bienvenida_de_ayer($client, $lead);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->sin_whatsapp();
        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', PruebaPlantillasService::DIA_5)->firstOrFail();
        $this->assertSame(PruebaWhatsappEnvio::CANAL_SISTEMA, $envio->canal);
        $mensaje = SupportMessage::where('support_ticket_id', $this->conversacion_del_sistema($client)->id)->firstOrFail();
        $this->assertStringContainsString('termina en 3 días', $mensaje->body);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($client) {
            return $mail->hasTo($client->email);
        });
    }

    /** @test */
    public function la_encuesta_de_un_ensayo_queda_en_el_sistema_con_su_enlace_y_sale_por_correo(): void
    {
        [$client] = $this->negocio(true);

        $resultado = app(PruebaEncuestaService::class)->enviar($client, \App\Models\PruebaEncuesta::MOTIVO_VENCIDA);

        $this->assertSame('enviada', $resultado['estado']);
        $this->assertSame('enviado', $resultado['whatsapp'], 'Registrado como enviado, por el sistema.');
        $this->sin_whatsapp();
        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->whereIn('plantilla', PruebaPlantillasService::ENCUESTAS)->firstOrFail();
        $this->assertSame(PruebaWhatsappEnvio::CANAL_SISTEMA, $envio->canal);
        $mensaje = SupportMessage::where('support_ticket_id', $this->conversacion_del_sistema($client)->id)->firstOrFail();
        $this->assertStringContainsString('/encuesta', $mensaje->body);
        Mail::assertSent(ComercioCityMail::class);
    }

    /** @test */
    public function el_aviso_de_importacion_de_un_ensayo_con_ticket_de_whatsapp_va_al_sistema(): void
    {
        [$client] = $this->negocio(true);
        $de_whatsapp = $this->ticket_del_cliente($client, true);

        $salida = app(PruebaWhatsappService::class)->mandar_texto_por_ticket($client, 'Ya leí tu Excel: 120 artículos.', (int) $de_whatsapp->id);

        $this->assertTrue($salida['enviado']);
        $this->sin_whatsapp();
        $this->assertSame(0, SupportMessage::where('support_ticket_id', $de_whatsapp->id)->where('sender_type', 'admin')->count());
        $sistema = SupportTicket::where('client_id', $client->id)->where('source', 'erp')->firstOrFail();
        $this->assertSame((int) $sistema->id, $salida['support_ticket_id']);
        $this->assertSame('Ya leí tu Excel: 120 artículos.', SupportMessage::where('support_ticket_id', $sistema->id)->value('body'));
    }

    /** @test */
    public function reusa_la_conversacion_del_sistema_que_ya_abrio_el_dueno_y_la_sincroniza_por_el_push(): void
    {
        [$client] = $this->negocio(true);
        $propia = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => (int) $client->user_id, 'status' => 'open',
            'source' => 'erp', 'opened_at' => now(),
        ]);
        $this->fakear_http(['*/api/admin-sync/support/messages' => Http::response(['model' => ['id' => 1]], 201)]);

        $salida = app(PruebaEnsayoSinWhatsappService::class)->dejar($client, 'Hola desde el ensayo.');

        $this->assertTrue($salida['enviado']);
        $this->assertSame((int) $propia->id, $salida['support_ticket_id']);
        $this->assertSame(1, SupportTicket::where('client_id', $client->id)->count());
        Http::assertSent(function ($request) {
            return strpos($request->url(), '/api/admin-sync/support/messages') !== false && $request['body'] === 'Hola desde el ensayo.';
        });
        $this->sin_whatsapp();
    }

    /** @test */
    public function un_cliente_que_no_es_de_ensayo_sigue_recibiendo_whatsapp_como_siempre(): void
    {
        [$client, $lead] = $this->negocio(false);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);
        $this->assertCount(1, $this->espia->plantillas);
        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->whereIn('plantilla', PruebaPlantillasService::BIENVENIDAS)->firstOrFail();
        $this->assertSame(PruebaWhatsappEnvio::CANAL_WHATSAPP, $envio->canal);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->where('source', 'erp')->count());

        $de_whatsapp = $this->ticket_del_cliente($client, true);
        $salida = app(PruebaWhatsappService::class)->mandar_texto_por_ticket($client, 'Ya leí tu Excel.', (int) $de_whatsapp->id);
        $this->assertTrue($salida['enviado']);
        $this->assertCount(1, $this->espia->textos);
    }
}
