<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteWhatsappSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\Prueba\PruebaVideoSoporteService;
use App\Services\PruebaWhatsappService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Los videos de la prueba en el ticket de Soporte (misión cruzada prueba-ayuda-unificada-en-soporte, 10/11/2026).
 *
 * Lo que protege:
 *  - A1: el contexto de Martín trae `prueba.videos` con `{codigo, titulo, url, hito, entregado_at}`, y
 *    `entregado_at` sale del registro de envíos.
 *  - A2: el video 04 sale sin Codex, una sola vez: enseguida de la bienvenida si la ventana está abierta; si no,
 *    con el primer entrante del lead, antes de que Martín tome el ticket, y sin tapar el entrante que espera
 *    respuesta. Nunca con la ventana cerrada.
 */
class VideosDeLaPruebaEnSoporteTest extends BaseDeLaPrueba
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(PruebaVideoCatalogoService::PIEZAS) as $codigo) {
            AdminSetting::where('key', PruebaVideoCatalogoService::key($codigo))->delete();
        }
    }

    /** Configura los seis videos aprobados. */
    private function videos_aprobados(array $codigos = ['04', '05', '06', '07', '08', '09']): void
    {
        foreach ($codigos as $codigo) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), PruebaVideoCatalogoService::URLS_APROBADAS[$codigo]);
        }
    }

    /** Prende el circuito de Codex para que `claim` atienda los tickets. */
    private function prender_codex(): void
    {
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
    }

    /** @return array<int, string> Los textos libres que salieron con la URL del video 04. */
    private function videos_04_mandados($espia): array
    {
        $url = PruebaVideoCatalogoService::URLS_APROBADAS['04'];

        return array_values(array_filter(array_column($espia->textos, 'body'), function ($body) use ($url) {
            return strpos((string) $body, $url) !== false;
        }));
    }

    /** @test */
    public function el_contexto_trae_los_videos_con_hito_y_entrega(): void
    {
        $this->videos_aprobados(['04', '06', '09']);
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        $videos = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude())
            ->assertOk()->json('ticket.prueba.videos');

        $this->assertSame([
            ['codigo' => '04', 'titulo' => 'Bienvenida y recorrido del sistema', 'url' => PruebaVideoCatalogoService::URLS_APROBADAS['04'], 'hito' => 'ingreso', 'entregado_at' => null],
            ['codigo' => '06', 'titulo' => 'Revisá y cambiá un precio', 'url' => PruebaVideoCatalogoService::URLS_APROBADAS['06'], 'hito' => 'articulo_creado', 'entregado_at' => null],
            ['codigo' => '09', 'titulo' => 'Probá al agente con tu catálogo', 'url' => PruebaVideoCatalogoService::URLS_APROBADAS['09'], 'hito' => 'agente', 'entregado_at' => null],
        ], $videos);

        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'plantilla' => 'video_06', 'hito' => 'articulo_creado',
            'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => PruebaWhatsappService::hoy(), 'enviado_at' => now(),
        ]);
        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'plantilla' => 'video_09', 'hito' => 'agente',
            'estado' => PruebaWhatsappEnvio::ESTADO_FALLIDO, 'dia' => PruebaWhatsappService::hoy(),
        ]);

        $videos = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude())
            ->assertOk()->json('ticket.prueba.videos');
        $this->assertNull($videos[0]['entregado_at']);
        $this->assertNotNull($videos[1]['entregado_at'], 'El envío registrado marca el video como entregado.');
        $this->assertNull($videos[2]['entregado_at'], 'Un envío fallido no cuenta como entregado.');
    }

    /** @test */
    public function sin_videos_configurados_la_lista_viene_vacia_y_fuera_de_la_prueba_no_hay_bloque(): void
    {
        $client = $this->cliente_en_prueba();
        $this->assertSame([], app(PruebaVideoSoporteService::class)->extension($client)['videos']);

        $comun  = $this->crear_cliente('+5493417770001', false, 'otra-clave');
        $ticket = $this->ticket_del_cliente($comun);
        $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude())
            ->assertOk()->assertJsonPath('ticket.prueba', null);
    }

    /** @test */
    public function con_la_ventana_abierta_el_video_sale_enseguida_de_la_bienvenida_y_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba();
        $this->videos_aprobados();
        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);
        $ticket = $this->ticket_del_cliente($client, true);

        $resultado = app(PruebaWhatsappService::class)->enviar_bienvenida_a($client, $lead);
        $this->assertSame('enviado', $resultado['estado']);

        $this->assertCount(1, $espia->plantillas, 'La bienvenida sale por plantilla.');
        $this->assertCount(1, $this->videos_04_mandados($espia));
        $this->assertStringContainsString('«Bienvenida y recorrido del sistema»', $espia->textos[0]['body']);

        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'video_04')->sole();
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, $envio->estado);
        $this->assertSame((int) $ticket->id, (int) $envio->support_ticket_id);
        $this->assertTrue(SupportMessage::whereKey($envio->support_message_id)->value('is_trial_import_notice') == true);

        /* Ni la segunda bienvenida, ni otro entrante, ni la toma lo repiten. */
        app(PruebaWhatsappService::class)->enviar_bienvenida_a($client, $lead);
        $this->assertSame('ya_entregado', app(PruebaVideoSoporteService::class)->entregar_bienvenida($client, (int) $ticket->id)['estado']);
        $this->assertCount(1, $this->videos_04_mandados($espia));
    }

    /** @test */
    public function con_la_ventana_cerrada_no_sale_y_sale_con_el_primer_entrante_antes_de_martin(): void
    {
        Queue::fake();
        AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, '1');
        $this->prender_codex();
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba();
        $this->videos_aprobados();
        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida_a($client, $lead)['estado']);
        $this->assertSame([], $this->videos_04_mandados($espia), 'Con la ventana cerrada no sale texto libre.');
        $this->assertSame(0, PruebaWhatsappEnvio::where('plantilla', 'video_04')->count());

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);
        $this->postear_webhook($this->payload_de_texto((string) $client->phone, '¿Cómo cargo un artículo?', 'wamid.PRIMERO'))->assertOk();

        $this->assertCount(1, $this->videos_04_mandados($espia), 'El primer entrante abre la ventana y el video sale.');
        $entrante = SupportMessage::where('whatsapp_message_id', 'wamid.PRIMERO')->firstOrFail();
        $video    = SupportMessage::where('support_ticket_id', $entrante->support_ticket_id)->where('sender_type', 'admin')
            ->where('is_trial_import_notice', true)->firstOrFail();
        $this->assertGreaterThan($entrante->id, $video->id);

        /* Martín ve el ticket pendiente por la pregunta del lead (el video no la tapa), el video en el historial y
         * `entregado_at` en el contexto. */
        $toma = $this->postJson('/api/claude/support-codex/tickets/' . $entrante->support_ticket_id . '/claim', [
            'worker_id' => 'martin', 'expected_message_id' => (int) $entrante->id,
        ], $this->headers_de_claude())->assertOk();
        $this->assertSame((int) $entrante->id, $toma->json('ticket.expected_message_id'));
        $this->assertNotNull($toma->json('ticket.prueba.videos.0.entregado_at'));
        $this->assertContains((int) $video->id, array_column($toma->json('ticket.messages'), 'id'));

        /* El segundo entrante no lo vuelve a mandar. */
        $this->postear_webhook($this->payload_de_texto((string) $client->phone, 'Gracias', 'wamid.SEGUNDO'))->assertOk();
        $this->assertCount(1, $this->videos_04_mandados($espia));
        $this->assertSame(1, PruebaWhatsappEnvio::where('plantilla', 'video_04')->count());
    }

    /** @test */
    public function si_martin_toma_el_ticket_antes_que_el_webhook_el_video_sale_antes_del_contexto(): void
    {
        $this->prender_codex();
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->videos_aprobados(['04']);
        $client   = $this->cliente_en_prueba();
        $ticket   = $this->ticket_del_cliente($client, true);
        $entrante = SupportMessage::where('support_ticket_id', $ticket->id)->sole();
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        $toma = $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'martin', 'expected_message_id' => (int) $entrante->id,
        ], $this->headers_de_claude())->assertOk();

        $this->assertCount(1, $this->videos_04_mandados($espia));
        $this->assertNotNull($toma->json('ticket.prueba.videos.0.entregado_at'));
        $this->assertSame((int) $entrante->id, $toma->json('ticket.expected_message_id'));
    }

    /** @test */
    public function si_otro_proceso_esta_mandando_el_video_la_toma_espera(): void
    {
        $this->prender_codex();
        $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->videos_aprobados(['04']);
        $client   = $this->cliente_en_prueba();
        $ticket   = $this->ticket_del_cliente($client, true);
        $entrante = SupportMessage::where('support_ticket_id', $ticket->id)->sole();

        $candado = \Illuminate\Support\Facades\Cache::lock('prueba-video-04-' . $client->id, 60);
        $this->assertTrue($candado->get());
        try {
            $respuesta = $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/claim', [
                'worker_id' => 'martin', 'expected_message_id' => (int) $entrante->id,
            ], $this->headers_de_claude());
            $respuesta->assertStatus(423);
            $this->assertStringStartsWith('lease_busy', (string) $respuesta->json('message'));
        } finally {
            $candado->release();
        }
    }

    /** @test */
    public function un_envio_rechazado_queda_fallido_y_no_se_reintenta(): void
    {
        $espia = $this->espiar_sender(false);
        $this->crear_config_whatsapp();
        $this->admin();
        $this->videos_aprobados(['04']);
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client, true);

        $this->assertSame('fallido', app(PruebaVideoSoporteService::class)->entregar_bienvenida($client, (int) $ticket->id)['estado']);
        $this->assertSame('ya_entregado', app(PruebaVideoSoporteService::class)->entregar_bienvenida($client, (int) $ticket->id)['estado']);
        $this->assertCount(1, $espia->textos);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_FALLIDO, PruebaWhatsappEnvio::where('plantilla', 'video_04')->sole()->estado);
    }

    /** @test */
    public function un_ticket_de_un_empleado_no_recibe_el_video(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->videos_aprobados(['04']);
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client, true);
        $empleado = $this->crear_empleado($client, '+5493419990000');
        SupportTicket::whereKey($ticket->id)->update(['client_employee_id' => $empleado->id]);

        $this->assertSame('sin_ticket', app(PruebaVideoSoporteService::class)->entregar_bienvenida($client, (int) $ticket->id)['estado']);
        $this->assertSame([], $espia->textos);
    }
}
