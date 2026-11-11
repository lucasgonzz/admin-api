<?php

namespace Tests\Feature\PruebaGratuita;

use App\Console\Commands\PruebasEnsayoCommand;
use App\Mail\ComercioCityMail;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportMessage;
use App\Services\Prueba\PruebaCicloService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Misión cruzada prueba-e2e-campana-imperio (11/11/2026), ítems G y H:
 *
 *  G. `pruebas:ensayo`: simula el día N de la prueba de UN cliente marcado como ensayo y corre sus pasos del
 *     ciclo. Se niega con cualquier otro cliente, no toca a los demás, no avisa al closer y suelta el reloj.
 *  H. `GET claude/prueba/resumen`: la foto por negocio para el informe diario, y los modelos nuevos de
 *     `claude/query`.
 */
class EnsayoYResumenDeLaPruebaTest extends BaseDeLaPrueba
{
    /**
     * Un negocio que arrancó la prueba recién (día 1 de 7), con la bienvenida de ayer, correo y todo aprobado.
     *
     * @param string $phone
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(string $phone = '+5493411234567'): array
    {
        $client = $this->cliente_en_prueba(7, 7, $phone);
        $client->email = 'ensayo-' . $client->id . '@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'lead_id' => $lead->id, 'plantilla' => PruebaPlantillasService::BIENVENIDA,
            'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
            'enviado_at' => now()->subDay(),
        ]);

        return [$client, $lead];
    }

    private function preparar(): void
    {
        Mail::fake();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->admin(true);
        $this->plantillas_de_la_prueba(true);
        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);
    }

    /* ------------------------------------------------------------------------------------------
     | G: el reloj de ensayo
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function se_niega_con_un_cliente_que_no_esta_marcado_como_ensayo_y_con_uno_que_no_esta_en_prueba(): void
    {
        $espia = $this->espiar_sender();
        $this->preparar();
        [$client] = $this->negocio();

        $this->assertSame(1, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '2']));
        $this->assertStringContainsString('no está marcado como ensayo', Artisan::output());

        /* A un cliente que ya contrató no se lo puede ni marcar. */
        $activo = $this->crear_cliente('+5493415550077', false, 'otra-clave');
        $activo->estado = PruebaCicloService::CLIENTE_ACTIVO;
        $activo->save();
        $this->assertSame(1, Artisan::call('pruebas:ensayo', ['cliente' => $activo->id, '--marcar' => true]));
        $this->assertFalse((bool) $activo->fresh()->es_ensayo);

        /* Y si quedó marcado de antes y ya no está en prueba, tampoco corre. */
        $activo->es_ensayo = true;
        $activo->save();
        $this->assertSame(1, Artisan::call('pruebas:ensayo', ['cliente' => $activo->id, '--dia' => '2']));
        $this->assertStringContainsString('no está en prueba', Artisan::output());

        $this->assertSame(1, Artisan::call('pruebas:ensayo', ['cliente' => 999999999, '--dia' => '2']));

