<?php

namespace Tests\Feature\LandingAgenda;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\LeadAiService;
use App\Services\LeadAiSuggestionScheduler;
use Illuminate\Support\Facades\Bus;
use Mockery;

/**
 * El lead que reservó desde la landing escribe por WhatsApp (D4, D11): su mensaje cae en la misma ficha,
 * el agente le contesta desde el PRIMER mensaje (el gate «la IA espera al segundo» es para el onboarding,
 * que no corre para un lead en demo) y el bloque DEMO DIRECTA le dice que el turno lo eligió él en la web.
 */
class PrimerMensajeDelLeadReservadoTest extends BaseDeLaAgendaDeLaLanding
{
    const SECRETO = 'secreto-de-prueba-agenda-landing';

    protected function setUp(): void
    {
        parent::setUp();

        WhatsappConfig::query()->update(['is_active' => false]);
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();
    }

    private function postear_webhook(string $desde, string $texto, string $wamid)
    {
        $body = json_encode([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $desde, 'contact_name' => 'Marina'],
            'message'      => [
                'id'        => $wamid,
                'from'      => $desde,
                'type'      => 'text',
                'text'      => ['body' => $texto],
                'timestamp' => (string) time(),
            ],
        ]);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body);
    }

    private function jobs_de_sugerencia(): int
    {
        return Bus::dispatched(GenerateLeadAiSuggestionJob::class)->count()
            + Bus::dispatchedSync(GenerateLeadAiSuggestionJob::class)->count()
            + Bus::dispatchedAfterResponse(GenerateLeadAiSuggestionJob::class)->count();
    }

    public function test_el_primer_mensaje_cae_en_su_ficha_y_programa_la_sugerencia(): void
    {
        $this->reservar($this->cuerpo())->assertStatus(201);
        $lead        = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $leads_antes = Lead::query()->count();

        Bus::fake([GenerateLeadAiSuggestionJob::class]);
        $this->postear_webhook('5493416665544', 'Hola, reservé para el viernes, ¿tengo que instalar algo?', 'wamid.AGENDA1')->assertStatus(200);

        $this->assertSame($leads_antes, Lead::query()->count(), 'El webhook encuentra al lead que reservó: no crea otro.');
        $this->assertTrue(LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->where('whatsapp_message_id', 'wamid.AGENDA1')->exists());
        $this->assertSame(1, $this->jobs_de_sugerencia(), 'Con un solo mensaje, el agente igual le contesta.');
    }

    public function test_un_lead_en_demo_que_no_reservo_desde_la_web_sigue_esperando_el_segundo_mensaje(): void
    {
        $this->ocupar($this->demo_1, '2026-10-23', '15:00', '17:00')->update(['phone' => '+5493416665544']);

        Bus::fake([GenerateLeadAiSuggestionJob::class]);
        $this->postear_webhook('5493416665544', 'Hola', 'wamid.AGENDA2')->assertStatus(200);

        $this->assertSame(0, $this->jobs_de_sugerencia(), 'El gate de siempre no cambia para los demás.');
    }

    public function test_el_job_genera_la_sugerencia_con_un_solo_mensaje_del_lead(): void
    {
        $this->reservar($this->cuerpo())->assertStatus(201);
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'content' => 'Hola, ¿qué tengo que hacer?',
            'status' => 'enviado', 'is_status_event' => false, 'sent_at' => now(), 'whatsapp_message_id' => 'wamid.AGENDA3',
        ]);

        Bus::fake([GenerateLeadAiSuggestionJob::class]);
        (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);
        $job = Bus::dispatched(GenerateLeadAiSuggestionJob::class)->first()
            ?: Bus::dispatchedAfterResponse(GenerateLeadAiSuggestionJob::class)->first();
        $this->assertNotNull($job);

        $agente = Mockery::mock(LeadAiService::class);
        $agente->shouldReceive('generate_suggestion')->once()->andReturn(new LeadMessage());
        $job->handle($agente, new LeadAiSuggestionScheduler());
    }

    public function test_la_red_de_seguridad_del_cron_tambien_recoge_su_primer_mensaje(): void
    {
        $this->reservar($this->cuerpo())->assertStatus(201);
        $reservado = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $otro      = $this->ocupar($this->demo_2, '2026-10-24', '15:00', '17:00');
        foreach ([$reservado, $otro] as $i => $lead) {
            LeadMessage::create([
                'lead_id' => $lead->id, 'sender' => 'lead', 'kind' => 'text', 'content' => 'Hola',
                'status' => 'enviado', 'is_status_event' => false, 'sent_at' => now()->subMinutes(10),
                'created_at' => now()->subMinutes(10), 'whatsapp_message_id' => 'wamid.CRON' . $i,
            ]);
        }

        Bus::fake([GenerateLeadAiSuggestionJob::class]);
        (new \App\Services\BatchLeadAiRecoveryService())->dispatch_unanswered_leads();

        $propiedad = new \ReflectionProperty(GenerateLeadAiSuggestionJob::class, 'lead_id');
        $propiedad->setAccessible(true);
        $leads = Bus::dispatchedAfterResponse(GenerateLeadAiSuggestionJob::class)->map(function ($job) use ($propiedad) {
            return (int) $propiedad->getValue($job);
        })->all();
        $this->assertContains((int) $reservado->id, $leads);
        $this->assertNotContains((int) $otro->id, $leads, 'Para los demás, el gate de siempre.');
    }

    public function test_el_bloque_demo_directa_dice_que_lo_reservo_desde_la_web(): void
    {
        $this->reservar($this->cuerpo())->assertStatus(201);
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();

        $metodo = new \ReflectionMethod(LeadAiService::class, 'build_demo_directa_context');
        $metodo->setAccessible(true);
        $bloque = (string) $metodo->invoke(new LeadAiService(), $lead);

        $this->assertStringContainsString('Reserva desde la web: este turno lo eligio EL MISMO', $bloque);
        $this->assertStringContainsString('ya recibio la carta por mail', $bloque);
        $this->assertStringContainsString('NO le ofrezcas la demo ni le pidas el mail de nuevo', $bloque);
        $this->assertStringContainsString('YA ASIGNADA', $bloque);

        /* Un lead agendado por el agente no lleva la línea. */
        $otro   = $this->ocupar($this->demo_2, '2026-10-23', '15:00', '17:00');
        $bloque = (string) $metodo->invoke(new LeadAiService(), $otro);
        $this->assertStringNotContainsString('Reserva desde la web', $bloque);
    }
}
