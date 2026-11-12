<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaSettings;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * `prueba_novedades_alcance` (misión cruzada prueba-e2e-campana-imperio, 11/11/2026): mientras valga `ensayo` (el
 * default), todo lo nuevo que le llega a un negocio en prueba corre SOLO para los clientes marcados como ensayo; un
 * negocio real recibe exactamente lo mismo que antes de la misión. Con `todos`, corre para todos.
 *
 * Lo nuevo: la encuesta (al vencer y al contratar), el aviso de que la prueba termina, el correo por paso, la
 * bienvenida v2, los pasos de los hitos nuevos y la copia de la bienvenida en el sistema.
 */
class AlcanceDeLasNovedadesTest extends BaseDeLaPrueba
{
    /**
     * Un negocio con correo, todas las plantillas aprobadas (las de siempre, las nuevas y las v2) y la bienvenida
     * de ayer salvo que se pida sin ella.
     *
     * @param int  $dias_restantes
     * @param bool $con_bienvenida
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(int $dias_restantes = 5, bool $con_bienvenida = true): array
    {
        Mail::fake();
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

        $client = $this->cliente_en_prueba($dias_restantes);
        $client->email = 'duena@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);

        if ($con_bienvenida) {
            PruebaWhatsappEnvio::create([
                'client_id' => $client->id, 'lead_id' => $lead->id, 'plantilla' => PruebaPlantillasService::BIENVENIDA,
                'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
                'enviado_at' => now()->subDay(),
            ]);
        }

        return [$client, $lead];
    }

    /**
     * @param array<string> $hechos
     */
    private function la_instancia_contesta(array $hechos): void
    {
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia($hechos), 200)]);
    }

    /** @test */
    public function por_defecto_el_alcance_es_ensayo(): void
    {
        $client = $this->cliente_en_prueba();

        $this->assertSame('ensayo', PruebaSettings::novedades_alcance());
        $this->assertFalse(PruebaSettings::novedades_para($client));

        $client->es_ensayo = true;
        $client->save();
        $this->assertTrue(PruebaSettings::novedades_para($client->fresh()));

        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'cualquier-cosa');
        $this->assertSame('ensayo', PruebaSettings::novedades_alcance(), 'Un valor que no es `todos` no abre nada.');
    }

    /** @test */
    public function con_el_alcance_en_ensayo_un_negocio_real_recibe_exactamente_lo_de_antes(): void
    {
        $espia = $this->espiar_sender();
        /* Va por el día 5 (vence en 2 días y media hora), con la v2 y todo lo nuevo aprobado en Meta. */
        [$client, $lead] = $this->negocio(2);
        $this->la_instancia_contesta(['ingreso', 'importo', 'articulo_creado']);

        /* El empujón del día 5 es el del hito que falta, en el orden de siempre: después de crear el artículo viene
           la venta (no «actualizar un artículo»), sin aviso de que termina y sin correo. */
        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);
        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_venta', $espia->plantillas[0]['template_name']);
        Mail::assertNothingSent();
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', '!=', PruebaWhatsappEnvio::CANAL_WHATSAPP)->count());

        /* La ficha muestra los diez hitos de siempre. */
        $resumen = app(PruebaDelClienteService::class)->resumen($client, $lead, true);
        $this->assertCount(10, $resumen['hitos_lista']);
        $this->assertSame('venta', $resumen['proximo_hito']);
        $this->assertArrayNotHasKey('venta_cc', $resumen['hitos']);
        $this->assertArrayNotHasKey('articulo_actualizado', $resumen['hitos']);

        /* Al vencer no recibe la encuesta, ni por el barrido ni al contratar. */
        $client->trial_ends_at = now()->subMinutes(5);
        $client->save();
        $this->assertSame(0, Artisan::call('pruebas:vencer'));
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, $client->fresh()->estado);
        app(PruebaCicloService::class)->contratar($client->fresh());

        $this->assertSame(0, PruebaEncuesta::where('client_id', $client->id)->count());
        $this->assertCount(1, $espia->plantillas, 'Ningún WhatsApp nuevo.');
        Mail::assertNothingSent();
    }

    /** @test */
    public function con_el_alcance_en_ensayo_la_bienvenida_de_un_negocio_real_es_la_de_siempre_y_no_deja_copia_en_el_sistema(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(6, false);
        $this->la_instancia_contesta([]);

        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $espia->plantillas[0]['template_name'], 'La v2 está aprobada, pero no es para él.');

        /* Otro negocio real, con la bienvenida en cola: no se le abre el ticket ni se le deja la copia. */
        FollowupTemplate::where('template_name', 'like', 'cc_prueba_bienvenida%')->update(['activa' => false, 'meta_status' => 'PENDING']);
        $otro = $this->cliente_en_prueba(6, 7, '+5493415550201');
        $lead_del_otro = $this->lead_en_prueba($otro);
        $this->assertSame('en_cola', app(PruebaWhatsappService::class)->enviar_bienvenida($lead_del_otro, $otro)['estado']);
        $this->assertSame(0, SupportTicket::where('client_id', $otro->id)->count());
        $this->assertSame(0, SupportMessage::whereHas('ticket', function ($ticket) use ($otro) {
            $ticket->where('client_id', $otro->id);
        })->count());
    }

    /** @test */
    public function con_el_alcance_en_ensayo_el_cliente_de_ensayo_si_recibe_las_novedades(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(2);
        $client->es_ensayo = true;
        $client->save();
        $this->la_instancia_contesta(['ingreso', 'importo', 'articulo_creado']);

        $servicio = app(PruebaWhatsappService::class);

        /* Día 5: el aviso de que termina, con su correo. */
        /* Desde el 12/11/2026 el ensayo no recibe WhatsApp: lo que sale queda en el sistema con canal sistema. */
        $del_sistema = function () use ($client) {
            return PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_SISTEMA)
                ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->orderBy('id')->pluck('plantilla')->all();
        };
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame([PruebaPlantillasService::DIA_5], $del_sistema());
        Mail::assertSent(ComercioCityMail::class, 1);

        /* Al día siguiente: el paso nuevo, con su correo. */
        PruebaWhatsappEnvio::where('client_id', $client->id)->update(['dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString()]);
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame([PruebaPlantillasService::DIA_5, 'cc_prueba_paso_articulo_actualizado'], $del_sistema());
        $this->assertSame([], $espia->plantillas);
        Mail::assertSent(ComercioCityMail::class, 2);
        $this->assertCount(12, app(PruebaDelClienteService::class)->resumen($client, $lead, true)['hitos_lista']);

        /* Y la encuesta, cuando se la manda el reloj de ensayo (el barrido real saltea a las cuentas de ensayo). */
        $this->assertSame('enviada', app(\App\Services\Prueba\PruebaEncuestaService::class)->enviar($client, PruebaEncuesta::MOTIVO_VENCIDA)['estado']);
        $this->assertSame(1, PruebaEncuesta::where('client_id', $client->id)->count());
        $this->assertSame([PruebaPlantillasService::DIA_5, 'cc_prueba_paso_articulo_actualizado', PruebaPlantillasService::FIN_ENCUESTA], $del_sistema());
        $this->assertSame([], $espia->plantillas);
    }

    /** @test */
    public function con_el_alcance_en_todos_las_novedades_le_llegan_a_cualquier_negocio(): void
    {
        $espia = $this->espiar_sender();
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        [$client, $lead] = $this->negocio(6, false);
        $this->la_instancia_contesta(['ingreso', 'importo', 'articulo_creado']);
        \App\Models\TrialInstance::create([
            'nombre' => 'prueba-' . \Illuminate\Support\Str::random(8), 'estado' => \App\Models\TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'user_id' => (int) $client->user_id, 'alias_slug' => 'ferreteria-todos',
        ]);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertTrue(PruebaSettings::novedades_para($client));

        $this->assertSame('enviado', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, $espia->plantillas[0]['template_name']);

        PruebaWhatsappEnvio::where('client_id', $client->id)->update(['dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString()]);
        $this->assertSame('enviado', $servicio->empujar($client, $lead)['estado']);
        $this->assertSame('cc_prueba_paso_articulo_actualizado', $espia->plantillas[1]['template_name']);
        Mail::assertSent(ComercioCityMail::class, 1);

        app(PruebaCicloService::class)->contratar($client);
        $this->assertSame(PruebaEncuesta::MOTIVO_CONTRATO, PruebaEncuesta::where('client_id', $client->id)->value('motivo'));
    }

    /** @test */
    public function lo_interno_no_depende_del_alcance(): void
    {
        [$client, $lead] = $this->negocio();
        $this->la_instancia_contesta([]);

        /* El canal acepta el hito nuevo de un negocio real y la lectura para el informe lo muestra. */
        $this->evento($lead, 'prueba.venta_cc')->assertStatus(200)->assertJson(['guardados' => 1, 'hitos' => 1]);

        $respuesta = $this->getJson('/api/claude/prueba/resumen?client_id=' . $client->id, $this->headers_de_claude())->assertOk();
        $this->assertSame('ensayo', $respuesta->json('novedades_alcance'));
        $this->assertFalse($respuesta->json('pruebas.0.recibe_novedades'));
        $this->assertSame(12, $respuesta->json('pruebas.0.hitos_total'));
        $this->assertNotNull($respuesta->json('pruebas.0.hitos.venta_cc'));
    }
}
