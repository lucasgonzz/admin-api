<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Primer mensaje de un ticket de WhatsApp con una plantilla real elegida por el operador.
 *
 * Hasta esta misión, con la ventana de 24hs cerrada, el único camino para el alta era texto libre
 * auto-envuelto en la plantilla oculta de apertura (cc_soporte_apertura). Este archivo cubre el
 * camino nuevo: el operador elige una plantilla del catálogo de client_templates -la misma tabla y
 * el mismo servicio de envío que ya usan las conversaciones existentes vía
 * ClientTemplateController::send_to_ticket_json()- y esa plantilla sale como el primer mensaje del
 * hilo, sin pasar por la plantilla oculta.
 *
 * El envío se sustituye a nivel WhatsappSendService, así que no se toca la red ni Meta; el resto
 * del camino (ruta, validación, resolución de contacto, reuso de ticket, persistencia) es el real.
 */
class PrimerMensajeConPlantillaElegidaTest extends TestCase
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
     * Plantilla de cliente cargada directamente, con dos variables ({{1}} y {{2}}).
     *
     * @param string $template_name Nombre de Meta.
     * @param array  $extra         Columnas a pisar.
     *
     * @return ClientTemplate
     */
    private function crear_plantilla(string $template_name, array $extra = []): ClientTemplate
    {
        return ClientTemplate::create(array_merge([
            'template_name'   => $template_name,
            'language_code'   => 'es_AR',
            'categoria'       => 'avisos',
            'categoria_label' => 'Avisos operativos',
            'categoria_orden' => 2,
            'titulo'          => 'Aviso de mantenimiento',
            'body_template'   => 'Hola {{1}}, te avisamos que el {{2}} vamos a estar actualizando el sistema.',
            'descripcion'     => 'Para avisar una ventana de mantenimiento programada.',
            'variables'       => [
                ['placeholder' => '{{1}}', 'label' => 'Nombre del contacto', 'field' => 'contact_name', 'ai_suggestable' => false],
                ['placeholder' => '{{2}}', 'label' => 'Fecha y hora', 'field' => null, 'ai_suggestable' => false],
            ],
            'activa'          => true,
        ], $extra));
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
     * El camino crítico: con la ventana cerrada, el alta manda la plantilla elegida y no la
     * plantilla oculta de apertura.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_el_alta_manda_la_plantilla_elegida()
    {
        $admin     = $this->crear_admin('plantilla-elegida-critico@test.local');
        $client    = $this->crear_cliente('+5493415551001');
        $plantilla = $this->crear_plantilla('cc_cliente_aviso_critico');
        $espia     = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551001',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan', 'martes 2 de septiembre a las 22hs'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.used_template', true);
        $response->assertJsonPath('whatsapp.template_name', 'cc_cliente_aviso_critico');
        $response->assertJsonPath('whatsapp.delivery', 'sent');

        $this->assertCount(1, $espia->plantillas, 'No se mandó la plantilla elegida.');
        $this->assertCount(0, $espia->textos, 'Se mandó texto libre en vez de la plantilla.');
        $this->assertSame('cc_cliente_aviso_critico', $espia->plantillas[0]['template_name']);
        $this->assertNotContains(
            'cc_soporte_apertura',
            array_column($espia->plantillas, 'template_name'),
            'Se mandó también la plantilla oculta de apertura.'
        );

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket);

        $this->assertSame(
            1,
            SupportMessage::where('support_ticket_id', $ticket->id)->count(),
            'El hilo quedó con más de un mensaje.'
        );

        $message = SupportMessage::where('support_ticket_id', $ticket->id)->first();
        $this->assertSame(
            'Hola Juan, te avisamos que el martes 2 de septiembre a las 22hs vamos a estar actualizando el sistema.',
            $message->body,
            'El body del hilo no quedó con los {{n}} reemplazados.'
        );
        $this->assertNotNull($message->whatsapp_message_id);
    }

    /**
     * Si ya hay un ticket abierto de ese contacto, se reusa en vez de crear otro. Protege que la
     * extracción de resolve_or_create_ticket() no haya cambiado el criterio de reuso.
     *
     * @return void
     */
    public function test_no_duplica_el_hilo_si_ya_hay_un_ticket_abierto_de_ese_contacto()
    {
        $admin     = $this->crear_admin('plantilla-reuso@test.local');
        $client    = $this->crear_cliente('+5493415551002');
        $plantilla = $this->crear_plantilla('cc_cliente_reuso');
        $this->espiar_sender();

        $existente = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415551002',
            'opened_at'      => now()->subHours(3),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551002',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan', 'mañana'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('reused', true);

        $this->assertSame(
            1,
            SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->count(),
            'Se creó un hilo duplicado en vez de reusar el ticket abierto.'
        );
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $existente->id)->count());
    }

    /**
     * Un teléfono que no está en la ficha del cliente se rechaza antes de mandar nada.
     *
     * @return void
     */
    public function test_un_telefono_ajeno_al_cliente_se_rechaza_sin_mandar_nada()
    {
        $admin     = $this->crear_admin('plantilla-telefono-ajeno@test.local');
        $client    = $this->crear_cliente('+5493415551003');
        $plantilla = $this->crear_plantilla('cc_cliente_telefono_ajeno');
        $espia     = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493419999998',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan'],
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
     * Si Meta rechaza la plantilla, el ticket igual queda creado y el mensaje marcado como no
     * entregado, con el botón de reintentar disponible.
     *
     * @return void
     */
    public function test_si_meta_rechaza_la_plantilla_el_ticket_igual_queda_creado()
    {
        $admin     = $this->crear_admin('plantilla-rechazada@test.local');
        $client    = $this->crear_cliente('+5493415551004');
        $plantilla = $this->crear_plantilla('cc_cliente_rechazada');
        $this->espiar_sender(false);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551004',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan', 'mañana'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.delivery', 'failed');
        $this->assertNotNull($response->json('whatsapp.error'), 'No se devolvió el motivo del rechazo.');

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket, 'El ticket se revirtió cuando falló el envío.');

        $message = SupportMessage::where('support_ticket_id', $ticket->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('not_received', $message->remote_delivery_status);
        $this->assertNull($message->whatsapp_message_id);
    }

    /**
     * Una plantilla desactivada no se puede mandar en el alta.
     *
     * @return void
     */
    public function test_una_plantilla_desactivada_no_se_puede_mandar_en_el_alta()
    {
        $admin     = $this->crear_admin('plantilla-desactivada@test.local');
        $client    = $this->crear_cliente('+5493415551005');
        $plantilla = $this->crear_plantilla('cc_cliente_desactivada', ['activa' => false]);
        $espia     = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551005',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan'],
        ]);

        $response->assertStatus(422);

        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
    }

    /**
     * A un cliente dado de baja no se le abre conversación ni con plantilla: el webhook no lo
     * reconoce en ninguna de sus tres formas.
     *
     * @return void
     */
    public function test_no_se_le_abre_conversacion_con_plantilla_a_un_cliente_inactivo()
    {
        $admin             = $this->crear_admin('plantilla-cliente-inactivo@test.local');
        $client            = $this->crear_cliente('+5493415551006');
        $client->is_active = false;
        $client->save();
        $plantilla = $this->crear_plantilla('cc_cliente_inactivo');
        $espia     = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551006',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan'],
        ]);

        $response->assertStatus(422);

        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
    }

    /**
     * Las variables viajan saneadas (sin saltos de línea) y en el orden en que las cargó el
     * operador: el array es posicional.
     *
     * @return void
     */
    public function test_las_variables_viajan_saneadas_y_en_orden()
    {
        $admin     = $this->crear_admin('plantilla-variables-saneadas@test.local');
        $client    = $this->crear_cliente('+5493415551007');
        $plantilla = $this->crear_plantilla('cc_cliente_variables_saneadas');
        $espia     = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551007',
            'client_template_id' => $plantilla->id,
            'variables'          => ['Juan', "martes 2 de septiembre\na las 22hs"],
        ]);

        $response->assertStatus(201);

        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('Juan', $espia->plantillas[0]['variables'][0]);
        $this->assertSame(
            'martes 2 de septiembre a las 22hs',
            $espia->plantillas[0]['variables'][1],
            'El salto de línea no se aplanó antes de mandarlo a Meta.'
        );
    }

    /**
     * Mandar body y client_template_id juntos en la misma alta se rechaza: o texto libre, o
     * plantilla, nunca las dos cosas.
     *
     * @return void
     */
    public function test_el_alta_con_body_y_plantilla_juntos_se_rechaza()
    {
        $admin     = $this->crear_admin('plantilla-body-junto@test.local');
        $client    = $this->crear_cliente('+5493415551008');
        $plantilla = $this->crear_plantilla('cc_cliente_body_junto');
        $espia     = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'          => $client->id,
            'source'             => 'whatsapp',
            'whatsapp_phone'     => '+5493415551008',
            'client_template_id' => $plantilla->id,
            'body'               => 'Además quiero escribir esto.',
            'variables'          => ['Juan'],
        ]);

        $response->assertStatus(422);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
    }

    /**
     * Smoke de regresión: sin client_template_id, el texto libre con la ventana cerrada sigue
     * saliendo por la plantilla oculta de apertura de siempre. Redundante a propósito con
     * AperturaDeTicketPorWhatsappTest: si mañana alguien toca este camino, el rojo aparece al lado
     * del cambio.
     *
     * @return void
     */
    public function test_el_texto_libre_con_la_ventana_cerrada_sigue_saliendo_por_la_plantilla_de_apertura()
    {
        $admin  = $this->crear_admin('smoke-texto-libre@test.local');
        $client = $this->crear_cliente('+5493415551009');
        $espia  = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $client->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493415551009',
            'body'           => 'Te escribo por una actualización pendiente.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('whatsapp.used_template', true);

        $this->assertCount(1, $espia->plantillas, 'No se envió la plantilla de apertura.');
        $this->assertSame('cc_soporte_apertura', $espia->plantillas[0]['template_name']);
        $this->assertCount(0, $espia->textos);
    }
}
