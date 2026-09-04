<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Las tres columnas del panel del closer y la regla que las separa.
 *
 * Lo que se protege acá es una sola cosa, y es la que más fácil se rompe: el divisor entre
 * "Listos para la llamada" y "En seguimiento" es `lead_calls.started_at`, NO la existencia de una
 * `LeadCall`. Desde el grupo 307 el agente le agenda la llamada al lead apenas confirma que quiere
 * avanzar (`LeadCallService::schedule_closer_call()`), y esa fila nace con `scheduled_at` cargado y
 * `started_at` en null. Dividir por "tiene llamada" mandaría a seguimiento a todo el que dijo que
 * sí, sin que haya hablado con nadie todavía.
 *
 * El otro invariante es que las tres reglas sean mutuamente excluyentes: un lead que aparece en dos
 * columnas se trabaja dos veces, y uno que no aparece en ninguna se pierde.
 */
class PanelDelCloserTresColumnasTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de este archivo sale a la red.
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        // Deja la base sin leads: los tests de `cards` comparan conteos exactos, y sin esto el
        // lead sembrado por LeadSeeder (o el resto de leads de otro test que no haya limpiado)
        // los correría. Mismo criterio que TarjetasDeEstadoDeLeadsTest.
        LeadMessage::query()->delete();
        Lead::query()->delete();
    }

    /**
     * Admin autenticado para pegarle al panel.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        return Admin::create([
            'name'     => 'Closer de prueba',
            'email'    => 'closer-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Lead mínimo con demo cargada.
     *
     * @param string $status Estado del pipeline.
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        return Lead::create([
            'contact_name'     => 'Lead ' . $status,
            'phone'            => '54911' . random_int(1000000, 9999999),
            'status'           => $status,
            'demo_date'        => now()->subDay()->toDateString(),
            'demo_start_time'  => '10:00',
        ]);
    }

    /**
     * Pega al panel y devuelve los ids de cada columna.
     *
     * @return array<string, array<int, int>>
     */
    private function columnas(): array
    {
        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/closer/panel');

        $response->assertStatus(200);

        $data = $response->json();

        $ids = function ($key) use ($data) {
            return array_map(function ($lead) {
                return (int) $lead['id'];
            }, $data[$key] ?? []);
        };

        return [
            'agendadas'   => $ids('agendadas'),
            'para_llamar' => $ids('para_llamar'),
            'seguimiento' => $ids('seguimiento'),
        ];
    }

    /**
     * El ciclo de demo sin terminar entero cae en la primera columna, no solo `demo_agendada`.
     *
     * @return void
     */
    public function test_la_primera_columna_junta_todo_el_ciclo_de_demo_sin_terminar(): void
    {
        $estados = [
            'demo_agendada',
            'demo_en_curso',
            'demo_pendiente_de_ingreso',
            'demo_pendiente_de_terminar',
        ];

        $ids = [];
        foreach ($estados as $estado) {
            $ids[] = $this->crear_lead($estado)->id;
        }

        $columnas = $this->columnas();

        foreach ($ids as $id) {
            $this->assertContains($id, $columnas['agendadas'], 'El lead debería estar en "Demos agendadas".');
            $this->assertNotContains($id, $columnas['para_llamar']);
            $this->assertNotContains($id, $columnas['seguimiento']);
        }
    }

    /**
     * 🔴 El caso que define el diseño: el lead que confirmó que quiere la llamada YA tiene una
     * `LeadCall` creada por el agente, pero todavía no habló con nadie. Va a la columna 2.
     *
     * @return void
     */
    public function test_la_llamada_agendada_por_el_agente_no_manda_el_lead_a_seguimiento(): void
    {
        $lead = $this->crear_lead('closer_activo');

        LeadCall::create([
            'lead_id'      => $lead->id,
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
            'estado'       => 'pendiente',
            'scheduled_at' => now()->addHour(),
            'started_at'   => null,
        ]);

        $columnas = $this->columnas();

        $this->assertContains($lead->id, $columnas['para_llamar']);
        $this->assertNotContains($lead->id, $columnas['seguimiento']);
        $this->assertNotContains($lead->id, $columnas['agendadas']);
    }

    /**
     * El lead que terminó la demo y a quien nadie le preguntó nada también espera en la columna 2:
     * es el que el frontend marca "Sin confirmar".
     *
     * @return void
     */
    public function test_demo_realizada_sin_llamadas_espera_en_la_segunda_columna(): void
    {
        $lead = $this->crear_lead('demo_realizada');

        $columnas = $this->columnas();

        $this->assertContains($lead->id, $columnas['para_llamar']);
        $this->assertNotContains($lead->id, $columnas['seguimiento']);
    }

    /**
     * Con la llamada ya arrancada (`started_at` cargado) el lead pasa a seguimiento.
     *
     * @return void
     */
    public function test_la_llamada_ya_iniciada_manda_el_lead_a_seguimiento(): void
    {
        $lead = $this->crear_lead('closer_activo');

        LeadCall::create([
            'lead_id'    => $lead->id,
            'meet_url'   => 'https://meet.google.com/abc-defg-hij',
            'estado'     => 'pendiente',
            'started_at' => now()->subMinutes(30),
        ]);

        $columnas = $this->columnas();

        $this->assertContains($lead->id, $columnas['seguimiento']);
        $this->assertNotContains($lead->id, $columnas['para_llamar']);
    }

    /**
     * Cerrado ganado sale del panel: es exactamente lo que pidió Lucas ("cuando cierre deja de
     * estar en seguimiento").
     *
     * @return void
     */
    public function test_el_cerrado_ganado_deja_de_estar_en_seguimiento(): void
    {
        $lead = $this->crear_lead('cerrado_ganado');

        LeadCall::create([
            'lead_id'    => $lead->id,
            'estado'     => 'completada',
            'started_at' => now()->subDay(),
        ]);

        $columnas = $this->columnas();

        $this->assertNotContains($lead->id, $columnas['seguimiento']);
        $this->assertNotContains($lead->id, $columnas['para_llamar']);
        $this->assertNotContains($lead->id, $columnas['agendadas']);
    }

    /**
     * Un lead con la demo reagendada DESPUÉS de una llamada tiene estado del ciclo de demo y una
     * llamada iniciada al mismo tiempo: no puede salir en dos columnas.
     *
     * @return void
     */
    public function test_ningun_lead_aparece_en_dos_columnas(): void
    {
        $lead = $this->crear_lead('demo_agendada');

        LeadCall::create([
            'lead_id'    => $lead->id,
            'estado'     => 'completada',
            'started_at' => now()->subDays(3),
        ]);

        $columnas = $this->columnas();

        $apariciones = 0;
        foreach (['agendadas', 'para_llamar', 'seguimiento'] as $columna) {
            if (in_array($lead->id, $columnas[$columna], true)) {
                $apariciones++;
            }
        }

        $this->assertSame(1, $apariciones, 'El lead tiene que caer en exactamente una columna.');
    }

    /**
     * 🔴 El botón "Unirse a Meet" de la columna 2 es lo que estampa `started_at` sobre la llamada
     * que el agente había dejado agendada, y con eso el lead cruza a "En seguimiento". Sin este
     * estampado el lead se queda para siempre en la columna 2 aunque la llamada ya haya ocurrido.
     *
     * De paso se verifica la promoción de estado: el lead que estaba en `demo_realizada` (nadie le
     * preguntó nada) queda en `closer_activo` en cuanto el closer se sube a la videollamada.
     *
     * @return void
     */
    public function test_unirse_al_meet_arranca_la_llamada_y_mueve_el_lead_a_seguimiento(): void
    {
        $lead = $this->crear_lead('demo_realizada');

        $call = LeadCall::create([
            'lead_id'      => $lead->id,
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
            'recall_bot_id' => 'bot-ya-mandado',
            'estado'       => 'pendiente',
            'scheduled_at' => now()->addMinutes(10),
            'started_at'   => null,
        ]);

        $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/calls/join')
            ->assertStatus(200);

        $call->refresh();
        $lead->refresh();

        $this->assertNotNull($call->started_at, 'Unirse al Meet tiene que estampar started_at.');
        $this->assertSame('closer_activo', $lead->status);

        $columnas = $this->columnas();
        $this->assertContains($lead->id, $columnas['seguimiento']);
        $this->assertNotContains($lead->id, $columnas['para_llamar']);
    }

    /**
     * Unirse a una llamada agendada que quedó SIN Meet (Google falló cuando el agente la creó)
     * no puede dejar al closer sin salida: hay que intentar generarle el Meet ahí mismo. Sin
     * esto la llamada es inentrable y encima le tapa el botón "Nueva reunión", porque ya existe
     * una llamada pendiente.
     *
     * @return void
     */
    public function test_unirse_a_una_llamada_sin_meet_intenta_generarlo(): void
    {
        $lead = $this->crear_lead('closer_activo');

        $call = LeadCall::create([
            'lead_id'      => $lead->id,
            'meet_url'     => null,
            'estado'       => 'pendiente',
            'scheduled_at' => now()->addMinutes(10),
            'started_at'   => null,
        ]);

        /* Sin closer con calendario conectado, generar el Meet no puede salir bien: lo que se
         * verifica es que el intento no rompa nada y la llamada igual arranque. */
        $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/calls/join')
            ->assertStatus(200);

        $call->refresh();

        $this->assertNotNull($call->started_at);
    }

    /**
     * El lead que nunca confirmó que terminó la demo vence a `demo_realizada`, no a
     * `closer_activo`: no confirmó nada, así que no puede aparecer como "Interesado".
     *
     * @return void
     */
    public function test_el_vencimiento_de_pendiente_de_terminar_deja_el_lead_en_demo_realizada(): void
    {
        $lead = $this->crear_lead('demo_pendiente_de_terminar');
        $lead->update([
            'automatizaciones_demo_activas' => true,
            'auto_check_fin_demo'           => true,
            /* Demo de ayer: el timeout desde el fin ya venció con cualquier configuración. */
            'demo_date'                     => now()->subDay()->toDateString(),
            'demo_start_time'               => '10:00',
        ]);

        $this->artisan('leads:check-demo-pendiente-terminar-timeout')->assertExitCode(0);

        $lead->refresh();

        $this->assertSame('demo_realizada', $lead->status);

        $columnas = $this->columnas();
        $this->assertContains($lead->id, $columnas['para_llamar']);
    }

    /**
     * La cuenta de Google conectada del closer viaja en `settings`: es con la que el panel abre
     * los Meet (`authuser=`) para que Google no le pida al closer que lo admitan a su propia
     * llamada.
     *
     * @return void
     */
    public function test_el_panel_informa_la_cuenta_de_google_del_closer(): void
    {
        $closer = Admin::create([
            'name'      => 'Tommy',
            'email'     => 'tommy-' . uniqid() . '@test.local',
            'password'  => bcrypt('secret'),
            'is_closer' => true,
        ]);

        AdminCalendarConnection::create([
            'admin_id'                       => $closer->id,
            'google_refresh_token_encrypted' => encrypt('token-falso'),
            'google_calendar_id'             => 'tommy@comerciocity.com',
            'google_account_email'           => 'tommy@comerciocity.com',
            'is_active'                      => true,
        ]);

        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/closer/panel');

        $response->assertStatus(200);
        $this->assertSame(
            'tommy@comerciocity.com',
            $response->json('settings.closer_google_account')
        );
    }

    /**
     * Mensaje entrante del lead sin ninguna respuesta posterior: es la razón A del scope
     * `Lead::scopeRequiereRevision()` (ver `apply_condicion_mensaje_sin_responder()`), el mismo
     * criterio que usa `LeadStatusCardsService` para las tarjetas del módulo de Leads.
     *
     * @param Lead $lead Dueño del mensaje.
     *
     * @return LeadMessage
     */
    private function crear_mensaje_sin_responder(Lead $lead): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'content'         => 'Hola, ¿siguen ahí?',
            'status'          => 'enviado',
            'kind'            => 'text',
            'is_status_event' => false,
            'is_error'        => false,
            'sent_at'         => now(),
        ]);
    }

    /**
     * Pega al panel y devuelve el body completo de la respuesta.
     *
     * @return array<string, mixed>
     */
    private function panel(): array
    {
        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/closer/panel');

        $response->assertStatus(200);

        return $response->json();
    }

    /**
     * Busca una card por su `value` dentro del body del panel.
     *
     * @param array<string, mixed> $data  Body de la respuesta del panel.
     * @param string               $value Valor de la card buscada (agendadas|para_llamar|seguimiento).
     *
     * @return array<string, mixed>
     */
    private function card(array $data, string $value): array
    {
        foreach ($data['cards'] ?? [] as $card) {
            if ($card['value'] === $value) {
                return $card;
            }
        }

        $this->fail('No se encontró la card "' . $value . '" en la respuesta del panel.');
    }

    /**
     * El `total` de cada card tiene que coincidir con la cantidad de leads que el mismo endpoint
     * devuelve en su columna correspondiente: la card no puede contar distinto de lo que el closer
     * ve en la lista de abajo.
     *
     * @return void
     */
    public function test_el_total_de_cada_card_coincide_con_la_cantidad_de_leads_de_su_columna(): void
    {
        // Columna 1: dos leads en distintos estados del ciclo de demo sin terminar.
        $this->crear_lead('demo_agendada');
        $this->crear_lead('demo_en_curso');

        // Columna 2: terminó la demo, todavía sin llamada.
        $this->crear_lead('demo_realizada');

        // Columna 3: ya tuvo la llamada (started_at cargado) y no está cerrado.
        $en_seguimiento = $this->crear_lead('closer_activo');
        LeadCall::create([
            'lead_id'    => $en_seguimiento->id,
            'estado'     => 'completada',
            'started_at' => now()->subDay(),
        ]);

        $data = $this->panel();

        $this->assertSame(count($data['agendadas']), $this->card($data, 'agendadas')['total']);
        $this->assertSame(count($data['para_llamar']), $this->card($data, 'para_llamar')['total']);
        $this->assertSame(count($data['seguimiento']), $this->card($data, 'seguimiento')['total']);

        // Y de paso, los números concretos que arma este fixture.
        $this->assertSame(2, $this->card($data, 'agendadas')['total']);
        $this->assertSame(1, $this->card($data, 'para_llamar')['total']);
        $this->assertSame(1, $this->card($data, 'seguimiento')['total']);
    }

    /**
     * Un lead en cada sección con un mensaje entrante sin respuesta posterior hace que el
     * `sin_responder` de esa card sea al menos 1.
     *
     * @return void
     */
    public function test_un_mensaje_sin_responder_cuenta_en_la_card_de_su_columna(): void
    {
        $agendada = $this->crear_lead('demo_agendada');
        $this->crear_mensaje_sin_responder($agendada);

        $para_llamar = $this->crear_lead('demo_realizada');
        $this->crear_mensaje_sin_responder($para_llamar);

        $seguimiento = $this->crear_lead('closer_activo');
        LeadCall::create([
            'lead_id'    => $seguimiento->id,
            'estado'     => 'completada',
            'started_at' => now()->subDay(),
        ]);
        $this->crear_mensaje_sin_responder($seguimiento);

        $data = $this->panel();

        $this->assertGreaterThanOrEqual(1, $this->card($data, 'agendadas')['sin_responder']);
        $this->assertGreaterThanOrEqual(1, $this->card($data, 'para_llamar')['sin_responder']);
        $this->assertGreaterThanOrEqual(1, $this->card($data, 'seguimiento')['sin_responder']);
    }

    /**
     * Un lead marcado como que ya no recibe mensajes queda afuera de `sin_responder` aunque tenga
     * un mensaje sin contestar: mismo comportamiento que ya garantiza el scope `requiereRevision`
     * en `ColoresDeFilaDeLeadsTest` y `TarjetasDeEstadoDeLeadsTest`.
     *
     * @return void
     */
    public function test_el_marcado_como_inalcanzable_no_cuenta_en_sin_responder(): void
    {
        $lead = $this->crear_lead('demo_agendada');
        $this->crear_mensaje_sin_responder($lead);
        $lead->update(['no_recibe_mensajes_at' => now()]);

        $data = $this->panel();

        $this->assertSame(0, $this->card($data, 'agendadas')['sin_responder']);
        // La marca no lo saca de la columna ni del total: solo apaga la revisión.
        $this->assertSame(1, $this->card($data, 'agendadas')['total']);
    }

    /**
     * Las tres cards vienen siempre en el mismo orden y con las mismas claves, aunque no haya un
     * solo lead: el SPA no inventa ni reordena nada, igual que las cards del módulo de Leads.
     *
     * @return void
     */
    public function test_las_cards_vienen_en_orden_fijo_con_todas_sus_claves(): void
    {
        $data = $this->panel();

        $cards = $data['cards'];

        $this->assertCount(3, $cards);

        $valores = array_map(function ($card) {
            return $card['value'];
        }, $cards);
        $this->assertSame(['agendadas', 'para_llamar', 'seguimiento'], $valores);

        foreach ($cards as $card) {
            $this->assertArrayHasKey('value', $card);
            $this->assertArrayHasKey('text', $card);
            $this->assertArrayHasKey('color', $card);
            $this->assertArrayHasKey('group', $card);
            $this->assertArrayHasKey('total', $card);
            $this->assertArrayHasKey('sin_responder', $card);
            $this->assertSame(0, $card['total']);
            $this->assertSame(0, $card['sin_responder']);
        }
    }
}
