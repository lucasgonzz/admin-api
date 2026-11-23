<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ManualRepositoryService;
use App\Services\Prueba\PruebaRespaldoSoporteService;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\SupportCodexHealthService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\ImplementacionContratada\BaseDeLaImplementacion;

/**
 * CA30 de la etapa E3 (misión embudo-juan-pipeline): la respuesta automática de Soporte (el respaldo mientras Martín no está) deja de
 * prometer «te lo cargamos sin costo» y depende de `implementacion_contratada`.
 *
 *  - Una prueba SIN implementación (`no`) que pide que le carguen el Excel recibe el video de importar (05) y la mención del servicio
 *    de implementación; nunca «sin costo».
 *  - Una prueba con implementación (`mitad`) recibe «lo cargamos», sin «sin costo».
 *  - El prompt que le llega al modelo (DeepSeek) cuando la persona NO pregunta por cargar un archivo tampoco dice «sin costo» ni «se lo
 *    cargamos» para `no`, y sí para `mitad`.
 *
 * Hoy están EN ROJO: el texto fijo y el prompt dicen «sin costo» para todos.
 */
class RespaldoSegunImplementacionTest extends BaseDeLaImplementacion
{
    /** @var \App\Services\WhatsappSendService */
    private $espia;

    /**
     * Martín caído, el modelo y el manual simulados.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
        $hace = now()->subMinutes(10)->getTimestamp();
        AdminSetting::set(SupportCodexHealthService::KEY, json_encode(['armed_at' => $hace - 3600, 'last_seen_at' => $hace, 'last_success_at' => $hace, 'status' => 'ok']));
        AdminSetting::set(PruebaVideoCatalogoService::key('05'), PruebaVideoCatalogoService::URLS_APROBADAS['05']);
        config(['services.deepseek.api_key' => 'clave-falsa']);
        $this->mock(ManualRepositoryService::class, function ($mock) {
            $mock->shouldReceive('file_list_cacheada')->andReturn('manual_sistema/articulos/crear.md');
            $mock->shouldReceive('get_file')->andReturn('Entrá a Artículos y tocá Nuevo.');
        });
        $this->espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
    }

    /**
     * Un ticket de la prueba con el entrante dado.
     *
     * @param string $estado_de_implementacion `no` o `mitad`.
     * @param string $texto
     *
     * @return array{0: SupportTicket, 1: SupportMessage}
     */
    private function ticket_con(string $estado_de_implementacion, string $texto): array
    {
        list($client) = $this->prueba_con_lead();
        Client::query()->whereKey($client->id)->update(['implementacion_contratada' => $estado_de_implementacion]);
        $ticket   = $this->ticket_del_cliente($client->fresh(), false);
        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => $texto,
            'delivered_at' => now(), 'whatsapp_message_id' => 'wamid.entrante.' . Str::random(6),
        ]);
        $ticket->last_client_message_at = now();
        $ticket->save();

        return [$ticket, $entrante];
    }

    /**
     * El cuerpo de lo último que el respaldo le mandó al ticket.
     *
     * @param SupportTicket $ticket
     *
     * @return string
     */
    private function respuesta_del_respaldo(SupportTicket $ticket): string
    {
        return (string) SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->orderByDesc('id')->value('body');
    }

    /**
     * CA30. Prueba sin implementación: video 05 y la implementación, sin «sin costo».
     *
     * @return void
     */
    public function test_sin_implementacion_pasa_el_video_y_ofrece_la_implementacion(): void
    {
        list($ticket, $entrante) = $this->ticket_con('no', 'te paso el excel para que me lo cargues');

        $resultado = app(PruebaRespaldoSoporteService::class)->responder((int) $ticket->id, (int) $entrante->id);

        $this->assertSame('enviado', $resultado['estado']);
        $texto = $this->respuesta_del_respaldo($ticket);
        $this->assertStringContainsString(PruebaVideoCatalogoService::URLS_APROBADAS['05'], $texto, 'El video de importar.');
        $this->assertStringContainsString('implementación', $texto);
        $this->assertStringNotContainsStringIgnoringCase('sin costo', $texto);
        $this->assertStringNotContainsStringIgnoringCase('te lo cargamos', $texto);
    }

    /**
     * CA30. Prueba con implementación: «lo cargamos», sin «sin costo».
     *
     * @return void
     */
    public function test_con_implementacion_lo_cargamos(): void
    {
        list($ticket, $entrante) = $this->ticket_con('mitad', 'te paso el excel para que me lo cargues');

        $resultado = app(PruebaRespaldoSoporteService::class)->responder((int) $ticket->id, (int) $entrante->id);

        $this->assertSame('enviado', $resultado['estado']);
        $texto = $this->respuesta_del_respaldo($ticket);
        $this->assertStringContainsString('lo cargamos', $texto);
        $this->assertStringNotContainsStringIgnoringCase('sin costo', $texto);
    }

    /**
     * CA30. El prompt que le llega al modelo depende del mismo dato.
     *
     * @return void
     */
    public function test_el_prompt_del_modelo_depende_de_la_implementacion(): void
    {
        $respuesta = ['content' => [['type' => 'text', 'text' => json_encode(['contenido' => 'Entrá a Artículos y tocá Nuevo.', 'fuentes' => ['prueba'], 'videos' => []])]]];

        foreach (['no' => false, 'mitad' => true] as $estado => $se_lo_cargan) {
            $this->fakear_http(['api.deepseek.com/*' => Http::response($respuesta, 200), '*/admin-sync/ai-excel-import/prueba-pendientes*' => Http::response(['pendientes' => [], 'en_proceso' => false], 200)]);
            list($ticket, $entrante) = $this->ticket_con($estado, '¿Cómo ordeno mis artículos por rubro?');

            app(PruebaRespaldoSoporteService::class)->responder((int) $ticket->id, (int) $entrante->id);

            $sistema = '';
            foreach (Http::recorded() as $par) {
                if (strpos($par[0]->url(), 'deepseek') !== false) {
                    $sistema = (string) $par[0]['system'];
                }
            }
            $this->assertNotSame('', $sistema, 'El modelo recibió el prompt (' . $estado . ').');
            $this->assertStringNotContainsStringIgnoringCase('sin costo', $sistema, 'Para «' . $estado . '».');
            if ($se_lo_cargan) {
                $this->assertStringContainsString('se lo cargamos', $sistema);
            } else {
                $this->assertStringNotContainsString('se lo cargamos', $sistema);
                $this->assertStringContainsString('implementación', $sistema);
            }
        }
    }
}
