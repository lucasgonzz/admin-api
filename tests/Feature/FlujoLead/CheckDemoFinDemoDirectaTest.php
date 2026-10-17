<?php

namespace Tests\Feature\FlujoLead;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use App\Services\WhatsappSessionWindowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `leads:check-demo-fin` para la demo directa (misión flujo-lead-landing-closer, D10, 17/10/2026): ya no
 * la excluye; al cumplirse la duración pregunta "¿cómo venís con la demo?" en texto libre si la ventana
 * de 24 hs está abierta, y con la plantilla si no. La posposición por conversación viva sigue igual.
 */
class CheckDemoFinDemoDirectaTest extends TestCase
{
    use DatabaseTransactions;

    /** La demo arrancó a las 10:10, dura 60: el check cae a las 11:10. */
    const AHORA = '2026-09-08 11:10:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_FIN_CHECK_SILENCIO_MINUTOS, '10');
        AdminSetting::set(LeadDemoSettings::KEY_FIN_CHECK_DEMORA_DEFAULT_MINUTOS, '15');
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
     * (1) 🔴 Demo directa (flexible, dinámica nueva) con la ventana abierta: texto libre y flag marcado.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_sale_el_texto_libre(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->ventana(true);
        $lead = $this->crear_lead_en_demo_directa();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(40));

        $this->artisan('leads:check-demo-fin')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame('¡Hola Guillermo! ¿Cómo venís con la demo? ¿Pudiste recorrerla completa? 😊', $espia->textos[0]['body']);
        $this->assertTrue((bool) $lead->refresh()->demo_fin_check_enviado);
        $this->assertDatabaseHas('lead_messages', ['lead_id' => $lead->id, 'sender' => 'sistema', 'content' => '¡Hola Guillermo! ¿Cómo venís con la demo? ¿Pudiste recorrerla completa? 😊']);
    }

    /**
     * (2) Con la ventana cerrada, la plantilla de siempre.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_sale_la_plantilla(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->ventana(false);
        $lead = $this->crear_lead_en_demo_directa();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(40));

        $this->artisan('leads:check-demo-fin')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_check_fin_demo', $espia->plantillas[0]['template_name']);
        $this->assertSame(['Guillermo'], $espia->plantillas[0]['variables']);
        $this->assertTrue((bool) $lead->refresh()->demo_fin_check_enviado);
    }

    /**
     * (3) Con conversación viva (el lead escribió hace 3 minutos) se pospone, como siempre.
     *
     * @return void
     */
    public function test_con_conversacion_viva_se_pospone(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->ventana(true);
        $lead = $this->crear_lead_en_demo_directa();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(3));

        $this->artisan('leads:check-demo-fin')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $lead->refresh();
        $this->assertFalse((bool) $lead->demo_fin_check_enviado);
        $this->assertNotNull($lead->demo_fin_check_reprogramado_para);
    }

    /**
     * (4) Sin `auto_check_fin_demo` no corre (es lo que el agendar_demo prende solo, D9).
     *
     * @return void
     */
    public function test_sin_el_check_prendido_no_corre(): void
    {
        $espia = $this->espiar_whatsapp();
        $this->ventana(true);
        $lead                      = $this->crear_lead_en_demo_directa();
        $lead->auto_check_fin_demo = false;
        $lead->save();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(40));

        $this->artisan('leads:check-demo-fin')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
    }

    /**
     * @return Lead
     */
    private function crear_lead_en_demo_directa(): Lead
    {
        $lead                                = new Lead();
        $lead->uuid                          = (string) Str::uuid();
        $lead->contact_name                  = 'Guillermo González';
        $lead->phone                         = '5493519990031';
        $lead->status                        = 'demo_en_curso';
        $lead->demo_id                       = 1;
        $lead->demo_date                     = '2026-09-08';
        $lead->demo_start_time               = '10:10';
        $lead->demo_end_time                 = '12:10';
        $lead->demo_flexible                 = true;
        $lead->demo_setup_status             = 'exitoso';
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_check_fin_demo           = true;
        $lead->demo_fin_check_enviado        = false;
        $lead->demo_ingreso_confirmado       = true;
        $lead->tiene_sugerencia_pendiente    = false;
        $lead->save();

        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * @param Lead   $lead
     * @param string $sender
     * @param Carbon $cuando
     *
     * @return LeadMessage
     */
    private function mensaje(Lead $lead, string $sender, Carbon $cuando): LeadMessage
    {
        $mensaje              = new LeadMessage();
        $mensaje->lead_id     = $lead->id;
        $mensaje->sender      = $sender;
        $mensaje->status      = 'enviado';
        $mensaje->is_followup = false;
        $mensaje->content     = 'Mensaje de ' . $sender;
        $mensaje->created_at  = $cuando;
        $mensaje->updated_at  = $cuando;
        $mensaje->save();

        return $mensaje;
    }

    /**
     * Resolutor de la ventana de 24 hs con la respuesta fija que pida el caso.
     *
     * @param bool $abierta
     *
     * @return void
     */
    private function ventana(bool $abierta): void
    {
        $fake = new class extends WhatsappSessionWindowService {
            /** @var bool */
            public $abierta = true;

            public function is_open(string $phone, ?string $proposito = null): bool
            {
                return $this->abierta;
            }
        };
        $fake->abierta = $abierta;

        $this->app->instance(WhatsappSessionWindowService::class, $fake);
    }

    /**
     * @return WhatsappSendService
     */
    private function espiar_whatsapp(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $textos = [];

            /** @var array<int, array<string, mixed>> */
            public $plantillas = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }
}
