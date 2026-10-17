<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\LeadWhatsappOnboardingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `contactado` determinístico (misión flujo-lead-landing-closer, 17/10/2026): el lead en `nuevo` que
 * escribe DESPUÉS de haber recibido la presentación de Martín pasa a `contactado` en el webhook, con el
 * evento de estado en el hilo. Antes de la presentación, o fuera de `nuevo`, nada cambia.
 */
class ContactadoAlResponderLaPresentacionTest extends TestCase
{
    use DatabaseTransactions;

    /** Secreto con el que se firman los webhooks de Kapso. */
    private const SECRETO = 'secreto-de-prueba-contactado';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        $config->test_mode       = true;
        $config->save();
    }

    /**
     * (1) Lead en `nuevo` con la presentación ya enviada: su siguiente mensaje lo pasa a `contactado`
     *     y queda el evento en el hilo.
     *
     * @return void
     */
    public function test_el_lead_que_responde_a_la_presentacion_pasa_a_contactado(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead($telefono, 'nuevo');
        $this->mensaje($lead, 'lead', 'Hola, quiero hacer la demo de ComercioCity');
        $this->mensaje($lead, 'sistema', '¡Hola! 👋 Enseguida Martín se suma a la conversación.', LeadWhatsappOnboardingService::KIND_AUTO);
        $this->mensaje($lead, 'sistema', 'Hola, soy Martín, del equipo de ComercioCity...', LeadWhatsappOnboardingService::KIND_WELCOME);

        $this->postear_kapso($telefono, 'Tengo una ferretería, somos 3', 'wamid.contactado.1')->assertStatus(200);

        $lead->refresh();
        $this->assertSame('contactado', (string) $lead->status);

        $evento = LeadMessage::where('lead_id', $lead->id)->where('is_status_event', true)->latest('id')->first();
        $this->assertNotNull($evento, 'Tiene que quedar el evento del cambio de estado en el hilo.');
        $this->assertStringContainsString('respondió a la presentación', (string) $evento->content);
        $this->assertStringContainsString('"Contactado"', (string) $evento->content);
    }

    /**
     * (2) Si la presentación todavía no salió (solo el auto), el lead sigue en `nuevo`: moverlo
     *     dejaría al job de la presentación sin mandar.
     *
     * @return void
     */
    public function test_antes_de_la_presentacion_sigue_en_nuevo(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead($telefono, 'nuevo');
        $this->mensaje($lead, 'lead', 'Hola, quiero hacer la demo de ComercioCity');
        $this->mensaje($lead, 'sistema', '¡Hola! 👋 Enseguida Martín se suma a la conversación.', LeadWhatsappOnboardingService::KIND_AUTO);

        $this->postear_kapso($telefono, 'Hola?', 'wamid.contactado.2')->assertStatus(200);

        $this->assertSame('nuevo', (string) $lead->refresh()->status);
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->where('is_status_event', true)->count());
    }

    /**
     * (3) Un lead que ya no está en `nuevo` no se toca, aunque tenga la presentación en el hilo.
     *
     * @return void
     */
    public function test_fuera_de_nuevo_no_cambia_nada(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead     = $this->crear_lead($telefono, 'calificado');
        $this->mensaje($lead, 'lead', 'Hola');
        $this->mensaje($lead, 'sistema', 'Hola, soy Martín...', LeadWhatsappOnboardingService::KIND_WELCOME);
        $this->mensaje($lead, 'lead', 'Tengo una ferretería');

        $this->postear_kapso($telefono, 'Somos 4', 'wamid.contactado.3')->assertStatus(200);

        $this->assertSame('calificado', (string) $lead->refresh()->status);
    }

    /**
     * @return string
     */
    private function telefono_al_azar(): string
    {
        return '+54911' . random_int(10000000, 99999999);
    }

    /**
     * @param string $telefono
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $telefono, string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->phone        = $telefono;
        $lead->status       = $status;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * @param Lead        $lead
     * @param string      $sender
     * @param string      $content
     * @param string|null $kind
     *
     * @return LeadMessage
     */
    private function mensaje(Lead $lead, string $sender, string $content, ?string $kind = null): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => $sender,
            'content'             => $content,
            'status'              => 'enviado',
            'is_followup'         => false,
            'system_message_kind' => $kind,
            'whatsapp_message_id' => 'wamid.' . Str::random(12),
            'sent_at'             => now(),
        ]);
    }

    /**
     * Pega al webhook de Kapso con el body firmado igual que lo firma Kapso.
     *
     * @param string $telefono
     * @param string $texto
     * @param string $wamid
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear_kapso(string $telefono, string $texto, string $wamid)
    {
        $body = json_encode([
            'event'        => 'whatsapp.message.received',
            'conversation' => ['phone_number' => $telefono, 'contact_name' => 'Lead de prueba'],
            'message'      => [
                'id'        => $wamid,
                'from'      => $telefono,
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
}
