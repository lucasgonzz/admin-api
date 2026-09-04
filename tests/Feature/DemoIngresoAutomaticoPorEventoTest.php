<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoEventosController;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Services\DemoHitosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El evento `demo.ingreso` ya no solo cierra el hito del roadmap: también avanza el pipeline
 * comercial del lead a `demo_en_curso` sin depender de que escriba nada por chat (misión
 * demo-v2-estados-automaticos, 4/9/2026). Ver DemoEventosController::avanzar_pipeline_por_ingreso_real().
 *
 * Estos tests miden el avance de pipeline. El comportamiento del canal en sí (auth, idempotencia,
 * el hito) ya está cubierto en DemoEventosIngestaTest y no se repite acá.
 */
class DemoIngresoAutomaticoPorEventoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead de la dinámica nueva, con su token de eventos y un hito de ingreso pendiente.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Lead de prueba';
        $lead->status             = $status;
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 1, 'tipo' => LeadDemoHito::TIPO_INGRESO,
            'seccion' => null, 'clip_id' => null, 'titulo' => 'Entrar a la demo',
            'evento_esperado' => DemoHitosService::EVENTO_INGRESO,
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);

        return $lead;
    }

    /**
     * Postea el evento `demo.ingreso` con el token del lead.
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_ingreso(Lead $lead)
    {
        return $this->postJson('/api/demo-eventos', [
            'eventos' => [[
                'uuid'        => (string) Str::uuid(),
                'nombre'      => DemoHitosService::EVENTO_INGRESO,
                'clip_id'     => null,
                'ocurrido_at' => now()->toIso8601String(),
                'datos'       => [],
            ]],
        ], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token]);
    }

    /**
     * El hito de ingreso del lead.
     *
     * @param Lead $lead
     *
     * @return LeadDemoHito
     */
    private function hito_de_ingreso(Lead $lead): LeadDemoHito
    {
        return LeadDemoHito::where('lead_id', $lead->id)->where('tipo', LeadDemoHito::TIPO_INGRESO)->first();
    }

    /**
     * Desde demo_agendada, el evento avanza el lead a demo_en_curso y marca demo_ingreso_confirmado.
     */
    public function test_desde_demo_agendada_avanza_a_demo_en_curso(): void
    {
        $lead = $this->crear_lead('demo_agendada');

        $this->postear_ingreso($lead)->assertStatus(200);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status);
        $this->assertTrue((bool) $lead->demo_ingreso_confirmado);
        $this->assertNotNull($lead->demo_ingreso_confirmado_at);
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $this->hito_de_ingreso($lead)->estado);
    }

    /**
     * Desde demo_pendiente_de_ingreso (el lead se hizo el timeout y después entró tarde) también
     * avanza a demo_en_curso: el plan lo pide explícitamente como segundo estado de origen válido.
     */
    public function test_desde_demo_pendiente_de_ingreso_tambien_avanza_a_demo_en_curso(): void
    {
        $lead = $this->crear_lead('demo_pendiente_de_ingreso');

        $this->postear_ingreso($lead)->assertStatus(200);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status);
        $this->assertTrue((bool) $lead->demo_ingreso_confirmado);
    }

    /**
     * 🔴 El evento NO distingue primera vez de reentrada: empresa-api emite uno nuevo cada vez que
     * el lead abre el Magic Link, incluida una demo ya terminada. Un lead más adelantado en el
     * ciclo (demo_en_curso, demo_pendiente_de_terminar, demo_realizada, closer_activo) NUNCA
     * retrocede por este evento.
     *
     * @dataProvider estados_mas_adelantados
     */
    public function test_un_lead_mas_adelantado_no_retrocede(string $estado_actual): void
    {
        $lead = $this->crear_lead($estado_actual);

        $this->postear_ingreso($lead)->assertStatus(200);

        $lead->refresh();
        $this->assertSame($estado_actual, $lead->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function estados_mas_adelantados(): array
    {
        return [
            'demo_en_curso'              => ['demo_en_curso'],
            'demo_pendiente_de_terminar' => ['demo_pendiente_de_terminar'],
            'demo_realizada'             => ['demo_realizada'],
            'closer_activo'              => ['closer_activo'],
        ];
    }

    /**
     * Defensa por dinámica: un lead que no usa la dinámica nueva no puede llegar acá por HTTP (el
     * middleware del canal ya lo rechaza con 401 -- DemoEventosKey), pero el propio método tiene su
     * guardia redundante. Se prueba llamando al método directo, igual que documenta su PHPDoc.
     */
    public function test_defensa_por_dinamica_un_lead_actual_no_se_toca_aunque_se_llame_directo(): void
    {
        $lead                   = $this->crear_lead('demo_agendada');
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        $controller = new class extends DemoEventosController {
            public function avanzar_publico(Lead $lead): void
            {
                $this->avanzar_pipeline_por_ingreso_real($lead);
            }
        };
        $controller->avanzar_publico($lead);

        $lead->refresh();
        $this->assertSame('demo_agendada', $lead->status);
        $this->assertFalse((bool) $lead->demo_ingreso_confirmado);
    }

    /**
     * Segunda llegada del evento sobre un lead ya confirmado (reentrada real, o el mismo evento
     * reenviado con otro uuid): no repite el timestamp de confirmación. Es la única señal
     * verificable sin salir a Google Calendar / WhatsApp real (ninguno de los dos tiene fixture de
     * closer/admin suscripto en este test, así que esas dos llamadas son no-ops estructurales por
     * diseño -- ver CloserGoogleCalendarEventService::mark_hold_as_demo_en_curso() y
     * DemoCicloAdminNotificationService::get_subscribed_admins() -- y el `$ya_confirmado` que
     * gatea al método bajo prueba es el mismo booleano que gatea esas dos llamadas).
     */
    public function test_segunda_llegada_no_repite_el_timestamp_de_confirmacion(): void
    {
        $lead = $this->crear_lead('demo_agendada');

        $this->postear_ingreso($lead)->assertStatus(200);
        $lead->refresh();
        $primer_timestamp = $lead->demo_ingreso_confirmado_at;
        $this->assertNotNull($primer_timestamp);

        // Reentrada: mismo lead, mismo nombre de evento, uuid distinto (no es un duplicado de canal).
        $this->postear_ingreso($lead)->assertStatus(200);
        $lead->refresh();

        $this->assertSame('demo_en_curso', $lead->status);
        $this->assertTrue($primer_timestamp->equalTo($lead->demo_ingreso_confirmado_at));
    }
}
