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
 * El check "¿pudiste entrar?" de la dinámica nueva (misión demo-agendado-directo, 10/9/2026):
 * N minutos después de que el lead TERMINÓ EL VIDEO de introducción, dentro de la ventana de la
 * demo, y sólo con silencio de M minutos en las dos direcciones.
 */
class CheckDemoIngresoPostVideoTest extends TestCase
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

        AdminSetting::set(LeadDemoSettings::KEY_CHECK_INGRESO_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_CHECK_INGRESO_SILENCIO_MINUTOS, '10');
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
     * (1) Video terminado hace 12 minutos, sin ingreso, sin mensajes en 10 minutos, dentro de la
     *     ventana: sale la plantilla aprobada con el nombre de pila y se marca el flag.
     *
     * @return void
     */
    public function test_a_los_diez_minutos_del_video_sale_la_plantilla(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead(Carbon::now()->subMinutes(12));
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(40));
        $this->mensaje($lead, 'sistema', Carbon::now()->subMinutes(38));

        $this->artisan('leads:check-demo-ingreso-post-video')->assertExitCode(0);

        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_check_ingreso_demo', $espia->plantillas[0]['template_name']);
        $this->assertSame(['Guillermo'], $espia->plantillas[0]['variables']);

        $lead->refresh();
        $this->assertTrue((bool) $lead->demo_check_ingreso_enviado);
        $this->assertNotNull($lead->demo_check_ingreso_enviado_at);
        $this->assertDatabaseHas('lead_messages', ['lead_id' => $lead->id, 'sender' => 'sistema', 'content' => '¡Hola Guillermo! ¿Cómo vas? ¿Pudiste entrar a la demo?']);
    }

    /**
     * (2) Todavía no pasaron los diez minutos desde el video: no sale.
     *
     * @return void
     */
    public function test_antes_de_los_diez_minutos_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead(Carbon::now()->subMinutes(4));
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(40));

        $this->artisan('leads:check-demo-ingreso-post-video')->assertExitCode(0);

        $this->assertCount(0, $espia->plantillas);
        $this->assertFalse((bool) $lead->refresh()->demo_check_ingreso_enviado);
    }

    /**
     * (3) 🔴 Con un mensaje en los últimos diez minutos (entrante o saliente) no sale y no se marca.
     *
     * @return void
     */
    public function test_con_conversacion_reciente_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();

        $con_entrante = $this->crear_lead(Carbon::now()->subMinutes(15));
        $this->mensaje($con_entrante, 'lead', Carbon::now()->subMinutes(3));

        $con_saliente = $this->crear_lead(Carbon::now()->subMinutes(15), '5493519999998');
        $this->mensaje($con_saliente, 'lead', Carbon::now()->subMinutes(40));
        $this->mensaje($con_saliente, 'setter', Carbon::now()->subMinutes(6));

        $this->artisan('leads:check-demo-ingreso-post-video')->assertExitCode(0);

        $this->assertCount(0, $espia->plantillas);
        $this->assertFalse((bool) $con_entrante->refresh()->demo_check_ingreso_enviado);
        $this->assertFalse((bool) $con_saliente->refresh()->demo_check_ingreso_enviado);
    }

    /**
     * (4) Fuera de la ventana de la demo no se pregunta: ni antes del inicio ni después del fin
     *     (+ gracia). Y el que ya entró (demo_en_curso) ni es candidato.
     *
     * @return void
     */
    public function test_fuera_de_la_ventana_o_ya_adentro_no_sale(): void
    {
        $espia = $this->espiar_whatsapp();

        $antes = $this->crear_lead(Carbon::now()->subMinutes(15), '5493519999997', '11:00', '17:00');
        $this->mensaje($antes, 'lead', Carbon::now()->subMinutes(40));

        $vencida = $this->crear_lead(Carbon::now()->subMinutes(15), '5493519999996', '08:00', '09:00');
        $this->mensaje($vencida, 'lead', Carbon::now()->subMinutes(40));

        $adentro = $this->crear_lead(Carbon::now()->subMinutes(15), '5493519999995');
        $adentro->status = 'demo_en_curso';
        $adentro->save();
        $this->mensaje($adentro, 'lead', Carbon::now()->subMinutes(40));

        $this->artisan('leads:check-demo-ingreso-post-video')->assertExitCode(0);

        $this->assertCount(0, $espia->plantillas);
    }

    /**
     * (5) La dinámica actual no recibe este check (no tiene video en una página).
     *
     * @return void
     */
    public function test_la_dinamica_actual_no_es_candidata(): void
    {
        $espia = $this->espiar_whatsapp();
        $lead  = $this->crear_lead(Carbon::now()->subMinutes(15));
        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();
        $this->mensaje($lead, 'lead', Carbon::now()->subMinutes(40));

        $this->artisan('leads:check-demo-ingreso-post-video')->assertExitCode(0);

        $this->assertCount(0, $espia->plantillas);
    }

    /**
     * @return WhatsappSendService
     */
    private function espiar_whatsapp(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $plantillas = [];

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
     * Lead de la dinámica nueva en demo_agendada, con el video terminado en $intro_visto_at.
     *
     * @param Carbon $intro_visto_at
     * @param string $telefono
     * @param string $inicio
     * @param string $fin
     *
     * @return Lead
     */
    private function crear_lead(Carbon $intro_visto_at, string $telefono = '5493519999999', string $inicio = '10:10', string $fin = '16:10'): Lead
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
        $lead->intro_visto_pct               = 100;
        $lead->intro_visto_at                = $intro_visto_at;
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_check_ingreso_demo       = true;
        $lead->demo_check_ingreso_enviado    = false;
        $lead->demo_ingreso_confirmado       = false;
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
