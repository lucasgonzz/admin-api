<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\LeadAiService;
use App\Services\LeadSuggestionSendService;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post-demo con Thomás (misión flujo-lead-landing-closer, 17/10/2026): el bloque de coordinación ya no
 * trae horarios, `confirmar_interes_closer` lleva al lead a `closer_activo` (apaga el agente, avisa al
 * closer al enviar, sin LeadCall ni evento de calendario) y `descartar_llamada_closer` lo deja en
 * `demo_realizada` sin intervención humana ni apagar el agente.
 */
class PostDemoConThomasTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        /* El catálogo de estados con su orden real (ver GateDeAprobacionTest::setUp). */
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);

        /* Config de WhatsApp en modo prueba: send_text devuelve un id simulado sin salir a la red. */
        WhatsappConfig::query()->update(['is_active' => false]);
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = 'secreto';
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();
    }

    /**
     * (1) El bloque de coordinación: Thomás, 20 a 40 minutos, las dos acciones, y nada de horarios.
     *
     * @return void
     */
    public function test_el_bloque_de_coordinacion_nombra_a_thomas_y_no_trae_horarios(): void
    {
        $lead = $this->crear_lead('demo_realizada');

        $metodo = new \ReflectionMethod(LeadAiService::class, 'build_coordinacion_llamada_closer_context');
        $metodo->setAccessible(true);
        $bloque = (string) $metodo->invoke(new LeadAiService(), $lead);

        $this->assertSame('Thomás', LeadAiService::NOMBRE_DEL_CLOSER);
        $this->assertStringContainsString('COORDINACIÓN DE LA LLAMADA CON THOMÁS (asesor comercial)', $bloque);
        $this->assertStringContainsString('videollamada de 20 a 40 minutos con Thomás', $bloque);
        $this->assertStringContainsString('en menos de una semana', $bloque);
        $this->assertStringContainsString('confirmar_interes_closer: true', $bloque);
        $this->assertStringContainsString('NO ofrezcas horarios ni links de reunión', $bloque);
        $this->assertStringContainsString('descartar_llamada_closer: {"motivo": "..."}', $bloque);
        $this->assertStringContainsString('lo evalúa Thomás en la llamada', $bloque);
        $this->assertStringContainsString('se desarrolla a medida', $bloque);
        $this->assertStringContainsString('posponer_check_fin_demo', $bloque);

        $this->assertStringNotContainsString('Próximos horarios', $bloque);
        $this->assertStringNotContainsString('agendar_llamada_closer', $bloque);
        $this->assertStringNotContainsString('15 y 20 minutos', $bloque);
        $this->assertStringNotContainsString('Tommy', $bloque);
    }

    /**
     * (2) 🔴 confirmar_interes_closer desde demo_realizada: closer_activo al enviar, agente apagado, sin
     *     LeadCall, y el aviso al closer sale por el camino de siempre al aplicarse el estado.
     *
     * @return void
     */
    public function test_confirmar_interes_closer_lleva_a_closer_activo_sin_aviso_automatico(): void
    {
        $closer               = new Admin();
        $closer->name         = 'Thomás';
        $closer->email        = 'thomas-' . Str::random(6) . '@comerciocity.test';
        $closer->password     = bcrypt('secret');
        $closer->is_closer    = true;
        $closer->phone_number = '5493511112222';
        $closer->save();

        $lead = $this->crear_lead('demo_realizada');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'         => 'Buenísimo. Thomás se comunica con vos por acá para coordinar día y hora.',
            'estado_sugerido'          => 'demo_realizada',
            'confirmar_interes_closer' => true,
        ]);

        $lead->refresh();
        $this->assertSame('closer_activo', $msg->suggested_lead_status);
        $this->assertFalse((bool) $lead->claude_auto_reply, 'Desde acá habla Thomás, no Martín.');
        $this->assertSame('demo_realizada', (string) $lead->status, 'El estado se aplica al enviar, como todo estado sugerido.');
        $this->assertSame(0, LeadCall::where('lead_id', $lead->id)->count(), 'Sin LeadCall: el horario lo coordina Thomás.');
        $this->assertNull($lead->google_event_id);

        (new LeadSuggestionSendService())->send_suggestion($msg->fresh());

        $lead->refresh();
        $this->assertSame('closer_activo', (string) $lead->status);
        // Cambio explícito de Lucas el 20/10: él revisa el post-demo antes de coordinar la llamada.
        $this->assertNull($lead->closer_notified_at, 'El pase conserva el estado y el pipeline, sin aviso automático al closer.');
        $this->assertSame(0, LeadCall::where('lead_id', $lead->id)->count());
    }

    /**
     * (3) También vale desde demo_en_curso (el turno en que confirma el fin y dice que quiere la
     *     llamada): marca el fin y va a closer_activo.
     *
     * @return void
     */
    public function test_desde_demo_en_curso_marca_el_fin_y_va_a_closer_activo(): void
    {
        $lead = $this->crear_lead('demo_en_curso');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'         => 'Genial que la pudiste recorrer. Thomás te escribe por acá.',
            'estado_sugerido'          => 'demo_realizada',
            'confirmar_interes_closer' => true,
        ]);

        $lead->refresh();
        $this->assertSame('closer_activo', $msg->suggested_lead_status);
        $this->assertTrue((bool) $lead->demo_terminada_confirmada);
        $this->assertNotNull($lead->demo_terminada_confirmada_at);
    }

    /**
     * (4) descartar_llamada_closer: queda en demo_realizada, sin intervención humana, con el agente
     *     prendido y el motivo en el hilo.
     *
     * @return void
     */
    public function test_descartar_llamada_closer_no_apaga_al_agente_ni_abre_intervencion(): void
    {
        $lead = $this->crear_lead('demo_realizada');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'          => 'Dale, ningún problema. Cualquier cosa, acá estoy.',
            'estado_sugerido'           => 'demo_realizada',
            'descartar_llamada_closer'  => ['motivo' => 'Lo quiere pensar con su socio'],
        ]);

        $lead->refresh();
        $this->assertSame('demo_realizada', (string) $lead->status);
        $this->assertNull($msg->suggested_lead_status);
        $this->assertFalse((bool) $lead->requiere_intervencion_humana);
        $this->assertTrue((bool) $lead->claude_auto_reply);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'         => $lead->id,
            'is_status_event' => true,
            'content'         => 'El lead no quiere avanzar con la llamada con Thomás. Motivo: Lo quiere pensar con su socio',
        ]);

        /* «Terminé, pero no me interesa»: llega con el lead todavía en demo_en_curso y el descarte vale
         * igual (hasta el 17/10 se ignoraba en silencio por exigir demo_realizada). */
        $en_curso = $this->crear_lead('demo_en_curso', '5493519990023');
        $msg_en_curso = $this->service()->aplicar($en_curso, [
            'mensaje_sugerido'          => 'Dale, gracias por probarla.',
            'estado_sugerido'           => 'demo_realizada',
            'confirmar_fin_demo'        => true,
            'descartar_llamada_closer'  => ['motivo' => 'Dice que no le interesa'],
        ]);
        $this->assertSame('demo_realizada', $msg_en_curso->suggested_lead_status);
        $this->assertTrue((bool) $en_curso->refresh()->claude_auto_reply);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'         => $en_curso->id,
            'is_status_event' => true,
            'content'         => 'El lead no quiere avanzar con la llamada con Thomás. Motivo: Dice que no le interesa',
        ]);

        /* La forma vieja (true + motivo_descarte_llamada) sigue valiendo. */
        $otro = $this->crear_lead('demo_realizada', '5493519990022');
        $this->service()->aplicar($otro, [
            'mensaje_sugerido'          => 'Dale.',
            'estado_sugerido'           => 'demo_realizada',
            'descartar_llamada_closer'  => true,
            'motivo_descarte_llamada'   => 'No le interesa por ahora',
        ]);
        $this->assertTrue((bool) $otro->refresh()->claude_auto_reply);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'         => $otro->id,
            'is_status_event' => true,
            'content'         => 'El lead no quiere avanzar con la llamada con Thomás. Motivo: No le interesa por ahora',
        ]);
    }

    /**
     * @param string $status
     * @param string $telefono
     *
     * @return Lead
     */
    private function crear_lead(string $status, string $telefono = '5493519990021'): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Guillermo González';
        $lead->phone              = $telefono;
        $lead->status             = $status;
        $lead->claude_auto_reply  = true;
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        $inbound              = new LeadMessage();
        $inbound->lead_id     = $lead->id;
        $inbound->sender      = 'lead';
        $inbound->status      = 'enviado';
        $inbound->is_followup = false;
        $inbound->content     = 'Sí, la terminé, me gustó.';
        $inbound->save();

        return $lead->refresh();
    }

    /**
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            public function aplicar(Lead $lead, array $parsed): LeadMessage
            {
                return $this->apply_parsed_response($lead, $parsed, false);
            }
        };
    }
}
