<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Las plantillas de CLIENTE: el alta que hace Claude desde afuera, la lectura del selector y el
 * envío al ticket.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 LA IDEMPOTENCIA POR `template_name`. El alta no la hace una persona en una pantalla: la
 *     hace Claude reenviando el lote entero cada vez que corrige una descripción o un orden. Si
 *     reenviar duplica, el selector del operador muestra la misma plantilla dos veces y nadie se
 *     entera hasta que alguien la elige.
 *  2. Que el alta sea ADITIVA: un lote con una sola plantilla no puede llevarse puestas las que ya
 *     estaban.
 *  3. 🔴 Que estas plantillas NO caigan en `followup_templates`. Ahí busca el motor de seguimiento
 *     automático de leads: una plantilla pensada para un cliente cargada ahí le saldría sola a un
 *     lead, sin que nadie la haya mandado.
 *  4. Que un envío que Meta rechaza deje el mensaje en el hilo marcado como no entregado, y no un
 *     mensaje que se lee como si hubiera salido.
 *
 * El envío se sustituye a nivel WhatsappSendService, así que no se toca la red ni Meta; el resto
 * del camino (ruta, middleware, validación, persistencia) es el real.
 */
class PlantillasDeClienteTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-plantillas';

    /**
     * Setea la clave de ingesta y tapa la red.
     *
     * En el `.env.testing` del slot la clave está vacía y el middleware es fail-closed, así que sin
     * esto todos los tests del bloque claude/* darían 401 y estarían midiendo el middleware en vez
     * del endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Payload de una plantilla, con los valores mínimos que pide el endpoint.
     *
     * @param string $template_name Nombre de Meta.
     * @param array  $extra         Campos a pisar o agregar.
     *
     * @return array<string, mixed>
     */
    private function payload_de_plantilla(string $template_name, array $extra = []): array
    {
        $base = [
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
        ];

        return array_merge($base, $extra);
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
     * Ticket abierto del canal indicado.
     *
     * @param Client $client Cliente dueño.
     * @param string $source erp | whatsapp.
     * @param string $phone  Teléfono del hilo (vacío para el canal ERP).
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $source = 'whatsapp', string $phone = '+5493415550001'): SupportTicket
    {
        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => $source,
            'whatsapp_phone' => $source === 'whatsapp' ? $phone : null,
            'opened_at'      => now(),
        ]);
    }

    /**
     * Plantilla de cliente cargada directamente, para los tests que no prueban el alta.
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
            ],
            'activa'          => true,
        ], $extra));
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra los envíos de plantilla.
     *
     * @param bool $confirma True: devuelve un whatsapp_message_id. False: simula rechazo de Meta.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(bool $confirma = true): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var bool Si el envío se confirma o no. */
            public $confirma = true;

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

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Sin la clave del header no entra nada.
     *
     * El middleware es fail-closed y este endpoint escribe la tabla que después consume la bandeja:
     * si quedara abierto, cualquiera podría cargarle plantillas al operador.
     *
     * @return void
     */
    public function test_sin_la_clave_el_endpoint_de_claude_rechaza()
    {
        $response = $this->postJson('/api/claude/client-templates', [
            'templates' => [$this->payload_de_plantilla('cc_cliente_sin_clave')],
        ]);

        $response->assertStatus(401);

        $this->assertSame(
            0,
            ClientTemplate::where('template_name', 'cc_cliente_sin_clave')->count(),
            'Se cargó una plantilla sin mandar la clave del header.'
        );
    }

    /**
     * El alta guarda la plantilla entera, con su categoría, su etiqueta, su orden y sus variables.
     *
     * @return void
     */
    public function test_claude_da_de_alta_una_plantilla_de_cliente()
    {
        $response = $this->withHeaders($this->headers())->postJson('/api/claude/client-templates', [
            'templates' => [$this->payload_de_plantilla('cc_cliente_aviso_mantenimiento')],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('resultados.creadas', 1);
        $response->assertJsonPath('resultados.actualizadas', 0);

        $plantilla = ClientTemplate::where('template_name', 'cc_cliente_aviso_mantenimiento')->first();

        $this->assertNotNull($plantilla, 'No se creó la plantilla de cliente.');
        $this->assertSame('avisos', $plantilla->categoria);
        $this->assertSame('Avisos operativos', $plantilla->categoria_label);
        $this->assertSame(2, $plantilla->categoria_orden);
        $this->assertSame('es_AR', $plantilla->language_code);
        $this->assertTrue($plantilla->activa);
        $this->assertIsArray($plantilla->variables, 'Las variables no se guardaron como JSON.');
        $this->assertCount(2, $plantilla->variables);
        $this->assertSame('{{1}}', $plantilla->variables[0]['placeholder']);
    }

    /**
     * 🔴 El test central: reenviar la misma plantilla actualiza la fila, nunca crea una segunda.
     *
     * Claude no manda diffs, manda el lote entero cada vez. Sin esto, cada corrección deja una
     * plantilla duplicada en el selector del operador.
     *
     * @return void
     */
    public function test_reenviar_la_misma_plantilla_no_duplica_la_fila()
    {
        $primero = $this->withHeaders($this->headers())->postJson('/api/claude/client-templates', [
            'templates' => [$this->payload_de_plantilla('cc_cliente_repetida', ['titulo' => 'Título viejo'])],
        ]);

        $primero->assertStatus(200);
        $primero->assertJsonPath('resultados.creadas', 1);

        $segundo = $this->withHeaders($this->headers())->postJson('/api/claude/client-templates', [
            'templates' => [$this->payload_de_plantilla('cc_cliente_repetida', ['titulo' => 'Título nuevo'])],
        ]);

        $segundo->assertStatus(200);
        $segundo->assertJsonPath('resultados.creadas', 0);
        $segundo->assertJsonPath('resultados.actualizadas', 1);

        $this->assertSame(
            1,
            ClientTemplate::where('template_name', 'cc_cliente_repetida')->count(),
            'Reenviar la misma plantilla dejó dos filas.'
        );

        $plantilla = ClientTemplate::where('template_name', 'cc_cliente_repetida')->first();
        $this->assertSame('Título nuevo', $plantilla->titulo, 'La segunda corrida no actualizó el título.');
    }

    /**
     * Un lote parcial no se lleva puestas las plantillas que no vinieron en el payload.
     *
     * @return void
     */
    public function test_el_alta_por_claude_nunca_borra_las_que_ya_estaban()
    {
        $this->withHeaders($this->headers())->postJson('/api/claude/client-templates', [
            'templates' => [
                $this->payload_de_plantilla('cc_cliente_lote_a'),
                $this->payload_de_plantilla('cc_cliente_lote_b'),
            ],
        ])->assertStatus(200);

        $this->withHeaders($this->headers())->postJson('/api/claude/client-templates', [
            'templates' => [$this->payload_de_plantilla('cc_cliente_lote_a', ['titulo' => 'Solo la A'])],
        ])->assertStatus(200);

        $this->assertSame(
            1,
            ClientTemplate::where('template_name', 'cc_cliente_lote_b')->count(),
            'Un lote que no la incluía borró la plantilla B.'
        );
    }

    /**
     * 🔴 Una plantilla de cliente no puede terminar en la tabla de las de lead.
     *
     * En `followup_templates` busca el motor de seguimiento automático por estado + día: una fila
     * de cliente ahí adentro le saldría sola a un lead.
     *
     * @return void
     */
    public function test_una_plantilla_de_cliente_no_entra_en_followup_templates()
    {
        $antes = DB::table('followup_templates')->count();

        $this->withHeaders($this->headers())->postJson('/api/claude/client-templates', [
            'templates' => [$this->payload_de_plantilla('cc_cliente_no_es_de_lead')],
        ])->assertStatus(200);

        $this->assertSame(
            $antes,
            DB::table('followup_templates')->count(),
            'El alta de una plantilla de cliente tocó followup_templates.'
        );
    }

    /**
     * El selector recibe las plantillas ya ordenadas por grupo, con los tres campos que necesita
     * para agruparlas.
     *
     * @return void
     */
    public function test_el_spa_lee_las_plantillas_agrupables_por_categoria()
    {
        $admin = $this->crear_admin('selector-plantillas@test.local');

        $this->crear_plantilla('cc_cliente_orden_ultimo', [
            'categoria'       => 'zeta',
            'categoria_label' => 'Último grupo',
            'categoria_orden' => 9,
        ]);
        $this->crear_plantilla('cc_cliente_orden_primero', [
            'categoria'       => 'bienvenida',
            'categoria_label' => 'Bienvenida',
            'categoria_orden' => 1,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client-template');

        $response->assertStatus(200);

        $models = $response->json('models');

        $this->assertIsArray($models);
        $this->assertGreaterThanOrEqual(2, count($models));

        $nombres = array_column($models, 'template_name');
        $posicion_primero = array_search('cc_cliente_orden_primero', $nombres, true);
        $posicion_ultimo  = array_search('cc_cliente_orden_ultimo', $nombres, true);

        $this->assertNotFalse($posicion_primero);
        $this->assertNotFalse($posicion_ultimo);
        $this->assertLessThan(
            $posicion_ultimo,
            $posicion_primero,
            'El listado no vino ordenado por categoria_orden.'
        );

        $fila = $models[$posicion_primero];
        $this->assertSame('bienvenida', $fila['categoria']);
        $this->assertSame('Bienvenida', $fila['categoria_label']);
        $this->assertSame(1, $fila['categoria_orden']);
    }

    /**
     * Una plantilla apagada no se le ofrece al operador, pero sigue existiendo.
     *
     * @return void
     */
    public function test_una_plantilla_desactivada_no_aparece_en_el_selector()
    {
        $admin = $this->crear_admin('plantilla-apagada@test.local');

        $this->crear_plantilla('cc_cliente_apagada', ['activa' => false]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client-template');
        $response->assertStatus(200);

        $nombres = array_column($response->json('models'), 'template_name');
        $this->assertNotContains(
            'cc_cliente_apagada',
            $nombres,
            'El selector ofreció una plantilla desactivada.'
        );

        $con_inactivas = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/client-template?incluir_inactivas=1');
        $con_inactivas->assertStatus(200);

        $nombres_todas = array_column($con_inactivas->json('models'), 'template_name');
        $this->assertContains(
            'cc_cliente_apagada',
            $nombres_todas,
            'Con incluir_inactivas=1 la plantilla apagada tendría que venir.'
        );
    }

    /**
     * Una fila cargada sin etiqueta de categoría igual muestra un encabezado legible.
     *
     * Sin esto, el selector agruparía bajo un título vacío y el operador no sabría qué está mirando.
     *
     * @return void
     */
    public function test_una_fila_sin_etiqueta_de_categoria_igual_se_puede_agrupar()
    {
        $admin = $this->crear_admin('sin-etiqueta@test.local');

        $this->crear_plantilla('cc_cliente_sin_etiqueta', [
            'categoria'       => 'avisos_operativos',
            'categoria_label' => null,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client-template');
        $response->assertStatus(200);

        $models = $response->json('models');
        $nombres = array_column($models, 'template_name');
        $posicion = array_search('cc_cliente_sin_etiqueta', $nombres, true);

        $this->assertNotFalse($posicion);
        $this->assertSame(
            'Avisos operativos',
            $models[$posicion]['categoria_label'],
            'Una fila sin etiqueta tendría que devolver una etiqueta armada con el slug.'
        );
    }

    /**
     * El envío al ticket: la plantilla sale con sus variables saneadas y el hilo queda con el texto
     * ya renderizado.
     *
     * @return void
     */
    public function test_se_manda_una_plantilla_al_ticket_de_whatsapp()
    {
        $admin  = $this->crear_admin('envio-plantilla@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client, 'whatsapp', '+5493415550001');
        $plantilla = $this->crear_plantilla('cc_cliente_envio_ok');
        $espia = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/send-client-template', [
                'client_template_id' => $plantilla->id,
                /* El salto de línea de la segunda variable es a propósito: Meta rechaza el envío
                   entero si un parámetro lo trae, así que el saneo tiene que aplanarlo. */
                'variables'          => ['Juan', "martes 2 de septiembre\na las 22hs"],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('delivery', 'sent');

        $this->assertCount(1, $espia->plantillas, 'No se mandó la plantilla.');
        $this->assertSame('cc_cliente_envio_ok', $espia->plantillas[0]['template_name']);
        $this->assertSame('+5493415550001', $espia->plantillas[0]['to']);
        $this->assertSame('es_AR', $espia->plantillas[0]['language_code']);
        $this->assertSame('Juan', $espia->plantillas[0]['variables'][0]);
        $this->assertSame(
            'martes 2 de septiembre a las 22hs',
            $espia->plantillas[0]['variables'][1],
            'El salto de línea no se aplanó antes de mandarlo a Meta.'
        );

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->first();

        $this->assertNotNull($mensaje, 'La plantilla no quedó en el hilo.');
        $this->assertSame('admin', $mensaje->sender_type);
        $this->assertSame((int) $admin->id, (int) $mensaje->sender_admin_id);
        $this->assertNotNull($mensaje->whatsapp_message_id, 'El mensaje quedó sin el id de Meta.');
        $this->assertNull($mensaje->remote_delivery_status);
        $this->assertSame(
            'Hola Juan, te avisamos que el martes 2 de septiembre a las 22hs vamos a estar actualizando el sistema.',
            $mensaje->body,
            'El body del hilo no quedó con los {{n}} reemplazados.'
        );
    }

    /**
     * Un ticket del ERP no tiene por dónde mandar una plantilla de WhatsApp.
     *
     * @return void
     */
    public function test_no_se_manda_una_plantilla_a_un_ticket_del_erp()
    {
        $admin  = $this->crear_admin('plantilla-al-erp@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client, 'erp');
        $plantilla = $this->crear_plantilla('cc_cliente_envio_erp');
        $espia = $this->espiar_sender();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/send-client-template', [
                'client_template_id' => $plantilla->id,
                'variables'          => ['Juan'],
            ]);

        $response->assertStatus(422);

        $this->assertCount(0, $espia->plantillas, 'Se mandó una plantilla por un ticket del ERP.');
        $this->assertSame(
            0,
            SupportMessage::where('support_ticket_id', $ticket->id)->count(),
            'Quedó un mensaje en el hilo de un envío que nunca se hizo.'
        );
    }

    /**
     * Si Meta rechaza la plantilla, el mensaje queda en el hilo pero marcado como no entregado.
     *
     * Es el mismo estado que ya produce cualquier respuesta rechazada: el operador ve el cartel y
     * el botón de reintentar. Lo que no puede pasar es que el mensaje se lea como si hubiera salido.
     *
     * @return void
     */
    public function test_si_meta_rechaza_la_plantilla_el_mensaje_queda_para_reintentar()
    {
        $admin  = $this->crear_admin('plantilla-rechazada@test.local');
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client, 'whatsapp', '+5493415550002');
        $plantilla = $this->crear_plantilla('cc_cliente_envio_falla');
        $this->espiar_sender(false);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-ticket/' . $ticket->id . '/send-client-template', [
                'client_template_id' => $plantilla->id,
                'variables'          => ['Juan'],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('delivery', 'failed');

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->first();

        $this->assertNotNull($mensaje, 'El mensaje desapareció del hilo cuando falló el envío.');
        $this->assertNull($mensaje->whatsapp_message_id);
        $this->assertSame(
            'not_received',
            $mensaje->remote_delivery_status,
            'Un envío rechazado quedó marcado como entregado.'
        );
        $this->assertNotNull($response->json('error'), 'No se devolvió el motivo del rechazo.');
    }
}
