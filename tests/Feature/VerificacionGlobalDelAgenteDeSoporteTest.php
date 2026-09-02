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

    /**
     * Sin fila en admin_settings, el GET dice que la verificación está prendida.
     *
     * @return void
     */
    public function test_el_get_devuelve_la_verificacion_prendida_cuando_nadie_toco_la_config()
    {
        $admin = $this->crear_admin('get-verificacion-global@test.local');
        $this->borrar_verificacion_global();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/support-ai');

        $response->assertStatus(200);
        $response->assertJsonPath('require_verification', true);

        // Las tres claves viejas tienen que seguir viajando: el panel las lee del mismo payload.
        $response->assertJsonStructure(['suggestions_enabled', 'suggestion_delay', 'auto_send_delay', 'require_verification']);
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

    /**
     * Un PUT sin el campo nuevo no da vuelta el régimen, en NINGUNA de las dos direcciones.
     *
     * Es el `nullable` del controller: un build viejo del SPA, cacheado en el navegador de un
     * operador, guarda las demoras sin mandar `require_verification`. Con `required` no podría
     * guardar nada; sin la guarda, guardar las demoras movería el régimen sin que nadie lo pida.
     *
     * 🔴 Las dos direcciones, y la que importa es la SEGUNDA. Con la fila en '1' el test pasa
     * igual con una implementación equivocada como `(bool) ($validated['require_verification'] ??
     * true)`, porque el valor esperado coincide con el default de la clase: la aserción no
     * distingue "respetó lo que había" de "cayó en el default". Con la fila en '0' se separan, y
     * además es el caso de la vida real — la instalación donde Lucas ya apagó el régimen a
     * propósito y un SPA cacheado no lo tiene que volver a prender solo.
     *
     * @return void
     */
    public function test_un_put_viejo_sin_el_campo_no_da_vuelta_el_regimen()
    {
        $admin = $this->crear_admin('put-viejo-verificacion@test.local');

        $put_viejo = function () use ($admin) {
            return $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings/support-ai', [
                'suggestions_enabled' => true,
                'suggestion_delay'    => 10,
                'auto_send_delay'     => 20,
            ]);
        };

        $this->fijar_verificacion_global(true);

        $response = $put_viejo();
        $response->assertStatus(200);
        $response->assertJsonPath('require_verification', true);
        $this->assertSame(
            '1',
            AdminSetting::get(SupportAiSettings::KEY_REQUIRE_VERIFICATION),
            'Un PUT sin el campo apagó un régimen que estaba prendido.'
        );

        $this->fijar_verificacion_global(false);

        $response = $put_viejo();
        $response->assertStatus(200);
        $response->assertJsonPath('require_verification', false);
        $this->assertSame(
            '0',
            AdminSetting::get(SupportAiSettings::KEY_REQUIRE_VERIFICATION),
            'Un PUT sin el campo volvió a prender un régimen que Lucas había apagado a propósito.'
        );
    }

    /**
     * 🔴 Un valor raro en la fila deja la verificación PRENDIDA, nunca apagada.
     *
     * Es la guarda del fail-safe del getter. El escritor normal es el controller, que normaliza a
     * '1'/'0', así que estos valores solo aparecen si una persona escribió la fila a mano en un
     * incidente: un UPDATE por consola, una remediación por SSH, un import. Un
     * `filter_var($raw, FILTER_VALIDATE_BOOLEAN)` pelado manda todos estos a false —o sea, a
     * "contestale solo al cliente"—, que es el error que no se puede deshacer. Si alguien
     * "simplifica" el getter a filter_var(), este test se pone rojo.
     *
     * @return void
     */
    public function test_un_valor_raro_en_la_fila_deja_la_verificacion_prendida()
    {
        $ambiguos = [' ', "	", '', 'si', 'yes', '2', '-1', 'null', 'basura'];

        foreach ($ambiguos as $valor) {
            AdminSetting::set(SupportAiSettings::KEY_REQUIRE_VERIFICATION, $valor);

            $this->assertTrue(
                SupportAiSettings::new_ticket_requires_verification(),
                'El valor ' . var_export($valor, true) . ' apagó la verificación: un valor que nadie sabe leer tiene que caer para el lado seguro.'
            );
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

    /**
     * 🔴 El ticket recién creado lee true sobre la MISMA instancia que devolvió create().
     *
     * Este es el test del agujero de `(bool) null`: la base tiene default true, pero `create()`
     * devuelve el modelo con lo que se le pasó, no con lo que la base rellenó. Si el hook se
     * "simplificara" volviendo temprano sin escribir el atributo, el flag quedaría en null, el
     * casteo lo daría vuelta a false y el agente le contestaría al cliente sin que nadie lea.
     * Si este test se rompe, no se mergea.
     *
     * @return void
     */
    public function test_un_ticket_nuevo_lee_true_en_la_misma_instancia_que_devuelve_create()
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

        $this->assertTrue(
            $ticket->requiere_verificacion_mensajes,
            'La instancia que devolvió create() no trae el régimen sellado.'
        );
        $this->assertNotNull(
            $ticket->getAttributes()['requiere_verificacion_mensajes'],
            'El atributo quedó en null: (bool) null es false y el ticket nacería autónomo.'
        );
    }

    /**
     * Sin fila en admin_settings, el ticket nace pidiendo verificación.
     *
     * @return void
     */
    public function test_sin_fila_en_admin_settings_el_ticket_nace_verificado()
    {
        $client = $this->crear_cliente();
        $this->borrar_verificacion_global();

        $ticket = $this->crear_ticket($client);

        $this->assertTrue($ticket->requiere_verificacion_mensajes, 'Sin config, el ticket no nació verificado.');
        $this->assertTrue((bool) $ticket->fresh()->requiere_verificacion_mensajes, 'En la base quedó otra cosa.');
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

    /**
     * Un create que elige el régimen a mano no lo pisa la config global.
     *
     * Cubre la rama `array_key_exists` del hook, de la que depende el montaje explícito de
     * AgentesSoloLoExplicitoTest.
     *
     * @return void
     */
    public function test_un_create_con_el_flag_explicito_no_lo_pisa_la_config()
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

        $this->assertTrue($ticket->requiere_verificacion_mensajes, 'La config global pisó una elección explícita.');
        $this->assertTrue((bool) $ticket->fresh()->requiere_verificacion_mensajes, 'En la base quedó otra cosa.');
    }

    /**
     * Una conversación abierta desde la bandeja hereda la config vigente al momento de abrirla.
     *
     * Es el único de punta a punta por HTTP: los otros puntos de creación (webhook, inbound del
     * ERP) pasan por el mismo `SupportTicket::create()` y quedan cubiertos a nivel modelo. Este
     * verifica que el camino real —ruta, validación, resolución de contacto y opener— no se saltee
     * el hook.
     *
     * @return void
     */
    public function test_una_conversacion_abierta_desde_el_admin_hereda_la_config_vigente()
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
        $this->assertTrue(
            (bool) $ticket_verificado->requiere_verificacion_mensajes,
            'La conversación abierta desde la bandeja no heredó la config prendida.'
        );
    }
}
