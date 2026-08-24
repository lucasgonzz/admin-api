<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Models\Lead;
use App\Models\LeadCall;
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
            'ingresando_demo',
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
}
