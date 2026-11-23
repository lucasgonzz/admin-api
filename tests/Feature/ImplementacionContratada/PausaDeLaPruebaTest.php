<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\PruebaWhatsappEnvio;
use App\Services\Prueba\PruebaVpsService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaPlantillasService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\PruebaVpsServiceFake;

/**
 * CA18 y CA19 de la etapa E2 (misión embudo-juan-pipeline): la pausa de la prueba y su reanudación.
 *
 * La pausa es «vencimiento en el horizonte (un año) + marca `prueba_pausada_at`»: con eso `pruebas:vencer`, el asistente, el chat y
 * el ERP la tratan como vigente sin tocar sus consultas. Lo que sí cambia es lo que depende del día de la prueba (el empujón por
 * WhatsApp, el aviso del día 5, `datos()` y el contexto de Soporte).
 *
 *  - CA18 — La pausa protege: con la fecha original ya pasada, no vence, no se empuja, `datos()` dice `pausada` y el contexto también.
 *  - CA19 — Al entregar (etapa 5 completada, por el panel o por Claude) o a mano (`POST client/{id}/prueba/reanudar`) la prueba
 *    vuelve a correr 7 días desde ese momento, el lead a `prueba_activa` y la tarjeta a «Para cerrar» con la próxima acción a los
 *    5 días. Un cliente que no está pausado no se toca (409 en la ruta manual).
 *
 * Hoy están EN ROJO: ninguna de las piezas existe.
 */
