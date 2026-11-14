<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteAvisosService;
use Illuminate\Support\Facades\Http;

/**
 * A7 (misión prueba-e2e-campana-imperio, 14/11/2026): en un cliente de ENSAYO, los avisos del circuito de carga
 * (`importacion_lista`, que empresa informa con el `support_ticket_id` de la corrida) van a la conversación del
 * sistema donde se lanzó la carga, no a la «Prueba gratuita».
 *
 * Caso real: cliente de ensayo 85. El dueño tenía abiertas la «Prueba gratuita» (ticket 125, exclusiva del sistema)
 * y otra conversación (126) donde mandó la planilla. La pregunta por la columna «Ubic.» y los dos «Cargué N
 * artículos…» cayeron en el 125: el dueño no los vio en el chat que usaba.
 *
 * Se corre el barrido real (`enviar_a_cliente_en_prueba`) con el `avisos-pendientes` de empresa fakeado. El espía
 * de `WhatsappSendService` tiene que quedar en cero para un ensayo.
 */
class AvisoDeCargaDeUnEnsayoEnSuTicketTest extends BaseDeLaPrueba
{
    const UUID  = '4f0e2b1c-9a7d-4c3e-8b21-6d5f0a9c7e12';
    const TEXTO = 'En tu lista hay una columna que se llama «Ubic.». ¿Qué guardás ahí?';

