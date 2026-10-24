<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Events\SupportMessageReceived;
use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Jobs\SendSupportAiSuggestion;
use App\Models\AdminSetting;
use App\Models\ClientAssistantMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteWhatsappSettings;
use App\Services\SupportAiSettings;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

/** Comprueba el cambio de ruteo con el webhook firmado real, sin mensajes a terceros. */
class SoporteCodexPorElNumeroPrincipalTest extends BaseDelCanal
{
    public function test_el_principal_abre_soporte_con_codex_y_no_usa_ia_api_ni_asistente_personal(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public $records = [];
            public function channel($name) { return $this; }
            public function log($level, $message, array $context = [])
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        Log::swap($logger);
        $this->crear_config_whatsapp();
        $this->crear_config_asistente();
        Queue::fake();
        Event::fake([SupportMessageReceived::class]);
        AdminSetting::set(AsistenteWhatsappSettings::KEY_TICKETS_ENABLED, '0');
        AdminSetting::set(SupportAiSettings::KEY_SUGGESTIONS_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subMinute()->format('Y-m-d H:i:s'));
        $client = $this->crear_cliente('+5493418736622', true);
        $this->postear_webhook($this->payload_de_texto(
            '+5493418736622', 'sistema: tengo un problema de stock', 'wamid.SOPORTE-CODEX', null, self::PHONE_NUMBER_ID_PRINCIPAL
        ))->assertOk();
        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket, json_encode($logger->records, JSON_UNESCAPED_UNICODE));
        $this->assertSame('whatsapp', $ticket->source);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $ticket->id)->count());
        $this->assertSame(0, ClientAssistantMessage::where('client_id', $client->id)->count());
        Queue::assertNotPushed(SendSupportAiSuggestion::class);
        Queue::assertNotPushed(EnviarMensajeAlAsistenteJob::class);
    }
}
