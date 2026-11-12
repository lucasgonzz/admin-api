<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\MetaTemplateService;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;

/**
 * La bienvenida de la prueba después de la misión cruzada prueba-e2e-campana-imperio (11/11/2026):
 *
 *  B. La bienvenida v2 explica los agentes (Soporte y Asistente personal). Son plantillas NUEVAS: salen cuando
 *     Meta las aprueba; mientras tanto, o con el setting en `v1`, sale la de siempre.
 *  A. El negocio ve la bienvenida y el video 04 en Soporte de SU SISTEMA aunque el WhatsApp no salga (plantilla
 *     en cola o rechazada): una sola copia, que se retira cuando la plantilla sale de verdad.
 *
 * Lo que «ve el sistema» se mide con el mismo endpoint que usa empresa: `GET inbound/agentes/soporte/{uuid}`.
 */
class BienvenidaV2YEnElSistemaTest extends BaseDeLaPrueba
{
    protected function setUp(): void
    {
        parent::setUp();

        /* Estas pruebas miden las novedades prendidas para todos los negocios; el alcance `ensayo` (el default)
           tiene su prueba aparte en AlcanceDeLasNovedadesTest. */
        \App\Models\AdminSetting::set(\App\Services\Prueba\PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
    }

    /**
     * @param array<string> $aprobadas   Plantillas registradas y aprobadas.
     * @param array<string> $pendientes  Plantillas registradas sin aprobar.
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(array $aprobadas, array $pendientes = []): array
    {
        $this->crear_config_whatsapp();
        $this->admin();
        if ($aprobadas !== []) {
            $this->plantillas_de_la_prueba_v2(true, $aprobadas);
        }
        if ($pendientes !== []) {
            $this->plantillas_de_la_prueba_v2(false, $pendientes);
        }

        $client = $this->cliente_en_prueba();
        $client->inbound_api_key = 'firma-de-la-bienvenida';
        $client->save();
        $lead = $this->lead_en_prueba($client);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response([], 404)]);

        return [$client, $lead];
    }

    /**
     * Registra plantillas de cualquiera de las dos tandas (las de siempre y las v2).
     *
     * @param bool          $aprobadas
     * @param array<string> $nombres
     *
     * @return void
     */
    private function plantillas_de_la_prueba_v2(bool $aprobadas, array $nombres): void
    {
        foreach (PruebaPlantillasService::todas() as $nombre => $definicion) {
            if (! in_array($nombre, $nombres, true)) {
                continue;
            }

            FollowupTemplate::create([
                'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
                'body_template' => $definicion['body'], 'language_code' => 'es_AR', 'activa' => $aprobadas,
                'meta_status' => $aprobadas ? 'APPROVED' : 'PENDING', 'meta_category' => 'UTILITY', 'uso' => $definicion['uso'],
                'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
            ]);
        }
    }

    /**
     * Lo que el sistema del negocio muestra en Soporte: los mensajes del ticket «Prueba gratuita».
     *
     * @param Client $client
     *
     * @return array<int, array<string, mixed>>
     */
    private function lo_que_ve_el_sistema(Client $client): array
    {
        $ticket = SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->first();
        if ($ticket === null) {
            return [];
        }

        $respuesta = $this->getJson('/api/inbound/agentes/soporte/' . $ticket->uuid . '?' . http_build_query([
            'client_uuid' => $client->uuid, 'client_user_id' => $client->user_id, 'es_dueno' => 1,
        ]), ['X-Admin-Api-Key' => (string) $client->inbound_api_key])->assertOk();

        return $respuesta->json('model.messages');
    }

    /* ------------------------------------------------------------------------------------------
     | B: la bienvenida v2
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function con_la_v2_aprobada_sale_la_bienvenida_que_explica_los_agentes_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio([PruebaPlantillasService::BIENVENIDA, PruebaPlantillasService::BIENVENIDA_V2]);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('enviado', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame('ya_enviada', $servicio->enviar_bienvenida($lead, $client)['estado']);

        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela'], $espia->plantillas[0]['variables']);

        $mensaje = SupportMessage::where('whatsapp_message_id', 'wamid.plantilla.1')->firstOrFail();
        $this->assertStringContainsString('Hola Marcela, soy Martín', $mensaje->body);
        $this->assertStringContainsString('en Agentes, tenés dos ayudas: Soporte, que soy yo', $mensaje->body);
        $this->assertStringContainsString('tu Asistente personal', $mensaje->body);
        $this->assertStringContainsString('recorrido guiado en tu pantalla', $mensaje->body);
        $this->assertStringContainsString('por este chat o desde Soporte en tu sistema', $mensaje->body, 'Sirve para los dos canales.');

        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->count());
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, PruebaWhatsappEnvio::where('client_id', $client->id)->value('plantilla'));

        /* El resumen de la pestaña la reconoce como bienvenida. */
        $this->assertTrue(app(\App\Services\PruebaDelClienteService::class)->resumen($client, $lead, false)['bienvenida_enviada']);
    }

