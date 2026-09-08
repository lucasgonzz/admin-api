<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El botón "Ofrecer/agendar demo" del sidebar (POST lead/{id}/offer-demo).
 *
 * generate_suggestion() y el pipeline de agendar (lock, link, Mail 1/Calendar) ya están probados
 * en otro lado (OfertaFlexibleDeDemoTest, GuardaDeOfertaFlexibleTest, AgendaDeDemoPorClaudeTest,
 * etc.) -- este archivo cubre solo lo que el endpoint nuevo agrega sobre eso: la guarda de lead
 * cerrado, que una sugerencia pendiente NO bloquea la generación (decisión de Lucas, 8/9/2026)
 * pero SÍ se le apaga el auto-envío de respaldo (hallazgo del chequeo independiente, mismo día:
 * sin esto el lead podía recibir la sugerencia vieja sola por WhatsApp minutos después), y que la
 * intención forzada del operador efectivamente llega al texto que recibe Claude -- en las dos
 * llamadas posibles, no solo la primera -- en vez de perderse camino a la llamada HTTP.
 */
class BotonOfrecerDemoLeadTest extends TestCase
{
    use DatabaseTransactions;

    const HOY = '2026-09-08';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HOY . ' 09:00:00', 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->sembrar_prompt_base();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * (1) Un lead cerrado (ganado o perdido) no admite ofrecer/agendar demo: 422, y ni siquiera se
     *     llega a consultar a Claude -- no tiene sentido gastar la llamada si la guarda ya corta.
     *
     * @return void
     */
    public function test_lead_cerrado_no_admite_ofrecer_demo(): void
    {
        foreach (['cerrado_ganado', 'cerrado_perdido'] as $status) {
            Http::fake();
            $admin = $this->crear_admin("cerrado-{$status}@test.local");
            $lead  = $this->crear_lead($status);

            $respuesta = $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/lead/' . $lead->id . '/offer-demo');

            $respuesta->assertStatus(422);
            Http::assertNothingSent();
        }
    }

