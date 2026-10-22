<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\DemoDirectaService;
use App\Services\LeadDemoSettings;
use App\Services\LeadWhatsappOnboardingSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Entorno común de las pruebas de la demo autogestionada desde la landing (misión
 * demo-autogestionada-landing, 22/10/2026).
 *
 * El «hoy» es el jueves 22/10/2026 a las 10:00 (Argentina). Configuración de producción de la demo:
 * duración 60, setup 15, gracia 10, ventana de 2 h, bloqueo real 120, horario 00:00–23:59 y slots cada
 * 5 minutos (que es lo que el filtro de presentación tiene que esconder). Dos instancias de demo.
 *
 * Sin red: Meta responde 200 falso, el correo es falso y el WhatsApp sale por un doble que anota cada
 * plantilla que se le pide mandar (el de los admins y el del lead).
 */
abstract class BaseDeLaAgendaDeLaLanding extends TestCase
{
    use DatabaseTransactions;

    const AHORA = '2026-10-22 10:00:00';

    const WEB_DATASET = '999000111222';

    const USER_AGENT = 'Mozilla/5.0 (prueba de la landing)';

    const URL_PAGINA = 'https://comerciocity.com/?utm_source=facebook';

    const FBP = 'fb.1.1761130000000.1234567890';

    /** @var Demo */
    protected $demo_1;

    /** @var Demo */
    protected $demo_2;

    /** @var Admin */
    protected $admin;

    /** @var WhatsappSendService Doble con `$enviados`. */
    protected $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, DemoDirectaService::TZ));
        Queue::fake();
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'traza'], 200)]);

        config([
            'services.admin_spa.url'             => 'https://admin.test',
            'services.meta_capi.token'           => '',
            'services.meta_capi.dataset_id'      => '',
            'services.meta_capi.web_token'       => 'token-web-de-prueba',
            'services.meta_capi.web_dataset_id'  => self::WEB_DATASET,
            'services.meta_capi.graph_version'   => 'v24.0',
            'services.meta_capi.test_event_code' => '',
            'landing_agenda.hosts_de_origen'     => ['comerciocity.com', 'www.comerciocity.com'],
        ]);

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '5');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '5');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadWhatsappOnboardingSettings::KEY_AUTO_ACTIVAR_VERIFICACION_AL_SOLICITAR_DISPONIBILIDAD, '1');

        $this->demo_1 = Demo::create(['erp_spa_url' => 'https://demo-1.test', 'erp_api_url' => 'https://demo-1-api.test', 'ecommerce_spa_url' => 'https://tienda-1.test', 'ecommerce_api_url' => 'https://tienda-1-api.test']);
        $this->demo_2 = Demo::create(['erp_spa_url' => 'https://demo-2.test', 'erp_api_url' => 'https://demo-2-api.test', 'ecommerce_spa_url' => 'https://tienda-2.test', 'ecommerce_api_url' => 'https://tienda-2-api.test']);

        $this->admin = Admin::create([
            'name'                            => 'Thomás de prueba',
            'email'                           => 'agenda-landing-' . uniqid() . '@test.local',
            'password'                        => 'fixture-no-utilizable',
            'phone_number'                    => '+5493410000001',
            'notify_demo_scheduled_whatsapp'  => true,
        ]);

        $this->whatsapp = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $enviados = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->enviados[] = ['to' => $to, 'template' => $template_name, 'variables' => $variables, 'language' => $language_code];

                return 'wamid.prueba.' . count($this->enviados);
            }
        };
        $this->app->instance(WhatsappSendService::class, $this->whatsapp);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Cuerpo válido de una reserva (viernes 23/10 a las 15:00).
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    protected function cuerpo(array $cambios = []): array
    {
        return array_merge([
            'nombre'           => 'Marina López',
            'whatsapp'         => '341 666-5544',
            'email'            => 'marina@example.com',
            'rubro'            => 'ferreteria',
            'fecha'            => '2026-10-23',
            'hora'             => '15:00',
            'atribucion'       => ['utm_source' => 'facebook', 'ad_id' => '120200000001', 'fbclid' => 'IwAR123abcXYZ'],
            'fbp'              => self::FBP,
            'event_source_url' => self::URL_PAGINA,
            'abierto_hace_ms'  => 8000,
        ], $cambios);
    }

    /**
     * @param array<string, mixed> $cuerpo
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function reservar(array $cuerpo)
    {
        return $this->withHeaders(['User-Agent' => self::USER_AGENT])->postJson('/api/landing/agenda/reservas', $cuerpo);
    }

    /**
     * Un lead que ocupa una instancia (demo agendada) en una fecha y hora.
     *
     * @param Demo   $demo
     * @param string $fecha
     * @param string $desde
     * @param string $hasta
     *
     * @return Lead
     */
    protected function ocupar(Demo $demo, string $fecha, string $desde, string $hasta): Lead
    {
        return Lead::create([
            'phone'           => '+54911' . random_int(10000000, 99999999),
            'contact_name'    => 'Ocupante',
            'status'          => 'demo_agendada',
            'demo_id'         => $demo->id,
            'demo_date'       => $fecha,
            'demo_start_time' => $desde,
            'demo_end_time'   => $hasta,
        ]);
    }

    /**
     * Plantillas que el doble de WhatsApp mandó con ese nombre.
     *
     * @param string $template
     *
     * @return array<int, array<string, mixed>>
     */
    protected function enviados_con(string $template): array
    {
        return array_values(array_filter($this->whatsapp->enviados, function ($envio) use ($template) {
            return $envio['template'] === $template;
        }));
    }
}