    /** @test */
    public function mientras_meta_no_aprueba_la_v2_sale_la_de_siempre_y_el_setting_v1_la_fuerza(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio([PruebaPlantillasService::BIENVENIDA], [PruebaPlantillasService::BIENVENIDA_V2]);

        $plantillas = app(PruebaPlantillasService::class);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $plantillas->bienvenida_vigente(false), 'La v2 está PENDING.');
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE, $plantillas->bienvenida_vigente(true), 'La v2 con asistente ni está registrada.');

        FollowupTemplate::where('template_name', PruebaPlantillasService::BIENVENIDA_V2)->update(['activa' => true, 'meta_status' => 'APPROVED']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, $plantillas->bienvenida_vigente(false));

        AdminSetting::set(PruebaSettings::KEY_BIENVENIDA_VERSION, 'v1');
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $plantillas->bienvenida_vigente(false));

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $espia->plantillas[0]['template_name']);
    }

    /** @test */
    public function el_comando_crea_las_v2_y_las_plantillas_nuevas_cumplen_las_reglas_de_meta(): void
    {
        $simulacion = app(PruebaPlantillasService::class)->crear_las_que_faltan(app(MetaTemplateService::class), false);
        $nombres    = array_column($simulacion, 'plantilla');

        foreach ([
            PruebaPlantillasService::BIENVENIDA_V2, PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE_V2,
            PruebaPlantillasService::DIA_5, PruebaPlantillasService::FIN_ENCUESTA,
        ] as $nueva) {
            $this->assertContains($nueva, $nombres);
        }

        foreach (PruebaPlantillasService::todas() as $nombre => $definicion) {
            $cuerpo = $definicion['body'];
            $this->assertLessThanOrEqual(1024, mb_strlen($cuerpo), $nombre . ': Meta acepta hasta 1024 caracteres.');
            $this->assertStringStartsNotWith('{{', $cuerpo, $nombre . ': no puede empezar con una variable.');
            $this->assertStringEndsNotWith('}}', $cuerpo, $nombre . ': no puede terminar con una variable.');
            $this->assertStringNotContainsString("\n\n\n", $cuerpo, $nombre);
            $this->assertStringContainsString('{{1}}', $cuerpo, $nombre);
            preg_match_all('/\{\{(\d+)\}\}/', $cuerpo, $variables);
            $numeros = array_values(array_unique(array_map('intval', $variables[1])));
            sort($numeros);
            $this->assertSame(range(1, count($numeros)), $numeros, $nombre . ': variables numeradas sin saltos.');
        }
    }

    /* ------------------------------------------------------------------------------------------
     | A: la bienvenida dentro del sistema
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function con_la_plantilla_en_cola_el_negocio_igual_ve_la_bienvenida_y_el_video_en_su_sistema_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio([], [PruebaPlantillasService::BIENVENIDA]);
        AdminSetting::set(PruebaVideoCatalogoService::key('04'), PruebaVideoCatalogoService::URLS_APROBADAS['04']);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('en_cola', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame('en_cola', $servicio->enviar_bienvenida($lead, $client)['estado'], 'El evento de ingreso se repite.');

        $this->assertCount(0, $espia->plantillas, 'Nada sale por WhatsApp sin plantilla aprobada.');
        $this->assertCount(0, $espia->textos);

        $visibles = $this->lo_que_ve_el_sistema($client);
        $this->assertCount(1, $visibles, 'Una sola bienvenida en Soporte del sistema.');
        $this->assertSame('admin', $visibles[0]['sender_type']);
        $this->assertStringContainsString('Hola Marcela, soy Martín', $visibles[0]['body']);
        $this->assertStringContainsString('en Agentes, tenés dos ayudas', $visibles[0]['body']);
        $this->assertStringContainsString(PruebaVideoCatalogoService::URLS_APROBADAS['04'], $visibles[0]['body'], 'Con el video 04.');

        $ticket = SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->sole();
        $this->assertSame('Prueba gratuita', $ticket->name);
        $copia = SupportMessage::where('support_ticket_id', $ticket->id)->sole();
        $this->assertNull($copia->whatsapp_message_id);
        $this->assertTrue($copia->is_trial_import_notice);

        /* El registro de envíos no cambia: la bienvenida por WhatsApp sigue en cola, una sola fila. */
        $envios = PruebaWhatsappEnvio::where('client_id', $client->id)->get();
        $this->assertCount(1, $envios);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_EN_COLA, $envios->first()->estado);
    }

    /** @test */
    public function cuando_la_plantilla_sale_de_verdad_la_copia_del_sistema_se_retira_y_no_se_duplica(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio([], [PruebaPlantillasService::BIENVENIDA]);

        $servicio = app(PruebaWhatsappService::class);
        $servicio->enviar_bienvenida($lead, $client);
        $this->assertCount(1, $this->lo_que_ve_el_sistema($client));

        FollowupTemplate::where('template_name', PruebaPlantillasService::BIENVENIDA)->update(['activa' => true, 'meta_status' => 'APPROVED']);
        $this->assertSame('enviado', $servicio->enviar_bienvenida($lead, $client)['estado']);

        $this->assertCount(1, $espia->plantillas);
        $visibles = $this->lo_que_ve_el_sistema($client);
        $this->assertCount(1, $visibles, 'Sigue habiendo una sola bienvenida: la que salió por WhatsApp.');
        $ticket = SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->sole();
        $this->assertSame('wamid.plantilla.1', SupportMessage::where('support_ticket_id', $ticket->id)->sole()->whatsapp_message_id);

        $this->assertSame('ya_enviada', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertCount(1, $this->lo_que_ve_el_sistema($client));
    }

    /** @test */
    public function si_meta_rechaza_el_envio_el_negocio_tambien_la_ve_en_su_sistema_una_sola_vez(): void
    {
        $espia = $this->espiar_sender(false);
        [$client, $lead] = $this->negocio([PruebaPlantillasService::BIENVENIDA]);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('fallido', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame('fallido', $servicio->enviar_bienvenida($lead, $client)['estado']);

        $visibles = $this->lo_que_ve_el_sistema($client);
        $this->assertCount(1, $visibles, 'Los intentos rechazados no se ven; la copia del sistema, una sola.');
        $this->assertStringContainsString('Hola Marcela, soy Martín', $visibles[0]['body']);
    }

    /** @test */
    public function con_la_plantilla_aprobada_no_se_deja_ninguna_copia(): void
    {
        $this->espiar_sender();
        [$client, $lead] = $this->negocio([PruebaPlantillasService::BIENVENIDA]);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);

        $ticket = SupportTicket::where('client_id', $client->id)->sole();
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        $this->assertCount(1, $this->lo_que_ve_el_sistema($client));
    }
}
