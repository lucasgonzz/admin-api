<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Models\AdminSetting;
use App\Models\ClientAssistantMessage;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use App\Services\AsistenteDemoLeadService;
use App\Services\DemoDirectaService;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** El principal conserva el chat comercial y el número dedicado atiende la demo del teléfono exacto. */
class DemoPorNumeroPrincipalTest extends BaseDelCanal
{
    const TELEFONO = '5493417778899';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 15:30:00', DemoDirectaService::TZ));
        Cache::flush();
        Queue::fake();
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_WHATSAPP_NUMERO_LEADS, '543444544199');
        $this->crear_config_whatsapp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(array $campos = []): Lead
    {
        $demo = Demo::create(['nombre' => 'Demo de prueba principal', 'erp_spa_url' => 'https://demo-principal.test',
            'erp_api_url' => 'https://api-demo-principal.test', 'erp_hosting_type' => 'vps',
            'ecommerce_spa_url' => 'https://tienda-demo.test', 'ecommerce_api_url' => 'https://api-tienda-demo.test',
            'api_key' => 'clave-de-prueba-principal']);
        return Lead::create(array_merge(['phone' => '+' . self::TELEFONO, 'contact_name' => 'Julia Demo',
            'status' => 'demo_agendada', 'demo_id' => $demo->id, 'demo_date' => '2026-10-19',
            'demo_start_time' => '15:10', 'demo_end_time' => '17:10', 'demo_setup_status' => 'exitoso'], $campos));
    }

    public function test_el_asistente_dedicado_atiende_y_responde_por_el_mismo_canal_de_entrada(): void
    {
        $this->crear_config_asistente(['display_phone' => '+1 201 555 0123']);
        $lead = $this->lead();
        $espia = $this->espiar_sender();
        $this->postear_webhook($this->payload_de_texto(self::TELEFONO, 'Cuánto vendí hoy?', 'wamid.DEMO-ASISTENTE', null, self::PHONE_NUMBER_ID_ASISTENTE), self::SECRETO_DEL_ASISTENTE)->assertOk();
        $fila = ClientAssistantMessage::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame((int) $lead->demo_id, (int) $fila->demo_id);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, (string) $fila->phone_number_id);
        Queue::assertPushed(EnviarMensajeAlAsistenteJob::class);
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count());

        $this->fakear_http([
            '*/asistente/mensajes' => Http::response(['ai_conversation_id' => 12, 'ai_message_id' => 77], 202),
            '*/asistente/mensajes/77' => Http::response(['estado' => 'listo', 'contenido' => 'Hoy vendiste $120.000.', 'ai_conversation_id' => 12], 200),
        ]);
        $job = new EnviarMensajeAlAsistenteJob((int) $fila->id);
        $this->correr_job($job, $espia);
        $this->correr_job($job, $espia);
        Http::assertSent(function ($r) { return strpos($r->url(), 'https://api-demo-principal.test/api/admin-sync/asistente/mensajes') === 0 && $r->hasHeader('X-Admin-Api-Key', 'clave-de-prueba-principal'); });
        $this->assertSame('+' . self::TELEFONO, $espia->textos[0]['to']);
        $this->assertSame(self::PHONE_NUMBER_ID_ASISTENTE, (string) $espia->destinos[0]);
        $this->assertSame('Hoy vendiste $120.000.', $espia->textos[0]['body']);
        $this->assertSame('+1 201 555 0123', AsistenteDemoLeadService::numero_visible());
        $this->assertStringContainsString('wa.me/12015550123', AsistenteDemoLeadService::url_whatsapp());
    }

    public function test_el_principal_con_turno_activo_conserva_el_chat_comercial(): void
    {
        $this->crear_config_asistente();
        $lead = $this->lead();
        $this->espiar_sender();
        $this->postear_webhook($this->payload_de_texto(self::TELEFONO, 'Quiero hablar con el equipo', 'wamid.PRINCIPAL-COMERCIAL', null, self::PHONE_NUMBER_ID_PRINCIPAL))->assertOk();
        $this->assertSame(0, ClientAssistantMessage::where('lead_id', $lead->id)->count());
        $this->assertTrue(LeadMessage::where('lead_id', $lead->id)->exists());
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }

    public function test_sin_numero_dedicado_listo_no_se_ofrece_el_principal_como_asistente(): void
    {
        $this->assertFalse(AsistenteDemoLeadService::disponible());
        $this->assertSame('', AsistenteDemoLeadService::numero_visible());
        $this->assertSame('', AsistenteDemoLeadService::url_whatsapp());

        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->assertFalse(AsistenteDemoLeadService::disponible());
        $this->assertSame('', AsistenteDemoLeadService::numero_visible());
        $this->assertSame('', AsistenteDemoLeadService::url_whatsapp());

        $this->assertNotNull(WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PRINCIPAL));
    }

    public function test_un_turno_vencido_sigue_en_el_canal_comercial(): void
    {
        $lead = $this->lead(['demo_start_time' => '09:00', 'demo_end_time' => '11:00']);
        $this->espiar_sender();
        $this->postear_webhook($this->payload_de_texto(self::TELEFONO, 'Quiero coordinar otra demo', 'wamid.DEMO-VENCIDA'))->assertOk();
        $this->assertSame(0, ClientAssistantMessage::where('lead_id', $lead->id)->count());
        $this->assertTrue(LeadMessage::where('lead_id', $lead->id)->exists());
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }

    public function test_otro_telefono_no_abre_la_instancia_del_lead(): void
    {
        $lead = $this->lead();
        $this->espiar_sender();
        $this->postear_webhook($this->payload_de_texto('5493517778899', 'Cuánto vendí hoy?', 'wamid.DEMO-AJENO'))->assertOk();
        $this->assertSame(0, ClientAssistantMessage::where('lead_id', $lead->id)->count());
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }
}