        $this->assertCount(0, $espia->plantillas);
        $this->assertCount(0, $espia->textos);
        Mail::assertNothingSent();
        $this->assertNull(Carbon::getTestNow(), 'El reloj no queda simulado.');
    }

    /** @test */
    public function simula_los_dias_de_un_solo_cliente_sin_tocar_a_los_demas_ni_avisar_al_closer(): void
    {
        $espia = $this->espiar_sender();
        $this->preparar();
        [$client] = $this->negocio();
        [$otro]   = $this->negocio('+5493415550088');
        $vence    = (string) $client->fresh()->trial_ends_at;

        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--marcar' => true]));
        $this->assertTrue((bool) $client->fresh()->es_ensayo);

        /* Día 2: el empujón por el hito que falta, con su correo. */
        $codigo = Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '2', '--correr' => 'empujar']);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('día 2 de 7', $salida);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[0]['template_name']);

        /* Día 5: el aviso de que termina ocupa el lugar del empujón. Sin aviso al closer. */
        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '5', '--correr' => 'empujar']), Artisan::output());
        $this->assertCount(2, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::DIA_5, $espia->plantillas[1]['template_name']);
        $this->assertSame(['Marcela', '2 días'], $espia->plantillas[1]['variables']);
        $this->assertCount(0, $espia->textos, 'En un ensayo no se le escribe al closer.');

        /* Día 6: vuelve el empujón (el envío del día 5 quedó registrado con la fecha simulada, no con la de hoy). */
        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '6', '--correr' => 'empujar']), Artisan::output());
        $this->assertCount(3, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[2]['template_name']);
        $dias = PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_WHATSAPP)
            ->where('plantilla', '!=', PruebaPlantillasService::BIENVENIDA)->orderBy('id')->pluck('dia')->all();
        $this->assertCount(3, array_unique($dias), 'Tres envíos en tres días simulados distintos.');

        /* Día 7 con `vencer`: todavía no vence. */
        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '7', '--correr' => 'vencer']));
        $this->assertStringContainsString('todavía no vence', Artisan::output());
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, $client->fresh()->estado);

        /* Día 8: vence y sale la encuesta por los dos canales. */
        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '8', '--correr' => 'vencer']), Artisan::output());
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, $client->fresh()->estado);
        $this->assertSame(PruebaPlantillasService::FIN_ENCUESTA, $espia->plantillas[3]['template_name']);
        $encuesta = PruebaEncuesta::where('client_id', $client->id)->sole();
        $this->assertSame(PruebaEncuesta::MOTIVO_VENCIDA, $encuesta->motivo);
        $this->assertNotNull($encuesta->enviada_at);
        $this->assertCount(0, $espia->textos, 'Tampoco al vencer.');

        /* Las fechas de la base no se movieron, el reloj quedó suelto y al otro negocio no se le mandó nada. */
        $this->assertSame($vence, (string) $client->fresh()->trial_ends_at);
        $this->assertNull(Carbon::getTestNow());
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, $otro->fresh()->estado);
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $otro->id)->count(), 'Solo la bienvenida que ya tenía.');
        $this->assertSame(0, PruebaEncuesta::where('client_id', $otro->id)->count());
        foreach ($espia->plantillas as $envio) {
            $this->assertSame((string) $client->phone, $envio['to']);
        }
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($client) {
            return $mail->hasTo($client->email);
        });
        Mail::assertNotSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($otro) {
            return $mail->hasTo($otro->email);
        });
    }

    /** @test */
    public function con_closer_si_avisa_y_el_momento_simulado_cae_en_el_dia_pedido(): void
    {
        $espia = $this->espiar_sender();
        $this->preparar();
        [$client] = $this->negocio();
        Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--marcar' => true]);

        $this->assertSame(0, Artisan::call('pruebas:ensayo', ['cliente' => $client->id, '--dia' => '5', '--correr' => 'empujar', '--con-closer' => true]));
        $this->assertCount(1, $espia->textos);
        $this->assertSame('+5493415550000', $espia->textos[0]['to']);

        /* El día N cuenta de a 24 horas desde el inicio: la hora pedida nunca cae en el día anterior. */
        $inicio = Carbon::parse('2026-11-11 21:40:00', 'UTC');
        foreach ([1, 2, 5, 7, 8] as $dia) {
            foreach (['00:05', '10:00', '23:50'] as $hora) {
                $momento = PruebasEnsayoCommand::momento_del_dia($inicio, $dia, $hora);
                $this->assertSame($dia, (int) floor($inicio->diffInSeconds($momento, false) / 86400) + 1, 'día ' . $dia . ' ' . $hora);
                $this->assertSame($hora, $momento->copy()->setTimezone(PruebaWhatsappService::ZONA)->format('H:i'));
            }
        }
    }

    /* ------------------------------------------------------------------------------------------
     | H: la lectura para el informe diario
     |----------------------------------------------------------------------------------------- */

    /** @test */
    public function el_resumen_trae_dia_hitos_envios_ultimo_mensaje_y_encuesta_por_negocio(): void
    {
        $this->espiar_sender();
        $this->preparar();
        [$client, $lead] = $this->negocio();
        $client->trial_ends_at = now()->addDays(4)->addMinutes(30);
        $client->save();
        $this->evento($lead, 'prueba.ingreso')->assertStatus(200);
        $this->evento($lead, 'prueba.venta_cc')->assertStatus(200);
        $ticket = $this->ticket_del_cliente($client, true);
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'kind' => 'text',
            'body' => 'No entiendo dónde cargo los precios', 'delivered_at' => now(), 'whatsapp_message_id' => 'wamid.ultimo']);

        /* Uno que venció hace dos días y contestó la encuesta, y uno que venció hace un mes (queda afuera). */
        [$vencido] = $this->negocio('+5493415550101');
        $vencido->estado = PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $vencido->trial_ends_at = now()->subDays(2);
        $vencido->save();
        PruebaEncuesta::create(['client_id' => $vencido->id, 'token' => str_repeat('k', 48), 'motivo' => 'vencida',
            'enviada_at' => now()->subDays(2), 'respondida_at' => now()->subDay(), 'estrellas' => 2, 'soporte_sirvio' => false, 'comentario' => 'Muy lento']);
        [$viejo] = $this->negocio('+5493415550102');
        $viejo->estado = PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;
        $viejo->trial_ends_at = now()->subDays(30);
        $viejo->save();

        $this->getJson('/api/claude/prueba/resumen')->assertStatus(401);

        $respuesta = $this->getJson('/api/claude/prueba/resumen', $this->headers_de_claude())->assertOk();
        $pruebas   = collect($respuesta->json('pruebas'))->keyBy('client_id');

        $this->assertTrue($pruebas->has($client->id));
        $this->assertTrue($pruebas->has($vencido->id));
        $this->assertFalse($pruebas->has($viejo->id), 'Vencida hace más de vencidas_dias.');
        $this->assertSame('admin', $respuesta->json('hitos_fuente'));
        $this->assertGreaterThanOrEqual(1, $respuesta->json('totales.en_curso'));
        $this->assertGreaterThanOrEqual(1, $respuesta->json('totales.encuestas_respondidas'));

        $fila = $pruebas[$client->id];
        $this->assertSame('prueba', $fila['estado']);
        $this->assertFalse($fila['es_ensayo']);
        $this->assertSame(3, $fila['dia_actual']);
        $this->assertSame(7, $fila['dias']);
        $this->assertFalse($fila['vencida']);
        $this->assertSame('Marcela Gómez', $fila['contacto']);
        $this->assertNotNull($fila['hitos']['ingreso']);
        $this->assertNotNull($fila['hitos']['venta_cc']);
        $this->assertNull($fila['hitos']['venta']);
        $this->assertSame(2, $fila['hitos_hechos']);
        $this->assertSame(12, $fila['hitos_total']);
        $this->assertSame('importo', $fila['proximo_hito']);
        $this->assertSame('whatsapp', $fila['envios'][0]['canal']);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $fila['envios'][0]['plantilla']);
        $this->assertNotNull($fila['envios'][0]['enviado_at']);
        $this->assertSame('negocio', $fila['ultimo_mensaje']['de']);
        $this->assertSame('whatsapp', $fila['ultimo_mensaje']['canal']);
        $this->assertSame('No entiendo dónde cargo los precios', $fila['ultimo_mensaje']['texto']);
        $this->assertNull($fila['encuesta']);

        $del_vencido = $pruebas[$vencido->id];
        $this->assertSame('prueba_vencida', $del_vencido['estado']);
        $this->assertTrue($del_vencido['vencida']);
        $this->assertSame(2, $del_vencido['encuesta']['estrellas']);
        $this->assertFalse($del_vencido['encuesta']['soporte_sirvio']);
        $this->assertSame('Muy lento', $del_vencido['encuesta']['comentario']);

        $cuerpo = (string) json_encode($respuesta->json());
        $this->assertStringNotContainsString(str_repeat('k', 48), $cuerpo, 'El token de la encuesta no viaja.');
        $this->assertStringNotContainsString('clave-del-cliente', $cuerpo);
        $this->assertStringNotContainsString((string) $client->phone, $cuerpo);

        /* Con el lapso estirado entra el viejo; con client_id, solo ese. */
        $amplio = $this->getJson('/api/claude/prueba/resumen?vencidas_dias=45', $this->headers_de_claude())->assertOk();
        $this->assertTrue(collect($amplio->json('pruebas'))->pluck('client_id')->contains($viejo->id));
        $uno = $this->getJson('/api/claude/prueba/resumen?client_id=' . $client->id, $this->headers_de_claude())->assertOk();
        $this->assertSame([$client->id], collect($uno->json('pruebas'))->pluck('client_id')->all());
        $this->getJson('/api/claude/prueba/resumen?vencidas_dias=900', $this->headers_de_claude())->assertStatus(422);
    }

    /** @test */
    public function el_que_contrato_entra_por_su_encuesta_y_los_modelos_nuevos_se_leen_por_query(): void
    {
        $this->espiar_sender();
        $this->preparar();
        [$client] = $this->negocio();

        app(PruebaCicloService::class)->contratar($client);

        $respuesta = $this->getJson('/api/claude/prueba/resumen?client_id=' . $client->id, $this->headers_de_claude())->assertOk();
        $fila      = $respuesta->json('pruebas.0');
        $this->assertSame('activo', $fila['estado']);
        $this->assertNull($fila['dia_actual']);
        $this->assertNull($fila['proximo_hito']);
        $this->assertSame('contrato', $fila['encuesta']['motivo']);
        $this->assertContains('correo_fin_encuesta', array_column($fila['envios'], 'plantilla'));
        $this->assertContains('correo', array_column($fila['envios'], 'canal'));

        $envios = $this->getJson('/api/claude/query?model=prueba_whatsapp_envio&client_id=' . $client->id . '&canal=correo', $this->headers_de_claude())->assertOk();
        $this->assertStringContainsString('correo_fin_encuesta', (string) json_encode($envios->json()));

        \App\Models\TrialInstance::create([
            'nombre' => 'prueba-q' . random_int(100, 999), 'estado' => \App\Models\TrialInstance::ESTADO_CLIENTE,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'user_id' => (int) $client->user_id, 'alias_slug' => 'ferreteria-q',
            'password_cifrada' => 'no-tiene-que-viajar',
        ]);
        $instancias = $this->getJson('/api/claude/query?model=trial_instance&client_id=' . $client->id, $this->headers_de_claude())->assertOk();
        $cuerpo     = (string) json_encode($instancias->json());
        $this->assertStringContainsString('ferreteria-q', $cuerpo);
        $this->assertStringNotContainsString('no-tiene-que-viajar', $cuerpo);
        $this->assertStringNotContainsString('password_cifrada', $cuerpo);
    }
}
