<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaEncuestaService;
use App\Services\Prueba\PruebaSettings;
use App\Services\PruebaPlantillasService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * La encuesta de fin de prueba gratuita (misión cruzada prueba-e2e-campana-imperio, 11/11/2026): se crea y
 * se manda al vencer y al contratar, por WhatsApp (plantilla) y por correo, una sola vez por canal; el negocio
 * la contesta en la página pública, una sola vez, y a Lucas le llega el resultado.
 *
 * Nada sale a la red: el envío a Meta se espía y el correo va con `Mail::fake()`.
 */
class EncuestaDeFinDePruebaTest extends BaseDeLaPrueba
{
    protected function setUp(): void
    {
        parent::setUp();

        /* Estas pruebas miden las novedades prendidas para todos los negocios; el alcance `ensayo` (el default)
           tiene su prueba aparte en AlcanceDeLasNovedadesTest. */
        \App\Models\AdminSetting::set(\App\Services\Prueba\PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
    }

    /**
     * Un negocio en prueba cuya fecha pasó hace unos minutos, con correo y su lead.
     *
     * @param bool $plantilla_aprobada
     *
     * @return Client
     */
    private function negocio_por_vencer(bool $plantilla_aprobada = true): Client
    {
        Mail::fake();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba($plantilla_aprobada, [PruebaPlantillasService::FIN_ENCUESTA]);

        $client = $this->cliente_en_prueba();
        $client->email         = 'duena@test.local';
        $client->trial_ends_at = now()->subMinutes(5);
        $client->save();
        $this->lead_en_prueba($client);

        return $client;
    }

    /** @test */
    public function al_vencer_crea_la_encuesta_y_la_manda_por_whatsapp_y_por_correo_una_sola_vez(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->negocio_por_vencer();

        $this->assertSame(0, Artisan::call('pruebas:vencer'));

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, $client->fresh()->estado);

        $encuesta = PruebaEncuesta::where('client_id', $client->id)->first();
        $this->assertNotNull($encuesta, 'Al vencer se crea la encuesta del cliente.');
        $this->assertSame(PruebaEncuesta::MOTIVO_VENCIDA, $encuesta->motivo);
        $this->assertNotNull($encuesta->enviada_at);
        $this->assertNull($encuesta->respondida_at);
        $this->assertSame(48, strlen($encuesta->token));

        $url = PruebaEncuestaService::url($encuesta);
        $this->assertStringEndsWith('/prueba/encuesta/' . $encuesta->token, $url);

        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::FIN_ENCUESTA, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela', $url], $espia->plantillas[0]['variables']);

        Mail::assertSent(ComercioCityMail::class, 1);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($client, $url) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->hasTo($client->email) && strpos($html, $url) !== false && strpos($html, 'Responder la encuesta') !== false;
        });

        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', PruebaPlantillasService::FIN_ENCUESTA)
            ->where('canal', PruebaWhatsappEnvio::CANAL_WHATSAPP)->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->count());
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'correo_fin_encuesta')
            ->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->count());

        /* El comando corre cada diez minutos: las corridas siguientes no mandan nada más. */
        Artisan::call('pruebas:vencer');
        Artisan::call('pruebas:vencer');
        $this->assertCount(1, $espia->plantillas);
        Mail::assertSent(ComercioCityMail::class, 1);
        $this->assertSame(1, PruebaEncuesta::where('client_id', $client->id)->count());
    }

    /** @test */
    public function con_la_plantilla_sin_aprobar_sale_el_correo_y_el_whatsapp_sale_cuando_meta_la_aprueba(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->negocio_por_vencer(false);

        Artisan::call('pruebas:vencer');

        $this->assertCount(0, $espia->plantillas, 'Sin plantilla aprobada no sale nada por WhatsApp.');
        Mail::assertSent(ComercioCityMail::class, 1);
        $this->assertNotNull(PruebaEncuesta::where('client_id', $client->id)->first()->enviada_at, 'Salió por correo: ya cuenta como enviada.');

        FollowupTemplate::where('template_name', PruebaPlantillasService::FIN_ENCUESTA)->update(['activa' => true, 'meta_status' => 'APPROVED']);

        Artisan::call('pruebas:vencer');
        Artisan::call('pruebas:vencer');

        $this->assertCount(1, $espia->plantillas, 'El barrido la manda apenas se aprueba, y una sola vez.');
        Mail::assertSent(ComercioCityMail::class, 1);
    }

    /** @test */
    public function una_prueba_vencida_hace_mas_de_un_dia_no_recibe_la_encuesta_por_el_barrido(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->negocio_por_vencer();
        $client->estado        = PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $client->trial_ends_at = now()->subDays(3);
        $client->save();

        Artisan::call('pruebas:vencer');

        $this->assertCount(0, $espia->plantillas);
        Mail::assertNothingSent();
        $this->assertSame(0, PruebaEncuesta::where('client_id', $client->id)->count());
    }

    /** @test */
    public function con_el_setting_apagado_no_se_crea_ni_se_manda(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->negocio_por_vencer();
        \App\Models\AdminSetting::set(PruebaSettings::KEY_ENCUESTA_ACTIVA, '0');

        Artisan::call('pruebas:vencer');

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, $client->fresh()->estado, 'Vencer no depende de la encuesta.');
        $this->assertCount(0, $espia->plantillas);
        Mail::assertNothingSent();
        $this->assertSame(0, PruebaEncuesta::where('client_id', $client->id)->count());
    }

    /** @test */
    public function al_contratar_tambien_se_manda_y_la_respuesta_dice_que_contrato(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->negocio_por_vencer();
        $client->trial_ends_at = now()->addDays(3);
        $client->save();

        app(PruebaCicloService::class)->contratar($client);

        $this->assertSame(PruebaCicloService::CLIENTE_ACTIVO, $client->fresh()->estado);
        $encuesta = PruebaEncuesta::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(PruebaEncuesta::MOTIVO_CONTRATO, $encuesta->motivo);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::FIN_ENCUESTA, $espia->plantillas[0]['template_name']);
        Mail::assertSent(ComercioCityMail::class, 1);

        $this->post('/prueba/encuesta/' . $encuesta->token, ['estrellas' => '5', 'atencion_buena' => 'si'])->assertRedirect();

        $this->assertTrue($encuesta->fresh()->contrato_al_responder);
    }

    /** @test */
    public function el_negocio_la_contesta_una_sola_vez_desde_la_pagina_publica_y_a_lucas_le_llega_el_aviso(): void
    {
        $this->espiar_sender();
        $client = $this->negocio_por_vencer();
        Artisan::call('pruebas:vencer');
        $encuesta = PruebaEncuesta::where('client_id', $client->id)->firstOrFail();
        $ruta     = '/prueba/encuesta/' . $encuesta->token;

        /* La página: las preguntas, las cinco estrellas y nada de terceros. */
        $pagina = $this->get($ruta)->assertOk();
        $pagina->assertSee('¿Te resultó práctica la puesta en marcha?', false);
        $pagina->assertSee('¿La atención fue buena?', false);
        $pagina->assertSee('name="estrellas"', false);
        $pagina->assertSee('name="comentario"', false);
        $pagina->assertDontSee('http://', false);
        $pagina->assertDontSee('https://', false);
        $this->assertSame(5, substr_count($pagina->getContent(), 'type="radio" name="estrellas"'));

        /* Sin estrellas no se guarda, y lo que eligió vuelve marcado. */
        $sin_estrellas = $this->post($ruta, ['soporte_sirvio' => 'no', 'comentario' => 'Me faltó tiempo']);
        $sin_estrellas->assertStatus(422)->assertSee('Elegí de 1 a 5 estrellas', false)->assertSee('Me faltó tiempo', false);
        $this->assertNull($encuesta->fresh()->respondida_at);
        $this->post($ruta, ['estrellas' => '9'])->assertStatus(422);
        $this->assertNull($encuesta->fresh()->respondida_at);

        /* La respuesta. */
        $this->post($ruta, [
            'estrellas'               => '4',
            'implementacion_practica' => 'si',
            'soporte_sirvio'          => 'si',
            'probo_gestion'           => 'si',
            'probo_tienda'            => 'no',
            'atencion_buena'          => 'si',
            'comentario'              => '  Me costó encontrar la tienda.  ',
        ])->assertRedirect(PruebaEncuestaService::url($encuesta));

        $guardada = $encuesta->fresh();
        $this->assertNotNull($guardada->respondida_at);
        $this->assertSame(4, $guardada->estrellas);
        $this->assertTrue($guardada->implementacion_practica);
        $this->assertTrue($guardada->soporte_sirvio);
        $this->assertTrue($guardada->probo_gestion);
        $this->assertFalse($guardada->probo_tienda);
        $this->assertNull($guardada->probo_agentes, 'Sin contestar queda en null, no en «no».');
        $this->assertTrue($guardada->atencion_buena);
        $this->assertSame('Me costó encontrar la tienda.', $guardada->comentario);
        $this->assertFalse($guardada->contrato_al_responder);
        $this->assertNotNull($guardada->avisada_at, 'A Lucas se le avisó el resultado.');

        $servicio = app(PruebaEncuestaService::class);
        $this->assertStringContainsString('puso 4 de 5', $servicio->titulo_del_aviso($guardada, $client));
        $aviso = $servicio->texto_del_aviso($guardada);
        $this->assertStringContainsString('No contrató', $aviso);
        $this->assertStringContainsString('Probó la tienda: no', $aviso);
        $this->assertStringContainsString('Probó los agentes: sin contestar', $aviso);
        $this->assertStringContainsString('Me costó encontrar la tienda.', $aviso);

        /* Volver a abrir el enlace muestra el gracias, y un segundo envío no pisa nada. */
        $this->get($ruta)->assertOk()->assertSee('Gracias por contarnos', false)->assertDontSee('name="estrellas"', false);
        $this->post($ruta, ['estrellas' => '1', 'comentario' => 'otra cosa'])->assertOk()->assertSee('Gracias por contarnos', false);
        $this->assertSame(4, $encuesta->fresh()->estrellas);
        $this->assertSame('Me costó encontrar la tienda.', $encuesta->fresh()->comentario);

        /* El barrido no le vuelve a escribir a quien ya contestó. */
        $this->assertSame('ya_respondida', $servicio->enviar($client->fresh(), PruebaEncuesta::MOTIVO_VENCIDA)['estado']);
    }

    /** @test */
    public function un_token_que_no_existe_da_404(): void
    {
        $this->get('/prueba/encuesta/' . str_repeat('a', 48))->assertStatus(404);
        $this->post('/prueba/encuesta/' . str_repeat('a', 48), ['estrellas' => '5'])->assertStatus(404);
        $this->get('/prueba/encuesta/corto')->assertStatus(404);
    }

    /** @test */
    public function claude_la_lee_por_query_sin_el_token_y_la_pestana_la_muestra(): void
    {
        $this->espiar_sender();
        $client = $this->negocio_por_vencer();
        Artisan::call('pruebas:vencer');
        $encuesta = PruebaEncuesta::where('client_id', $client->id)->firstOrFail();
        $this->post('/prueba/encuesta/' . $encuesta->token, ['estrellas' => '5', 'comentario' => 'Todo bien'])->assertRedirect();

        $respuesta = $this->getJson('/api/claude/query?model=prueba_encuesta&client_id=' . $client->id, $this->headers_de_claude())->assertOk();
        $cuerpo    = (string) json_encode($respuesta->json());
        $this->assertStringNotContainsString($encuesta->token, $cuerpo);
        $this->assertStringContainsString('Todo bien', $cuerpo);

        $resumen = app(\App\Services\PruebaDelClienteService::class)->resumen($client->fresh(), null, false);
        $this->assertSame(5, $resumen['encuesta']['estrellas']);
        $this->assertArrayNotHasKey('token', $resumen['encuesta']);
    }
}
