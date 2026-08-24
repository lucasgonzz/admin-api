<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Apertura de una conversación de soporte por WhatsApp desde la bandeja del admin.
 *
 * Hasta esta misión un ticket de WhatsApp solo podía nacer si el cliente escribía primero.
 * Lo que se verifica acá es lo que ningún camino feliz mira: que el alta vieja (canal ERP)
 * siga comportándose exactamente igual, que el canal WhatsApp NUNCA replique el ticket al
 * empresa-api del cliente, y que la elección entre texto libre y plantilla dependa de la
 * ventana de 24hs de Meta y no de lo que le parezca al operador.
 *
 * El envío se sustituye a nivel WhatsappSendService, así que no se toca la red ni Meta; el
 * resto del camino (ruta, validación, resolución de contacto, persistencia) es el real.
 */
class AperturaDeTicketPorWhatsappTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Evita que cualquier POST hacia el empresa-api del cliente salga de verdad.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Admin operador de soporte.
     *
     * @param string $email Email único del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente activo con teléfono y api_url cargados.
     *
     * @param string $phone Teléfono de la ficha.
     *
     * @return Client
     */
    private function crear_cliente(string $phone = '+5493411111111'): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = $phone;
        $client->is_active = true;
        $client->api_url   = 'https://api-cliente-de-prueba.test';
        $client->api_key   = 'clave-de-prueba';
        $client->save();

        return $client;
    }

    /**
     * Empleado del cliente con teléfono propio.
     *
     * @param Client $client Cliente dueño.
     * @param string $phone  Teléfono del empleado.
     *
     * @return ClientEmployee
     */
    private function crear_empleado(Client $client, string $phone): ClientEmployee
    {
        $employee            = new ClientEmployee();
        $employee->client_id = $client->id;
        $employee->name      = 'Brisa';
        $employee->phone     = $phone;
        $employee->save();

        return $employee;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra los envíos.
     *
     * @param bool $confirma True: devuelve un whatsapp_message_id. False: simula rechazo de Meta.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(bool $confirma = true): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            /** @var bool Si el envío se confirma o no. */
            public $confirma = true;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                    'language_code' => $language_code,
                ];

                if (! $this->confirma) {
                    $this->last_send_error = 'La plantilla no existe en Meta (simulado en el test).';

                    return null;
                }

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Deja la ventana de 24hs abierta con un entrante de soporte reciente de ese número.
     *
     * @param Client $client Cliente dueño del ticket.
     * @param string $phone  Teléfono normalizado del contacto.
     *
     * @return void
     */
    private function abrir_ventana_por_soporte(Client $client, string $phone): void
    {
        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'closed',
            'source'         => 'whatsapp',
            'whatsapp_phone' => $phone,
            'opened_at'      => now()->subDay(),
            'closed_at'      => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Hola, tengo una duda',
            'delivered_at'      => now()->subHour(),
        ]);
    }

    /**
     * El alta sin parámetros nuevos sigue creando un ticket del canal ERP y replicándolo.
     *
     * Es la garantía de compatibilidad hacia atrás: el modal que ya está en producción no
     * manda `source`, y tiene que seguir funcionando igual que antes de esta misión.
     *
     * @return void
     */
    public function test_el_alta_sin_source_sigue_creando_un_ticket_del_erp()
    {
        $admin  = $this->crear_admin('erp-intacto@test.local');
        $client = $this->crear_cliente();
        $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'        => $client->id,
            'client_user_id'   => 7,
            'client_user_name' => 'Usuario del ERP',
            'name'             => 'Consulta de prueba',
        ]);

        $response->assertStatus(201);

        $ticket = SupportTicket::where('client_id', $client->id)->first();

        $this->assertNotNull($ticket, 'No se creó el ticket del canal ERP.');
        $this->assertSame('erp', $ticket->source, 'El ticket sin source dejó de ser del canal ERP.');
        $this->assertNull($ticket->whatsapp_phone);

        Http::assertSentCount(1);
    }

    /**
     * Con la ventana abierta el texto del operador sale tal cual, sin plantilla.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_manda_texto_libre()
    {
        $admin  = $this->crear_admin('ventana-abierta@test.local');
        $client = $this->crear_cliente('+5493415550001');
        $this->abrir_ventana_por_soporte($client, '+5493415550001');
        $espia = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '3415550001',
            'body'           => 'Te aviso que ya quedó resuelto lo del stock.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.used_template', false);
        $response->assertJsonPath('whatsapp.delivery', 'sent');

        $this->assertCount(1, $espia->textos, 'No se envió el texto libre.');
        $this->assertCount(0, $espia->plantillas, 'Se usó plantilla con la ventana abierta.');
        $this->assertSame('Te aviso que ya quedó resuelto lo del stock.', $espia->textos[0]['body']);

        $ticket = SupportTicket::where('client_id', $client->id)->where('status', 'open')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('whatsapp', $ticket->source);
        $this->assertSame('+5493415550001', $ticket->whatsapp_phone, 'El teléfono no quedó normalizado a E.164.');
    }

    /**
     * Sin entrantes recientes se manda la plantilla, con las tres variables y el body legible.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_manda_la_plantilla()
    {
        $admin  = $this->crear_admin('ventana-cerrada@test.local');
        $client = $this->crear_cliente('+5493415550002');
        $espia  = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550002',
            'body'           => "Te escribo por la actualización\nque quedó pendiente.",
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.used_template', true);
        $response->assertJsonPath('whatsapp.delivery', 'sent');

        $this->assertCount(0, $espia->textos, 'Se mandó texto libre con la ventana cerrada.');
        $this->assertCount(1, $espia->plantillas, 'No se envió la plantilla.');

        $envio = $espia->plantillas[0];
        $this->assertSame('cc_soporte_apertura', $envio['template_name']);
        $this->assertSame('es_AR', $envio['language_code']);
        $this->assertCount(3, $envio['variables'], 'La plantilla no recibió las tres variables.');
        $this->assertSame('Lucas', $envio['variables'][1], 'La variable 2 no es el nombre del operador.');
        $this->assertStringNotContainsString(
            "\n",
            $envio['variables'][2],
            'La variable con el texto del operador viaja con salto de línea y Meta la rechaza.'
        );

        $message = SupportMessage::whereNotNull('whatsapp_message_id')->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString(
            'del equipo de soporte de ComercioCity',
            (string) $message->body,
            'El body guardado no es el texto completo que recibe el cliente.'
        );
    }

    /**
     * Un entrante reciente por el canal de leads también abre la ventana.
     *
     * La ventana de Meta es por par de números, no por canal, y leads y soporte comparten la
     * misma configuración de WhatsApp.
     *
     * @return void
     */
    public function test_un_entrante_de_leads_del_mismo_numero_abre_la_ventana()
    {
        $admin  = $this->crear_admin('ventana-por-leads@test.local');
        $client = $this->crear_cliente('+5493415550003');

        $lead               = new Lead();
        $lead->contact_name = 'Contacto que ya escribió';
        $lead->company_name = 'Distribuidora de prueba';
        $lead->phone        = '+5493415550003';
        $lead->status       = 'contactado';
        $lead->save();

        $message           = new LeadMessage();
        $message->lead_id  = $lead->id;
        $message->sender   = 'lead';
        $message->content  = 'Buenas, una consulta';
        $message->save();

        $espia = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550003',
            'body'           => 'Seguimos por acá.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.used_template', false);
        $this->assertCount(1, $espia->textos, 'No se aprovechó la ventana abierta por el canal de leads.');
    }

    /**
     * Un número que no está en la ficha del cliente se rechaza antes de enviar nada.
     *
     * Si saliera igual, la respuesta del cliente caería en el pipeline de leads porque el
     * webhook no tiene forma de reconocer ese número como suyo.
     *
     * @return void
     */
    public function test_un_telefono_ajeno_al_cliente_se_rechaza()
    {
        $admin  = $this->crear_admin('telefono-ajeno@test.local');
        $client = $this->crear_cliente('+5493415550004');
        $espia  = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493419999999',
            'body'           => 'Hola',
        ]);

        $response->assertStatus(422);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(
            0,
            SupportTicket::where('client_id', $client->id)->count(),
            'Se creó un ticket con un teléfono que no pertenece al cliente.'
        );
    }

    /**
     * El teléfono de un empleado abre el ticket con client_employee_id.
     *
     * Es la misma regla que aplica el webhook: si el ticket no queda atado al empleado, la
     * respuesta del empleado abre un hilo aparte y la conversación queda partida en dos.
     *
     * @return void
     */
    public function test_el_telefono_de_un_empleado_ata_el_ticket_al_empleado()
    {
        $admin    = $this->crear_admin('empleado@test.local');
        $client   = $this->crear_cliente('+5493415550005');
        $employee = $this->crear_empleado($client, '+5493415550006');
        $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550006',
            'body'           => 'Hola Brisa',
        ]);

        $response->assertStatus(201);

        $ticket = SupportTicket::where('client_id', $client->id)->where('status', 'open')->first();
        $this->assertNotNull($ticket);
        $this->assertSame((int) $employee->id, (int) $ticket->client_employee_id);
    }

    /**
     * Si ya hay un ticket abierto de ese contacto, se reusa en vez de crear otro.
     *
     * @return void
     */
    public function test_no_duplica_el_hilo_si_ya_hay_un_ticket_abierto()
    {
        $admin  = $this->crear_admin('reuso@test.local');
        $client = $this->crear_cliente('+5493415550007');
        $this->espiar_sender();

        $existente = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550007',
            'opened_at'      => now()->subHours(3),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550007',
            'body'           => 'Te sumo un dato más.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('reused', true);

        $this->assertSame(
            1,
            SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->count(),
            'Se creó un hilo duplicado en vez de reusar el ticket abierto.'
        );
        $this->assertSame(
            1,
            SupportMessage::where('support_ticket_id', $existente->id)->count()
        );
    }

    /**
     * Si Meta rechaza el envío, el ticket queda creado y el mensaje marcado como no entregado.
     *
     * Revertir borraría un hilo que puede haber salido igual, y dejaría al operador sin
     * ningún lugar desde donde reintentar.
     *
     * @return void
     */
    public function test_si_el_envio_falla_el_ticket_igual_queda_creado()
    {
        $admin  = $this->crear_admin('envio-fallido@test.local');
        $client = $this->crear_cliente('+5493415550008');
        $this->espiar_sender(false);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550008',
            'body'           => 'Hola, te escribo del soporte.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.delivery', 'failed');

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket, 'El ticket se revirtió cuando falló el envío.');

        $message = SupportMessage::where('support_ticket_id', $ticket->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('not_received', $message->remote_delivery_status);
        $this->assertNull($message->whatsapp_message_id);
    }

    /**
     * El canal WhatsApp nunca replica el ticket al empresa-api del cliente.
     *
     * @return void
     */
    public function test_el_canal_whatsapp_no_toca_el_erp_del_cliente()
    {
        $admin  = $this->crear_admin('sin-sync@test.local');
        $client = $this->crear_cliente('+5493415550009');
        $this->espiar_sender();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415550009',
            'body'           => 'Hola',
        ])->assertStatus(201);

        Http::assertNothingSent();
    }
}
