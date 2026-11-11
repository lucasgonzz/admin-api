<?php

namespace Tests\Feature\PruebaGratuita;

use App\Jobs\ResponderRespaldoDePruebaJob;
use App\Models\AdminSetting;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\AsistenteWhatsappSettings;
use App\Services\ManualRepositoryService;
use App\Services\Prueba\PruebaRespaldoSoporteService;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\SupportCodexHealthService;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use App\Mail\SupportCodexHealthMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * El respaldo cuando Martín está caído (A4 de la misión cruzada prueba-ayuda-unificada-en-soporte, 10/11/2026).
 *
 * Cada guarda tiene su caso: Martín sano no responde, el tope de 3 por día, la ventana cerrada, un adjunto, una
 * importación en curso, un pedido comercial, alguien que ya contestó, y el modelo que falla. Y el caso feliz:
 * el texto empieza con «Respuesta automática:», sale por el ticket sin tapar la pregunta del lead y queda
 * registrado para que Martín lo vea al volver.
 */
class RespaldoDeSoporteDeLaPruebaTest extends BaseDeLaPrueba
{
    /** @var \App\Services\WhatsappSendService */
    private $espia;

    protected function setUp(): void
    {
        parent::setUp();

        /* 12:00 de Argentina: la vigilancia mide (después de las 07:00). */
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'America/Argentina/Buenos_Aires'));
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::where('key', SupportCodexHealthService::KEY)->delete();
        foreach (array_keys(PruebaVideoCatalogoService::PIEZAS) as $codigo) {
            AdminSetting::where('key', PruebaVideoCatalogoService::key($codigo))->delete();
        }
        config(['services.deepseek.api_key' => 'clave-falsa']);
        $this->mock(ManualRepositoryService::class, function ($mock) {
            $mock->shouldReceive('file_list_cacheada')->andReturn('manual_sistema/articulos/crear.md');
            $mock->shouldReceive('get_file')->andReturn('Entrá a Artículos y tocá Nuevo.');
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

    /** Martín caído: el monitor dejó de mandar pulso hace 10 minutos. */
    private function martin_caido(): void
    {
        $hace = now()->subMinutes(10)->getTimestamp();
        AdminSetting::set(SupportCodexHealthService::KEY, json_encode([
            'armed_at' => $hace - 3600, 'last_seen_at' => $hace, 'last_success_at' => $hace, 'status' => 'ok',
        ]));
    }

    /** Martín sano: pulso de hace un minuto. */
    private function martin_sano(): void
    {
        $hace = now()->subMinute()->getTimestamp();
        AdminSetting::set(SupportCodexHealthService::KEY, json_encode([
            'armed_at' => $hace - 3600, 'last_seen_at' => $hace, 'last_success_at' => $hace, 'status' => 'ok',
        ]));
    }

    /**
     * Fakea DeepSeek (una respuesta con el manual leído) y la instancia sin importaciones.
     *
     * @param bool $modelo_falla
     * @param bool $importacion_en_curso
     */
    private function red(bool $modelo_falla = false, bool $importacion_en_curso = false): void
    {
        /* El modelo propone el video 06: tiene que estar en el catálogo aprobado o la validación lo rechaza. */
        AdminSetting::set(PruebaVideoCatalogoService::key('06'), PruebaVideoCatalogoService::URLS_APROBADAS['06']);
        $this->fakear_http([
            '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['pendientes' => [], 'en_proceso' => $importacion_en_curso], 200),
            'api.deepseek.com/*' => $modelo_falla
                ? Http::response(['error' => 'caído'], 500)
                : Http::sequence()
                    ->push(['content' => [['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'leer_manual', 'input' => ['ruta' => 'manual_sistema/articulos/crear.md']]]])
                    ->push(['content' => [['type' => 'text', 'text' => json_encode([
                        'contenido' => 'Entrá a Artículos y tocá Nuevo.', 'fuentes' => ['manual_sistema/articulos/crear.md'],
                        'videos' => ['prueba:06'],
                    ])]]]),
        ]);
    }

    /**
     * Un ticket de prueba con un entrante de texto (la ventana queda abierta).
     *
     * @param string $texto
     *
     * @return array{0: SupportTicket, 1: SupportMessage}
     */
    private function ticket_con_pregunta(string $texto = '¿Cómo cargo un artículo?'): array
    {
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client, false);
        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => $texto,
            'delivered_at' => now(), 'whatsapp_message_id' => 'wamid.entrante.' . Str::random(6),
        ]);

        return [$ticket, $entrante];
    }

    private function responder(SupportTicket $ticket, SupportMessage $entrante): array
    {
        return app(PruebaRespaldoSoporteService::class)->responder((int) $ticket->id, (int) $entrante->id);
    }

    /** @test */
    public function con_martin_caido_contesta_marcada_y_sin_tapar_la_pregunta(): void
    {
        AdminSetting::set(PruebaVideoCatalogoService::key('06'), PruebaVideoCatalogoService::URLS_APROBADAS['06']);
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);

        $this->assertCount(1, $this->espia->textos);
        $texto = $this->espia->textos[0]['body'];
        $this->assertStringStartsWith('Respuesta automática: Entrá a Artículos y tocá Nuevo.', $texto);
        $this->assertStringContainsString(PruebaVideoCatalogoService::URLS_APROBADAS['06'], $texto);

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->sole();
        $this->assertTrue((bool) $mensaje->is_trial_import_notice, 'No tapa el entrante: Martín lo sigue viendo pendiente.');
        $envio = PruebaWhatsappEnvio::where('plantilla', PruebaRespaldoSoporteService::PLANTILLA)->sole();
        $this->assertSame((int) $mensaje->id, (int) $envio->support_message_id);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, $envio->estado);
        $this->assertTrue(PruebaWhatsappEnvio::where('plantilla', 'video_06')->where('estado', 'enviado')->exists(),
            'El video que sumó cuenta como entregado en prueba.videos.');

        /* El mismo entrante no se contesta dos veces. */
        $this->assertSame('ya_respondido', $this->responder($ticket, $entrante)['estado']);
        $this->assertCount(1, $this->espia->textos);

        Http::assertSent(function ($request) {
            return strpos($request->url(), 'deepseek') !== false
                && strpos((string) $request['system'], 'respuesta automática del soporte') !== false;
        });
    }

    /** @test */
    public function con_martin_sano_no_responde(): void
    {
        $this->martin_sano();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        $this->assertSame('martin_sano', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'deepseek') !== false;
        });
    }

    /** @test */
    public function con_el_proveedor_apagado_no_responde(): void
    {
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '0');
        $this->assertSame('martin_sano', $this->responder($ticket, $entrante)['estado'], 'Apagado a mano es una pausa, no una caída.');
        $this->assertSame([], $this->espia->textos);
    }

    /**
     * De noche: el respaldo mide (Martín sin pulso = caído) y contesta, pero el correo de vigilancia sigue callado
     * hasta las 07:00. Cambio del coordinador del 10/11/2026: el respaldo existe justamente para la noche.
     *
     * @test
     */
    public function de_noche_sin_pulso_esta_en_falla_y_responde_pero_el_correo_no_sale(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-10 02:00:00', 'America/Argentina/Buenos_Aires'));
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        $this->assertTrue(app(SupportCodexHealthService::class)->en_falla());
        $this->artisan('support:check-codex-health')->assertExitCode(0);
        Mail::assertNothingSent();

        $this->assertSame('enviado', $this->responder($ticket, $entrante)['estado']);
        $this->assertCount(1, $this->espia->textos);
    }

    /** @test */
    public function de_dia_sin_pulso_esta_en_falla_y_el_correo_sale_como_antes(): void
    {
        Mail::fake();
        $this->martin_caido();

        $this->assertTrue(app(SupportCodexHealthService::class)->en_falla());
        $this->artisan('support:check-codex-health')->assertExitCode(0);
        Mail::assertSent(SupportCodexHealthMail::class, function ($mail) {
            return $mail->kind === 'failure' && $mail->health['reason'] === 'sin_pulso';
        });

        /* Con pulso fresco, de día o de noche, no está en falla. */
        $this->martin_sano();
        $this->assertFalse(app(SupportCodexHealthService::class)->en_falla());
        Carbon::setTestNow(Carbon::parse('2026-11-10 03:00:00', 'America/Argentina/Buenos_Aires'));
        $this->martin_sano();
        $this->assertFalse(app(SupportCodexHealthService::class)->en_falla());
    }

    /** @test */
    public function no_pasa_de_tres_respuestas_por_ticket_por_dia(): void
    {
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();
        foreach ([1, 2, 3] as $n) {
            PruebaWhatsappEnvio::create([
                'client_id' => $ticket->client_id, 'plantilla' => PruebaRespaldoSoporteService::PLANTILLA,
                'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'support_ticket_id' => $ticket->id,
                'detalle' => 'entrante:' . ($entrante->id - $n), 'dia' => \App\Services\PruebaWhatsappService::hoy(), 'enviado_at' => now(),
            ]);
        }

        $this->assertSame('tope_diario', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
    }

    /** @test */
    public function con_la_ventana_cerrada_no_responde(): void
    {
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();
        SupportMessage::whereKey($entrante->id)->update(['delivered_at' => now()->subHours(25)]);

        $this->assertSame('ventana_cerrada', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
    }

    /** @test */
    public function con_un_adjunto_en_el_turno_no_responde(): void
    {
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta('Te mando la lista');
        SupportMessageAttachment::create([
            'support_message_id' => $entrante->id, 'disk' => 'public', 'path' => 'soporte/lista.xlsx',
            'mime' => 'application/vnd.ms-excel', 'size' => 10,
        ]);

        $this->assertSame('adjunto', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
    }

    /** @test */
    public function con_una_importacion_en_curso_no_responde(): void
    {
        $this->martin_caido();
        $this->red(false, true);
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        $this->assertSame('importacion_en_curso', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
    }

    /** @test */
    public function ante_un_pedido_de_precio_o_de_contratacion_no_responde(): void
    {
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta('¿Cuánto sale el sistema por mes? Quiero contratarlo');

        $this->assertSame('pedido_comercial', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);

        /* Pero «cómo cambio un precio» es una duda de uso y sí se contesta. */
        [$otro, $de_uso] = $this->ticket_con_pregunta('¿Cómo cambio el precio de un artículo?');
        $r = $this->responder($otro, $de_uso); $this->assertSame('enviado', $r['estado'], (string) $r['detalle']);
    }

    /** @test */
    public function si_el_modelo_falla_no_manda_nada(): void
    {
        $this->martin_caido();
        $this->red(true);
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        $this->assertSame('modelo_fallo', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
        $this->assertSame(0, SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->count());
        $this->assertSame(0, PruebaWhatsappEnvio::where('plantilla', PruebaRespaldoSoporteService::PLANTILLA)->count());
    }

    /** @test */
    public function si_ya_contestaron_o_martin_tiene_el_ticket_no_responde(): void
    {
        $this->martin_caido();
        $this->red();
        [$ticket, $entrante] = $this->ticket_con_pregunta();

        $caso = SupportCodexCase::firstOrCreate(['support_ticket_id' => $ticket->id]);
        $caso->lease_expires_at = now()->addMinutes(5);
        $caso->save();
        $this->assertSame('martin_atendiendo', $this->responder($ticket, $entrante)['estado']);

        $caso->lease_expires_at = null;
        $caso->save();
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'body' => 'Te ayudo yo']);
        $this->assertSame('ya_respondido', $this->responder($ticket, $entrante)['estado']);
        $this->assertSame([], $this->espia->textos);
    }

    /** @test */
    public function el_entrante_de_un_ticket_de_prueba_programa_el_job_a_los_minutos_configurados(): void
    {
        Queue::fake();
        AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, '1');
        AdminSetting::set(PruebaSettings::KEY_RESPALDO_SOPORTE_MINUTOS, '5');
        $client = $this->cliente_en_prueba();
        $comun  = $this->crear_cliente('+5493417770001', false, 'otra-clave');

        $this->postear_webhook($this->payload_de_texto((string) $client->phone, '¿Cómo cargo un artículo?', 'wamid.PRUEBA'))->assertOk();
        $this->postear_webhook($this->payload_de_texto((string) $comun->phone, 'Hola', 'wamid.COMUN'))->assertOk();

        $entrante = SupportMessage::where('whatsapp_message_id', 'wamid.PRUEBA')->firstOrFail();
        Queue::assertPushed(ResponderRespaldoDePruebaJob::class, 1);
        Queue::assertPushed(ResponderRespaldoDePruebaJob::class, function ($job) use ($entrante) {
            return $job->message_id === (int) $entrante->id && $job->ticket_id === (int) $entrante->support_ticket_id
                && $job->delay !== null && now()->addMinutes(5)->diffInSeconds($job->delay) < 5;
        });
    }
}