    /** @var \App\Services\WhatsappSendService */
    private $espia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
    }

    private function ensayo(bool $ensayo = true): Client
    {
        $client = $this->cliente_en_prueba();
        $client->es_ensayo = $ensayo;
        $client->save();

        return $client;
    }

    /** La «Prueba gratuita» que abre la bienvenida de un ensayo: exclusiva del sistema. */
    private function prueba_gratuita(Client $client): SupportTicket
    {
        return SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => (int) $client->user_id, 'name' => 'Prueba gratuita',
            'name_source' => 'sistema', 'status' => 'open', 'source' => 'erp', 'opened_at' => now()->subHour(),
            'sistema_exclusivo_at' => now()->subHour(),
        ]);
    }

    /** Otra conversación del sistema (como el 126), nativa del ERP: no exclusiva. */
    private function otra_del_sistema(Client $client, array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'client_id' => $client->id, 'client_user_id' => (int) $client->user_id, 'name' => 'Te mando la lista',
            'status' => 'open', 'source' => 'erp', 'agentes_erp' => true, 'opened_at' => now(),
        ], $extra));
    }

    /** Empresa ofrece el aviso con el ticket de la corrida (o sin ticket) y acepta el sync del mensaje. */
    private function empresa_ofrece(?int $ticket_id): void
    {
        $aviso = [
            'id' => 'importacion_lista:' . self::UUID, 'tipo' => 'importacion_lista', 'uuid' => self::UUID,
            'ai_conversation_id' => null, 'texto' => self::TEXTO, 'resumen' => self::TEXTO,
            'ruta' => '/listado-de-articulos?importacion=' . self::UUID,
            'url' => 'https://ferreteria.comerciocity.com/listado-de-articulos?importacion=' . self::UUID,
        ];
        if ($ticket_id !== null) {
            $aviso['support_ticket_id'] = $ticket_id;
        }
        $this->fakear_http([
            '*/asistente/avisos-pendientes*'   => Http::response(['avisos' => [$aviso]], 200),
            '*/asistente/avisos/avisado'       => Http::response(['ok' => true], 200),
            '*/api/admin-sync/support/messages' => Http::response(['model' => ['id' => 1]], 201),
        ]);
    }

    private function barrer(Client $client): array
    {
        return app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($client);
    }

    /** POSTs de mensajes de Soporte al empresa-api del negocio. */
    private function syncs(): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            if ($par[0]->method() === 'POST' && strpos($par[0]->url(), '/api/admin-sync/support/messages') !== false) {
                $pedidos[] = $par[0];
            }
        }

        return $pedidos;
    }

    private function avisos_en(SupportTicket $ticket)
    {
        return SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->get();
    }

    private function sin_whatsapp(): void
    {
        $this->assertSame([], $this->espia->textos, 'Ningún texto de WhatsApp al ensayo.');
        $this->assertSame([], $this->espia->plantillas, 'Ninguna plantilla de WhatsApp al ensayo.');
    }

    /** @test */
    public function el_aviso_con_el_ticket_de_la_carga_queda_en_esa_conversacion_y_viaja_al_negocio(): void
    {
        $client  = $this->ensayo();
        $prueba  = $this->prueba_gratuita($client);
        $segunda = $this->otra_del_sistema($client);
        $this->empresa_ofrece((int) $segunda->id);

        $resultado = $this->barrer($client);

        $this->assertSame(1, $resultado['enviados'], $resultado['detalle']);
        $this->sin_whatsapp();
        $this->assertCount(0, $this->avisos_en($prueba), 'No cae en la «Prueba gratuita».');
        $avisos = $this->avisos_en($segunda);
        $this->assertCount(1, $avisos);
        $this->assertSame(self::TEXTO, $avisos[0]->body);
        $this->assertTrue((bool) $avisos[0]->is_trial_import_notice);
        $this->assertNotNull($avisos[0]->synced_to_client_at, 'Confirmado por el empresa-api del negocio.');

        // Una conversación NO exclusiva llega al chat del negocio por el push de siempre, con el uuid de ESE ticket.
        $syncs = $this->syncs();
        $this->assertCount(1, $syncs);
        $this->assertSame($segunda->uuid, $syncs[0]['ticket_uuid']);
        $this->assertSame($avisos[0]->uuid, $syncs[0]['message_uuid']);
        $this->assertSame(self::TEXTO, $syncs[0]['body']);
    }

    /** @test */
    public function el_aviso_sin_ticket_va_a_la_prueba_gratuita_como_hoy(): void
    {
        $client  = $this->ensayo();
        $prueba  = $this->prueba_gratuita($client);
        $segunda = $this->otra_del_sistema($client);
        $this->empresa_ofrece(null);

        $this->assertSame(1, $this->barrer($client)['enviados']);

        $this->sin_whatsapp();
        $this->assertCount(0, $this->avisos_en($segunda));
        $avisos = $this->avisos_en($prueba);
        $this->assertCount(1, $avisos);
        $this->assertStringStartsWith(self::TEXTO, $avisos[0]->body);
        $this->assertTrue((bool) $avisos[0]->is_trial_import_notice);
        // Exclusiva: la hace visible la marca (el sistema la lee del admin), sin push al empresa-api.
        $this->assertNotNull($avisos[0]->synced_to_client_at);
        $this->assertCount(0, $this->syncs());
    }

    /**
     * @test
     * @dataProvider tickets_no_elegibles
     */
    public function un_ticket_que_no_es_una_conversacion_del_sistema_abierta_del_dueno_cae_en_la_prueba_gratuita(string $caso): void
    {
        $client = $this->ensayo();
        $prueba = $this->prueba_gratuita($client);

        if ($caso === 'de_otro_cliente') {
            $otro = $this->ensayo();
            $ticket = $this->otra_del_sistema($otro);
        } elseif ($caso === 'cerrado') {
            $ticket = $this->otra_del_sistema($client, ['status' => 'closed', 'closed_at' => now()]);
        } elseif ($caso === 'de_whatsapp') {
            $ticket = $this->ticket_del_cliente($client, true);
        } elseif ($caso === 'de_un_empleado') {
            $ticket = $this->otra_del_sistema($client, ['client_employee_id' => 77]);
        } else {
            $ticket = $this->otra_del_sistema($client, ['client_user_id' => (int) $client->user_id + 1]);
        }
        $this->empresa_ofrece((int) $ticket->id);

        $this->assertSame(1, $this->barrer($client)['enviados']);

        $this->sin_whatsapp();
        $this->assertCount(0, $this->avisos_en($ticket));
        $avisos = $this->avisos_en($prueba);
        $this->assertCount(1, $avisos);
        $this->assertSame(self::TEXTO, $avisos[0]->body);
        $this->assertNotNull($avisos[0]->synced_to_client_at);
        $this->assertCount(0, $this->syncs());
    }

    public function tickets_no_elegibles(): array
    {
        return [
            'de otro cliente'      => ['de_otro_cliente'],
            'cerrado'              => ['cerrado'],
            'de WhatsApp'          => ['de_whatsapp'],
            'de un empleado'       => ['de_un_empleado'],
            'de otro usuario'      => ['de_otro_usuario'],
        ];
    }

    /** @test */
    public function un_cliente_que_no_es_de_ensayo_sigue_igual(): void
    {
        $client  = $this->ensayo(false);
        $prueba  = $this->prueba_gratuita($client);
        $segunda = $this->otra_del_sistema($client);
        $this->empresa_ofrece((int) $segunda->id);

        $this->assertSame(1, $this->barrer($client)['enviados']);

        // La rama del ticket del sistema de siempre (`mandar_texto_al_sistema`): a ese ticket, por el push.
        $this->sin_whatsapp();
        $this->assertCount(0, $this->avisos_en($prueba));
        $this->assertCount(1, $this->avisos_en($segunda));
        $this->assertCount(1, $this->syncs());
    }
}
