<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El recordatorio de demo en la dinámica nueva (misión demo-agendado-directo, 10/9/2026): texto
 * libre nuevo, elegible durante toda la ventana de la demo mientras el lead no entró, y SOLO con
 * silencio de N minutos en las dos direcciones. La dinámica actual sigue con su plantilla de
 * siempre, dentro de su ventana de siempre.
 */
class RecordatorioDemoDirectaTest extends TestCase
{
    use DatabaseTransactions;

    /** El "ahora": la demo arrancó a las 10:10 y estamos a las 10:45. */
    const AHORA = '2026-09-08 10:45:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_RECORDATORIO_SILENCIO_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
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
     * (1) Lead de la dinámica nueva, con la carta ya mandada, 35 minutos de silencio: sale el texto
     *     nuevo, por texto libre (no plantilla), con el nombre de pila, y se marca como enviado.
     *
     * @return void
     */
    public function test_con_silencio_sale_el_texto_nuevo_por_texto_libre(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead_nueva_con_demo('10:10', '16:10');
        $lead->demo_mail_sent_at = Carbon::now()->subMinutes(40);
        $lead->save();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(120));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(35));

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(1, $espia->textos, 'Tenía que salir un texto libre.');
        $this->assertCount(0, $espia->plantillas, 'La dinámica nueva no usa la plantilla vieja.');
        $this->assertStringContainsString('Hola Guillermo!', $espia->textos[0]['body']);
        $this->assertStringNotContainsString('González', $espia->textos[0]['body']);
        $this->assertStringContainsString('están en el mail que te mandamos', $espia->textos[0]['body']);
        $this->assertStringContainsString('escribime por acá', $espia->textos[0]['body']);
        $this->assertStringNotContainsString('video introductorio', $espia->textos[0]['body']);

        $this->assertTrue((bool) $lead->refresh()->recordatorio_demo_enviado);
        $this->assertDatabaseHas('lead_messages', ['lead_id' => $lead->id, 'sender' => 'sistema', 'content' => $espia->textos[0]['body']]);
    }

    /**
     * (2) 🔴 Con un mensaje en los últimos 30 minutos —en cualquier dirección— NO sale, aunque el
     *     recordatorio esté activado, y no se marca: se reevalúa en el próximo tick.
     *
     * @return void
     */
    public function test_con_conversacion_reciente_no_sale_y_no_se_marca(): void
    {
        $espia = $this->espiar_whatsapp();

        $lead_entrante = $this->crear_lead_nueva_con_demo('10:10', '16:10');
        $this->mensaje($lead_entrante, 'lead', Carbon::now()->subMinutes(5));

        $lead_saliente = $this->crear_lead_nueva_con_demo('10:10', '16:10', '5493519999998');
        $this->mensaje($lead_saliente, 'lead', Carbon::now()->subMinutes(120));
        $this->mensaje($lead_saliente, 'setter', Carbon::now()->subMinutes(20));

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertFalse((bool) $lead_entrante->refresh()->recordatorio_demo_enviado);
        $this->assertFalse((bool) $lead_saliente->refresh()->recordatorio_demo_enviado);
    }

    /**
     * (3) Sin carta enviada (el lead nunca pasó el mail), el texto habla del link del chat y ofrece
     *     mandarlo por mail.
     *
     * @return void
     */
    public function test_sin_carta_el_texto_apunta_al_chat_y_pide_el_mail(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead_nueva_con_demo('10:10', '16:10');
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(120));

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertStringContainsString('el link que te pasé más arriba', $espia->textos[0]['body']);
        $this->assertStringContainsString('pasame tu correo', $espia->textos[0]['body']);
    }

    /**
     * (4) Fuera de la ventana de la demo (ya vencida) no sale nada; y con la ventana de 24 hs de
     *     Meta cerrada (sin entrante en un día) se saltea sin marcar.
     *
     * @return void
     */
    public function test_ventana_vencida_o_sesion_cerrada_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();

        /* Vencida: terminó a las 09:00 (+10 de gracia) y son las 10:45. */
        $vencida = $this->crear_lead_nueva_con_demo('08:00', '09:00');
        $this->mensaje($vencida, 'lead', Carbon::now()->subMinutes(120));

        /* Sesión cerrada: el último entrante fue hace dos días. */
        $cerrada = $this->crear_lead_nueva_con_demo('10:10', '16:10', '5493519999997');
        $this->mensaje($cerrada, 'lead', Carbon::now()->subDays(2));

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertFalse((bool) $vencida->refresh()->recordatorio_demo_enviado);
        $this->assertFalse((bool) $cerrada->refresh()->recordatorio_demo_enviado);
    }

    /**
     * (5) La dinámica ACTUAL no cambia: plantilla Meta de siempre, sólo dentro de los 15 minutos
     *     previos al inicio, sin mirar el silencio.
     *
     * @return void
     */
    public function test_la_dinamica_actual_sigue_con_su_plantilla(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead_nueva_con_demo('10:55', '11:55', '5493519999996');
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(2));

        $this->artisan('leads:send-demo-reminders')->assertExitCode(0);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_recordatorio_demo_', $espia->plantillas[0]['template_name']);
        $this->assertTrue((bool) $lead->refresh()->recordatorio_demo_enviado);
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

    /**
     * Lead de la dinámica nueva en demo_agendada, con las automatizaciones prendidas.
     *
     * @param string $inicio
     * @param string $fin
     * @param string $telefono
     *
     * @return Lead
     */
    private function crear_lead_nueva_con_demo(string $inicio, string $fin, string $telefono = '5493519999999'): Lead
    {
        $lead                                = new Lead();
        $lead->uuid                          = (string) Str::uuid();
        $lead->contact_name                  = 'Guillermo González';
        $lead->company_name                  = 'Ferretería de prueba';
        $lead->phone                         = $telefono;
        $lead->status                        = 'demo_agendada';
        $lead->demo_id                       = 1;
        $lead->demo_date                     = '2026-09-08';
        $lead->demo_start_time               = $inicio;
        $lead->demo_end_time                 = $fin;
        $lead->demo_flexible                 = true;
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_recordatorio_demo        = true;
        $lead->recordatorio_demo_enviado     = false;
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
}
