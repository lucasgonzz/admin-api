<?php

namespace Tests\Feature;

use App\Exceptions\SinDemoLibreException;
use App\Helpers\AppTime;
use App\Mail\LeadDemoAccesoMail;
use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\DemoDirectaService;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La DEMO DIRECTA (misión demo-agendado-directo, 10/9/2026): el agente ofrece "te la puedo tener
 * lista en diez minutos", el lead dice que sí, y la instancia libre se asigna al aplicar la acción,
 * sin grilla ni horarios.
 *
 * Dos mitades:
 *
 *   1) Generación (casos 1 a 3): con el marcador `demo_directa` en el `.md` de la dinámica nueva,
 *      el modelo recibe el bloque DEMO DIRECTA con los dos links, no hay segunda llamada aunque
 *      pida disponibilidad, y `agendar_demo` se normaliza a `{ahora: true}` con la previsión de
 *      instancia. Sin el marcador, nada de esto existe (estado intermedio del despliegue).
 *   2) Aplicación (casos 4 a 8): al aprobar, se elige la instancia libre, se escribe la ventana
 *      (inicio en diez minutos, fin por ventana extendida), se corrige la URL de la tienda en el
 *      texto si la instancia cambió, sale la carta de acceso si hay email, y sin instancia libre
 *      se tira la excepción reintentable sin tocar nada.
 */
class DemoDirectaTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de todos los casos, un martes a media mañana. */
    const AHORA = '2026-09-08 10:00:00';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Argentina/Buenos_Aires'));
        Queue::fake();
        Mail::fake();

        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* 1 a 3 — generación                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * (1) Con el marcador vivo: el modelo recibe el bloque DEMO DIRECTA con la página (link corto por
     *     teléfono) y la tienda de la instancia prevista; pide disponibilidad y NO hay segunda
     *     llamada; su `agendar_demo: true` queda normalizado a `{ahora: true}` con la previsión; y
     *     la nota del system prompt reemplaza a la prohibición de rangos sin JSON.
     *
     * @return void
     */
    public function test_con_el_marcador_el_agente_recibe_el_bloque_y_no_hay_segunda_llamada(): void
    {
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido'        => 'Dale, te la dejo lista en diez minutos. Acá tenés tu página: https://admin.test/experiencia/5493511234567 y la tienda: https://tienda-1.test',
            'estado_sugerido'         => 'demo_agendada',
            'razonamiento'            => '',
            'solicita_disponibilidad' => true,
            'agendar_demo'            => true,
        ]);
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $mensaje = (new LeadAiService())->generate_suggestion($lead, false);

        $llamadas = 0;
        $prompt   = '';
        $system   = '';
        Http::assertSent(function ($request) use (&$llamadas, &$prompt, &$system) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }
            $llamadas++;
            $data   = $request->data();
            $prompt = isset($data['messages'][0]['content']) ? (string) $data['messages'][0]['content'] : $prompt;
            $system = isset($data['system'][0]['text']) ? (string) $data['system'][0]['text'] : $system;

            return true;
        });

        $this->assertSame(1, $llamadas, 'Con la demo directa no puede haber segunda llamada de disponibilidad.');
        $this->assertStringContainsString('DEMO DIRECTA', $prompt);
        $this->assertStringContainsString('/experiencia/5493511234567', $prompt, 'El link de la página tiene que ser el corto, por teléfono.');
        $this->assertStringContainsString('https://tienda-1.test', $prompt, 'La tienda de la instancia prevista tiene que estar en el contexto.');
        $this->assertStringContainsString('hay una instancia libre ahora mismo', $prompt);
        $this->assertStringContainsString('DINÁMICA DE DEMO DIRECTA', $system);
        $this->assertStringNotContainsString('Nunca anunciar un rango de horario propio sin JSON', $system);

        $this->assertSame('sugerido', $mensaje->status);
        $pendientes = $mensaje->pending_actions;
        $this->assertFalse((bool) ($pendientes['solicita_disponibilidad'] ?? false));
        $this->assertTrue((bool) ($pendientes['agendar_demo']['ahora'] ?? false));
        $this->assertSame($demo->id, (int) $pendientes['agendar_demo']['demo_id_previsto']);
        $this->assertSame('https://tienda-1.test', $pendientes['agendar_demo']['url_tienda_prevista']);
    }

    /**
     * (2) Sin el marcador (código nuevo, `.md` viejo): el bloque es el de siempre, el system prompt
     *     conserva la prohibición, y el modelo no ve nada de la demo directa. Es el estado
     *     intermedio del despliegue, y tiene que ser inerte de verdad.
     *
     * @return void
     */
    public function test_sin_el_marcador_no_cambia_nada(): void
    {
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Contame un poco de tu negocio.',
            'estado_sugerido'  => 'contactado',
            'razonamiento'     => '',
        ]);
        $this->sembrar_recurso_demo_agenda_v2("# Agenda de la demo\n\nOfrecele el primer horario del JSON.\n");
        $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        (new LeadAiService())->generate_suggestion($lead, false);

        $prompt = '';
        $system = '';
        Http::assertSent(function ($request) use (&$prompt, &$system) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }
            $data   = $request->data();
            $prompt = isset($data['messages'][0]['content']) ? (string) $data['messages'][0]['content'] : $prompt;
            $system = isset($data['system'][0]['text']) ? (string) $data['system'][0]['text'] : $system;

            return true;
        });

        $this->assertStringNotContainsString('DEMO DIRECTA', $prompt);
        $this->assertStringContainsString('PAGINA DE ACCESO A LA DEMO', $prompt);
        $this->assertStringContainsString('Nunca anunciar un rango de horario propio sin JSON', $system);
        $this->assertStringNotContainsString('DINÁMICA DE DEMO DIRECTA', $system);
    }

    /**
     * (3) Sin instancia libre al generar, el bloque le dice al agente a qué hora se libera la
     *     primera, para que no prometa "ahora".
     *
     * @return void
     */
    public function test_sin_instancia_libre_el_contexto_dice_cuando_se_libera(): void
    {
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'A partir de las 13:10 te la puedo tener lista, avisame.',
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
        ]);
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());
        $demo = $this->crear_demo(1);
        $this->ocupar_instancia($demo, '2026-09-08', '09:30', '13:00');
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        (new LeadAiService())->generate_suggestion($lead, false);

        $prompt = '';
        Http::assertSent(function ($request) use (&$prompt) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }
            $data   = $request->data();
            $prompt = isset($data['messages'][0]['content']) ? (string) $data['messages'][0]['content'] : $prompt;

            return true;
        });

        $this->assertStringContainsString('NO hay ninguna instancia libre ahora', $prompt);
        /* 13:00 de fin + 10 de gracia. */
        $this->assertStringContainsString('se libera a las 13:10', $prompt);
    }

    /**
     * (3-bis) Sin el recorte de la ventana (15/10/2026), un turno POSTERIOR en la misma instancia
     *      también la bloquea. La hora que se le dice al agente tiene que ser una liberación en la
     *      que la ventana ENTERA entra: con X hasta las 11:00 (se libera 11:10) e Y desde las 13:00,
     *      un "sí" apenas pasadas las 11:10 da una ventana (11:2x a 13:2x) que choca con Y; recién
     *      cuando se libera Y (15:10) entra.
     *
     * @return void
     */
    public function test_la_hora_de_liberacion_tiene_en_cuenta_los_turnos_posteriores(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '120');
        $demo = $this->crear_demo(1);
        $this->ocupar_instancia($demo, '2026-09-08', '09:00', '11:00');
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $directa = app(DemoDirectaService::class);
        $ahora   = AppTime::now(DemoDirectaService::TZ);

        /* Sin Y: X se libera a las 11:10 (11:00 + gracia 10; el bloqueo real da lo mismo). */
        $this->assertSame('11:10', $directa->proxima_liberacion($lead, $ahora)->format('H:i'));

        /* Con Y a las 13:00, las 11:10 dejaron de servir: hay que esperar a que se libere Y. */
        $this->ocupar_instancia($demo, '2026-09-08', '13:00', '15:00');
        $this->assertSame('15:10', $directa->proxima_liberacion($lead, $ahora)->format('H:i'));
    }

    /**
     * (3-ter) La frase de la reserva que dicta el prompt ("la tenés reservada dos horas, hasta las
     *      HH:MM", con el fin real) NO es "un horario concreto": el "sí" que la usa no se retiene por
     *      eso. Una hora que no es la del sistema sí se sigue reteniendo.
     *
     * @return void
     */
    public function test_la_frase_de_la_reserva_con_su_hora_real_no_dispara_el_detector(): void
    {
        $mensaje = $this->generar_si_con_la_reserva_hasta('12:10');

        $this->assertStringNotContainsString('horario concreto', (string) $mensaje->ai_reasoning, 'La frase de la reserva con su fin real (10:10 + dos horas) no es un horario inventado.');
    }

    /**
     * (3-ter, contracara) La misma frase con una hora que NO es la que dio el sistema se sigue
     *      reteniendo: la exención es para el fin real y nada más.
     *
     * @return void
     */
    public function test_la_frase_de_la_reserva_con_otra_hora_se_sigue_reteniendo(): void
    {
        $mensaje = $this->generar_si_con_la_reserva_hasta('15:30');

        $this->assertTrue((bool) ($mensaje->pending_actions['requiere_verificacion'] ?? false));
        $this->assertStringContainsString('horario concreto', (string) $mensaje->ai_reasoning, 'Una hora que no es el fin real se sigue reteniendo.');
    }

    /**
     * Un "sí" de la demo directa cuyo texto dice "la tenés reservada dos horas, hasta las {$hora}",
     * con la ventana en dos horas (el "ahora" es 10:00, así que el fin real es 12:10).
     *
     * @param string $hora
     *
     * @return LeadMessage
     */
    private function generar_si_con_la_reserva_hasta(string $hora): LeadMessage
    {
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '2');
        $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Dale! Se hace desde la computadora y dura menos de una hora; la tenés reservada dos horas, hasta las '
                . $hora . ', para que la recorras tranquilo. Entrá desde acá: ' . $lead->demo_experiencia_url
                . ' y la tienda: https://tienda-1.test',
            'estado_sugerido'  => 'demo_agendada',
            'agendar_demo'     => ['ahora' => true],
            'razonamiento'     => '',
        ]);
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());

        return (new LeadAiService())->generate_suggestion($lead, false);
    }

    /* ------------------------------------------------------------------ */
    /* 4 a 8 — aplicación al aprobar                                          */
    /* ------------------------------------------------------------------ */

    /**
     * (4) Al aprobar `{ahora: true}`: instancia libre asignada, inicio en diez minutos, fin por la
     *     ventana extendida (6 h), ventana extendida marcada, estado demo_agendada, y sin email no
     *     sale ninguna carta.
     *
     * @return void
     */
    public function test_al_aprobar_asigna_la_instancia_libre_y_escribe_la_ventana(): void
    {
        $demo    = $this->crear_demo(1);
        $lead    = $this->crear_lead_de_la_dinamica_nueva();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));

        $lead->refresh();
        $this->assertSame($demo->id, (int) $lead->demo_id);
        $this->assertSame('2026-09-08', $lead->demo_date->format('Y-m-d'));
        $this->assertSame('10:10', substr((string) $lead->demo_start_time, 0, 5));
        $this->assertSame('16:10', substr((string) $lead->demo_end_time, 0, 5));
        $this->assertTrue((bool) $lead->demo_flexible, 'La demo directa siempre es ventana extendida.');
        $this->assertSame('demo_agendada', $lead->status);

        $mensaje->refresh();
        $this->assertNull($mensaje->pending_actions, 'Las acciones pendientes se consumen al aplicar.');
        $this->assertStringContainsString('Agendar demo: ahora', (string) json_encode($mensaje->applied_actions_summary, JSON_UNESCAPED_UNICODE));

        Mail::assertNothingSent();
    }

    /**
     * (5) La instancia ocupada se saltea (aunque fuera la prevista) y la URL de su tienda, que el
     *     modelo escribió en el texto, se reemplaza por la de la instancia asignada.
     *
     * @return void
     */
    public function test_al_aprobar_saltea_la_ocupada_y_corrige_la_tienda_en_el_texto(): void
    {
        $demo1 = $this->crear_demo(1);
        $demo2 = $this->crear_demo(2);
        $this->ocupar_instancia($demo1, '2026-09-08', '09:30', '13:00');
        $lead    = $this->crear_lead_de_la_dinamica_nueva();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo1, 'Acá tenés la tienda: https://tienda-1.test/ y tu página.');

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));

        $lead->refresh();
        $this->assertSame($demo2->id, (int) $lead->demo_id, 'La instancia ocupada no se puede asignar.');

        $mensaje->refresh();
        $this->assertStringContainsString('https://tienda-2.test', (string) $mensaje->content);
        $this->assertStringNotContainsString('tienda-1.test', (string) $mensaje->content);
    }

    /**
     * (6) 🔴 Sin instancia libre al aprobar: excepción REINTENTABLE que nombra a qué hora se libera
     *     la primera, y nada cambia: ni el lead, ni el mensaje (sigue sugerido con sus acciones).
     *
     * @return void
     */
    public function test_sin_instancia_libre_al_aprobar_tira_excepcion_reintentable_y_no_toca_nada(): void
    {
        $demo = $this->crear_demo(1);
        $this->ocupar_instancia($demo, '2026-09-08', '09:30', '13:00');
        $lead    = $this->crear_lead_de_la_dinamica_nueva();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        try {
            (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));
            $this->fail('Tenía que tirar SinDemoLibreException.');
        } catch (SinDemoLibreException $e) {
            $this->assertStringContainsString('13:10', $e->getMessage());
        }

        $lead->refresh();
        $this->assertNull($lead->demo_id);
        $this->assertSame('calificado', $lead->status);
        $this->assertFalse((bool) $lead->requiere_intervencion_humana);

        $mensaje->refresh();
        $this->assertSame('sugerido', $mensaje->status);
        $this->assertNotNull($mensaje->pending_actions, 'Las acciones tienen que seguir pendientes para volver a aprobar.');
    }

    /**
     * (7) Con el email ya cargado, la carta de acceso sale al asignar, a ese email, con las dos
     *     llaves de ESA instancia.
     *
     * @return void
     */
    public function test_con_email_la_carta_de_acceso_sale_al_asignar(): void
    {
        $demo        = $this->crear_demo(1);
        $lead        = $this->crear_lead_de_la_dinamica_nueva();
        $lead->email = 'lead@test.local';
        $lead->save();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));

        Mail::assertSent(LeadDemoAccesoMail::class, function (LeadDemoAccesoMail $mail) {
            return $mail->hasTo('lead@test.local')
                && strpos($mail->url_experiencia, '/experiencia/5493511234567') !== false
                && $mail->url_tienda === 'https://tienda-1.test';
        });

        $this->assertNotNull($lead->refresh()->demo_mail_sent_at);
    }

    /**
     * (8) El email que llega DESPUÉS, como `guardar_email` suelto (el camino normal de esta
     *     dinámica): manda la carta y no deriva a intervención humana, que es lo que la guarda vieja
     *     de "agenda fuera de secuencia" hacía con cualquier guardar_email sin agendar_demo.
     *
     * @return void
     */
    public function test_el_email_que_llega_despues_manda_la_carta_y_no_deriva_a_intervencion(): void
    {
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Listo, ya te mandé las llaves al mail.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'guardar_email'    => 'lead@test.local',
        ]);
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        /* Demo ya asignada en un turno anterior. */
        $lead->status          = 'demo_agendada';
        $lead->demo_id         = $demo->id;
        $lead->demo_date       = '2026-09-08';
        $lead->demo_start_time = '09:40';
        $lead->demo_end_time   = '15:40';
        $lead->demo_flexible   = true;
        $lead->save();

        $mensaje = (new LeadAiService())->generate_suggestion($lead->refresh(), false);

        $this->assertFalse((bool) ($mensaje->pending_actions['requiere_intervencion_humana'] ?? false), 'guardar_email suelto es el camino normal de la demo directa, no una agenda fuera de secuencia.');
        $this->assertSame('lead@test.local', $mensaje->pending_actions['guardar_email']);

        /* El tramo de agenda retiene el mensaje: se aprueba y ahí sale la carta. */
        (new LeadAiService())->apply_pending_actions($mensaje->fresh(), $this->final_actions_del_panel($lead, ['guardar_email' => 'lead@test.local', 'agendar_demo' => null]));

        Mail::assertSent(LeadDemoAccesoMail::class, function (LeadDemoAccesoMail $mail) {
            return $mail->hasTo('lead@test.local');
        });
        $this->assertSame('lead@test.local', $lead->refresh()->email);
        $this->assertFalse((bool) $lead->requiere_intervencion_humana);
    }

    /**
     * (8b) 🔴 `guardar_email` + `agendar_demo` en el MISMO paquete —el orden nuevo: el correo se pide ANTES de los
     *      links (misión mail-demo-turno-y-asistente)— mandan UNA sola carta, ya con el turno asignado: el mail
     *      sale con el día y la hora reales, no con un turno vacío ni dos veces.
     *
     * @return void
     */
    public function test_guardar_email_y_agendar_demo_juntos_mandan_una_sola_carta_con_el_turno(): void
    {
        $demo    = $this->crear_demo(1);
        $lead    = $this->crear_lead_de_la_dinamica_nueva();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead, ['guardar_email' => 'lead@test.local']));

        Mail::assertSent(LeadDemoAccesoMail::class, 1);
        Mail::assertSent(LeadDemoAccesoMail::class, function (LeadDemoAccesoMail $mail) {
            $mail->build();

            return $mail->hasTo('lead@test.local')
                && is_array($mail->turno)
                && $mail->turno['hora_inicio'] === '10:10'
                && $mail->turno['es_hoy'] === true
                && strpos((string) $mail->subject, 'hoy a las 10:10') !== false;
        });
        $this->assertSame('lead@test.local', $lead->refresh()->email);
        $this->assertNotNull($lead->demo_mail_sent_at);
    }

    /**
     * (8c) 🔴 Al reasignar al lead —casi siempre la MISMA instancia, con otra hora de inicio— se manda otra
     *      carta: la anterior le dice un día y una hora que ya no son los suyos (desde esta misión el mail lleva
     *      el turno).
     *
     * @return void
     */
    public function test_al_reasignar_la_misma_instancia_con_otro_turno_se_manda_otra_carta(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $lead->email             = 'lead@test.local';
        $lead->status            = 'calificado';
        $lead->demo_id           = $demo->id;
        $lead->demo_date         = '2026-09-07';
        $lead->demo_start_time   = '10:10';
        $lead->demo_end_time     = '12:10';
        $lead->demo_mail_sent_at = AppTime::now()->subDay();
        $lead->save();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));

        $this->assertSame($demo->id, (int) $lead->refresh()->demo_id, 'Misma instancia.');
        Mail::assertSent(LeadDemoAccesoMail::class, 1);
        Mail::assertSent(LeadDemoAccesoMail::class, function (LeadDemoAccesoMail $mail) {
            $mail->build();

            return is_array($mail->turno) && $mail->turno['es_hoy'] === true;
        });
    }

    /**
     * (8d) La guardia anti-ráfaga: una carta que salió hace menos de 5 minutos NO se repite por un cambio de turno
     *      sobre la misma instancia (un doble `agendar_demo`), y sí por un cambio de INSTANCIA (la carta vieja
     *      apunta a otra tienda).
     *
     * @return void
     */
    public function test_la_guardia_de_cinco_minutos_frena_el_cambio_de_turno_pero_no_el_de_instancia(): void
    {
        $demo1 = $this->crear_demo(1);
        $demo2 = $this->crear_demo(2);

        // (a) Misma instancia, otro turno, carta de hace dos minutos: no se repite.
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $lead->email             = 'lead@test.local';
        $lead->status            = 'calificado';
        $lead->demo_id           = $demo1->id;
        $lead->demo_date         = '2026-09-08';
        $lead->demo_start_time   = '09:00';
        $lead->demo_end_time     = '11:00';
        $lead->demo_mail_sent_at = AppTime::now()->subMinutes(2);
        $lead->save();

        (new LeadAiService())->apply_pending_actions($this->crear_mensaje_pendiente($lead, $demo1), $this->final_actions_del_panel($lead));

        Mail::assertNothingSent();

        // (b) Otra instancia, carta de hace dos minutos: sí se manda (la vieja apunta a otra tienda).
        $this->ocupar_instancia($demo1, '2026-09-08', '09:30', '13:00');
        $otro = $this->crear_lead_de_la_dinamica_nueva();
        $otro->phone              = '5493511239998';
        $otro->email              = 'otro@test.local';
        $otro->status             = 'calificado';
        $otro->demo_id            = $demo1->id;
        $otro->demo_date          = '2026-09-08';
        $otro->demo_start_time    = '09:00';
        $otro->demo_end_time      = '11:00';
        $otro->demo_mail_sent_at  = AppTime::now()->subMinutes(2);
        $otro->save();

        (new LeadAiService())->apply_pending_actions($this->crear_mensaje_pendiente($otro, $demo2), $this->final_actions_del_panel($otro));

        $this->assertSame($demo2->id, (int) $otro->refresh()->demo_id, 'La instancia ocupada se saltea: se asigna la otra.');
        Mail::assertSent(LeadDemoAccesoMail::class, 1);
    }

    /* ------------------------------------------------------------------ */
    /* 9 a 14 — lo que encontró el chequeo adversarial                       */
    /* ------------------------------------------------------------------ */

    /**
     * (9) 🔴 CAMBIO DE CONDUCTA del 13/10/2026 (misión demo-dos-horas-y-experiencia): la instancia
     *     que tiene un turno más tarde YA NO SIRVE.
     *
     *     Hasta esa fecha este test afirmaba lo contrario: la ventana se recortaba para terminar
     *     antes del turno vecino (15:00 − 15 de setup − 10 de gracia − 1 = 14:34) y la instancia se
     *     asignaba igual, con media reserva. Eso se podía defender mientras la ventana extendida
     *     fueran seis horas y nadie le prometiera nada al lead. Desde esta misión el agente le dice
     *     "la tenés reservada dos horas, hasta las HH:MM", así que una reserva recortada sería una
     *     promesa incumplida: `DemoDirectaService::instancia_libre()` descarta la instancia y, si no
     *     queda ninguna, la asignación falla con la excepción reintentable que nombra desde qué hora
     *     sí (el mismo camino del caso 6).
     *
     *     Acá la única instancia está tomada de 15:00 a 16:00, y su fin REAL es 17:00
     *     (15:00 + bloqueo_real 120), que es la hora que tiene que nombrar el aviso.
     *
     * @return void
     */
    public function test_la_instancia_con_un_turno_mas_tarde_ya_no_se_usa(): void
    {
        $demo = $this->crear_demo(1);
        $this->ocupar_instancia($demo, '2026-09-08', '15:00', '16:00');
        $lead    = $this->crear_lead_de_la_dinamica_nueva();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        /* Antes de esta misión acá se asignaba la instancia con la ventana recortada a las 14:34. */
        $this->assertNull(
            (new DemoDirectaService())->elegir_prevista($lead)['demo'],
            'La instancia con un turno vecino no puede ofrecerse: la ventana entera no entra.'
        );

        try {
            (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));
            $this->fail('Sin instancia libre para la ventana entera, aplicar el "sí" tiene que tirar SinDemoLibreException.');
        } catch (SinDemoLibreException $e) {
            $this->assertStringContainsString('17:00', $e->getMessage(), 'El aviso tiene que decir desde qué hora se libera (15:00 + bloqueo_real 120).');
        }

        $lead->refresh();
        $this->assertNull($lead->demo_id, 'La excepción es reintentable: no puede dejar nada escrito.');
        $this->assertNull($lead->demo_date);
    }

    /**
     * (9 bis) Y el contrapunto: con una segunda instancia libre, se usa ÉSA y con la ventana
     *         ENTERA (10:10 a 16:10, las seis horas que este archivo fija en su setUp), no la
     *         recortada de la primera. Es lo que vuelve aceptable el descarte del caso anterior:
     *         se saltea la instancia comprometida y se prueba la siguiente.
     *
     * @return void
     */
    public function test_con_otra_instancia_libre_se_usa_esa_con_la_ventana_entera(): void
    {
        $demo_con_turno = $this->crear_demo(1);
        $demo_libre     = $this->crear_demo(2);
        $this->ocupar_instancia($demo_con_turno, '2026-09-08', '15:00', '16:00');
        $lead    = $this->crear_lead_de_la_dinamica_nueva();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo_con_turno);

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));

        $lead->refresh();
        $this->assertSame($demo_libre->id, (int) $lead->demo_id, 'Se tiene que saltear la instancia comprometida y tomar la siguiente.');
        $this->assertSame('10:10', substr((string) $lead->demo_start_time, 0, 5));
        $this->assertSame('16:10', substr((string) $lead->demo_end_time, 0, 5), 'La ventana va entera: ya no se recorta por ningún vecino.');
    }

    /**
     * (10) Al asignar se resetean los relojes del turno anterior y el setup vuelve a `pendiente`,
     *      para que la instancia se vuelva a preparar y el recordatorio y el check puedan salir.
     *
     * @return void
     */
    public function test_al_asignar_se_resetean_los_relojes_y_el_setup(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $lead->recordatorio_demo_enviado  = true;
        $lead->demo_check_ingreso_enviado = true;
        $lead->demo_setup_status          = 'exitoso';
        $lead->save();
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead));

        $lead->refresh();
        $this->assertFalse((bool) $lead->recordatorio_demo_enviado);
        $this->assertFalse((bool) $lead->demo_check_ingreso_enviado);
        $this->assertSame('pendiente', (string) $lead->demo_setup_status);
    }

    /**
     * (11) "No voy a poder entrar" (marcar_no_ingreso) en una demo directa LIBERA la instancia: se
     *      limpia el turno y el setup, y la página deja de habilitar el botón.
     *
     * @return void
     */
    public function test_marcar_no_ingreso_libera_la_instancia_y_cierra_la_pagina(): void
    {
        config(['services.admin_spa.url' => 'https://admin.test']);
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        $lead->status            = 'demo_agendada';
        $lead->demo_id           = $demo->id;
        $lead->demo_date         = '2026-09-08';
        $lead->demo_start_time   = '09:40';
        $lead->demo_end_time     = '15:40';
        $lead->demo_flexible     = true;
        $lead->demo_setup_status = 'exitoso';
        $lead->intro_visto_pct   = 100;
        $lead->save();

        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'sistema';
        $mensaje->status                = 'sugerido';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = true;
        $mensaje->content               = 'Dale, avisame cuando puedas y en diez minutos la tenés lista.';
        $mensaje->pending_actions       = [
            'mensaje_sugerido'  => $mensaje->content,
            'estado_sugerido'   => 'demo_agendada',
            'razonamiento'      => '',
            'marcar_no_ingreso' => true,
        ];
        $mensaje->save();

        $mensaje = (new LeadAiService())->apply_pending_actions($mensaje, $this->final_actions_del_panel($lead, ['agendar_demo' => null]));

        /* El cambio de estado se aplica al ENVIAR (apply_suggested_pipeline_status), como con
         * cualquier acción del ciclo; acá se verifica la decisión y la liberación del turno. */
        $this->assertSame('demo_pendiente_de_ingreso', $mensaje->suggested_lead_status);
        $lead->refresh();
        $this->assertNull($lead->demo_id);
        $this->assertNull($lead->demo_date);
        $this->assertSame('pendiente', (string) $lead->demo_setup_status);

        /* Otro lead puede tomar la instancia ahora mismo. */
        $otro = $this->crear_lead_de_la_dinamica_nueva();
        $this->assertNotNull((new DemoDirectaService())->elegir_prevista($otro)['demo']);

        /* Y la página del primero ya no habilita el botón. */
        $this->getJson('/api/demo-experiencia/' . $lead->uuid)
            ->assertStatus(200)
            ->assertJsonPath('puede_ingresar', false)
            ->assertJsonPath('turno.estado', 'sin_turno');
    }

    /**
     * (12) 🔴 CAMBIO DE CONDUCTA del 13/10/2026 (misión demo-dos-horas-y-experiencia): el no-show de
     *      los 60 minutos YA NO libera la instancia.
     *
     *      Hasta esa fecha este test afirmaba que el lead perdía el turno (`demo_id`, `demo_date` y
     *      el setup en `pendiente`) para que la instancia quedara libre al toque. Tenía sentido con
     *      la ventana extendida en SEIS horas; con la reserva en DOS, y sobre todo desde que el
     *      agente le promete al lead "la tenés reservada dos horas", vaciarle el turno a los 60
     *      minutos era apagarle el botón justo cuando se sentaba.
     *
     *      Ahora el turno queda intacto y la instancia sigue reservada hasta el fin REAL de la
     *      ventana — `max(fin + gracia, inicio + bloqueo_real)`, que para el turno de 08:30 a 14:30
     *      de este test da las 14:40. Lo que se mide acá es justamente eso: que ningún otro lead
     *      pueda tomarla antes, y que después sí.
     *
     *      Y ahora SÍ sale un mensaje: el "¿pudiste entrar?" con la plantilla aprobada
     *      `cc_check_ingreso_demo` (el detalle de ese envío, con sus guardas, lo cubre
     *      `CheckDemoIngresoTimeoutTest`).
     *
     * @return void
     */
    public function test_el_no_show_conserva_el_turno_y_la_instancia_sigue_reservada(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_DIRECTA_NO_SHOW_MINUTOS, '60');
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        /* Dos instancias: la 1 la tiene un lead que arrancó hace 30 minutos (no es no-show todavía)
         * y la 2 el que arrancó hace 90 (sí lo es). Separadas para poder medir la instancia del
         * no-show sin que la del otro lead tape el resultado. */
        $demo_reciente = $this->crear_demo(1);
        $demo_vencida  = $this->crear_demo(2);

        $reciente = $this->crear_lead_de_la_dinamica_nueva();
        $this->dejar_en_demo_directa($reciente, $demo_reciente, '09:40', '15:40');

        $vencido = $this->crear_lead_de_la_dinamica_nueva();
        $vencido->phone = '5493519999998';
        $vencido->save();
        $this->dejar_en_demo_directa($vencido, $demo_vencida, '08:30', '14:30');

        /* El helper deja un entrante del lead con `created_at` = ahora, y la ventana de silencio
         * del check de ingreso (10 minutos por default) lo leería como "estamos hablando con él" y
         * se saltearía el mensaje. Se corre al momento en que de verdad dijo que sí. */
        LeadMessage::where('lead_id', $vencido->id)->update(['created_at' => Carbon::parse('2026-09-08 08:20:00', 'America/Argentina/Buenos_Aires')]);

        $this->artisan('leads:check-demo-ingreso-timeout')->assertExitCode(0);

        $reciente->refresh();
        $this->assertSame('demo_agendada', $reciente->status, 'Arrancó hace 30 minutos: todavía no es no-show.');
        $this->assertSame($demo_reciente->id, (int) $reciente->demo_id);

        /* El vencido cambia de estado pero NO de turno. */
        $vencido->refresh();
        $this->assertSame('demo_pendiente_de_ingreso', $vencido->status, 'Arrancó hace 90 minutos sin entrar: cambia de estado.');
        $this->assertSame($demo_vencida->id, (int) $vencido->demo_id, 'El turno se conserva: la instancia sigue siendo suya.');
        $this->assertNotNull($vencido->demo_date);
        $this->assertSame('08:30', substr((string) $vencido->demo_start_time, 0, 5));
        $this->assertSame('14:30', substr((string) $vencido->demo_end_time, 0, 5));
        $this->assertTrue((bool) $vencido->demo_flexible, 'Sigue siendo una demo directa: el flexible no se apaga.');
        $this->assertSame('exitoso', (string) $vencido->demo_setup_status, 'La instancia está armada y lista: el setup no vuelve a pendiente.');

        /* Y le salió el "¿pudiste entrar?". */
        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $vencido->id,
            'sender'  => 'sistema',
            'status'  => 'enviado',
            'content' => '¡Hola Guillermo! ¿Cómo vas? ¿Pudiste entrar a la demo?',
        ]);
        $this->assertTrue((bool) $vencido->demo_check_ingreso_enviado);

        /* Ahora mismo (10:00) ninguna de las dos instancias se puede ofrecer: la 1 la tiene el
         * reciente y la 2 sigue reservada para el que no entró. */
        $otro = $this->crear_lead_de_la_dinamica_nueva();
        $this->assertNull(
            (new DemoDirectaService())->elegir_prevista($otro)['demo'],
            'La instancia del no-show sigue reservada: no puede ofrecérsele a otro lead.'
        );

        /* Pasado el fin real de esa ventana (14:40), la instancia 2 sí queda libre. */
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:50:00', 'America/Argentina/Buenos_Aires'));
        $libre = (new DemoDirectaService())->elegir_prevista($otro->refresh());
        $this->assertNotNull($libre['demo'], 'Pasada la ventana, la instancia tiene que volver al pool.');
        $this->assertSame($demo_vencida->id, (int) $libre['demo']->id);
    }

    /**
     * (13) Guardas de generación: el "sí" sin los dos links en el texto, o con una hora escrita, se
     *      retiene para verificación; y `demo_agendada` sin agendar_demo no mueve el estado.
     *
     * @return void
     */
    public function test_el_si_sin_links_o_con_hora_se_retiene_y_demo_agendada_sin_agendar_no_mueve(): void
    {
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Dale, te la dejo lista para las 15:30.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => 'ok',
            'agendar_demo'     => ['demo_id' => 1, 'demo_date' => '2026-09-08', 'demo_start_time' => '15:30'],
        ]);
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());
        $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $mensaje = (new LeadAiService())->generate_suggestion($lead, false);

        $pendientes = $mensaje->pending_actions;
        $this->assertTrue((bool) ($pendientes['agendar_demo']['ahora'] ?? false), 'La forma vieja se normaliza a {ahora: true}.');
        $this->assertTrue((bool) ($pendientes['requiere_verificacion'] ?? false));
        $this->assertStringContainsString('horario concreto', (string) $mensaje->ai_reasoning);
        $this->assertStringContainsString('no trae el link de la página', (string) $mensaje->ai_reasoning);
        $this->assertStringContainsString('no trae el link de la tienda', (string) $mensaje->ai_reasoning);
        $this->assertSame([], $pendientes['horarios_ofrecidos']);
    }

    /**
     * (13-bis) `estado_sugerido: demo_agendada` sin `agendar_demo` (el lead 30 del 4/8/2026): no se
     *      mueve el estado y se retiene para verificación. Con grilla lo frenaba la segunda llamada;
     *      en la demo directa no hay segunda llamada.
     *
     * @return void
     */
    public function test_demo_agendada_sin_agendar_demo_no_mueve_el_estado(): void
    {
        $this->sembrar_entorno_del_agente([
            'mensaje_sugerido' => 'Genial, en diez minutos entrás.',
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
        ]);
        $this->sembrar_recurso_demo_agenda_v2($this->md_con_marcador());
        $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $mensaje = (new LeadAiService())->generate_suggestion($lead, false);

        $this->assertSame('calificado', $mensaje->pending_actions['estado_sugerido'], 'demo_agendada sin agendar_demo no puede mover al lead.');
        $this->assertNull($mensaje->suggested_lead_status);
        $this->assertTrue((bool) ($mensaje->pending_actions['requiere_verificacion'] ?? false));
        $this->assertStringContainsString('sin devolver agendar_demo', (string) $mensaje->ai_reasoning);
    }

    /**
     * (14) Con la ventana de 24 hs de Meta cerrada, aprobar un "sí" viejo NO asigna la demo: el
     *      mensaje se rechaza sin tocar al lead (antes se asignaba "para ahora" 25 horas después
     *      del sí, salía la carta y recién después se descubría que no se podía mandar).
     *
     * @return void
     */
    public function test_con_la_sesion_de_meta_cerrada_no_se_asigna_al_aprobar(): void
    {
        $demo = $this->crear_demo(1);
        $lead = $this->crear_lead_de_la_dinamica_nueva();
        /* El único entrante del lead fue hace dos días: ventana cerrada. */
        LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->update([
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);
        $mensaje = $this->crear_mensaje_pendiente($lead, $demo);

        (new \App\Services\LeadSuggestionSendService())->send_suggestion($mensaje, null, $this->final_actions_del_panel($lead));

        $lead->refresh();
        $this->assertNull($lead->demo_id);
        $this->assertSame('calificado', $lead->status);
        $this->assertSame('rechazado', $mensaje->fresh()->status);
        Mail::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /* 15 — bloqueo real (11/9/2026)                                         */
    /* ------------------------------------------------------------------ */

    /**
     * (15) 🔴 El pedido de Lucas: con el bloqueo real en 180 minutos, una instancia sigue ocupada
     *      mucho después de que "duración + gracia" (70 min con la config de este archivo) ya
     *      pasaron. Con una sola instancia (`crear_demo(1)`) y un ocupante que arrancó a las 09:00 y
     *      terminó (nominal) a las 10:00, un pedido a las 11:55 —bajo la lógica vieja ya libre desde
     *      las 10:10— tiene que seguir sin instancia disponible, porque 09:00 + 180 = 12:00.
     *
     * @return void
     */
    public function test_bloqueo_real_mantiene_la_instancia_ocupada_mas_alla_de_duracion_mas_gracia(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '180');
        $demo = $this->crear_demo(1);
        $this->ocupar_instancia($demo, '2026-09-08', '09:00', '10:00');
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $inicio_pedido = Carbon::parse('2026-09-08 11:55:00', DemoDirectaService::TZ);
        $fin_pedido    = Carbon::parse('2026-09-08 17:55:00', DemoDirectaService::TZ);

        $libre = (new DemoDirectaService())->instancia_libre($lead, $inicio_pedido, $fin_pedido);

        $this->assertNull($libre, 'A las 11:55 la única instancia tiene que seguir ocupada: 09:00 + bloqueo_real(180) = 12:00.');
    }

    /**
     * (15-bis) Control positivo del caso anterior: bien pasado el bloqueo real (12:30, con el
     * margen del setup de por medio: 12:00 + 15 min de setup), la misma instancia vuelve a estar
     * libre. Sin este control, (15) no distingue "el bloqueo real frena de verdad" de "esta
     * instancia nunca se libera".
     *
     * @return void
     */
    public function test_pasado_el_bloqueo_real_la_instancia_vuelve_a_estar_libre(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_BLOQUEO_REAL_MINUTOS, '180');
        $demo = $this->crear_demo(1);
        $this->ocupar_instancia($demo, '2026-09-08', '09:00', '10:00');
        $lead = $this->crear_lead_de_la_dinamica_nueva();

        $inicio_pedido = Carbon::parse('2026-09-08 12:30:00', DemoDirectaService::TZ);
        $fin_pedido    = Carbon::parse('2026-09-08 18:30:00', DemoDirectaService::TZ);

        $libre = (new DemoDirectaService())->instancia_libre($lead, $inicio_pedido, $fin_pedido);

        $this->assertNotNull($libre, 'A las 12:30 (12:00 del bloqueo real + 15 de setup), la instancia tiene que volver a estar libre.');
        $this->assertSame($demo->id, $libre['demo']->id);
    }

    /* ------------------------------------------------------------------ */
    /* helpers                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Deja al lead como quedó después de aceptar la demo directa (turno asignado, automatizaciones
     * prendidas, sin ingreso).
     *
     * @param Lead   $lead
     * @param Demo   $demo
     * @param string $inicio
     * @param string $fin
     *
     * @return void
     */
    private function dejar_en_demo_directa(Lead $lead, Demo $demo, string $inicio, string $fin): void
    {
        $lead->status                        = 'demo_agendada';
        $lead->demo_id                       = $demo->id;
        $lead->demo_date                     = '2026-09-08';
        $lead->demo_start_time               = $inicio;
        $lead->demo_end_time                 = $fin;
        $lead->demo_flexible                 = true;
        $lead->demo_setup_status             = 'exitoso';
        $lead->automatizaciones_demo_activas = true;
        $lead->auto_check_ingreso_demo       = true;
        $lead->tiene_sugerencia_pendiente    = false;
        $lead->demo_ingreso_confirmado       = false;
        $lead->demo_no_ingreso_notificado    = false;
        $lead->save();
    }

    /**
     * Fake de la API + cola fakeada + prompt base + system base. Lo que `generate_suggestion()`
     * necesita para llegar hasta la llamada a Claude sin salir a ningún lado.
     *
     * @param array<string, mixed> $respuesta_del_modelo JSON que "devuelve" el modelo.
     *
     * @return void
     */
    private function sembrar_entorno_del_agente(array $respuesta_del_modelo): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        config(['services.admin_spa.url' => 'https://admin.test']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode($respuesta_del_modelo, JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => 'System base de prueba.',
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * El recurso `demo_agenda` de la dinámica nueva, donde vive el marcador.
     *
     * @param string $contenido
     *
     * @return void
     */
    private function sembrar_recurso_demo_agenda_v2(string $contenido): void
    {
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => $contenido,
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * @return string
     */
    private function md_con_marcador(): string
    {
        return "# Agenda de la demo\n\n## DEMO DIRECTA\n\nOfrecé \"te la puedo tener lista en diez minutos\" y devolvé agendar_demo: {\"ahora\": true}.\nMarcador: " . LeadAiService::MARCADOR_DEMO_DIRECTA . "\n";
    }

    /**
     * Lead de la dinámica nueva, calificado, sin turno, con teléfono (para el link corto).
     *
     * @return Lead
     */
    private function crear_lead_de_la_dinamica_nueva(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '5493511234567';
        $lead->status       = 'calificado';
        $lead->save();

        /* Después del save: el hook `creating` estampa la dinámica por defecto. */
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        $inbound              = new LeadMessage();
        $inbound->lead_id     = $lead->id;
        $inbound->sender      = 'lead';
        $inbound->status      = 'enviado';
        $inbound->is_followup = false;
        $inbound->content     = 'Dale, hagámosla ahora.';
        $inbound->save();

        return $lead->refresh();
    }

    /**
     * Instancia de demo con tienda propia (`https://tienda-N.test`).
     *
     * @param int $n
     *
     * @return Demo
     */
    private function crear_demo(int $n): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-' . $n . '.test';
        $demo->erp_api_url       = 'https://demo-' . $n . '-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-' . $n . '.test';
        $demo->ecommerce_api_url = 'https://tienda-' . $n . '-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Otro lead en demo_agendada ocupando la instancia en esa franja.
     *
     * @param Demo   $demo
     * @param string $fecha
     * @param string $inicio
     * @param string $fin
     *
     * @return Lead
     */
    private function ocupar_instancia(Demo $demo, string $fecha, string $inicio, string $fin): Lead
    {
        $otro                  = new Lead();
        $otro->uuid            = (string) Str::uuid();
        $otro->contact_name    = 'Otro lead';
        $otro->phone           = '5493519999999';
        $otro->status          = 'demo_agendada';
        $otro->demo_id         = $demo->id;
        $otro->demo_date       = $fecha;
        $otro->demo_start_time = $inicio;
        $otro->demo_end_time   = $fin;
        $otro->save();

        return $otro;
    }

    /**
     * Mensaje `sugerido` con el paquete de la demo directa, tal cual lo deja generate_suggestion().
     *
     * @param Lead        $lead
     * @param Demo        $prevista
     * @param string|null $texto
     *
     * @return LeadMessage
     */
    private function crear_mensaje_pendiente(Lead $lead, Demo $prevista, ?string $texto = null): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'sistema';
        $mensaje->status                = 'sugerido';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = true;
        $mensaje->content               = $texto !== null ? $texto : 'Dale, en diez minutos la tenés lista. Acá tenés tu página.';
        $mensaje->pending_actions       = [
            'mensaje_sugerido' => $mensaje->content,
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
            'agendar_demo'     => [
                'ahora'               => true,
                'demo_id_previsto'    => $prevista->id,
                'url_tienda_prevista' => DemoDirectaService::url_tienda($prevista),
            ],
        ];
        $mensaje->save();

        return $mensaje;
    }

    /**
     * `final_actions` como los manda el panel de verificación para la demo directa.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function final_actions_del_panel(Lead $lead, array $override = []): array
    {
        return array_merge([
            'estado_sugerido'              => 'demo_agendada',
            'agendar_demo'                 => ['ahora' => true],
            'forzar_slot'                  => false,
            'enviar_mail_demo'             => true,
            'reenviar_mail_demo'           => false,
            'guardar_nombre'               => null,
            'guardar_email'                => null,
            'cancelar_demo'                => false,
            'requiere_intervencion_humana' => false,
            'motivo_intervencion'          => null,
        ], $override);
    }
}
