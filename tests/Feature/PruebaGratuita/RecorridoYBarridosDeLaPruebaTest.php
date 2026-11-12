<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\DemoEventoRecibido;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaCorreoService;
use App\Services\Prueba\PruebaSettings;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\Prueba\PruebaVideoSoporteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tercera tanda de la misión cruzada prueba-e2e-campana-imperio (11/11/2026), después de las dos revisiones:
 *
 *  A. A la cuenta de ensayo la maneja SOLO `pruebas:ensayo`: los barridos reales la saltean.
 *  B. El recorrido nuevo y sus cinco reglas (tope de dos envíos por paso, saltear el paso sin plantilla, «escribió
 *     hace poco» por cualquier canal, sin paso el último día, y los días que faltan de verdad).
 *  C. El video de cada paso.
 *  F. El bloque `hoy` de `claude/prueba/resumen`, calculado con las mismas reglas que el empujón.
 */
class RecorridoYBarridosDeLaPruebaTest extends BaseDeLaPrueba
{
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        Mail::fake();
    }

    /** Todas las plantillas de la prueba (las de siempre, las nuevas y las v2), aprobadas. */
    private function todo_aprobado(): void
    {
        $this->crear_config_whatsapp();
        $this->admin();
        foreach (PruebaPlantillasService::todas() as $nombre => $definicion) {
            FollowupTemplate::create([
                'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
                'body_template' => $definicion['body'], 'language_code' => 'es_AR', 'activa' => true,
                'meta_status' => 'APPROVED', 'meta_category' => 'UTILITY', 'uso' => $definicion['uso'],
                'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
            ]);
        }
    }

    /**
     * Un negocio con correo y la bienvenida de ayer.
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(int $dias_restantes = 5, string $phone = '+5493411234567'): array
    {
        $client = $this->cliente_en_prueba($dias_restantes, 7, $phone);
        $client->email = 'negocio-' . $client->id . '@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'lead_id' => $lead->id, 'plantilla' => PruebaPlantillasService::BIENVENIDA,
            'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => now(PruebaWhatsappService::ZONA)->subDays(3)->toDateString(),
            'enviado_at' => now()->subDays(3),
        ]);

        return [$client, $lead];
    }

    /**
     * @param array<string> $hechos
     */
    private function la_instancia_contesta(array $hechos): void
    {
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia($hechos), 200)]);
    }

    /** Pasa un día: lo que salió hoy queda como de ayer. */
    private function al_dia_siguiente(Client $client): void
    {
        PruebaWhatsappEnvio::where('client_id', $client->id)->where('dia', now(PruebaWhatsappService::ZONA)->toDateString())
            ->update(['dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString()]);
    }

    /* ------------------------------------------------------------------------------------------
     | A: la cuenta de ensayo y los barridos reales
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function los_barridos_reales_saltean_a_la_cuenta_de_ensayo(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        $this->admin(true);
        /* Los dos van por el día 5: al real le toca el aviso de que termina y al closer, su aviso. */
        [$real]   = $this->negocio(2);
        [$ensayo, $lead_de_ensayo] = $this->negocio(2, '+5493415550301');
        $ensayo->es_ensayo = true;
        $ensayo->save();
        $this->la_instancia_contesta(['ingreso']);

        /* El empujón de las 10:00. */
        $resultados = app(PruebaWhatsappService::class)->empujar_a_todos();
        $this->assertSame([(int) $real->id], array_column($resultados, 'client_id'), 'La cuenta de ensayo ni se recorre.');
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame((string) $real->phone, $espia->plantillas[0]['to']);
        $this->assertCount(1, $espia->textos, 'Un solo aviso al closer: el del negocio real.');
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $ensayo->id)->count(), 'Solo la bienvenida que ya tenía.');

        /* Tampoco a pedido. (El barrido de bienvenidas no la saltea, pero acá no tiene instancia que retomar.) */
        $this->assertSame(1, Artisan::call('prueba:empujar', ['--cliente' => $ensayo->id]));
        $this->assertStringContainsString('pruebas:ensayo', Artisan::output());
        PruebaWhatsappEnvio::where('client_id', $ensayo->id)->delete();
        app(PruebaWhatsappService::class)->retomar_bienvenidas();
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $ensayo->id)->count());

        /* El vencimiento por barrido y su encuesta. */
        foreach ([$real, $ensayo] as $client) {
            $client->trial_ends_at = now()->subMinutes(5);
            $client->save();
        }
        $this->assertSame(0, Artisan::call('pruebas:vencer'));
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, $real->fresh()->estado);
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, $ensayo->fresh()->estado, 'A la cuenta de ensayo la vence solo su comando.');
        $this->assertSame(1, PruebaEncuesta::where('client_id', $real->id)->count());
        $this->assertSame(0, PruebaEncuesta::where('client_id', $ensayo->id)->count());

        /* El evento `prueba.vencida` de su sistema no le avisa al closer; y aunque quede vencida, el barrido no le manda la encuesta. */
        $textos = count($espia->textos);
        $this->evento($lead_de_ensayo, 'prueba.vencida')->assertStatus(200);
        Artisan::call('pruebas:vencer');
        $this->assertCount($textos, $espia->textos);
        $this->assertSame(0, PruebaEncuesta::where('client_id', $ensayo->id)->count());
        foreach ($espia->plantillas as $envio) {
            $this->assertSame((string) $real->phone, $envio['to']);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | B: las cinco reglas del recorrido
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function regla_1_un_paso_sale_como_mucho_dos_veces_y_despues_se_pasa_al_siguiente(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        [$client, $lead] = $this->negocio();
        $this->la_instancia_contesta(['ingreso']);
        $servicio = app(PruebaWhatsappService::class);

        foreach (['cc_prueba_paso_importo', 'cc_prueba_paso_importo', 'cc_prueba_paso_articulo_creado_v2', 'cc_prueba_paso_articulo_creado_v2', 'cc_prueba_paso_articulo_actualizado'] as $indice => $esperada) {
            $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
            $this->assertSame($esperada, $espia->plantillas[$indice]['template_name'], 'Envío ' . ($indice + 1));
            $this->al_dia_siguiente($client);
        }

        $this->assertSame('articulo_creado', PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'cc_prueba_paso_articulo_creado_v2')->value('hito'),
            'La variante corregida cuenta para el mismo paso.');
    }

    /** @test */
    public function regla_1_si_no_entro_nunca_y_ya_van_dos_recordatorios_no_se_le_manda_ningun_paso_mas(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        [$client, $lead] = $this->negocio();
        $this->la_instancia_contesta([]);
        $servicio = app(PruebaWhatsappService::class);

        foreach ([0, 1] as $indice) {
            $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
            $this->assertSame('cc_prueba_paso_ingreso', $espia->plantillas[$indice]['template_name']);
            $this->al_dia_siguiente($client);
        }

        $tercero = $servicio->empujar($client, $lead);
        $this->assertSame('tope_de_ingreso', $tercero['estado']);
        $this->assertCount(2, $espia->plantillas, 'No «entrá al sistema» por tercera vez, ni ningún otro paso.');
    }

    /** @test */
    public function regla_2_si_la_plantilla_del_paso_no_esta_aprobada_sale_el_siguiente_y_su_correo(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        FollowupTemplate::where('template_name', 'cc_prueba_paso_importo')->update(['activa' => false, 'meta_status' => 'PENDING']);
        /* La variante corregida todavía sin aprobar: sale la de siempre. */
        FollowupTemplate::where('template_name', 'cc_prueba_paso_articulo_creado_v2')->update(['activa' => false, 'meta_status' => 'PENDING']);
        [$client, $lead] = $this->negocio();
        $this->la_instancia_contesta(['ingreso']);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);

        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_articulo_creado', $espia->plantillas[0]['template_name']);
        Mail::assertSent(ComercioCityMail::class, 1);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            return $mail->payload->subject === 'Un paso corto: cargá un artículo';
        });
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'correo_paso_importo')->count(), 'El correo del paso salteado no sale.');
    }

    /** @test */
    public function regla_3_si_le_escribio_a_soporte_por_cualquier_canal_hoy_no_sale_paso(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        [$client, $lead] = $this->negocio();
        $this->la_instancia_contesta(['ingreso']);

        /* Escribió por el chat de Soporte de su sistema: no hay ventana de WhatsApp abierta, pero lo está guiando Martín. */
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => (int) $client->user_id, 'status' => 'open',
            'source' => 'erp', 'opened_at' => now(), 'agentes_erp' => true,
        ]);
        $mensaje = SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => '¿Cómo cargo un artículo?', 'delivered_at' => now()]);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('escribio_hace_poco', $servicio->empujar($client, $lead)['estado']);
        $this->assertCount(0, $espia->plantillas);

        /* Pasadas las 24 horas vuelve el paso. */
        SupportMessage::whereKey($mensaje->id)->update(['created_at' => now()->subHours(30)]);
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[0]['template_name']);
    }

    /** @test */
    public function regla_3_el_aviso_de_que_termina_sale_igual_y_con_los_dias_que_se_le_informan_al_closer(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        $this->admin(true);
        [$client] = $this->negocio(2);
        $this->la_instancia_contesta(['ingreso']);
        $ticket = SupportTicket::create(['client_id' => $client->id, 'client_user_id' => (int) $client->user_id, 'status' => 'open', 'source' => 'erp', 'opened_at' => now(), 'agentes_erp' => true]);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Hola', 'delivered_at' => now()]);

        $resultados = app(PruebaWhatsappService::class)->empujar_a_todos();

        $this->assertSame('enviado', $resultados[0]['estado']);
        $this->assertSame(PruebaPlantillasService::DIA_5, $espia->plantillas[0]['template_name']);
        /* Regla 5: el mismo número que lee el closer. */
        $this->assertSame(['Marcela', '3 días'], $espia->plantillas[0]['variables']);
        $this->assertStringContainsString('le quedan 3 días', $espia->textos[0]['body']);
    }

    /** @test */
    public function a_minutos_de_vencer_no_sale_paso(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        /* Vence en media hora: es el día 7 de 7. El aviso de que termina ya había salido. */
        [$client, $lead] = $this->negocio(0);
        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'plantilla' => PruebaPlantillasService::DIA_5, 'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia' => now(PruebaWhatsappService::ZONA)->subDays(2)->toDateString(), 'enviado_at' => now()->subDays(2),
        ]);
        $this->la_instancia_contesta(['ingreso']);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('ultimo_dia', $resultado['estado']);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'like', 'correo_paso_%')->count(), 'Tampoco el correo de un paso.');
    }

    /* ------------------------------------------------------------------------------------------
     | C: el video de cada paso
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function cada_paso_lleva_su_video_y_el_que_no_tiene_no_promete_ninguno(): void
    {
        foreach (PruebaVideoCatalogoService::URLS_APROBADAS as $codigo => $url) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), $url);
        }
        $this->assertSame(['ingreso' => '04', 'importo' => '05', 'articulo_actualizado' => '06', 'compra' => '07', 'venta' => '08', 'agente' => '09'],
            PruebaCorreoService::VIDEO_DEL_PASO);

        [$client, $lead] = $this->negocio();
        $correos = app(PruebaCorreoService::class);
        $correos->enviar_paso($client, $lead, 'articulo_actualizado');
        $correos->enviar_paso($client, $lead, 'articulo_creado');

        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->payload->subject === 'Probá cambiar un precio'
                && strpos($html, 'Entrá a Listado, abrí un artículo, cambiale el costo o el margen y guardá.') !== false
                && strpos($html, 'Te dejo un video que te puede servir:') !== false
                && strpos($html, PruebaVideoCatalogoService::URLS_APROBADAS['06']) !== false;
        });
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->payload->subject === 'Un paso corto: cargá un artículo'
                && strpos($html, 'Desde Listado, con el botón Crear,') !== false
                && strpos($html, 'Te dejo un video') === false
                && strpos($html, 'videos.comerciocity.store') === false;
        });

        /* En el contexto de Martín (`prueba.videos`), el 06 acompaña a «actualizó un artículo»… */
        $this->assertSame('articulo_actualizado', PruebaVideoSoporteService::hito_del_video('06', $client));
        $this->assertSame('importo', PruebaVideoSoporteService::hito_del_video('05', $client));
        /* …y fuera del alcance sigue como estaba. */
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'ensayo');
        $this->assertSame('articulo_creado', PruebaVideoSoporteService::hito_del_video('06', $client));
    }

    /* ------------------------------------------------------------------------------------------
     | F: qué sale hoy
     |----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function hoy_de(Client $client, bool $en_vivo = true): array
    {
        return $this->getJson('/api/claude/prueba/resumen?client_id=' . $client->id . ($en_vivo ? '&consultar_instancia=1' : ''), $this->headers_de_claude())
            ->assertOk()->json('pruebas.0');
    }

    /** @test */
    public function el_resumen_dice_que_sale_hoy_con_las_mismas_reglas_que_el_empujon(): void
    {
        $espia = $this->espiar_sender();
        $this->todo_aprobado();
        [$client, $lead] = $this->negocio();
        $this->la_instancia_contesta(['ingreso']);

        $antes = $this->hoy_de($client)['hoy'];
        $this->assertSame([
            'paso' => 'importo', 'paso_en_el_correo' => null, 'motivo_sin_paso' => null, 'aviso_dia_5' => false, 'vence_hoy' => false,
            'encuesta_pendiente' => false, 'plantilla' => 'cc_prueba_paso_importo', 'video_codigo' => '05',
        ], $antes);

        /* Lo que dijo es lo que sale. */
        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);
        $this->assertSame($antes['plantilla'], $espia->plantillas[0]['template_name']);

        $despues = $this->hoy_de($client)['hoy'];
        $this->assertNull($despues['paso']);
        $this->assertSame('Ya salió un mensaje de la prueba hoy.', $despues['motivo_sin_paso']);
    }

    /** @test */
    public function el_resumen_anuncia_el_aviso_del_dia_5_el_ultimo_dia_y_la_encuesta_pendiente(): void
    {
        $this->espiar_sender();
        $this->todo_aprobado();
        $this->la_instancia_contesta(['ingreso']);

        [$dia_5] = $this->negocio(2);
        $hoy = $this->hoy_de($dia_5)['hoy'];
        $this->assertTrue($hoy['aviso_dia_5']);
        $this->assertNull($hoy['paso']);
        $this->assertSame(PruebaPlantillasService::DIA_5, $hoy['plantilla']);
        $this->assertFalse($hoy['vence_hoy']);

        [$ultimo] = $this->negocio(0, '+5493415550401');
        PruebaWhatsappEnvio::create(['client_id' => $ultimo->id, 'plantilla' => PruebaPlantillasService::DIA_5, 'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia' => now(PruebaWhatsappService::ZONA)->subDays(2)->toDateString(), 'enviado_at' => now()->subDays(2)]);
        $hoy = $this->hoy_de($ultimo)['hoy'];
        $this->assertTrue($hoy['vence_hoy']);
        $this->assertTrue($hoy['encuesta_pendiente']);
        $this->assertNull($hoy['paso']);
        $this->assertSame(PruebaWhatsappService::MOTIVO_ULTIMO_DIA, $hoy['motivo_sin_paso']);

        /* Ya vencida y con la encuesta enviada: nada pendiente. */
        [$vencido] = $this->negocio(-1, '+5493415550402');
        $vencido->estado = PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $vencido->save();
        $this->assertTrue($this->hoy_de($vencido)['hoy']['encuesta_pendiente']);
        PruebaEncuesta::create(['client_id' => $vencido->id, 'token' => str_repeat('z', 48), 'motivo' => 'vencida', 'enviada_at' => now()]);
        $hoy = $this->hoy_de($vencido)['hoy'];
        $this->assertFalse($hoy['encuesta_pendiente']);
        $this->assertSame('La prueba venció: no se empuja más.', $hoy['motivo_sin_paso']);
    }

    /** @test */
    public function fuera_del_alcance_el_resumen_anuncia_el_paso_de_siempre_y_el_proximo_hito_respeta_la_version(): void
    {
        $this->espiar_sender();
        $this->todo_aprobado();
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'ensayo');
        [$client, $lead] = $this->negocio(2);
        $this->la_instancia_contesta(['ingreso', 'importo', 'articulo_creado']);

        /* Un negocio real, en el día 5: sin aviso de que termina, el paso de siempre y sin video. */
        $fila = $this->hoy_de($client);
        $this->assertSame([
            'paso' => 'venta', 'paso_en_el_correo' => null, 'motivo_sin_paso' => null, 'aviso_dia_5' => false, 'vence_hoy' => false,
            'encuesta_pendiente' => false, 'plantilla' => 'cc_prueba_paso_venta', 'video_codigo' => null,
        ], $fila['hoy']);
        $this->assertSame('venta', $fila['proximo_hito']);
        $this->assertSame(12, $fila['hitos_total'], 'La lectura interna sigue mostrando el catálogo entero.');

        /* Dentro del alcance y SIN consultar a la instancia: una versión anterior a los hitos nuevos no los informa,
           así que el próximo paso los saltea. */
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA, 'version' => '4.3.51',
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'user_id' => (int) $client->user_id, 'alias_slug' => 'ferreteria-version',
        ]);
        foreach (['ingreso', 'importo', 'articulo_creado'] as $tipo) {
            DemoEventoRecibido::create(['lead_id' => $lead->id, 'uuid' => (string) Str::uuid(), 'nombre' => 'prueba.' . $tipo, 'ocurrido_at' => now()->subDay()]);
        }

        $sin_consultar = $this->hoy_de($client, false);
        $this->assertSame('admin', $this->getJson('/api/claude/prueba/resumen?client_id=' . $client->id, $this->headers_de_claude())->json('hitos_fuente'));
        $this->assertEqualsCanonicalizing(['articulo_actualizado', 'venta_cc'], $sin_consultar['hitos_sin_soporte']);
        $this->assertSame('venta', $sin_consultar['proximo_hito'], 'No «actualizá un artículo»: su versión no lo informa.');

        TrialInstance::where('client_id', $client->id)->update(['version' => '4.3.52']);
        $this->assertSame('articulo_actualizado', $this->hoy_de($client, false)['proximo_hito']);
    }

    /** @test */
    public function el_resumen_tapa_los_enlaces_de_encuesta(): void
    {
        $this->espiar_sender();
        $this->todo_aprobado();
        [$client] = $this->negocio();
        $token  = str_repeat('Q', 48);
        $ticket = $this->ticket_del_cliente($client, false);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'admin', 'kind' => 'text', 'delivered_at' => now(),
            'body' => 'Contame cómo te fue: https://api-admin.test/public/prueba/encuesta/' . $token . ' Gracias.', 'whatsapp_message_id' => 'wamid.encuesta']);
        PruebaWhatsappEnvio::create(['client_id' => $client->id, 'plantilla' => PruebaPlantillasService::FIN_ENCUESTA, 'estado' => PruebaWhatsappEnvio::ESTADO_FALLIDO,
            'detalle' => 'Meta rechazó https://api-admin.test/public/prueba/encuesta/' . $token, 'dia' => now()->toDateString()]);

        $respuesta = $this->getJson('/api/claude/prueba/resumen?client_id=' . $client->id, $this->headers_de_claude())->assertOk();

        $this->assertStringNotContainsString($token, (string) $respuesta->getContent());
        $this->assertStringContainsString('/prueba/encuesta/[enlace]', $respuesta->json('pruebas.0.ultimo_mensaje.texto'));
        $this->assertStringContainsString('/prueba/encuesta/[enlace]', $respuesta->json('pruebas.0.envios.0.detalle'));
    }
}
