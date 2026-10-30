<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportAiSettings;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La config global de Cuenta que decide con qué régimen NACE un ticket de soporte.
 *
 * Lo que se prueba acá no es que la clave se guarde —eso es lo fácil— sino las dos cosas que se
 * rompen en silencio si alguien "simplifica" el mecanismo:
 *
 *   1. Que el ticket lea `true` sobre la MISMA instancia que devuelve `create()`. La base tiene
 *      un default `true`, pero `create()` devuelve el modelo con lo que se le pasó, no con lo que
 *      la base rellenó: si el atributo queda en null, `(bool) null` es false y el ticket nace
 *      autónomo justo en el camino de mandarle algo al cliente sin que nadie lo lea.
 *   2. Que mover la config NO le cambie el régimen a ningún ticket ya abierto. Un ticket decide
 *      su modo una sola vez, al nacer; después solo lo cambia una persona con el botón del
 *      encabezado (decisión de Lucas). Leer la config en runtime dejaría contestando solos a
 *      tickets que un operador ya venía verificando a mano.
 *
 * Cada test fija la clave (o borra la fila) antes de crear nada: la base de testing del slot es
 * MySQL persistente y estos tests usan DatabaseTransactions, así que asumir estado ambiente sería
 * heredar lo que dejó la última corrida.
 */
class VerificacionGlobalDelAgenteDeSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Corta cualquier salida HTTP real.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Operador de soporte.
     *
     * @param string $email Email único.
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
     * Cliente activo con teléfono.
     *
     * @param string $phone Teléfono de la ficha, en E.164.
     *
     * @return Client
     */
    private function crear_cliente(string $phone = '+5493416660001'): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = $phone;
        $client->is_active = true;
        $client->api_url   = 'https://api-cliente-verificacion-global.test';
        $client->api_key   = 'clave-de-prueba';
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto, con un mensaje entrante reciente del cliente.
     *
     * El entrante deja la ventana de 24hs abierta, así el envío sale como texto libre y no como
     * plantilla: acá lo que se mide es el régimen del ticket, no el canal.
     *
     * @param Client $client Cliente dueño.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => $client->phone,
            'opened_at'        => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => '¿Cómo cargo una nota de crédito?',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Sustituye WhatsappSendService por un espía.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                ];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Sustituye el servicio que llama a Claude por uno que devuelve lo que se le indique.
     *
     * @param array<string, mixed> $resultado Lo que devuelve generate().
     *
     * @return SupportAiSuggestionService
     */
    private function espiar_claude(array $resultado): SupportAiSuggestionService
    {
        $espia = new class extends SupportAiSuggestionService {
            /** @var int Cantidad de veces que se consultó a Claude. */
            public $llamadas = 0;

            /** @var array<string, mixed> Respuesta simulada. */
            public $resultado = [];

            public function generate(SupportTicket $ticket): array
            {
                $this->llamadas++;

                return $this->resultado;
            }
        };

        $espia->resultado = $resultado;
        $this->app->instance(SupportAiSuggestionService::class, $espia);

        return $espia;
    }

    /**
     * Respuesta típica del agente, sin escalado ni cierre.
     *
     * @param string $mensaje Texto sugerido.
     *
     * @return array<string, mixed>
     */
    private function respuesta_del_agente(string $mensaje): array
    {
        return [
            'suggested_message' => $mensaje,
            'reasoning'         => 'Está en el manual.',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
        ];
    }

    /**
     * Corre el job del agente sobre un ticket.
     *
     * @param SupportTicket $ticket Ticket a procesar.
     *
     * @return void
     */
    private function correr_agente(SupportTicket $ticket): void
    {
        // En los tests la cola es `sync`, así que el propio scheduler ejecuta el job en el acto:
        // despacharlo otra vez a mano lo correría dos veces y duplicaría cada envío.
        app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $ticket->id);
    }

    /**
     * Fija la clave global del régimen de nacimiento.
     *
     * @param bool $prendida True para que los tickets nuevos nazcan pidiendo verificación.
     *
     * @return void
     */
    private function fijar_verificacion_global(bool $prendida): void
    {
        AdminSetting::set(SupportAiSettings::KEY_REQUIRE_VERIFICATION, $prendida ? '1' : '0');
    }

    /**
     * Borra la fila de la clave global para simular una instalación que nunca tocó el panel.
     *
     * @return void
     */
    private function borrar_verificacion_global(): void
    {
        AdminSetting::where('key', SupportAiSettings::KEY_REQUIRE_VERIFICATION)->delete();
    }

    /* ==================================================================================
     * La clave en el panel de Cuenta
     * ================================================================================== */

    /** La Cuenta informa el default automático del pedido del 30/10/2026. */
    public function test_el_get_informa_tickets_nuevos_automaticos()
    {
        $admin = $this->crear_admin('get-verificacion-global@test.local');
        $this->borrar_verificacion_global();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/support-ai')
            ->assertOk()->assertJsonPath('require_verification', false)
            ->assertJsonStructure(['suggestions_enabled', 'suggestion_delay', 'auto_send_delay', 'require_verification']);
    }

    /**
     * El PUT persiste el régimen sin pisar las dos demoras que viajan en el mismo payload.
     *
     * @return void
     */
    public function test_el_put_persiste_la_verificacion_sin_pisar_las_demoras()
    {
        $admin = $this->crear_admin('put-verificacion-global@test.local');
        $this->fijar_verificacion_global(true);

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings/support-ai', [
            'suggestions_enabled'  => true,
            'suggestion_delay'     => 45,
            'auto_send_delay'      => 90,
            'require_verification' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('require_verification', false);
        $response->assertJsonPath('suggestion_delay', 45);
        $response->assertJsonPath('auto_send_delay', 90);

        $this->assertSame('0', AdminSetting::get(SupportAiSettings::KEY_REQUIRE_VERIFICATION));
        $this->assertSame(45, SupportAiSettings::get_suggestion_delay_seconds());
        $this->assertSame(90, SupportAiSettings::get_auto_send_delay_seconds());
        $this->assertTrue(SupportAiSettings::is_suggestions_enabled());
    }

    /** Un SPA anterior puede guardar demoras, sin restaurar la herencia global retirada. */
    public function test_put_anterior_no_reactiva_la_verificacion_global()
    {
        $admin = $this->crear_admin('put-viejo-verificacion@test.local');
        foreach ([true, false] as $previous) {
            $this->fijar_verificacion_global($previous);
            $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings/support-ai', [
                'suggestions_enabled' => true, 'suggestion_delay' => 10, 'auto_send_delay' => 20,
            ])->assertOk()->assertJsonPath('require_verification', false);
            $this->assertSame('0', AdminSetting::get(SupportAiSettings::KEY_REQUIRE_VERIFICATION));
        }
    }

    /** Ningún residuo de configuración global habilita verificación en otro chat. */
    public function test_valores_anteriores_no_cambian_el_default_automatico()
    {
        foreach (['1', 'true', ' ', 'si', '2', '-1', 'null', 'basura'] as $value) {
            AdminSetting::set(SupportAiSettings::KEY_REQUIRE_VERIFICATION, $value);
            $this->assertFalse(SupportAiSettings::new_ticket_requires_verification());
        }
    }

    /**
     * Apagar la verificación se dice sin ambigüedad, y estas son todas las formas de decirlo.
     *
     * El espejo del anterior: el fail-safe no puede ser tan estricto que el '0' del controller
     * —o el 'false' que escribe una persona a mano— deje de apagar nada.
     *
     * @return void
     */
    public function test_los_valores_explicitos_de_apagado_apagan_la_verificacion()
    {
        $apagados = ['0', ' 0 ', 'false', 'FALSE', 'off', 'no', 'NO'];

        foreach ($apagados as $valor) {
            AdminSetting::set(SupportAiSettings::KEY_REQUIRE_VERIFICATION, $valor);

            $this->assertFalse(
                SupportAiSettings::new_ticket_requires_verification(),
                'El valor ' . var_export($valor, true) . ' tendría que apagar la verificación y no lo hizo.'
            );
        }
    }

    /* ==================================================================================
     * El sello al nacer
     * ================================================================================== */

    /** La instancia devuelta por create debe expresar inmediatamente el default automático. */
    public function test_un_ticket_nuevo_lee_false_en_la_misma_instancia_que_devuelve_create()
    {
        $client = $this->crear_cliente();
        $this->fijar_verificacion_global(true);

        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => $client->phone,
            'opened_at'        => now(),
        ]);

        $this->assertFalse(
            $ticket->requiere_verificacion_mensajes,
            'La instancia que devolvió create() no trae el régimen sellado.'
        );
        $this->assertNotNull(
            $ticket->getAttributes()['requiere_verificacion_mensajes'],
            'El atributo quedó en null: (bool) null es false y el ticket nacería autónomo.'
        );
    }

    /** La ausencia de configuración también crea tickets automáticos. */
    public function test_sin_fila_en_admin_settings_el_ticket_nace_automatico()
    {
        $client = $this->crear_cliente();
        $this->borrar_verificacion_global();

        $ticket = $this->crear_ticket($client);

        $this->assertFalse($ticket->requiere_verificacion_mensajes, 'Sin config, el ticket tiene que nacer automático.');
        $this->assertFalse((bool) $ticket->fresh()->requiere_verificacion_mensajes, 'En la base quedó otra cosa.');
    }

    /**
     * Con la config apagada, el ticket nuevo nace autónomo y su respuesta sale sola.
     *
     * @return void
     */
    public function test_con_la_config_apagada_el_ticket_nuevo_nace_autonomo()
    {
        $client = $this->crear_cliente();
        $this->fijar_verificacion_global(false);

        $ticket = $this->crear_ticket($client);

        $this->assertFalse($ticket->requiere_verificacion_mensajes, 'El ticket nació verificado con la config apagada.');
        $this->assertFalse((bool) $ticket->fresh()->requiere_verificacion_mensajes, 'En la base quedó otra cosa.');

        $espia = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas.'));
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, 0);

        $this->correr_agente($ticket);

        $this->assertCount(1, $espia->textos, 'El ticket autónomo no le contestó al cliente.');
        $this->assertSame('Se carga desde Ventas.', $espia->textos[0]['body']);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->first();

        $this->assertNull($borrador, 'Quedó un borrador esperando aprobación en un ticket autónomo.');
    }

    /**
     * 🔴 Apagar la config no cambia el régimen de los tickets ya abiertos.
     *
     * Es la decisión de producto escrita como test: si el job leyera la config global en runtime,
     * apagar el interruptor en Cuenta pondría a contestar solos a tickets que un operador ya venía
     * verificando a mano, sin que nadie lo haya pedido ticket por ticket.
     *
     * @return void
     */
    public function test_apagar_la_config_no_cambia_los_tickets_ya_abiertos()
    {
        $client = $this->crear_cliente();
        $this->fijar_verificacion_global(true);

        $ticket = $this->crear_ticket($client);
        $admin = $this->crear_admin('verificado-individual@test.local');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-requiere-verificacion')->assertOk();
        $ticket->refresh();

        $this->fijar_verificacion_global(false);

        $espia = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas, botón Nota de crédito.'));
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, 0);

        $this->correr_agente($ticket);

        $this->assertCount(0, $espia->textos, 'Apagar la config global mandó sola la respuesta de un ticket ya abierto.');
        $this->assertCount(0, $espia->plantillas);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->first();

        $this->assertNotNull($borrador, 'No quedó el borrador esperando aprobación.');
        $this->assertNull($borrador->ai_auto_send_at, 'El borrador quedó con fecha de autoenvío: se va a mandar solo.');
    }

    /**
     * Prender la config tampoco cambia el régimen de los tickets ya abiertos.
     *
     * El espejo del anterior. Sin él, un hook que leyera la config en runtime pasaría el test de
     * arriba por el lado seguro y nadie se enteraría de que el régimen sí se relee.
     *
     * @return void
     */
    public function test_prender_la_config_no_cambia_los_tickets_ya_abiertos()
    {
        $client = $this->crear_cliente();
        $this->fijar_verificacion_global(false);

        $ticket = $this->crear_ticket($client);

        $this->fijar_verificacion_global(true);

        $espia = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas.'));
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, 0);

        $this->correr_agente($ticket);

        $this->assertCount(1, $espia->textos, 'Prender la config global frenó un ticket que había nacido autónomo.');
        $this->assertSame('Se carga desde Ventas.', $espia->textos[0]['body']);
    }

    /**
     * El botón del encabezado le gana a la config global.
     *
     * Es la única forma de cambiarle el régimen a un ticket ya abierto: una persona, ticket por
     * ticket, y con la config global sin tocar.
     *
     * @return void
     */
    public function test_el_boton_del_ticket_le_gana_a_la_config_global()
    {
        $admin  = $this->crear_admin('boton-le-gana-a-la-global@test.local');
        $client = $this->crear_cliente();
        $this->fijar_verificacion_global(false);

        $ticket = $this->crear_ticket($client);
        $this->assertFalse($ticket->requiere_verificacion_mensajes, 'El ticket no nació autónomo.');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/toggle-requiere-verificacion')
            ->assertStatus(200);

        $this->assertTrue((bool) $ticket->fresh()->requiere_verificacion_mensajes, 'El botón no prendió la verificación.');

        $espia = $this->espiar_sender();
        $this->espiar_claude($this->respuesta_del_agente('Se carga desde Ventas.'));
        AdminSetting::set(SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS, 0);

        $this->correr_agente($ticket);

        $this->assertCount(0, $espia->textos, 'Salió el mensaje pese al botón prendido en el ticket.');
        $this->assertSame('0', AdminSetting::get(SupportAiSettings::KEY_REQUIRE_VERIFICATION), 'El botón del ticket tocó la config global.');

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->first();

        $this->assertNotNull($borrador, 'No quedó borrador esperando aprobación.');
    }

    /** Un alta heredada no sustituye el botón que activa aprobación en cada ticket. */
    public function test_un_create_heredado_con_flag_true_tambien_nace_automatico()
    {
        $client = $this->crear_cliente();
        $this->fijar_verificacion_global(false);

        $ticket = SupportTicket::create([
            'client_id'                      => $client->id,
            'client_user_id'                 => 0,
            'client_user_name'               => 'Contacto',
            'status'                         => 'open',
            'source'                         => 'whatsapp',
            'whatsapp_phone'                 => $client->phone,
            'opened_at'                      => now(),
            'requiere_verificacion_mensajes' => true,
        ]);

        $this->assertFalse($ticket->requiere_verificacion_mensajes, 'La creación no puede reemplazar el botón del ticket.');
        $this->assertFalse((bool) $ticket->fresh()->requiere_verificacion_mensajes, 'En la base quedó otra cosa.');
    }

    /** Las aperturas HTTP nunca heredan aprobación de la configuración de Cuenta. */
    public function test_todas_las_aperturas_http_nacen_automaticas()
    {
        $admin = $this->crear_admin('apertura-hereda-la-global@test.local');

        // Un solo espía para las dos aperturas: el id de mensaje que devuelve lleva un contador, y
        // registrar un espía nuevo lo reiniciaría en 1, chocando contra el índice único de
        // `support_messages.whatsapp_message_id` en la segunda apertura.
        $this->espiar_sender();

        $cliente_autonomo = $this->crear_cliente('+5493416660011');
        $this->fijar_verificacion_global(false);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $cliente_autonomo->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493416660011',
            'body'           => 'Te escribo por la actualización pendiente.',
        ])->assertStatus(201);

        $ticket_autonomo = SupportTicket::where('client_id', $cliente_autonomo->id)->firstOrFail();
        $this->assertFalse(
            (bool) $ticket_autonomo->requiere_verificacion_mensajes,
            'La conversación abierta desde la bandeja no heredó la config apagada.'
        );

        $cliente_verificado = $this->crear_cliente('+5493416660012');
        $this->fijar_verificacion_global(true);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id'      => $cliente_verificado->id,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493416660012',
            'body'           => 'Te escribo por la actualización pendiente.',
        ])->assertStatus(201);

        $ticket_verificado = SupportTicket::where('client_id', $cliente_verificado->id)->firstOrFail();
        $this->assertFalse(
            (bool) $ticket_verificado->requiere_verificacion_mensajes,
            'La conversación nueva heredó indebidamente la configuración global.'
        );
    }
}
