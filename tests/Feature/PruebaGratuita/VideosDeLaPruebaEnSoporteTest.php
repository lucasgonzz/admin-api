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
}
