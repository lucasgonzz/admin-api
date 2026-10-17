<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageVariant;
use App\Services\LeadOrigenService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Database\Seeders\MessageVariantLandingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Onboarding del lead que llega con la FRASE DE UN BOTÓN (misión flujo-lead-landing-closer,
 * 17/10/2026, D8 del plan): auto landing al toque, presentación con una variante `welcome_landing` a
 * los 10 segundos (con fallback a `welcome`), y el lead que escribe cualquier otra cosa sigue
 * exactamente como hoy (auto genérico, variantes `welcome`, 60 segundos).
 */
class OnboardingDelLeadDeLaLandingTest extends TestCase
{
    use DatabaseTransactions;

    /** El "ahora" fijo, para medir la demora del job. */
    const AHORA = '2026-10-17 10:00:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        Http::fake();

        /* Settings explícitas: la base del slot puede tener otros valores guardados. */
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS, '60');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING, '10');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING, '¡Hola! 👋 Enseguida Martín se suma a la conversación.');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_WITHOUT_NAME, '¡Hola! 👋 Un asesor comercial se pondrá en contacto a la brevedad.');

        /* Las tres variantes landing del seeder, y todas las `welcome` de la base apagadas salvo una
         * propia, para que el reparto sea determinístico. */
        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME)->update(['active' => false]);
        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING)->delete();
        (new MessageVariantLandingSeeder())->run();
        MessageVariant::create([
            'slug'          => 'test_welcome_generico',
            'name'          => 'Genérica de prueba',
            'message_type'  => LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME,
            'body'          => 'Hola {nombre}! Soy Martín. ¿A qué se dedica el negocio?',
            'active'        => true,
            'delay_seconds' => null,
        ]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * (1) Frase de la landing: auto landing, job a los 10 s y variante `welcome_landing` asignada. Y
     *     al correr el job, la presentación es el texto de esa variante.
     *
     * @return void
     */
    public function test_la_frase_de_la_landing_dispara_el_onboarding_landing(): void
    {
        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_welcome_and_schedule_presentation($lead, null, LeadOrigenService::FRASE_LANDING);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('¡Hola! 👋 Enseguida Martín se suma a la conversación.', $espia->textos[0]['body']);

        Queue::assertPushed(SendLeadPresentationMessageJob::class, function (SendLeadPresentationMessageJob $job) {
            return $job->delay !== null && Carbon::parse($job->delay)->equalTo(now()->addSeconds(10));
        });

        $lead->refresh();
        $variante = MessageVariant::find($lead->welcome_variant_id);
        $this->assertNotNull($variante, 'La variante se asigna al encolar, para que el job lea la misma.');
        $this->assertSame(LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING, $variante->message_type);
        $this->assertStringStartsWith('landing_', $variante->slug);

        /* El job (acá, a mano): manda la variante landing como presentación. */
        $servicio->send_presentation_message($lead, null);

        $this->assertCount(2, $espia->textos);
        $this->assertSame($variante->body, $espia->textos[1]['body']);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'             => $lead->id,
            'system_message_kind' => LeadWhatsappOnboardingService::KIND_WELCOME,
            'content'             => $variante->body,
        ]);
    }

    /**
     * (2) La landing vieja y la frase de Instagram también son botones; y un saludo adelante no
     *     cambia nada ("Buenas! Hola, quiero hacer la demo de ComercioCity").
     *
     * @return void
     */
    public function test_las_otras_frases_de_boton_y_el_saludo_adelante_tambien_cuentan(): void
    {
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadOrigenService::FRASE_LANDING_VIEJA));
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadOrigenService::FRASE_INSTAGRAM));
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton('Buenas! ' . LeadOrigenService::FRASE_LANDING));
        $this->assertTrue(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton('¡Hola, quiero hacer la demo de ComercioCity!'));

        /* Algo atrás ya es un mensaje propio del lead: no es la frase. */
        $this->assertFalse(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(LeadOrigenService::FRASE_LANDING . ', pero la semana que viene'));
        $this->assertFalse(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton('Hola, tengo una ferretería y quiero info'));
        $this->assertFalse(LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton(null));
    }

    /**
     * (3) Sin variantes `welcome_landing` activas, el lead de un botón cae a una `welcome` (nunca se
     *     queda sin presentación) y la demora sigue siendo la landing (10 s).
     *
     * @return void
     */
    public function test_sin_variantes_landing_activas_cae_a_welcome_con_la_demora_landing(): void
    {
        MessageVariant::query()->where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING)->update(['active' => false]);

        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_welcome_and_schedule_presentation($lead, null, LeadOrigenService::FRASE_LANDING);

        $lead->refresh();
        $variante = MessageVariant::find($lead->welcome_variant_id);
        $this->assertNotNull($variante);
        $this->assertSame(LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME, $variante->message_type);

        Queue::assertPushed(SendLeadPresentationMessageJob::class, function (SendLeadPresentationMessageJob $job) {
            return $job->delay !== null && Carbon::parse($job->delay)->equalTo(now()->addSeconds(10));
        });
    }

    /**
     * (4) El lead que escribe lo suyo sigue exactamente como hoy: auto genérico (sin nombre), job a
     *     los 60 s y variante `welcome`.
     *
     * @return void
     */
    public function test_un_primer_mensaje_libre_sigue_con_el_onboarding_de_siempre(): void
    {
        $espia    = $this->espiar_whatsapp();
        $servicio = new LeadWhatsappOnboardingService($espia);
        $lead     = $this->crear_lead_nuevo();

        $servicio->send_welcome_and_schedule_presentation($lead, null, 'Hola, tengo una distribuidora y quiero saber más');

        $this->assertCount(1, $espia->textos);
        $this->assertSame('¡Hola! 👋 Un asesor comercial se pondrá en contacto a la brevedad.', $espia->textos[0]['body']);

        Queue::assertPushed(SendLeadPresentationMessageJob::class, function (SendLeadPresentationMessageJob $job) {
            return $job->delay !== null && Carbon::parse($job->delay)->equalTo(now()->addSeconds(60));
        });

        $lead->refresh();
        $variante = MessageVariant::find($lead->welcome_variant_id);
        $this->assertNotNull($variante);
        $this->assertSame(LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME, $variante->message_type);
    }

    /**
     * (5) El seeder es idempotente: una segunda corrida no pisa el texto ni el interruptor que Lucas
     *     haya tocado a mano.
     *
     * @return void
     */
    public function test_el_seeder_no_pisa_body_ni_active_de_una_variante_existente(): void
    {
        $variante         = MessageVariant::where('slug', 'landing_una_pregunta')->firstOrFail();
        $variante->body   = 'Texto editado a mano por Lucas.';
        $variante->active = false;
        $variante->save();

        (new MessageVariantLandingSeeder())->run();

        $variante->refresh();
        $this->assertSame('Texto editado a mano por Lucas.', $variante->body);
        $this->assertFalse((bool) $variante->active);
        $this->assertSame(3, MessageVariant::where('message_type', LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING)->count());
    }

    /**
     * (6) Las dos settings nuevas salen en el GET del panel con sus defaults y se persisten solo si
     *     vienen en el PUT (el panel de hoy no las manda).
     *
     * @return void
     */
    public function test_las_settings_landing_tienen_default_y_son_opcionales_en_el_put(): void
    {
        AdminSetting::query()->whereIn('key', [
            LeadWhatsappOnboardingSettings::KEY_AUTO_LANDING,
            LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING,
        ])->delete();

        $this->assertSame('¡Hola! 👋 Enseguida Martín se suma a la conversación.', LeadWhatsappOnboardingSettings::get_auto_message_landing());
        $this->assertSame(10, LeadWhatsappOnboardingSettings::get_welcome_delay_seconds_landing());

        $panel = LeadWhatsappOnboardingSettings::to_array();
        $this->assertArrayHasKey('auto_message_landing', $panel);
        $this->assertArrayHasKey('welcome_delay_seconds_landing', $panel);

        /* El PUT de hoy, sin las claves nuevas: no tira y no las toca. */
        LeadWhatsappOnboardingSettings::persist_from_request([
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes' => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => true,
        ]);
        $this->assertNull(AdminSetting::get(LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING));

        LeadWhatsappOnboardingSettings::persist_from_request([
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes' => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => true,
            'auto_message_landing'         => 'Hola, ya te atiende Martín.',
            'welcome_delay_seconds_landing' => 20,
        ]);
        $this->assertSame('Hola, ya te atiende Martín.', LeadWhatsappOnboardingSettings::get_auto_message_landing());
        $this->assertSame(20, LeadWhatsappOnboardingSettings::get_welcome_delay_seconds_landing());
    }

    /**
     * Lead recién nacido, en `nuevo`, sin historial.
     *
     * @return Lead
     */
    private function crear_lead_nuevo(): Lead
    {
        $lead         = new Lead();
        $lead->uuid   = (string) Str::uuid();
        $lead->phone  = '+54911' . random_int(10000000, 99999999);
        $lead->status = 'nuevo';
        $lead->save();

        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count());

        return $lead->refresh();
    }

    /**
     * @return WhatsappSendService
     */
    private function espiar_whatsapp(): WhatsappSendService
    {
        return new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }
        };
    }
}
