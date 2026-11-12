<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Models\PruebaWhatsappEnvio;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaAsistenteService;
use App\Services\Prueba\PruebaCorreoService;
use App\Services\Prueba\PruebaVideoCatalogoService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Misión cruzada prueba-e2e-campana-imperio (11/11/2026), ítems C y D:
 *
 *  C. Los hitos nuevos `venta_cc` y `articulo_actualizado`: el canal los acepta, entran al orden de los pasos con
 *     su plantilla, y un sistema que todavía no los informa no deja trabado el empujón.
 *  D. Con cada empujón sale también un correo con el mismo paso y el video del catálogo que le corresponde, uno
 *     por paso; y el correo de bienvenida lleva el video 04.
 */
class HitosNuevosYCorreoPorPasoTest extends BaseDeLaPrueba
{
    protected function setUp(): void
    {
        parent::setUp();

        /* Estas pruebas miden las novedades prendidas para todos los negocios; el alcance `ensayo` (el default)
           tiene su prueba aparte en AlcanceDeLasNovedadesTest. */
        \App\Models\AdminSetting::set(\App\Services\Prueba\PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
    }

    /**
     * Un negocio con la bienvenida de ayer, correo, todas las plantillas aprobadas y la instancia con esos hitos.
     *
     * @param array<string>             $hechos
     * @param array<string, mixed>|null $estado Para pisar lo que contesta la instancia.
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(array $hechos, ?array $estado = null): array
    {
        Mail::fake();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba(true);

        $client = $this->cliente_en_prueba();
        $client->email = 'duena@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'lead_id' => $lead->id, 'plantilla' => PruebaPlantillasService::BIENVENIDA,
            'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
            'enviado_at' => now()->subDay(),
        ]);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($estado !== null ? $estado : $this->estado_de_la_instancia($hechos), 200)]);

        return [$client, $lead];
    }

    /** Deja configurados los videos aprobados del catálogo de la prueba. */
    private function videos_configurados(): void
    {
        foreach (PruebaVideoCatalogoService::URLS_APROBADAS as $codigo => $url) {
            AdminSetting::set(PruebaVideoCatalogoService::key($codigo), $url);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | C: los hitos nuevos
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function el_canal_acepta_los_hitos_nuevos_y_un_tipo_desconocido_sigue_sin_dar_error(): void
    {
        $client = $this->cliente_en_prueba();
        $lead   = $this->lead_en_prueba($client);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response([], 404)]);

        $this->evento($lead, 'prueba.venta_cc')->assertStatus(200)->assertJson(['guardados' => 1, 'hitos' => 1]);
        $this->evento($lead, 'prueba.articulo_actualizado')->assertStatus(200)->assertJson(['guardados' => 1, 'hitos' => 1]);
        /* Repetidos (otro uuid): el evento se guarda y el hito no se duplica. */
        $this->evento($lead, 'prueba.venta_cc')->assertStatus(200)->assertJson(['hitos' => 0]);
        /* Un emisor más nuevo que este admin no puede romper el canal. */
        $this->evento($lead, 'prueba.algo_que_todavia_no_existe')->assertStatus(200)->assertJson(['guardados' => 1, 'hitos' => 1]);

        $venta_cc = LeadDemoHito::where('lead_id', $lead->id)->where('evento_esperado', 'prueba.venta_cc')->sole();
        $this->assertSame('venta_cc', $venta_cc->clip_id);
        $this->assertSame('Vendió en cuenta corriente', $venta_cc->titulo);

        $actualizado = LeadDemoHito::where('lead_id', $lead->id)->where('evento_esperado', 'prueba.articulo_actualizado')->sole();
        $this->assertSame('articulo_a', $actualizado->clip_id, 'El mismo clip_id que le daba el canal cuando no lo conocía.');
        $this->assertSame('Actualizó un artículo', $actualizado->titulo);

        /* Y cuentan como hechos en el seguimiento, por los eventos recibidos. */
        $hitos = app(PruebaDelClienteService::class)->hitos_locales($lead);
        $this->assertNotNull($hitos['venta_cc']);
        $this->assertNotNull($hitos['articulo_actualizado']);
        $this->assertNull($hitos['venta']);
    }

    /** @test */
    public function el_orden_de_los_pasos_es_el_del_programa_y_cada_paso_tiene_su_plantilla_y_su_correo(): void
    {
        $this->assertSame([
            'ingreso', 'importo', 'articulo_creado', 'articulo_actualizado', 'venta', 'cliente_creado',
            'venta_cc', 'pago_cc', 'compra', 'gasto', 'tienda_pedido', 'agente',
        ], array_keys(PruebaDelClienteService::HITOS));

        foreach (array_keys(PruebaDelClienteService::HITOS) as $hito) {
            $this->assertArrayHasKey(PruebaPlantillasService::plantilla_del_paso($hito), PruebaPlantillasService::PLANTILLAS, $hito);
            $this->assertArrayHasKey($hito, PruebaCorreoService::PASOS, $hito);
        }
    }

    /** @test */
    public function el_empujon_ofrece_actualizar_un_articulo_despues_de_crearlo_y_la_venta_en_cuenta_corriente_despues_del_cliente(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(['ingreso', 'importo', 'articulo_creado']);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame('cc_prueba_paso_articulo_actualizado', $espia->plantillas[0]['template_name']);

        PruebaWhatsappEnvio::where('client_id', $client->id)->update(['dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString()]);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response(
            $this->estado_de_la_instancia(['ingreso', 'importo', 'articulo_creado', 'articulo_actualizado', 'venta', 'cliente_creado']), 200
        )]);

        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame('cc_prueba_paso_venta_cc', $espia->plantillas[1]['template_name']);
        $this->assertSame('venta_cc', PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'cc_prueba_paso_venta_cc')->value('hito'));
    }

    /** @test */
    public function un_sistema_que_todavia_no_informa_los_hitos_nuevos_no_deja_trabado_el_empujon(): void
    {
        $espia = $this->espiar_sender();
        /* Un sistema anterior: su respuesta no trae las claves `articulo_actualizado` ni `venta_cc`. */
        $viejo = $this->estado_de_la_instancia(['ingreso', 'importo', 'articulo_creado']);
        unset($viejo['hitos']['articulo_actualizado'], $viejo['hitos']['venta_cc']);
        [$client, $lead] = $this->negocio([], $viejo);

        $resumen = app(PruebaDelClienteService::class)->resumen($client, $lead, true);
        $this->assertSame('venta', $resumen['proximo_hito']);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);
        $this->assertSame('cc_prueba_paso_venta', $espia->plantillas[0]['template_name'], 'Saltea el paso que ese sistema nunca va a informar.');
    }

    /* ------------------------------------------------------------------------------------------
     | D: el correo por paso
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function con_el_empujon_sale_un_correo_del_mismo_paso_con_su_video_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        $this->videos_configurados();
        [$client, $lead] = $this->negocio(['ingreso']);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[0]['template_name']);

        Mail::assertSent(ComercioCityMail::class, 1);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($client) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->hasTo($client->email)
                && $mail->payload->subject === 'Te cargamos tu catálogo sin costo'
                && strpos($html, 'Hola Marcela, soy Martín') !== false
                && strpos($html, PruebaVideoCatalogoService::URLS_APROBADAS['05']) !== false
                && strpos($html, 'Importá tu catálogo') !== false;
        });

        $registro = PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)->sole();
        $this->assertSame('correo_paso_importo', $registro->plantilla);
        $this->assertSame('importo', $registro->hito);
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, $registro->estado);

        /* Al día siguiente el paso sigue faltando: el WhatsApp se repite, el correo no. */
        PruebaWhatsappEnvio::where('client_id', $client->id)->update(['dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString()]);
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertCount(2, $espia->plantillas);
        Mail::assertSent(ComercioCityMail::class, 1);
    }

    /** @test */
    public function sin_la_plantilla_del_paso_aprobada_el_paso_llega_igual_por_correo_y_un_paso_sin_video_no_inventa_uno(): void
    {
        $espia = $this->espiar_sender();
        $this->videos_configurados();
        [$client, $lead] = $this->negocio(['ingreso', 'importo', 'articulo_creado', 'articulo_actualizado', 'venta', 'cliente_creado', 'venta_cc', 'pago_cc', 'compra']);
        FollowupTemplate::where('template_name', 'cc_prueba_paso_gasto')->update(['activa' => false, 'meta_status' => 'PENDING']);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('plantilla_no_disponible', $resultado['estado']);
        $this->assertCount(0, $espia->plantillas);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->payload->subject === 'Cargá un gasto y mirá la caja del día'
                && $mail->payload->links === []
                && strpos($html, 'videos.comerciocity.store') === false;
        });
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'correo_paso_gasto')->count());
    }

    /** @test */
    public function sin_correo_en_la_ficha_el_empujon_sale_igual_y_no_queda_ningun_registro_de_correo(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(['ingreso']);
        $client->email = null;
        $client->save();

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);
        $this->assertCount(1, $espia->plantillas);
        Mail::assertNothingSent();
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)->count());
    }

    /** @test */
    public function el_correo_de_bienvenida_lleva_el_video_de_bienvenida_y_recorrido(): void
    {
        $this->espiar_sender();
        Mail::fake();
        $this->videos_configurados();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente(['display_phone' => '+1 201-586-6682']);
        $this->admin();
        $this->plantillas_de_la_prueba();

        $client = $this->cliente_en_prueba();
        $client->email = 'alta@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);
        $lead->demo_setup_status = 'exitoso';
        $lead->save();
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'lead_id' => (int) $lead->id, 'user_id' => (int) $client->user_id,
            'alias_slug' => 'ferreteria-test',
        ]);
        $this->fakear_http(['*/' . PruebaAsistenteService::RUTA_DISPONIBILIDAD => Http::response(['disponible' => true, 'user_id' => (int) $client->user_id], 200)]);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);

        Mail::assertSent(ComercioCityMail::class, 1);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return strpos($html, PruebaVideoCatalogoService::URLS_APROBADAS['04']) !== false
                && strpos($html, 'Bienvenida y recorrido del sistema') !== false;
        });
    }
}
