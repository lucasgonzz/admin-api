<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoHitosService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `leads:check-demo-ingreso-timeout`, reescrito en la misión demo-v2-estados-automaticos
 * (4/9/2026) para leer `demo_agendada` directo (ya no hay estado intermedio `ingresando_demo`)
 * y medir el timeout desde `demo_start_time`, sin el corrimiento por último mensaje del lead que
 * tenía sentido cuando el comando viejo mandaba un mensaje de WhatsApp.
 */
class CheckDemoIngresoTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Lead con demo agendada, listo para ser candidato del comando (todos los gates en su
     * posición "pasa"), salvo lo que el test pise después.
     *
     * @param Carbon $demo_datetime Momento de inicio de la demo.
     *
     * @return Lead
     */
    private function crear_lead_candidato(Carbon $demo_datetime): Lead
    {
        $lead                            = new Lead();
        $lead->uuid                      = (string) Str::uuid();
        $lead->contact_name              = 'Lead de prueba';
        $lead->status                    = 'demo_agendada';
        $lead->demo_experiencia          = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_date                 = $demo_datetime->copy();
        $lead->demo_start_time           = $demo_datetime->format('H:i');
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_check_ingreso_demo   = true;
        $lead->tiene_sugerencia_pendiente = false;
        $lead->demo_ingreso_confirmado   = false;
        $lead->demo_no_ingreso_notificado = false;
        $lead->demo_flexible             = false;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Deja el timeout de ingreso en un valor previsible.
     *
     * @param int $minutos
     *
     * @return void
     */
    private function fijar_timeout(int $minutos): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_INGRESO_TIMEOUT_MINUTOS, (string) $minutos);
    }

    /**
     * Vencido el timeout desde demo_start_time, sin confirmar: pasa a demo_pendiente_de_ingreso,
     * libera la reserva del closer (no-op sin closer_hold_event_id) y marca el anti-duplicado.
     */
    public function test_timeout_vencido_pasa_a_pendiente_de_ingreso(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);

        // 20 minutos después del inicio: el timeout de 15 ya venció.
        Carbon::setTestNow($inicio->copy()->addMinutes(20));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status);
        $this->assertTrue((bool) $lead->demo_no_ingreso_notificado);
    }

    /**
     * Todavía dentro de la ventana: no se toca.
     */
    public function test_dentro_de_la_ventana_no_se_toca(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);

        // 5 minutos después del inicio: todavía adentro del timeout de 15.
        Carbon::setTestNow($inicio->copy()->addMinutes(5));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_agendada', $lead->status);
        $this->assertFalse((bool) $lead->demo_no_ingreso_notificado);
    }

    /**
     * Ventana extendida (demo_flexible + demo_experiencia='nueva'): excluido del timeout aunque
     * esté larguísimo pasado el horario nominal, igual que antes de esta misión.
     */
    public function test_ventana_extendida_queda_excluida(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        $lead->demo_flexible     = true;
        $lead->demo_experiencia  = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        /* A los 30 minutos: el timeout corto (15) no la toca, sigue agendada. */
        Carbon::setTestNow($inicio->copy()->addMinutes(30));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_agendada', $lead->status);

        /* Cambio de comportamiento (misión demo-agendado-directo, 10/9/2026): la ventana extendida
         * sigue excluida del timeout corto, pero desde esa misión toda ventana extendida de la
         * dinámica nueva es una demo DIRECTA, y a los 60 minutos del inicio sin ingreso (setting
         * demo_directa_no_show_minutos) pasa a demo_pendiente_de_ingreso. Antes de esa misión este
         * test afirmaba que a las 3 horas seguía agendada.
         *
         * 13/10/2026 (misión demo-dos-horas-y-experiencia): lo que ese vencimiento ya NO hace es
         * soltar el turno — acá se afirma que el turno sobrevive, que es lo contrario de lo que
         * decía esta misma línea hasta hoy ("el no-show libera la instancia"). El caso completo, con
         * la instancia y el mensaje, está en test_el_no_show_conserva_el_turno_y_manda_el_check(). */
        \App\Models\AdminSetting::set(\App\Services\LeadDemoSettings::KEY_DEMO_DIRECTA_NO_SHOW_MINUTOS, '60');
        Carbon::setTestNow($inicio->copy()->addHours(3));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status);
        $this->assertNotNull($lead->demo_date, 'El no-show ya no suelta el turno: la instancia sigue reservada.');
        $this->assertSame('10:00', substr((string) $lead->demo_start_time, 0, 5));
    }

    /**
     * Un lead ya en demo_en_curso (ya entró de verdad, lo puso el evento demo.ingreso) ni siquiera
     * es candidato: el filtro por status = 'demo_agendada' lo saca de la query desde el vamos.
     */
    public function test_lead_ya_en_demo_en_curso_no_es_candidato(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        $lead->status                  = 'demo_en_curso';
        $lead->demo_ingreso_confirmado = true;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addHours(2));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status);
    }

    /**
     * 🔴 La carrera real que arregla esta misión (demo-v2-estados-automaticos, 4/9/2026): el
     * comando arma sus candidatos en memoria con un solo `->get()`, y recién en el loop hace
     * llamadas de red (Google Calendar + WhatsApp) que pueden tardar segundos entre un candidato y
     * el siguiente. Si en el medio llega el evento real `demo.ingreso`
     * (`DemoEventosController::avanzar_pipeline_por_ingreso_real()`, un request HTTP totalmente
     * aparte) y avanza a ESTE MISMO lead a `demo_en_curso`, el UPDATE del comando tiene que
     * detectarlo y saltear el lead -- sin pisarlo de vuelta a `demo_pendiente_de_ingreso` y sin
     * disparar la notificación de "no ingresó" sobre un lead que sí está adentro.
     *
     * Se simula la carrera con un `DB::listen()`: apenas el comando ejecuta el SELECT que arma la
     * colección de candidatos (el lead todavía en memoria con status `demo_agendada`), pero ANTES
     * de que el loop llegue a su propio UPDATE, se aplica por fuera el mismo cambio que aplicaría
     * `avanzar_pipeline_por_ingreso_real()` sobre la fila real. Es la ventana exacta que describe
     * el bug: la colección en memoria queda vieja, la base ya no.
     */
    public function test_carrera_con_ingreso_real_no_pisa_el_avance(): void
    {
        $this->fijar_timeout(15);
        $inicio = Carbon::parse('2026-09-04 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_candidato($inicio);
        // Experiencia nueva porque es la única que puede llegar a demo_en_curso vía el evento real
        // demo.ingreso (usa_experiencia_demo_nueva()) -- demo_flexible sigue en false, así que
        // igual queda dentro de la ventana normal del timeout (no la extendida) y es candidato.
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addMinutes(20));

        $carrera_ya_aplicada = false;
        DB::listen(function ($query) use ($lead, &$carrera_ya_aplicada) {
            if ($carrera_ya_aplicada) {
                return;
            }
            $sql = strtolower($query->sql);
            if (strpos($sql, 'select') !== 0 || strpos($sql, 'from `leads`') === false) {
                return;
            }

            // Este es el SELECT de candidatos: el lead ya está en memoria con status
            // demo_agendada. Antes de que el loop del comando llegue a su UPDATE, otro camino
            // completamente aparte (el evento real de ingreso) avanza la fila de verdad.
            $carrera_ya_aplicada = true;
            DB::table('leads')->where('id', $lead->id)->update([
                'status'                     => 'demo_en_curso',
                'demo_ingreso_confirmado'    => true,
                'demo_ingreso_confirmado_at' => Carbon::now(),
            ]);
        });

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status, 'El comando no puede pisar de vuelta a demo_pendiente_de_ingreso un lead que ya entró de verdad.');
        $this->assertFalse((bool) $lead->demo_no_ingreso_notificado, 'No corresponde marcar el anti-duplicado de "no ingresó" sobre un lead que sí ingresó.');
    }

    /* ------------------------------------------------------------------ */
    /* El no-show de la demo DIRECTA, misión demo-dos-horas-y-experiencia    */
    /* (13/10/2026)                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 El cambio de conducta: a los 60 minutos sin ingreso el lead pasa a
     * `demo_pendiente_de_ingreso` pero CONSERVA el turno, y se le manda el "¿pudiste entrar?" con
     * la plantilla aprobada `cc_check_ingreso_demo`.
     *
     * Hasta hoy este vencimiento nuleaba `demo_id`/`demo_date`/`demo_start_time`/`demo_end_time`,
     * apagaba `demo_flexible` y volvía `demo_setup_status` a `pendiente`, y no mandaba ningún
     * mensaje. Con la reserva en dos horas eso le apagaba el botón al lead justo cuando se sentaba,
     * después de haberle prometido las dos horas enteras.
     *
     * Se mide todo junto porque es un solo comportamiento: el turno sobrevive, el mensaje sale, y
     * el lead puede entrar después (el evento real `demo.ingreso` lo lleva a `demo_en_curso` desde
     * `demo_pendiente_de_ingreso`, sin tocar ningún gate). Que la instancia vuelva al pool recién
     * al terminar la ventana lo cubre `DemoDirectaTest::test_el_no_show_conserva_el_turno_y_la_instancia_sigue_reservada()`,
     * que es donde vive la aritmética de ocupación.
     *
     * @return void
     */
    public function test_el_no_show_conserva_el_turno_y_manda_el_check(): void
    {
        $espia  = $this->espiar_whatsapp();
        $inicio = Carbon::parse('2026-10-13 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_de_demo_directa($inicio);

        Carbon::setTestNow($inicio->copy()->addMinutes(60));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status);
        $this->assertTrue((bool) $lead->demo_no_ingreso_notificado);

        /* El turno, intacto. */
        $this->assertNotNull($lead->demo_id, 'El turno se conserva: la instancia sigue reservada para él.');
        $this->assertNotNull($lead->demo_date);
        $this->assertSame('10:00', substr((string) $lead->demo_start_time, 0, 5));
        $this->assertSame('12:00', substr((string) $lead->demo_end_time, 0, 5));
        $this->assertTrue((bool) $lead->demo_flexible);
        $this->assertSame('exitoso', (string) $lead->demo_setup_status);

        /* El "¿pudiste entrar?", con la plantilla aprobada y el nombre de pila. */
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_check_ingreso_demo', $espia->plantillas[0]['template_name']);
        $this->assertSame(['Guillermo'], $espia->plantillas[0]['variables']);
        $this->assertTrue((bool) $lead->demo_check_ingreso_enviado);
        $this->assertNotNull($lead->demo_check_ingreso_enviado_at);
        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $lead->id,
            'sender'  => 'sistema',
            'content' => '¡Hola Guillermo! ¿Cómo vas? ¿Pudiste entrar a la demo?',
        ]);

        /* Y en el minuto 90 entra igual: el evento real lo avanza desde demo_pendiente_de_ingreso. */
        Carbon::setTestNow($inicio->copy()->addMinutes(90));
        $this->postJson('/api/demo-eventos', [
            'eventos' => [[
                'uuid'        => (string) Str::uuid(),
                'nombre'      => DemoHitosService::EVENTO_INGRESO,
                'clip_id'     => null,
                'ocurrido_at' => Carbon::now()->toIso8601String(),
                'datos'       => [],
            ]],
        ], ['X-Demo-Eventos-Key' => $lead->demo_eventos_token])->assertStatus(200);

        $lead->refresh();
        $this->assertSame('demo_en_curso', $lead->status, 'El lead que no entró en la primera hora tiene que poder entrar después.');
        $this->assertTrue((bool) $lead->demo_ingreso_confirmado);
    }

    /**
     * Si la demo no se armó (`demo_setup_status !== 'exitoso'`) NO se le pregunta si pudo entrar —
     * no pudo, el botón nunca se habilitó— pero el vencimiento corre igual: cambia el estado,
     * conserva el turno y avisa a admins. Misma guarda que `CheckDemoIngresoPostVideo`.
     *
     * @return void
     */
    public function test_sin_setup_exitoso_no_se_le_pregunta_pero_el_vencimiento_corre(): void
    {
        $espia  = $this->espiar_whatsapp();
        $inicio = Carbon::parse('2026-10-13 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_de_demo_directa($inicio);
        $lead->demo_setup_status = 'fallido';
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addMinutes(60));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status, 'El vencimiento corre igual.');
        $this->assertNotNull($lead->demo_id, 'Y el turno se conserva igual.');
        $this->assertCount(0, $espia->plantillas, 'Sin demo armada no se le pregunta si pudo entrar.');
        $this->assertFalse((bool) $lead->demo_check_ingreso_enviado);
    }

    /**
     * Si `CheckDemoIngresoPostVideo` ya le preguntó (flag `demo_check_ingreso_enviado` en true), el
     * no-show no insiste: los dos comandos comparten el flag justamente para no mandar el mismo
     * mensaje dos veces.
     *
     * @return void
     */
    public function test_si_ya_se_le_pregunto_el_no_show_no_insiste(): void
    {
        $espia  = $this->espiar_whatsapp();
        $inicio = Carbon::parse('2026-10-13 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_de_demo_directa($inicio);
        $lead->demo_check_ingreso_enviado = true;
        $lead->save();

        Carbon::setTestNow($inicio->copy()->addMinutes(60));

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status);
        $this->assertCount(0, $espia->plantillas, 'El post-video ya preguntó: no se duplica.');
    }

    /**
     * Con conversación en los últimos minutos (la ventana `check_ingreso_silencio_minutos`), el
     * mensaje automático no sale: hay alguien hablando con el lead. El vencimiento sí corre.
     *
     * @return void
     */
    public function test_con_conversacion_reciente_no_sale_el_check(): void
    {
        $espia  = $this->espiar_whatsapp();
        $inicio = Carbon::parse('2026-10-13 10:00:00', 'America/Argentina/Buenos_Aires');
        $lead   = $this->crear_lead_de_demo_directa($inicio);

        Carbon::setTestNow($inicio->copy()->addMinutes(60));

        $mensaje              = new LeadMessage();
        $mensaje->lead_id     = $lead->id;
        $mensaje->sender      = 'lead';
        $mensaje->status      = 'enviado';
        $mensaje->is_followup = false;
        $mensaje->content     = 'Perdón, estaba atendiendo.';
        $mensaje->save();
        LeadMessage::where('id', $mensaje->id)->update(['created_at' => Carbon::now()->subMinutes(3)]);

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $lead->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $lead->status, 'El vencimiento corre igual.');
        $this->assertCount(0, $espia->plantillas, 'Con conversación viva no se manda la plantilla.');
        $this->assertFalse((bool) $lead->demo_check_ingreso_enviado);
    }

    /**
     * Lead de demo DIRECTA listo para ser candidato del no-show: dinámica nueva, ventana extendida
     * (`demo_flexible`), instancia asignada, setup exitoso y su token de eventos para poder entrar
     * después.
     *
     * El entrante que deja el hilo se fecha ANTES del turno a propósito: con `created_at` = ahora, la
     * ventana de silencio del check de ingreso (10 minutos por default) leería "estamos hablando con
     * él" y el mensaje no saldría. Eso se prueba aparte, en
     * test_con_conversacion_reciente_no_sale_el_check().
     *
     * @param Carbon $inicio Inicio del turno.
     *
     * @return Lead
     */
    private function crear_lead_de_demo_directa(Carbon $inicio): Lead
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-noshow.test';
        $demo->erp_api_url       = 'https://demo-noshow-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-noshow.test';
        $demo->ecommerce_api_url = 'https://tienda-noshow-api.test';
        $demo->save();

        $lead                                = new Lead();
        $lead->uuid                          = (string) Str::uuid();
        $lead->contact_name                  = 'Guillermo González';
        $lead->phone                         = '5493511234567';
        $lead->status                        = 'demo_agendada';
        $lead->save();

        /* Después del save: el hook `creating` estampa la dinámica por defecto. */
        $lead->demo_experiencia              = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_id                       = $demo->id;
        $lead->demo_date                     = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time               = $inicio->format('H:i');
        $lead->demo_end_time                 = $inicio->copy()->addHours(2)->format('H:i');
        $lead->demo_flexible                 = true;
        $lead->demo_setup_status             = 'exitoso';
        $lead->demo_eventos_token            = Str::random(64);
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_check_ingreso_demo       = true;
        $lead->tiene_sugerencia_pendiente    = false;
        $lead->demo_ingreso_confirmado       = false;
        $lead->demo_no_ingreso_notificado    = false;
        $lead->demo_check_ingreso_enviado    = false;
        $lead->save();

        $entrante              = new LeadMessage();
        $entrante->lead_id     = $lead->id;
        $entrante->sender      = 'lead';
        $entrante->status      = 'enviado';
        $entrante->is_followup = false;
        $entrante->content     = 'Dale, hagámosla ahora.';
        $entrante->save();
        LeadMessage::where('id', $entrante->id)->update(['created_at' => $inicio->copy()->subMinutes(20)]);

        return $lead->refresh();
    }

    /**
     * Doble de `WhatsappSendService` que anota las plantillas en vez de mandarlas, igual que el que
     * usa `CheckDemoIngresoPostVideoTest`. Se registra en el contenedor para que el comando lo
     * reciba por su constructor.
     *
     * @return WhatsappSendService
     */
    private function espiar_whatsapp(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> */
            public $plantillas = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }
}