class PausaDeLaPruebaTest extends BaseDeLaImplementacion
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        $this->app->instance(PruebaVpsService::class, new PruebaVpsServiceFake());
    }

    /**
     * Una prueba pausada (pagó la mitad el 23/11 a las 12:00).
     *
     * @return array{0: Client, 1: \App\Models\Lead}
     */
    private function prueba_pausada(): array
    {
        list($client, $lead) = $this->prueba_con_lead();
        $this->fijar_implementacion($client, 'mitad', ['gestion'])->assertStatus(200);

        return [$client->fresh(), $lead->fresh()];
    }

    /**
     * Pone la implementación del cliente en la etapa 5 («Entrega»), con la 3 y la 4 completadas.
     *
     * @param Client $client
     *
     * @return Implementation
     */
    private function en_la_etapa_de_entrega(Client $client): Implementation
    {
        $implementacion = Implementation::query()->where('client_id', $client->id)->firstOrFail();
        $implementacion->current_stage = 5;
        $implementacion->save();

        foreach ([3, 4] as $numero) {
            ImplementationStage::query()->where('implementation_id', $implementacion->id)->where('stage_number', $numero)->update(['status' => 'completed']);
        }
        ImplementationStage::query()->where('implementation_id', $implementacion->id)->where('stage_number', 5)->update(['status' => 'in_progress']);

        return $implementacion->fresh();
    }

    /**
     * Afirma todo lo que deja una reanudación el 10/12/2026 a las 09:00.
     *
     * @param Client           $client
     * @param \App\Models\Lead $lead
     *
     * @return void
     */
    private function afirmar_reanudada(Client $client, $lead): void
    {
        $client = $client->fresh();
        $this->assertSame('2026-12-17 09:00:00', $client->trial_ends_at->format('Y-m-d H:i:s'), 'Siete días desde la entrega.');
        $this->assertSame(7, (int) $client->trial_days);
        $this->assertSame('2026-12-10 09:00:00', $client->prueba_reanudada_at->format('Y-m-d H:i:s'));

        $c3 = $this->pedidos_c3();
        $this->assertStringStartsWith('2026-12-17T09:00:00', (string) $c3[count($c3) - 1]['vence_at'], 'C3 con la fecha nueva.');

        $this->assertSame('prueba_activa', (string) $lead->fresh()->status);
        $tarjeta = $this->tarjeta_del_lead($lead->fresh());
        $this->assertSame('en_seguimiento', $this->clave_de_la_columna($tarjeta), 'La tarjeta va a «Para cerrar».');
        $this->assertSame('2026-12-15 10:00:00', $this->proxima_accion_de($tarjeta), 'Próxima acción a los 5 días, a las 10:00.');
        $this->assertStringContainsString('Entregada', (string) $tarjeta->next_action_note);
    }

    /**
     * CA18. Con la fecha original ya pasada y la prueba pausada: no vence, no se empuja, `datos()` y el contexto lo dicen.
     *
     * @return void
     */
    public function test_la_pausa_protege(): void
    {
        $this->crear_config_whatsapp();
        $espia = $this->espiar_sender();
        $this->plantillas_de_la_prueba(true);
        $this->admin(true);
        list($client, $lead) = $this->prueba_pausada();

        PruebaWhatsappEnvio::create([
            'client_id' => $client->id, 'lead_id' => $lead->id, 'plantilla' => PruebaPlantillasService::BIENVENIDA, 'hito' => null,
            'estado' => PruebaWhatsappEnvio::ESTADO_ENVIADO, 'dia' => '2026-11-20', 'enviado_at' => Carbon::parse('2026-11-20 11:00', self::$ZONA_DEL_TABLERO),
        ]);

        /* Pasó la fecha original (27/11) con holgura. */
        $this->clavar_el_reloj('2026-12-01 12:00:00');

        $this->assertSame(0, Artisan::call('pruebas:vencer'));
        $this->assertSame('prueba', (string) $client->fresh()->estado, 'La prueba pausada no vence.');

        $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
        $this->assertCount(0, $espia->plantillas, 'Ningún hito ni aviso del día 5 para una prueba pausada.');
        $this->assertCount(0, $espia->textos);

        $datos = app(PruebaDelClienteService::class)->datos($client->fresh());
        $this->assertTrue($datos['pausada']);
        $this->assertNull($datos['dia_actual']);
        $this->assertNull($datos['dias_restantes']);
        $this->assertFalse($datos['vencida']);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);
        $this->assertTrue((bool) $this->contexto_de_soporte($client->fresh())->assertStatus(200)->json('ticket.prueba.pausada'));
    }

    /**
     * CA18. Una prueba que no está pausada tiene `pausada: false` en `datos()` (clave aditiva).
     *
     * @return void
     */
    public function test_una_prueba_normal_no_esta_pausada(): void
    {
        list($client) = $this->prueba_con_lead();

        $this->assertFalse(app(PruebaDelClienteService::class)->datos($client->fresh())['pausada']);
    }

    /**
     * CA19. Completar la etapa 5 por el panel (`advance-stage`) reanuda la prueba: 7 días desde ahora (10/12 a las 09:00).
     *
     * @return void
     */
    public function test_reanudar_al_entregar_por_el_panel(): void
    {
        list($client, $lead) = $this->prueba_pausada();
        $implementacion = $this->en_la_etapa_de_entrega($client);
        $this->clavar_el_reloj('2026-12-10 09:00:00');

        $this->postJson('/api/admin/implementation/' . $implementacion->id . '/advance-stage')->assertStatus(200);

        $this->afirmar_reanudada($client, $lead);
    }

    /**
     * CA19. Lo mismo por `POST claude/implementations/{id}/advance`.
     *
     * @return void
     */
    public function test_reanudar_al_entregar_por_claude(): void
    {
        list($client, $lead) = $this->prueba_pausada();
        $implementacion = $this->en_la_etapa_de_entrega($client);
        $this->clavar_el_reloj('2026-12-10 09:00:00');

        $this->postJson('/api/claude/implementations/' . $implementacion->id . '/advance', ['etapa_actual' => 5, 'dry_run' => false], $this->headers_de_claude())
            ->assertStatus(200);

        $this->afirmar_reanudada($client, $lead);
    }

    /**
     * CA19. `POST client/{id}/prueba/reanudar` hace lo mismo con `desde = ahora`.
     *
     * @return void
     */
    public function test_reanudar_a_mano(): void
    {
        list($client, $lead) = $this->prueba_pausada();
        $this->clavar_el_reloj('2026-12-10 09:00:00');

        $this->postJson('/api/admin/client/' . $client->id . '/prueba/reanudar')->assertStatus(200);

        $this->afirmar_reanudada($client, $lead);
    }

    /**
     * CA19. Reanudar un cliente que no está pausado es 409 y no toca nada; y la etapa 5 de un cliente no pausado tampoco.
     *
     * @return void
     */
    public function test_reanudar_sin_pausa_da_409_y_la_etapa_5_de_un_cliente_comun_no_toca_nada(): void
    {
        list($client) = $this->prueba_con_lead();

        $respuesta = $this->postJson('/api/admin/client/' . $client->id . '/prueba/reanudar');
        $respuesta->assertStatus(409);
        $this->assertSame('La prueba no está pausada.', $respuesta->json('message'));

        $implementacion = app(\App\Services\ImplementationStartService::class)->start($client, true);
        $implementacion->current_stage = 5;
        $implementacion->save();
        ImplementationStage::query()->where('implementation_id', $implementacion->id)->where('stage_number', 5)->update(['status' => 'in_progress']);
        $antes = $client->fresh()->trial_ends_at->format('Y-m-d H:i:s');

        $this->postJson('/api/admin/implementation/' . $implementacion->id . '/advance-stage')->assertStatus(200);

        $this->assertSame($antes, $client->fresh()->trial_ends_at->format('Y-m-d H:i:s'), 'La etapa 5 de un cliente sin pausa no toca el vencimiento.');
        $this->assertNull($client->fresh()->prueba_reanudada_at);
        $this->assertSame([], $this->pedidos_c3(), 'Y no avisa al ERP.');
    }
}
