<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El paso del día de un cliente de ENSAYO entregado por la conversación del sistema (12/11/2026): lleva el video de
 * ESE paso (el mismo del correo del paso, del catálogo aprobado) como URL suelta en el texto, que el chat de Soporte
 * del sistema muestra como reproductor. Un paso sin video no promete «si querés te paso un video».
 */
class PasoDelEnsayoConSuVideoTest extends BaseDeLaPrueba
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

        /* El catálogo aprobado completo (04 a 09), como en producción. */
        foreach (PruebaVideoCatalogoService::URLS_APROBADAS as $codigo => $url) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), $url);
        }
    }

    /**
     * Un ensayo con la bienvenida de ayer y la instancia con estos hechos: el empujón de hoy cae en el paso siguiente.
     *
     * @param array<int, string> $hechos
     *
     * @return array{0: Client, 1: Lead}
     */
    private function ensayo_con(array $hechos): array
    {
        $client = $this->cliente_en_prueba(5);
        $client->email     = 'duena@test.local';
        $client->es_ensayo = true;
        $client->save();
        $lead = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id'  => $client->id,
            'lead_id'    => $lead->id,
            'canal'      => PruebaWhatsappEnvio::CANAL_SISTEMA,
            'plantilla'  => PruebaPlantillasService::BIENVENIDA,
            'estado'     => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia'        => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
            'enviado_at' => now()->subDay(),
        ]);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia($hechos), 200)]);

        return [$client, $lead];
    }

    /** El mensaje que quedó en la conversación del sistema del dueño. */
    private function mensaje_del_sistema(Client $client): SupportMessage
    {
        $ticket = SupportTicket::where('client_id', $client->id)->where('source', 'erp')->firstOrFail();

        return SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->firstOrFail();
    }

    /** @test */
    public function el_paso_de_la_compra_llega_al_sistema_con_el_video_07_y_sin_la_promesa(): void
    {
        [$client, $lead] = $this->ensayo_con(['ingreso', 'importo', 'articulo_creado', 'articulo_actualizado', 'venta', 'agente', 'tienda_pedido', 'venta_cc', 'pago_cc']);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertSame('compra', PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_SISTEMA)
            ->where('plantilla', 'like', PruebaPlantillasService::PASO_PREFIJO . '%')->value('hito'));

        $cuerpo = $this->mensaje_del_sistema($client)->body;
        $url    = PruebaVideoCatalogoService::URLS_APROBADAS['07'];
        $this->assertStringContainsString('probá cargar una compra a un proveedor desde Compras', $cuerpo);
        $this->assertStringNotContainsString('te paso un video', $cuerpo);
        $this->assertStringContainsString('«Cargá una compra con una foto»: ' . $url, $cuerpo);
        /* La URL termina el texto: el chat la separa y la muestra como reproductor. */
        $this->assertStringEndsWith($url, $cuerpo);

        /* El correo del paso lleva el mismo video. */
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($url) {
            return in_array($url, array_column((array) $mail->payload->links, 'url'), true);
        });
        $this->assertSame([], $this->espia->plantillas);
        $this->assertSame([], $this->espia->textos);
    }

    /** @test */
    public function un_paso_sin_video_no_promete_un_video(): void
    {
        [$client, $lead] = $this->ensayo_con(['ingreso', 'importo']);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);

        $cuerpo = $this->mensaje_del_sistema($client)->body;
        $this->assertStringContainsString('cargá un artículo tuyo', $cuerpo);
        $this->assertStringNotContainsString('video', $cuerpo);
        $this->assertStringNotContainsString('videos.comerciocity.store', $cuerpo);
        $this->assertStringEndsWith('desde Vender.', $cuerpo);
    }

    /** @test */
    public function cada_paso_lleva_el_video_del_correo_y_ninguno_queda_prometiendo(): void
    {
        foreach (array_keys(PruebaPlantillasService::todas()) as $plantilla) {
            if (strpos($plantilla, PruebaPlantillasService::PASO_PREFIJO) !== 0) {
                continue;
            }
            $hito  = preg_replace('/' . PruebaPlantillasService::SUFIJO_V2 . '$/', '', substr($plantilla, strlen(PruebaPlantillasService::PASO_PREFIJO)));
            $texto = PruebaWhatsappService::paso_con_su_video(str_replace('{{1}}', 'Raúl', PruebaPlantillasService::cuerpo_de($plantilla)), $hito);

            $this->assertStringNotContainsString('te paso un video', mb_strtolower($texto), $plantilla);
            $video = \App\Services\Prueba\PruebaCorreoService::video_del_paso($hito);
            if ($video !== null) {
                $this->assertStringEndsWith($video['url'], $texto, $plantilla);
            } else {
                $this->assertStringNotContainsString('video', $texto, $plantilla);
            }
        }
    }

    /** @test */
    public function un_cliente_que_no_es_de_ensayo_recibe_la_plantilla_de_whatsapp_tal_cual(): void
    {
        [$client, $lead] = $this->ensayo_con(['ingreso', 'importo']);
        $client->es_ensayo = false;
        $client->save();
        PruebaWhatsappEnvio::where('client_id', $client->id)->update(['canal' => PruebaWhatsappEnvio::CANAL_WHATSAPP]);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);

        $this->assertCount(1, $this->espia->plantillas);
        $mensaje = SupportMessage::whereHas('ticket', function ($ticket) use ($client) {
            $ticket->where('client_id', $client->id)->where('source', 'whatsapp');
        })->where('sender_type', 'admin')->firstOrFail();
        $this->assertStringContainsString('Si querés te paso un video de cómo se hace.', $mensaje->body, 'Por WhatsApp no cambia nada.');
    }
}