    /**
     * (2) Sin sugerencia pendiente: el endpoint genera igual y el flag de aviso viene en false.
     *
     * @return void
     */
    public function test_sin_sugerencia_pendiente_el_flag_viene_en_false(): void
    {
        $this->fake_claude([
            'mensaje_sugerido' => 'Dale, te la puedo dejar lista ahora mismo.',
            'estado_sugerido'  => 'contactado',
        ]);
        $admin = $this->crear_admin('sin-pendiente@test.local');
        $lead  = $this->crear_lead('calificado');

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/offer-demo');

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['habia_sugerencia_pendiente' => false]);
    }

    /**
     * (3) 🔴 EL CASO QUE LUCAS PIDIÓ (8/9/2026): con una sugerencia sin aprobar ya cargada, el
     *     botón NO bloquea -- genera la suya igual, sin tocar la vieja, y el flag avisa que había
     *     algo sin revisar.
     *
     * @return void
     */
    public function test_con_sugerencia_pendiente_no_bloquea_y_avisa(): void
    {
        $this->fake_claude([
            'mensaje_sugerido' => 'Dale, te la puedo dejar lista ahora mismo.',
            'estado_sugerido'  => 'contactado',
        ]);
        $admin = $this->crear_admin('con-pendiente@test.local');
        $lead  = $this->crear_lead('calificado');

        $pendiente              = new LeadMessage();
        $pendiente->lead_id     = $lead->id;
        $pendiente->sender      = 'sistema';
        $pendiente->status      = 'sugerido';
        $pendiente->is_followup = false;
        $pendiente->content     = 'Una sugerencia vieja sin aprobar.';
        $pendiente->save();

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/offer-demo');

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['habia_sugerencia_pendiente' => true]);

        // La sugerencia vieja sigue ahí, sin tocar -- el endpoint no la pisa ni la borra.
        $this->assertDatabaseHas('lead_messages', [
            'id'      => $pendiente->id,
            'status'  => 'sugerido',
            'content' => 'Una sugerencia vieja sin aprobar.',
        ]);

        // Y ahora hay una fila MÁS del sistema para el mismo lead: la que generó el botón. No se
        // filtra por status a propósito -- el pipeline de envío automático puede mandarla directo
        // (status enviado) o dejarla sugerida según la config del lead, y eso ya lo cubren otros
        // tests; acá solo importa que no pisó la vieja y sumó la suya.
        $this->assertSame(
            2,
            LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'sistema')->count(),
            'El botón tiene que crear su propia sugerencia sin tocar la que ya estaba pendiente.'
        );
    }

    /**
     * (4) 🔴 EL TEST CENTRAL: la instrucción del operador llega tal cual al texto que recibe
     *     Claude -- si se pierde en el camino (generate_suggestion → generate_suggestion_with_availability
     *     → build_user_content), el botón queda idéntico a "Pedir sugerencia a Claude" y no cumple
     *     lo que Lucas pidió.
     *
     * @return void
     */
    public function test_la_intencion_forzada_llega_al_texto_que_recibe_claude(): void
    {
        $this->fake_claude([
            'mensaje_sugerido' => 'Dale, te la puedo dejar lista ahora mismo.',
            'estado_sugerido'  => 'contactado',
        ]);
        $admin = $this->crear_admin('intencion@test.local');
        $lead  = $this->crear_lead('calificado');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/offer-demo')
            ->assertStatus(200);

        Http::assertSent(function ($request) {
            if (! Str::contains($request->url(), 'api.anthropic.com')) {
                return false;
            }
            // El body sale con los acentos escapados (ó) -- decodificar antes de comparar
            // el texto de la instrucción, que tiene tildes reales en UTF-8.
            $payload = json_decode($request->body(), true);
            $texto_usuario = $payload['messages'][0]['content'] ?? '';

            return Str::contains($texto_usuario, LeadAiService::INTENCION_OFRECER_AGENDAR_DEMO);
        });
    }

    /**
     * (5) 🔴 EL FIX DEL CHEQUEO INDEPENDIENTE (8/9/2026): la sugerencia vieja no puede quedar con
     *     su auto-envío de respaldo corriendo -- si no se apaga, el lead puede recibir por
     *     WhatsApp la sugerencia vieja solo, minutos después de que el operador ya resolvió todo
     *     con la nueva. cancel_for_message() no borra el mensaje ni lo marca rechazado: solo
     *     limpia ai_auto_send_at (y bumpea el token que el job verifica antes de mandar).
     *
     * @return void
     */
    public function test_neutraliza_el_auto_envio_de_la_sugerencia_vieja(): void
    {
        $this->fake_claude([
            'mensaje_sugerido' => 'Dale, te la puedo dejar lista ahora mismo.',
            'estado_sugerido'  => 'contactado',
        ]);
        $admin = $this->crear_admin('neutraliza-auto-envio@test.local');
        $lead  = $this->crear_lead('calificado');

        $pendiente                 = new LeadMessage();
        $pendiente->lead_id        = $lead->id;
        $pendiente->sender         = 'sistema';
        $pendiente->status         = 'sugerido';
        $pendiente->is_followup    = false;
        $pendiente->requiere_verificacion = true;
        $pendiente->content        = 'Acá tenés estos horarios: 10, 14 o 16hs.';
        $pendiente->ai_auto_send_at = AppTime::now()->addMinutes(30);
        $pendiente->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/offer-demo')
            ->assertStatus(200);

        $pendiente->refresh();
        $this->assertNull(
            $pendiente->ai_auto_send_at,
            'La sugerencia vieja quedó con su timer de auto-envío corriendo: puede mandarle al lead un WhatsApp viejo sin que el operador lo sepa.'
        );
    }

    /**
     * (6) 🔴 Cobertura que faltaba (chequeo independiente, 8/9/2026): la intención forzada también
     *     tiene que llegar en la SEGUNDA llamada (la que trae la grilla de disponibilidad), no solo
     *     en la primera. Si algún día se reordenan los argumentos de
     *     generate_suggestion_with_availability() sin darse cuenta, este es el test que lo agarra.
     *
     * @return void
     */
    public function test_la_intencion_forzada_llega_tambien_en_la_segunda_llamada(): void
    {
        $this->sembrar_agenda_amplia();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'stop_reason' => 'end_turn',
                    'content'     => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'mensaje_sugerido'        => '',
                            'estado_sugerido'         => 'solicita_disponibilidad',
                            'solicita_disponibilidad' => true,
                            'dia_solicitado'          => 'hoy',
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ], 200)
                ->push([
                    'stop_reason' => 'end_turn',
                    'content'     => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'mensaje_sugerido' => 'Tengo lugar hoy a las 10 o a las 14, ¿cuál te queda mejor?',
                            'estado_sugerido'  => 'solicita_disponibilidad',
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->crear_admin('segunda-llamada@test.local');
        $lead  = $this->crear_lead('calificado');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/offer-demo')
            ->assertStatus(200);

        // No se cuentan TODAS las requests HTTP fake (el catch-all '*' también capta otras
        // llamadas salientes del sistema, como avisos a admins) -- solo importa cuántas de las
        // que SÍ son a Anthropic llevan la intención.
        $llamadas_a_anthropic   = 0;
        $llamadas_con_intencion = 0;
        Http::assertSent(function ($request) use (&$llamadas_a_anthropic, &$llamadas_con_intencion) {
            if (! Str::contains($request->url(), 'api.anthropic.com')) {
                return false;
            }
            $llamadas_a_anthropic++;
            $payload       = json_decode($request->body(), true);
            $texto_usuario = $payload['messages'][0]['content'] ?? '';
            if (Str::contains($texto_usuario, LeadAiService::INTENCION_OFRECER_AGENDAR_DEMO)) {
                $llamadas_con_intencion++;
            }

            return true;
        });

        $this->assertSame(2, $llamadas_a_anthropic, 'Se esperaban exactamente dos llamadas a Claude: la primera y la de disponibilidad.');

        $this->assertSame(
            2,
            $llamadas_con_intencion,
            'La intención forzada tiene que llegar en las DOS llamadas a Claude (primera y la de disponibilidad), no solo en la primera.'
        );
    }

    /**
     * Config amplia de horarios + una demo física, para que la segunda llamada (disponibilidad)
     * tenga grilla real y no corte antes por falta de instancias.
     *
     * @return void
     */
    private function sembrar_agenda_amplia(): void
    {
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_CLOSER_HORARIO_LUNES_VIERNES, '00:00-23:59');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_CLOSER_HORARIO_SABADO, '00:00-23:59');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_CLOSER_HORARIO_DOMINGO, '00:00-23:59');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '30');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '5');

        $demo               = new \App\Models\Demo();
        $demo->uuid         = (string) Str::uuid();
        $demo->erp_spa_url  = 'https://demo-erp.test';
        $demo->erp_api_url  = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();
    }

    /**
     * Mockea la respuesta de Anthropic con un texto JSON fijo, como respuesta única (sin
     * tool_use). Los casos que necesitan una segunda llamada de disponibilidad arman su propio
     * Http::fake() con Http::sequence() -- ver test_la_intencion_forzada_llega_tambien_en_la_segunda_llamada.
     *
     * @param array $respuesta El $parsed que Claude "devuelve".
     *
     * @return void
     */
    private function fake_claude(array $respuesta): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode($respuesta, JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Fila mínima de prompt para que build_system_prompt() no tire. Sin config de horarios ni
     * demo física: ningún caso de este archivo pasa por la segunda llamada de disponibilidad.
     *
     * @return void
     */
    private function sembrar_prompt_base(): void
    {
        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'comercial/agente_leads/system_base.md',
            'content'   => 'System base de prueba.',
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * Admin que aprieta el botón desde el panel.
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
     * Lead mínimo con al menos un mensaje entrante, para que la conversación no quede vacía.
     *
     * @param string $status Estado del lead antes de generar el mensaje.
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->phone        = '5493511234567';
        $lead->status       = $status;
        $lead->save();

        $inbound              = new LeadMessage();
        $inbound->lead_id     = $lead->id;
        $inbound->sender      = 'lead';
        $inbound->status      = 'enviado';
        $inbound->is_followup = false;
        $inbound->content     = 'Hola, quería saber más del sistema.';
        $inbound->save();

        return $lead->refresh();
    }
}
