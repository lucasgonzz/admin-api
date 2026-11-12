<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\ManualRepositoryService;
use App\Services\Prueba\PruebaRespaldoSoporteService;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\SupportCodexHealthService;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * El respaldo automático de Soporte en la prueba, de noche (misión cruzada prueba-e2e-campana-imperio, 12/11/2026;
 * medido en el ticket 104): con Martín en su pausa nocturna, el respaldo le explicó al dueño cómo importar su lista
 * él solo y con jerga. En la prueba la lista se la cargamos nosotros.
 *
 *  1. Una consulta sobre cargar o importar desde un archivo recibe una respuesta fija, sin modelo: que adjunte el
 *     archivo en ese chat y Martín lo toma. Con el video 05 como alternativa.
 *  2. Fuera del horario de Martín, toda respuesta cierra diciendo cuándo sigue («desde las 7 de la mañana»).
 *  3. El modelo recibe la regla de hablar sin jerga y de no explicar el importador.
 *  4. Un Excel o un PDF mandado de noche recibe un acuse fijo, una vez por ticket y por noche.
 */
class RespaldoNocturnoDeLaPruebaTest extends BaseDeLaPrueba
{
    /** @var \App\Services\WhatsappSendService */
    private $espia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a_las('01:30');
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
        foreach (PruebaVideoCatalogoService::URLS_APROBADAS as $codigo => $url) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), $url);
        }
        config(['services.deepseek.api_key' => 'clave-falsa']);
        $this->mock(ManualRepositoryService::class, function ($mock) {
            $mock->shouldReceive('file_list_cacheada')->andReturn('manual_sistema/articulos/crear.md');
            $mock->shouldReceive('get_file')->andReturn('Entrá a Listado y tocá Crear.');
        });

        $this->espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Fija la hora de Argentina y deja a Martín sin pulso desde hace diez minutos. */
    private function a_las(string $hora): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 ' . $hora . ':00', 'America/Argentina/Buenos_Aires'));
        $hace = now()->subMinutes(10)->getTimestamp();
        AdminSetting::set(SupportCodexHealthService::KEY, json_encode([
            'armed_at' => $hace - 3600, 'last_seen_at' => $hace, 'last_success_at' => $hace, 'status' => 'ok',
        ]));
    }

    /** Fakea el modelo (con una respuesta común) y la instancia sin importaciones. */
    private function red(): void
    {
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['pendientes' => [], 'en_proceso' => false], 200),
            '*/api/admin-sync/support/messages'               => Http::response(['model' => ['id' => 1]], 201),
            'api.deepseek.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'leer_manual', 'input' => ['ruta' => 'manual_sistema/articulos/crear.md']]]])
                ->push(['content' => [['type' => 'text', 'text' => json_encode([
                    'contenido' => 'Entrá a Listado y tocá Crear.', 'fuentes' => ['manual_sistema/articulos/crear.md'], 'videos' => [],
                ])]]]),
        ]);
    }

    /**
     * El dueño escribe en el chat de Soporte de su sistema.
     *
     * @return array{0: SupportTicket, 1: SupportMessage}
     */
    private function escribe_en_el_sistema(string $texto, ?SupportTicket $ticket = null): array
    {
        if ($ticket === null) {
            $client = $this->cliente_en_prueba();
            $ticket = SupportTicket::create([
                'client_id' => $client->id, 'client_user_id' => (int) $client->user_id, 'status' => 'open',
                'source' => 'erp', 'opened_at' => now(), 'last_client_message_at' => now(), 'agentes_erp' => true,
            ]);
        }
        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => $texto,
            'delivered_at' => now(), 'synced_to_client_at' => now(),
            'erp_auto_reply' => [SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX => true],
        ]);

        return [$ticket, $entrante];
    }

    private function responder(SupportTicket $ticket, SupportMessage $entrante): array
    {
        return app(PruebaRespaldoSoporteService::class)->responder((int) $ticket->id, (int) $entrante->id);
    }

    private function respuesta_en(SupportTicket $ticket): string
    {
        return (string) SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->orderByDesc('id')->value('body');
    }

    /** @test */
    public function a_quien_pregunta_como_cargar_su_lista_no_se_le_explica_el_importador_se_le_pide_el_archivo(): void
    {
        $this->red();
        [$ticket, $entrante] = $this->escribe_en_el_sistema('tengo una lista del proveedor en excel, ¿cómo hago para cargar mis productos?');

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);

        $texto = $this->respuesta_en($ticket);
        $this->assertStringStartsWith('Respuesta automática: Eso te lo cargamos nosotros, sin costo. Adjuntá el Excel o el PDF de tu proveedor con el clip, acá abajo, y Martín lo toma apenas vuelve.', $texto);
        $this->assertStringContainsString('Si preferís hacerlo vos, acá te muestra cómo: ' . PruebaVideoCatalogoService::URLS_APROBADAS['05'], $texto);
        $this->assertStringEndsWith('Martín te sigue desde las 7 de la mañana.', $texto);
        foreach (['mapeo', 'Importar con IA', 'columnas', 'propiedades', 'duplicados'] as $jerga) {
            $this->assertStringNotContainsStringIgnoringCase($jerga, $texto);
        }
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'deepseek') !== false;
        });
        $this->assertSame([], $this->espia->textos, 'Llegó por el sistema: vuelve por el sistema.');
    }

    /** @test */
    public function por_whatsapp_la_misma_respuesta_dice_por_aca_y_de_dia_no_lleva_el_cierre_nocturno(): void
    {
        $this->a_las('15:00');
        $this->red();
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client, false);
        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => '¿Cómo importo mis clientes?',
            'delivered_at' => now(), 'whatsapp_message_id' => 'wamid.entrante.' . Str::random(6),
        ]);
        $ticket->last_client_message_at = now();
        $ticket->save();

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);

        $texto = (string) $this->espia->textos[0]['body'];
        $this->assertStringContainsString('Adjuntá el Excel o el PDF de tu proveedor por acá, y Martín lo toma apenas vuelve.', $texto);
        $this->assertStringNotContainsString('con el clip', $texto);
        $this->assertStringNotContainsString('desde las 7 de la mañana', $texto, 'De día no se promete la hora.');
    }

    /**
     * Un cliente de ensayo que escribe por WhatsApp de noche (12/11/2026): el respaldo no le contesta por WhatsApp, deja
     * la respuesta en la conversación del sistema del negocio y la registra con `canal = sistema`.
     *
     * @test
     */
    public function el_respaldo_a_un_cliente_de_ensayo_por_whatsapp_queda_en_el_sistema_sin_whatsapp(): void
    {
        $this->red();
        $client = $this->cliente_en_prueba();
        $client->es_ensayo = true;
        $client->save();
        $ticket = $this->ticket_del_cliente($client, false);
        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => '¿Cómo importo mis clientes?',
            'delivered_at' => now(), 'whatsapp_message_id' => 'wamid.entrante.' . Str::random(6),
        ]);
        $ticket->last_client_message_at = now();
        $ticket->save();

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);

        $this->assertSame([], $this->espia->textos);
        $this->assertSame([], $this->espia->plantillas);
        $sistema = SupportTicket::where('client_id', $client->id)->where('source', 'erp')->firstOrFail();
        $this->assertStringStartsWith('Respuesta automática:', $this->respuesta_en($sistema));
        $envio = PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', PruebaRespaldoSoporteService::PLANTILLA)->firstOrFail();
        $this->assertSame(PruebaWhatsappEnvio::CANAL_SISTEMA, $envio->canal);
        $this->assertSame((int) $sistema->id, (int) $envio->support_ticket_id);
        $this->assertNotNull($envio->support_message_id);
    }

    /** @test */
    public function una_duda_comun_de_noche_la_contesta_el_modelo_con_la_regla_sin_jerga_y_cierra_con_la_hora_de_martin(): void
    {
        $this->red();
        [$ticket, $entrante] = $this->escribe_en_el_sistema('¿Cómo cargo un artículo?');

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);

        $this->assertSame("Respuesta automática: Entrá a Listado y tocá Crear.\nMartín te sigue desde las 7 de la mañana.", $this->respuesta_en($ticket));
        Http::assertSent(function ($request) {
            return strpos($request->url(), 'deepseek') !== false
                && strpos((string) $request['system'], 'Hablá sin jerga') !== false
                && strpos((string) $request['system'], 'NO expliques el importador') !== false;
        });
    }

    /** @test */
    public function un_excel_mandado_de_noche_recibe_un_acuse_fijo_una_sola_vez_por_noche(): void
    {
        $this->red();
        [$ticket, $entrante] = $this->escribe_en_el_sistema('Te mando la lista');
        SupportMessageAttachment::create(['support_message_id' => $entrante->id, 'disk' => 'public', 'path' => 'soporte/lista.xlsx',
            'original_name' => 'lista.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 10]);

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame('Respuesta automática: Recibí tu archivo. Martín lo revisa y te lo carga desde las 7 de la mañana; te avisa por acá.', $this->respuesta_en($ticket));
        $this->assertSame(1, PruebaWhatsappEnvio::where('support_ticket_id', $ticket->id)->where('plantilla', PruebaRespaldoSoporteService::PLANTILLA_ACUSE)->count());
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'deepseek') !== false;
        });

        /* Manda otro archivo esa misma noche: no se le repite el acuse. */
        [, $otro] = $this->escribe_en_el_sistema('Y este otro', $ticket);
        SupportMessageAttachment::create(['support_message_id' => $otro->id, 'disk' => 'public', 'path' => 'soporte/catalogo.pdf', 'mime' => 'application/pdf', 'size' => 10]);
        $this->assertSame('adjunto', $this->responder($ticket, $otro)['estado']);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->count());
    }

    /** @test */
    public function una_foto_de_noche_o_un_excel_de_dia_siguen_esperando_a_una_persona(): void
    {
        $this->red();
        [$ticket, $entrante] = $this->escribe_en_el_sistema('Mirá esta pantalla');
        SupportMessageAttachment::create(['support_message_id' => $entrante->id, 'disk' => 'public', 'path' => 'soporte/captura.png', 'mime' => 'image/png', 'size' => 10]);
        $this->assertSame('adjunto', $this->responder($ticket, $entrante)['estado']);

        $this->a_las('15:00');
        [$de_dia, $con_excel] = $this->escribe_en_el_sistema('Te mando la lista');
        SupportMessageAttachment::create(['support_message_id' => $con_excel->id, 'disk' => 'public', 'path' => 'soporte/lista.xlsx', 'mime' => 'application/vnd.ms-excel', 'size' => 10]);
        $this->assertSame('adjunto', $this->responder($de_dia, $con_excel)['estado']);

        $this->assertSame(0, SupportMessage::whereIn('support_ticket_id', [$ticket->id, $de_dia->id])->where('sender_type', 'admin')->count());
    }
}
