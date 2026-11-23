<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadScheduledMessage;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadFollowupService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadOrigenService;
use App\Services\LeadSuggestionSendService;
use App\Services\PruebaPlantillasService;
use App\Services\WhatsappSendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Seguimientos automáticos del embudo (misión embudo-whatsapp-precios, etapa E3, criterios CA17 a CA20 del PLAN;
 * §2.3 y §6).
 *
 * Con el interruptor del flujo de entrada prendido, cada estado del embudo programa sus pasos de seguimiento sobre
 * `LeadScheduledMessage` (sin motor nuevo):
 *  - `nuevo`: `nuevo_3h` y `nuevo_22h`, anclados en la salida de `llegada_2`;
 *  - `contactado` (etiqueta «Conversando»): `conversando_22h` y, sólo con plantilla configurada, `conversando_dia_3`;
 *  - `link_enviado`: `link_2h`, `link_22h` y, sólo con plantilla configurada, `link_dia_3`.
 * Un cambio de estado cancela los pasos del estado anterior (`CANCELED_CAMBIO_DE_ESTADO`) y programa los del nuevo; el
 * despacho (`leads:send-scheduled-messages`) agrega el freno por cambio de estado; y el cron viejo
 * (`leads:check-followups`) no toca a un lead que tiene pasos del embudo.
 *
 * Todo el tiempo es un reloj fijo en hora de Argentina (`Carbon::setTestNow`), el HTTP de WhatsApp es el espía del trait
 * `ArmadoDelFlujoDeEntrada` (nada sale de la máquina) y el interruptor del flujo se pone explícito en cada test.
 *
 * Las ENTRADAS son las reales de E2 (decisiones de `.mision/DECISIONES-E3.md`):
 *  - llegada: `POST webhook/whatsapp` + `SendLeadPresentationMessageJob` (`llegada_2` sale en
 *    `LeadWhatsappOnboardingService::enviar_puntos_del_flujo`);
 *  - el lead responde: el webhook lo pasa a `contactado` (`marcar_contactado_si_respondio_a_la_presentacion`) y, si no
 *    pregunta, encola `SendLeadDosFormasJob`;
 *  - el link: `LeadSuggestionSendService::send_suggestion` → `LeadFlujoEntradaService::marcar_link_enviado`.
 *
 * Hoy están EN ROJO: no existe la columna `lead_scheduled_messages.paso_embudo`, ni `LeadEmbudoSeguimientosService`, ni
 * `LeadScheduledMessage::CANCELED_CAMBIO_DE_ESTADO`, y el despacho y el cron viejo no conocen los pasos.
 */
class SeguimientosDelEmbudoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Zona horaria del negocio. */
    private const AR = 'America/Argentina/Buenos_Aires';

    /** Texto con el que llega un lead del anuncio, sin pregunta. */
    private const PRIMER_MENSAJE = 'Hola, vi el video de ComercioCity y quiero más info';

    /** Lo que contesta el lead al rubro y los productos: sin pregunta, así le salen los literales del paso 2. */
    private const RESPUESTA_SIN_PREGUNTA = 'Ferretería, unos 4000 productos';

    /**
     * Reloj fijo de las 10:00 del lunes 23/11/2026 (hora de Argentina) y flujo prendido. El reloj se fija ANTES de
     * prender el interruptor, así los leads que crea cada test (a las 10:00) quedan «en el flujo» (E2, punto 10).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* La propiedad estática es del trait y cada clase que lo usa tiene la suya: acá se pisa sólo la de este test. */
        self::$AHORA_DEL_FLUJO = '2026-11-23 10:00:00';

        $this->preparar_flujo_de_entrada(true);

        /* Claves del embudo sin pisar: arrancan en los textos por defecto del PLAN §2.3. */
        foreach (['nuevo_3h', 'nuevo_22h', 'conversando_22h', 'link_2h', 'link_22h'] as $paso) {
            AdminSetting::set('lead_embudo_paso_' . $paso, '');
        }
        AdminSetting::set('lead_embudo_plantilla_conversando_dia_3', '');
        AdminSetting::set('lead_embudo_plantilla_link_dia_3', '');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // CA17 — nuevo: 3 h y 22 h desde llegada_2
    // ------------------------------------------------------------------------------------------

    /**
     * CA17. Al salir `llegada_2` a las 10:00 quedan programados `nuevo_3h` a las 13:00 y `nuevo_22h` a las 08:00 del día
     * siguiente, corrido por la franja de seguimientos al inicio (09:00; la franja suma un minuto de margen, así que vale
     * entre 09:00:00 y 09:01:00), dentro de la ventana de 24 h que cierra a las 10:00. Los dos son texto libre, sin autor
     * (`created_by_admin_id` null), con `paso_embudo` y `estado_lead_al_programar = nuevo`, y no se cancelan si el lead
     * contesta por su cuenta (`cancel_if_lead_replies` apagado: la respuesta se maneja reprogramando).
     *
     * El texto de `nuevo_22h` es `respuesta_1`, las dos formas de arrancar, tal cual (PLAN §2.3).
     *
     * @return void
     */
    public function test_al_llegar_se_programan_3h_y_22h_dentro_de_la_franja(): void
    {
        [$lead] = $this->llegar_y_recibir_el_paso_1();

        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($lead), 'Al salir llegada_2 tienen que quedar programados los dos pasos de nuevo.');

        $tres_horas = $this->unico_pendiente($lead, 'nuevo_3h');
        $this->assertSame('2026-11-23 13:00:00', $this->hora_ar($tres_horas->scheduled_send_at), 'nuevo_3h: 3 h después de las 10:00.');
        $this->assertSame(LeadScheduledMessage::MODE_TEXTO_LIBRE, $tres_horas->mode);
        $this->assertNotSame('', trim((string) $tres_horas->content), 'El paso lleva su texto por defecto.');

        $veintidos = $this->unico_pendiente($lead, 'nuevo_22h');
        $this->assert_en_la_apertura_de_la_franja($veintidos, '2026-11-24', 'nuevo_22h caía a las 08:00, antes de la franja: se corre a la apertura de las 09:00.');
        $this->assertTrue(
            $veintidos->scheduled_send_at->lt($this->ar('2026-11-24 10:00:00')),
            'Y sigue dentro de la ventana de 24 h, que cierra a las 10:00 del día siguiente.'
        );
        $this->assertSame(LeadScheduledMessage::MODE_TEXTO_LIBRE, $veintidos->mode);
        $this->assertSame($this->texto_respuesta_1(), (string) $veintidos->content, 'nuevo_22h es respuesta_1, las dos formas de arrancar, tal cual.');

        foreach ([$tres_horas, $veintidos] as $paso) {
            $this->assertSame('nuevo', (string) $paso->estado_lead_al_programar);
            $this->assertNull($paso->created_by_admin_id, 'Los pasos del embudo no tienen autor: los programa el sistema.');
            $this->assertFalse((bool) $paso->cancel_if_lead_replies, 'La respuesta se maneja reprogramando, no con el check de cancelar.');
            $this->assertSame(LeadScheduledMessage::STATUS_PENDIENTE, (string) $paso->status);
        }
    }

    /**
     * CA17 (extensión por E2). `llegada_2` también puede salir DENTRO del mensaje de la IA, cuando el primer mensaje del
     * lead pregunta algo (el bloque del agente le pasa los puntos obligatorios; E2: `puntos_entregados` cuenta el punto
     * `prueba_y_rubro` por kind o por ancla). Cuando ese mensaje sale (el lead sigue en `nuevo`), cuenta como la salida de
     * `llegada_2`: se programan los mismos dos pasos. Ver DECISIONES-E3 punto 3 (si el Coordinador lo descarta, se saca).
     *
     * @return void
     */
    public function test_si_llegada_2_sale_dentro_del_mensaje_de_la_ia_tambien_se_programan_los_pasos(): void
    {
        $lead = $this->crear_lead_de_flujo('nuevo');
        $this->mensaje_del_lead($lead, '¿Cuánto cuesta?');
        $espia = $this->espiar_whatsapp();

        $sugerencia = LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'status' => 'sugerido',
            'content' => '¡Hola! Soy Martín, de ComercioCity. Lo podés probar gratis 7 días con tu propio negocio. Comentame, ¿qué rubro trabajás y cuántos productos manejás?',
            'is_followup' => false, 'is_status_event' => false, 'requiere_verificacion' => false, 'suggested_lead_status' => null,
        ]);
        (new LeadSuggestionSendService($espia))->send_suggestion($sugerencia);

        $this->assertSame('nuevo', (string) $lead->fresh()->status, 'Precondición: el mensaje de la IA no mueve al lead de nuevo.');
        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($lead), 'Salió llegada_2 dentro del mensaje de la IA: se programan los pasos de nuevo.');
        $this->assertSame('2026-11-23 13:00:00', $this->hora_ar($this->unico_pendiente($lead, 'nuevo_3h')->scheduled_send_at));
    }

    /**
     * PLAN §2.3 (franja). Si el paso cae fuera de la franja se corre al próximo inicio de franja; si así se sale de la
     * ventana de 24 h, se ADELANTA a la última hora de franja que quede dentro de la ventana.
     *
     * Llegada a las 08:30: `nuevo_3h` cae a las 11:30 (dentro de la franja) y `nuevo_22h` a las 06:30 del día siguiente
     * (fuera): correrlo a las 09:00 lo deja después del cierre de la ventana (08:30), así que sale la víspera, en la última
     * hora de la franja (entre las 20:00 y las 20:59:59, porque la franja cierra a las 21:00 exclusivo).
     *
     * @return void
     */
    public function test_si_correr_el_paso_lo_saca_de_la_ventana_se_adelanta_a_la_ultima_hora_de_la_franja(): void
    {
        $this->ir_a('2026-11-23 08:30:00');
        /* El flujo se prendió a las 08:00: el lead que llega a las 08:30 está «en el flujo». */
        AdminSetting::set(LeadFlujoEntradaService::KEY_ACTIVADO_EN, $this->ar('2026-11-23 08:00:00')->toIso8601String());

        [$lead] = $this->llegar_y_recibir_el_paso_1();

        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($lead));
        $this->assertSame('2026-11-23 11:30:00', $this->hora_ar($this->unico_pendiente($lead, 'nuevo_3h')->scheduled_send_at));

        $veintidos = $this->hora_ar($this->unico_pendiente($lead, 'nuevo_22h')->scheduled_send_at);
        $this->assertGreaterThanOrEqual('2026-11-23 20:00:00', $veintidos, 'Se adelanta a la última hora de franja de la víspera.');
        $this->assertLessThan('2026-11-23 21:00:00', $veintidos, 'La franja cierra a las 21:00 (exclusivo).');
    }

    /**
     * CA17. Si el lead responde pasa a `contactado`: los dos pendientes de `nuevo` quedan `cancelado` con
     * `CANCELED_CAMBIO_DE_ESTADO` y se programa `conversando_22h`. Camino del lead que contesta SIN preguntar (sale el
     * literal del paso 2 por `SendLeadDosFormasJob`, que también programa los pasos del estado nuevo).
     *
     * Nunca más de un `conversando_22h` pendiente: el webhook (cada inbound re-ancla) y el job no duplican.
     *
     * @return void
     */
    public function test_contestar_cancela_y_reprograma(): void
    {
        [$lead, $telefono, $espia] = $this->llegar_y_recibir_el_paso_1();

        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, self::RESPUESTA_SIN_PREGUNTA)->assertStatus(200);
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Precondición (E2): el lead que contesta pasa a contactado en el webhook.');

        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'Precondición (E2): sin pregunta, el paso 2 sale literal por SendLeadDosFormasJob.');
        $this->avanzar(3);
        $this->correr_job($jobs->first());

        $motivo = $this->motivo_cambio_de_estado();
        foreach (['nuevo_3h', 'nuevo_22h'] as $clave) {
            $paso = $this->pasos_de($lead)->where('paso_embudo', $clave)->first();
            $this->assertNotNull($paso, "El paso {$clave} tuvo que haberse programado en la llegada.");
            $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, (string) $paso->status, "{$clave} queda cancelado: el lead ya contestó.");
            $this->assertSame($motivo, (string) $paso->canceled_reason, "{$clave} se cancela por cambio de estado.");
        }

        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead), 'Pasa a contactado: queda un solo paso, conversando_22h (conversando_dia_3 sin plantilla no se programa).');
        $conversando = $this->unico_pendiente($lead, 'conversando_22h');
        $this->assertSame('contactado', (string) $conversando->estado_lead_al_programar);
        $this->assert_en_la_apertura_de_la_franja($conversando, '2026-11-24', '22 h después de las 10:05 es 08:05: antes de la franja, se corre a las 09:00.');
        $this->assertSame(LeadScheduledMessage::MODE_TEXTO_LIBRE, $conversando->mode);
        $this->assertNull($conversando->created_by_admin_id);
        $this->assertFalse((bool) $conversando->cancel_if_lead_replies);
    }

    /**
     * CA17 (camino de la pregunta). Si el lead responde PREGUNTANDO algo, el literal no sale (la IA le contesta) y no hay
     * job de las dos formas: el cambio a `contactado` ocurre igual en el webhook, y ahí mismo, sin esperar a ningún job,
     * se cancelan los pasos de `nuevo` y se programa `conversando_22h` (PLAN §2.3: cada inbound del webhook de un lead con
     * pasos vuelve a anclar los pasos).
     *
     * @return void
     */
    public function test_contestar_con_una_pregunta_tambien_cancela_y_reprograma(): void
    {
        [$lead, $telefono] = $this->llegar_y_recibir_el_paso_1();

        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, '¿Cuánto sale?')->assertStatus(200);

        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Precondición (E2): contactado en el webhook.');
        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'Precondición (E2): con pregunta no sale el literal, contesta la IA.');

        $motivo = $this->motivo_cambio_de_estado();
        foreach (['nuevo_3h', 'nuevo_22h'] as $clave) {
            $paso = $this->pasos_de($lead)->where('paso_embudo', $clave)->first();
            $this->assertNotNull($paso, "El paso {$clave} tuvo que haberse programado en la llegada.");
            $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, (string) $paso->status, "{$clave} se cancela apenas el lead contesta, sin esperar a ningún job.");
            $this->assertSame($motivo, (string) $paso->canceled_reason);
        }

        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead));
        $this->assert_en_la_apertura_de_la_franja($this->unico_pendiente($lead, 'conversando_22h'), '2026-11-24', 'Anclado en el inbound de las 10:05: 08:05 se corre a la apertura.');
    }

    /**
     * CA17. Con el interruptor apagado no se programa nada: el onboarding de hoy sigue igual y la tabla de pasos no recibe
     * filas. Control en el mismo test: con el interruptor prendido el mismo recorrido SÍ programa los dos pasos (así el
     * test no pasa por vacío antes de que exista la función).
     *
     * @return void
     */
    public function test_apagado_no_programa(): void
    {
        [$con_flujo] = $this->llegar_y_recibir_el_paso_1();
        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($con_flujo), 'Control: prendido, la llegada programa los dos pasos.');

        AdminSetting::set(LeadFlujoEntradaService::KEY_ACTIVO, '0');
        $this->avanzar(60);

        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, self::PRIMER_MENSAJE)->assertStatus(200);
        $sin_flujo = Lead::query()->where('phone', $telefono)->firstOrFail();

        $this->espiar_whatsapp();
        $this->correr_job($this->jobs_encolados(SendLeadPresentationMessageJob::class)->last());
        $this->avanzar(60);
        $this->postear_kapso($telefono, self::RESPUESTA_SIN_PREGUNTA)->assertStatus(200);

        $this->assertSame(
            0,
            LeadScheduledMessage::query()->where('lead_id', $sin_flujo->id)->count(),
            'Con el interruptor apagado no se programa ningún seguimiento del embudo (ni al llegar ni al contestar).'
        );
    }

    /**
     * PLAN §2.3. El texto de cada paso se puede pisar desde `admin_settings` (`lead_embudo_paso_<paso>`).
     *
     * @return void
     */
    public function test_el_texto_del_paso_se_pisa_desde_admin_settings(): void
    {
        AdminSetting::set('lead_embudo_paso_nuevo_3h', 'Texto propio del paso de las tres horas.');

        [$lead] = $this->llegar_y_recibir_el_paso_1();

        $this->assertSame('Texto propio del paso de las tres horas.', (string) $this->unico_pendiente($lead, 'nuevo_3h')->content);
        $this->assertSame($this->texto_respuesta_1(), (string) $this->unico_pendiente($lead, 'nuevo_22h')->content, 'Los que no se pisaron siguen con su texto por defecto.');
    }

    // ------------------------------------------------------------------------------------------
    // CA18 — link_enviado: 2 h, 22 h y día 3 (sólo con plantilla)
    // ------------------------------------------------------------------------------------------

    /**
     * CA18. `link_enviado` a las 12:00 con el último inbound a las 11:50: `link_2h` a las 14:00 y `link_22h` a las 10:00 del
     * día siguiente (el ancla es el momento del envío del link, no el inbound: así lo dicen los horarios del CA). Los dos
     * llevan el link de la prueba (sin el marcador `{link_prueba}` sin reemplazar) y `estado_lead_al_programar =
     * link_enviado`. `link_dia_3` (72 h, plantilla) sólo existe si `lead_embudo_plantilla_link_dia_3` tiene nombre.
     *
     * @return void
     */
    public function test_link_enviado_programa_2h_22h_y_dia_3_solo_con_plantilla(): void
    {
        /* Sin plantilla configurada. */
        $lead = $this->lead_con_el_link_enviado('2026-11-23 11:50:00', '2026-11-23 12:00:00');

        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Precondición (E2): enviar el link pasa al lead a link_enviado.');
        $this->assertSame(['link_22h', 'link_2h'], $this->claves_pendientes($lead), 'Sin plantilla configurada no hay link_dia_3.');

        $dos_horas = $this->unico_pendiente($lead, 'link_2h');
        $this->assertSame('2026-11-23 14:00:00', $this->hora_ar($dos_horas->scheduled_send_at));
        $veintidos = $this->unico_pendiente($lead, 'link_22h');
        $this->assertSame('2026-11-24 10:00:00', $this->hora_ar($veintidos->scheduled_send_at));

        foreach ([$dos_horas, $veintidos] as $paso) {
            $this->assertSame(LeadScheduledMessage::MODE_TEXTO_LIBRE, $paso->mode);
            $this->assertSame('link_enviado', (string) $paso->estado_lead_al_programar);
            $this->assertNull($paso->created_by_admin_id);
            $this->assertFalse((bool) $paso->cancel_if_lead_replies);
            $this->assertStringContainsString(LeadOfertaDePruebaService::link(), (string) $paso->content, 'El paso vuelve a dar el link de la prueba.');
            $this->assertStringNotContainsString('{link_prueba}', (string) $paso->content, 'El marcador del texto por defecto se reemplaza por el link.');
        }

        /* Con la plantilla configurada (y aprobada, como cuando Meta la aprueba) sale también el día 3. */
        AdminSetting::set('lead_embudo_plantilla_link_dia_3', 'cc_embudo_link_dia_3');
        $this->plantilla_aprobada('cc_embudo_link_dia_3');
        $con_plantilla = $this->lead_con_el_link_enviado('2026-11-23 11:50:00', '2026-11-23 12:00:00');

        $this->assertSame(['link_22h', 'link_2h', 'link_dia_3'], $this->claves_pendientes($con_plantilla));
        $dia_3 = $this->unico_pendiente($con_plantilla, 'link_dia_3');
        $this->assertSame(LeadScheduledMessage::MODE_PLANTILLA, $dia_3->mode, 'El día 3 está fuera de la ventana de 24 h: sólo sale con plantilla.');
        $this->assertSame('cc_embudo_link_dia_3', (string) $dia_3->template_name);
        $this->assertSame('2026-11-26 12:00:00', $this->hora_ar($dia_3->scheduled_send_at), '72 h después del envío del link.');
        $this->assertSame('link_enviado', (string) $dia_3->estado_lead_al_programar);
    }

    /**
     * PLAN §2.3 (ventana). Un paso de texto libre cuya hora cae fuera de la ventana de 24 h desde el último inbound no se
     * programa. Último inbound a las 10:00 (la ventana cierra a las 10:00 del día siguiente) y link a las 14:00:
     * `link_2h` (16:00) entra; `link_22h` (12:00 del día siguiente) ya no.
     *
     * @return void
     */
    public function test_el_paso_de_texto_que_cae_fuera_de_la_ventana_no_se_programa(): void
    {
        $lead = $this->lead_con_el_link_enviado('2026-11-23 10:00:00', '2026-11-23 14:00:00');

        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Precondición (E2).');
        $this->assertSame(['link_2h'], $this->claves_pendientes($lead), 'link_22h caería a las 12:00 del día siguiente, con la ventana ya cerrada: no se programa.');
        $this->assertSame('2026-11-23 16:00:00', $this->hora_ar($this->unico_pendiente($lead, 'link_2h')->scheduled_send_at));
    }

    /**
     * PLAN §2.3. «Cada inbound del webhook de un lead con pasos de embudo y estado `contactado` o `link_enviado` vuelve a
     * anclar los pasos»: el lead en `link_enviado` que escribe a las 13:00 deja sus pasos en 15:00 (`link_2h`) y 11:00 del
     * día siguiente (`link_22h`), y los viejos (14:00 y 10:00) ya no están pendientes. Siempre uno solo de cada paso.
     *
     * @return void
     */
    public function test_cada_inbound_en_link_enviado_vuelve_a_anclar_los_pasos(): void
    {
        $telefono = $this->telefono_al_azar();
        $lead = $this->lead_con_el_link_enviado('2026-11-23 11:50:00', '2026-11-23 12:00:00', $telefono);
        $this->assertSame(['link_22h', 'link_2h'], $this->claves_pendientes($lead), 'Precondición: los pasos del link están programados.');

        $this->ir_a('2026-11-23 13:00:00');
        $this->postear_kapso($telefono, '¿Dónde subo mi Excel?')->assertStatus(200);

        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Escribir no cambia el estado: sigue en link_enviado.');
        $this->assertSame(['link_22h', 'link_2h'], $this->claves_pendientes($lead), 'Un solo paso pendiente de cada clase.');
        $this->assertSame('2026-11-23 15:00:00', $this->hora_ar($this->unico_pendiente($lead, 'link_2h')->scheduled_send_at), '13:00 + 2 h.');
        $this->assertSame('2026-11-24 11:00:00', $this->hora_ar($this->unico_pendiente($lead, 'link_22h')->scheduled_send_at), '13:00 + 22 h.');
    }

    /**
     * CA18. Si el lead crea la prueba (queda con `promoted_client_id`, y `prueba_activa`) los pasos pendientes no salen: el
     * despacho los cancela con `CANCELED_LEAD_PROMOVIDO` (el freno de hoy, que gana al de cambio de estado) y no se manda
     * ningún texto.
     *
     * @return void
     */
    public function test_crear_la_prueba_cancela_los_pasos(): void
    {
        $lead = $this->lead_con_el_link_enviado('2026-11-23 11:50:00', '2026-11-23 12:00:00');
        $this->assertSame(['link_22h', 'link_2h'], $this->claves_pendientes($lead), 'Precondición: hay pasos del link programados.');
        $espia = $this->espiar_whatsapp();

        /* Como lo deja el alta de la prueba (por query builder): `prueba_activa` y el cliente promovido. */
        $cliente = Client::query()->create([
            'name' => 'Negocio que creó su prueba', 'is_active' => true, 'estado' => 'prueba', 'api_url' => '', 'api_key' => 'x',
            'inbound_api_key' => 'y', 'slug' => 'prueba-e3-' . uniqid(),
        ]);
        Lead::query()->whereKey($lead->id)->update(['status' => 'prueba_activa', 'promoted_client_id' => $cliente->id]);

        $this->ir_a('2026-11-24 10:05:00'); // vencen los dos
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertSame([], $this->cuerpos_enviados($espia), 'No sale ningún seguimiento a quien ya creó su prueba.');
        foreach ($this->pasos_de($lead) as $paso) {
            $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, (string) $paso->status, "{$paso->paso_embudo} tiene que quedar cancelado.");
            $this->assertSame(LeadScheduledMessage::CANCELED_LEAD_PROMOVIDO, (string) $paso->canceled_reason, "{$paso->paso_embudo}: el lead ya es de un cliente en prueba.");
        }
        $this->assertSame(0, LeadMessage::query()->where('lead_id', $lead->id)->where('sender', '!=', 'lead')->where('is_status_event', false)->where('system_message_kind', 'whatsapp_embudo_paso')->count(), 'Ni se escribe nada en el hilo.');
    }

    // ------------------------------------------------------------------------------------------
    // Decisión del Coordinador P1 (23/11/2026): el link por el camino literal también pasa a link_enviado
    // ------------------------------------------------------------------------------------------

    /**
     * DECISIONES-E3 P1. El lead de la web (frase de la landing) que contesta SIN preguntar recibe, por el camino literal
     * (`SendLeadDosFormasJob`), las dos formas, la página y el link de la prueba. Como a cualquier otro envío del link
     * (`LeadSuggestionSendService`), eso lo pasa a `link_enviado` con su evento de estado, cancela los pasos de los estados
     * anteriores y programa los del link: `link_2h` y `link_22h`. No le quedan pasos de `contactado`.
     *
     * @return void
     */
    public function test_el_link_por_el_camino_literal_pasa_al_lead_de_la_web_a_link_enviado(): void
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, LeadOrigenService::FRASE_LANDING_PRUEBA)->assertStatus(200);
        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();

        $espia = $this->espiar_whatsapp();
        $this->correr_job($this->jobs_encolados(SendLeadPresentationMessageJob::class)->last());
        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, self::RESPUESTA_SIN_PREGUNTA)->assertStatus(200);
        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'Precondición (E2): sin pregunta sale el literal del paso 2.');
        $this->avanzar(3);
        $this->correr_job($jobs->first());

        $this->assertTrue(
            count(array_filter($this->cuerpos_enviados($espia), function ($texto) {
                return strpos($texto, LeadOfertaDePruebaService::link()) !== false;
            })) === 1,
            'Precondición (E2): el literal del lead de la web lleva el link de la prueba.'
        );
        $this->assertSame('link_enviado', (string) $lead->fresh()->status, 'Haberle mandado el link lo pasa a link_enviado, salga por donde salga.');
        $this->assertSame(
            1,
            LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->where('content', 'like', '%' . \App\Models\LeadPipelineStatus::label_for('link_enviado') . '%')->count(),
            'Y queda el evento de estado en el hilo.'
        );
        $this->assertSame(['link_22h', 'link_2h'], $this->claves_pendientes($lead), 'Quedan los pasos del link y ninguno de nuevo ni de contactado.');
        $this->assertSame('2026-11-23 12:05:03', $this->hora_ar($this->unico_pendiente($lead, 'link_2h')->scheduled_send_at), 'link_2h: 2 h después de que sale el link.');
    }

    // ------------------------------------------------------------------------------------------
    // CA19 — el despacho frena por cambio de estado
    // ------------------------------------------------------------------------------------------

    /**
     * CA19. `leads:send-scheduled-messages` con un paso vencido cuyo lead cambió de estado lo cancela con
     * `CANCELED_CAMBIO_DE_ESTADO` y NO llama a WhatsApp. Control en el mismo test: el paso de un lead que sigue en el
     * estado con el que se programó SÍ sale (texto a su teléfono y burbuja en el hilo, sin autor).
     *
     * El cambio de estado se hace por la base, sin pasar por el servicio de pasos: es justo el caso que el freno del
     * despacho tiene que atrapar (un cambio que nadie reprogramó).
     *
     * @return void
     */
    public function test_el_despacho_cancela_si_cambio_el_estado(): void
    {
        $espia = $this->espiar_whatsapp();

        $cambio = $this->crear_lead_de_flujo('nuevo');
        $igual  = $this->crear_lead_de_flujo('nuevo');
        foreach ([$cambio, $igual] as $lead) {
            $this->mensaje_del_lead($lead, 'Hola, quiero info'); // abre la ventana de 24 h
        }
        $paso_cambio = $this->insertar_paso($cambio, 'nuevo_3h', 'nuevo', '2026-11-23 13:00:00', 'Seguimiento que no tiene que salir.');
        $paso_igual  = $this->insertar_paso($igual, 'nuevo_3h', 'nuevo', '2026-11-23 13:00:00', 'Seguimiento que sí sale.');

        Lead::query()->whereKey($cambio->id)->update(['status' => 'calificado']);

        $this->ir_a('2026-11-23 13:00:30');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertSame(LeadScheduledMessage::STATUS_ENVIADO, (string) $paso_igual->fresh()->status, 'Control: el del lead que sigue en nuevo sale.');
        $this->assertSame([['to' => $igual->phone, 'body' => 'Seguimiento que sí sale.']], $espia->textos, 'Sólo se llamó a WhatsApp por el lead que no cambió.');
        $enviado = LeadMessage::query()->where('lead_id', $igual->id)->where('sender', '!=', 'lead')->where('is_status_event', false)->first();
        $this->assertNotNull($enviado, 'Queda la burbuja en el hilo.');
        $this->assertSame('Seguimiento que sí sale.', (string) $enviado->content);
        $this->assertNull($enviado->sent_by_admin_id, 'Un paso del embudo no se hace pasar por un operador.');
        /* Decisión P3: sale como automático del sistema, con un kind que E2 trata como `kinds_automaticos()`. */
        $this->assertSame('sistema', (string) $enviado->sender, 'El paso sale como mensaje del sistema (E2 cuenta lo entregado por ahí).');
        $this->assertSame('whatsapp_embudo_paso', (string) $enviado->system_message_kind);
        $this->assertContains((string) $enviado->system_message_kind, LeadFlujoEntradaService::kinds_automaticos());

        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, (string) $paso_cambio->fresh()->status, 'El lead pasó de nuevo a calificado: el paso no sale.');
        $this->assertSame($this->motivo_cambio_de_estado(), (string) $paso_cambio->fresh()->canceled_reason);
        $this->assertSame(0, LeadMessage::query()->where('lead_id', $cambio->id)->where('sender', '!=', 'lead')->where('is_status_event', false)->count());
    }

    // ------------------------------------------------------------------------------------------
    // Decisiones del Coordinador P2 y P3 (23/11/2026)
    // ------------------------------------------------------------------------------------------

    /**
     * DECISIONES-E3 P2. Si al despachar el lead tiene una pregunta sin contestar, el paso NO sale: se pospone 60 minutos
     * (respetando franja y ventana) y no se llama a WhatsApp. Cuando la pregunta se contesta, el paso pospuesto sale.
     *
     * @return void
     */
    public function test_el_paso_con_una_pregunta_sin_contestar_se_pospone_60_minutos(): void
    {
        [$lead, $telefono, $espia] = $this->lead_contactado_con_su_paso();
        $this->ir_a('2026-11-23 10:30:00');
        $this->postear_kapso($telefono, '¿Cuánto sale?')->assertStatus(200);
        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead), 'Precondición: el paso sigue programado.');
        $antes = count($espia->textos);

        $this->ir_a('2026-11-24 09:05:00'); // el paso venció a las 09:01 y la pregunta sigue sin contestar
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertCount($antes, $espia->textos, 'No se le manda nada: Martín todavía tiene que contestarle.');
        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead), 'El paso queda pendiente, pospuesto.');
        $this->assertSame('2026-11-24 10:05:00', $this->hora_ar($this->unico_pendiente($lead, 'conversando_22h')->scheduled_send_at), '09:05 + 60 minutos.');

        /* Se contesta la pregunta: a la hora pospuesta el paso sale. */
        $this->mensaje_de_sistema($lead, 'Cuesta lo que te conté arriba, ¿lo vemos?');
        $this->ir_a('2026-11-24 10:06:00');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));
        $this->assertCount($antes + 1, $espia->textos, 'Contestada la pregunta, el paso pospuesto sale.');
        $this->assertSame([], $this->claves_pendientes($lead));
    }

    /**
     * DECISIONES-E3 P2. Si el paso pospuesto ya no entra en la ventana de 24 h (el último inbound fue a las 10:30 del
     * 23/11: cierra a las 10:30 del 24/11), se cancela con `CANCELED_CONVERSACION_PENDIENTE` y no se llama a WhatsApp.
     *
     * @return void
     */
    public function test_si_el_paso_pospuesto_no_entra_en_la_ventana_se_cancela(): void
    {
        [$lead, $telefono, $espia] = $this->lead_contactado_con_su_paso();
        $this->ir_a('2026-11-23 10:30:00');
        $this->postear_kapso($telefono, '¿Cuánto sale?')->assertStatus(200);
        $antes = count($espia->textos);

        $this->ir_a('2026-11-24 09:45:00'); // 09:45 + 60 minutos = 10:45, con la ventana ya cerrada
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $motivo = $this->motivo_de_cancelacion('CANCELED_CONVERSACION_PENDIENTE');
        $this->assertCount($antes, $espia->textos, 'No se llama a WhatsApp.');
        $paso = $this->pasos_de($lead)->where('paso_embudo', 'conversando_22h')->where('status', LeadScheduledMessage::STATUS_CANCELADO)->first();
        $this->assertNotNull($paso, 'El paso queda cancelado.');
        $this->assertSame($motivo, (string) $paso->canceled_reason);
        $this->assertSame([], $this->claves_pendientes($lead));
    }

    /**
     * DECISIONES-E3 P2. Una sugerencia de Martín sin enviar (pendiente de aprobación, retenida) también retiene el paso: se
     * pospone 60 minutos y no sale nada.
     *
     * @return void
     */
    public function test_el_paso_con_una_sugerencia_sin_enviar_se_pospone(): void
    {
        [$lead, , $espia] = $this->lead_contactado_con_su_paso();
        $this->ir_a('2026-11-23 10:20:00');
        $this->mensaje_de_sistema($lead, 'Sugerencia de Martín pendiente de aprobación.', null, 'sugerido');
        $antes = count($espia->textos);

        /* El último inbound fue a las 10:05 del 23/11 (la ventana cierra a las 10:05 del 24): 09:02 + 60 minutos entra. */
        $this->ir_a('2026-11-24 09:02:00');
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertCount($antes, $espia->textos, 'No sale mientras haya una sugerencia sin enviar.');
        $this->assertSame('2026-11-24 10:02:00', $this->hora_ar($this->unico_pendiente($lead, 'conversando_22h')->scheduled_send_at));
    }

    /**
     * DECISIONES-E3 P3. El mensaje de un paso del embudo queda marcado para que E2 lo trate como automático del sistema:
     * su kind está en `kinds_automaticos()`, así que NO cuenta como respuesta a una pregunta del lead
     * (`hay_pregunta_sin_contestar` sigue en true) aunque salga después de la pregunta.
     *
     * @return void
     */
    public function test_el_mensaje_de_un_paso_es_automatico_y_no_contesta_una_pregunta(): void
    {
        $this->assertTrue(defined(LeadFlujoEntradaService::class . '::KIND_EMBUDO_PASO'), 'Falta LeadFlujoEntradaService::KIND_EMBUDO_PASO.');
        $kind = (string) constant(LeadFlujoEntradaService::class . '::KIND_EMBUDO_PASO');
        $this->assertSame('whatsapp_embudo_paso', $kind);
        $this->assertContains($kind, LeadFlujoEntradaService::kinds_automaticos());

        $lead = $this->crear_lead_de_flujo('contactado');
        $this->dejar_entregado_el_paso_1($lead);
        $this->mensaje_del_lead($lead, '¿Cuánto sale?');
        $this->mensaje_de_sistema($lead, '¿Pudiste pensarlo?', $kind);

        $this->assertTrue(LeadFlujoEntradaService::hay_pregunta_sin_contestar($lead->fresh()), 'El paso no contesta la pregunta.');
    }

    /**
     * DECISIONES-E3 (menores). Con el interruptor del flujo apagado, los pasos pendientes que vencen se cancelan con
     * `CANCELED_FLUJO_APAGADO` y no se llama a WhatsApp; los que todavía no vencieron siguen pendientes.
     *
     * @return void
     */
    public function test_con_el_interruptor_apagado_el_despacho_cancela_los_pasos(): void
    {
        [$lead, , $espia] = $this->llegar_y_recibir_el_paso_1();
        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($lead), 'Precondición: hay pasos programados.');
        AdminSetting::set(LeadFlujoEntradaService::KEY_ACTIVO, '0');
        $antes = count($espia->textos);

        $this->ir_a('2026-11-23 13:05:00'); // venció nuevo_3h
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $motivo = $this->motivo_de_cancelacion('CANCELED_FLUJO_APAGADO');
        $this->assertCount($antes, $espia->textos, 'Apagado el flujo no sale ningún seguimiento.');
        $tres_horas = $this->pasos_de($lead)->where('paso_embudo', 'nuevo_3h')->first();
        $this->assertSame(LeadScheduledMessage::STATUS_CANCELADO, (string) $tres_horas->status);
        $this->assertSame($motivo, (string) $tres_horas->canceled_reason);
        $this->assertSame(['nuevo_22h'], $this->claves_pendientes($lead), 'El que todavía no venció sigue pendiente (lo cancelará su despacho).');
    }

    // ------------------------------------------------------------------------------------------
    // CA20 — el cron viejo no toca al lead con pasos
    // ------------------------------------------------------------------------------------------

    /**
     * CA20. `leads:check-followups` NO procesa (no manda ni sugiere) a un lead en `nuevo` sin respuesta que tiene pasos de
     * embudo: la regla de 48 h de `nuevo` mandaría una segunda cadencia encima de la nueva. Un lead sin pasos sigue como
     * hoy (control en el mismo test: recibe su plantilla). Los 9 casos de `ElCronNoTocaAlLeadQueContestoTest` siguen verdes.
     *
     * 🔴 `process_single_lead` puede devolver `null` o un resultado nuevo (`embudo`): el PLAN dice «sale un resultado nuevo
     * `embudo` en el log de la corrida» y no aclara si es el valor de retorno. Lo que fija el test es que NO es
     * `suggestion` ni `paused` y que no sale nada (DECISIONES-E3, pregunta 4).
     *
     * @return void
     */
    public function test_el_cron_viejo_no_toca_leads_con_pasos(): void
    {
        $espia = $this->espiar_plantillas();
        $this->crear_regla_de_seguimiento('nuevo', 48, 2);
        $this->crear_plantilla_de_seguimiento('nuevo');

        $con_pasos = $this->lead_de_anuncio_que_nunca_contesto();
        $sin_pasos = $this->lead_de_anuncio_que_nunca_contesto();

        /* Por el servicio del cron: la decisión sobre un lead. Primero el control, sin pasos de por medio. */
        $servicio = app(LeadFollowupService::class);
        $this->assertSame('suggestion', $servicio->process_single_lead($sin_pasos), 'Control: sin pasos, el cron sigue llevando al lead como hoy.');
        $this->assertCount(1, $espia->plantillas, 'Control: salió la plantilla del lead sin pasos.');

        $this->assertSame(substr(preg_replace('/\D/', '', $sin_pasos->phone), -8), substr(preg_replace('/\D/', '', $espia->plantillas[0]['to']), -8), 'La plantilla fue al lead sin pasos.');

        $this->insertar_paso($con_pasos, 'nuevo_3h', 'nuevo', '2026-11-23 13:00:00', 'Seguimiento del embudo pendiente.');

        $resultado = $servicio->process_single_lead($con_pasos);
        $this->assertContains($resultado, [null, 'embudo'], 'El lead con pasos del embudo no se procesa: ni sugerencia ni pausa.');
        $this->assertCount(1, $espia->plantillas, 'Al lead con pasos no le sale ninguna plantilla.');

        /* Y por el comando real, dentro de la franja (son las 10:00): el único envío nuevo posible sería al lead sin pasos. */
        $this->assertSame(0, Artisan::call('leads:check-followups'));
        foreach ($espia->plantillas as $envio) {
            $this->assertNotSame(
                substr(preg_replace('/\D/', '', $con_pasos->phone), -8),
                substr(preg_replace('/\D/', '', $envio['to']), -8),
                'El comando no le manda nada al lead con pasos del embudo.'
            );
        }
        $this->assertSame(0, LeadMessage::query()->where('lead_id', $con_pasos->id)->where('is_followup', true)->count(), 'Ni deja un seguimiento sugerido en el hilo.');
        $this->assertSame('nuevo', (string) $con_pasos->fresh()->status, 'Ni lo pausa.');
    }

    // ------------------------------------------------------------------------------------------
    // Interacción con los frenos de E2
    // ------------------------------------------------------------------------------------------

    /**
     * Interacción con E2. Un seguimiento del embudo que sale NO cuenta como respuesta a una pregunta del lead: si el lead
     * preguntó algo y la IA todavía no contestó, `hay_pregunta_sin_contestar` sigue en true después de que el paso salga
     * (los frenos del literal del paso 2 se apoyan en eso). Vale tanto si el paso sale como si se retiene por la pregunta
     * pendiente (DECISIONES-E3, pregunta 2): lo que no puede pasar es que cuente como contestación.
     *
     * @return void
     */
    public function test_el_seguimiento_del_embudo_no_cuenta_como_respuesta_a_la_pregunta(): void
    {
        [$lead, $telefono] = $this->llegar_y_recibir_el_paso_1();
        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, self::RESPUESTA_SIN_PREGUNTA)->assertStatus(200);
        $this->avanzar(3);
        $this->correr_job($this->jobs_encolados(SendLeadDosFormasJob::class)->first());
        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead), 'Precondición: el lead está en contactado con su paso programado.');

        $this->ir_a('2026-11-23 10:30:00');
        $this->postear_kapso($telefono, '¿Cuánto sale?')->assertStatus(200);
        $this->assertTrue(LeadFlujoEntradaService::hay_pregunta_sin_contestar($lead->fresh()), 'Precondición: la pregunta espera la respuesta de la IA.');

        $espia = $this->espiar_whatsapp();
        $this->ir_a('2026-11-24 09:30:00'); // el paso ya venció (09:00-09:01) y la ventana sigue abierta hasta las 10:30
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));

        $this->assertTrue(
            LeadFlujoEntradaService::hay_pregunta_sin_contestar($lead->fresh()),
            'El seguimiento del embudo no es una contestación: la pregunta sigue sin contestar (' . count($espia->textos) . ' texto(s) salieron).'
        );
    }

    /**
     * Interacción con E2 (caso PROPUESTO, DECISIONES-E3 pregunta 5). `nuevo_22h` es `respuesta_1` tal cual (las dos formas
     * de arrancar). Si ya le salió al lead y después el lead contesta, el literal del paso 2 no tiene que repetirle esas dos
     * formas: lo que salió por el paso cuenta como entregado. Hoy `mensajes_de_puntos_entregados` sólo mira mensajes de
     * `sender = sistema`, y el despacho de un programado guarda `sender = setter` sin kind.
     *
     * @return void
     */
    public function test_lo_que_salio_en_el_paso_de_22h_cuenta_como_entregado_y_no_se_repite(): void
    {
        [$lead, $telefono, $espia] = $this->llegar_y_recibir_el_paso_1();

        $this->ir_a('2026-11-24 09:30:00'); // vencieron nuevo_3h y nuevo_22h; el ancla (10:00 del 23) sigue dentro de las 24 h
        $this->assertSame(0, Artisan::call('leads:send-scheduled-messages'));
        $this->assertContains($this->texto_respuesta_1(), $this->cuerpos_enviados($espia), 'Precondición: nuevo_22h (respuesta_1) salió.');

        $this->avanzar(600); // 09:40
        $this->postear_kapso($telefono, self::RESPUESTA_SIN_PREGUNTA)->assertStatus(200);

        $this->assertNotContains('dos_formas', LeadFlujoEntradaService::puntos_debidos($lead->fresh()), 'Las dos formas ya se las mandó el paso de 22 h: no se le deben.');

        $antes = count($espia->textos);
        $jobs  = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'Precondición (E2): sin pregunta, sale el literal del paso 2.');
        $this->correr_job($jobs->first());

        $nuevos = array_slice($this->cuerpos_enviados($espia), $antes);
        $this->assertNotContains($this->texto_respuesta_1(), $nuevos, 'El literal no repite las dos formas de arrancar.');
        $this->assertContains($this->texto_respuesta_2(), $nuevos, 'Pero sí lo que todavía no recibió: la página.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead del anuncio que escribe sin preguntar, recibe los dos mensajes de la llegada por el job real y queda en
     * `nuevo` con el paso 1 entregado. Precondiciones de E2 incluidas (así un rojo de acá no es de E2).
     *
     * @return array{0: Lead, 1: string, 2: WhatsappSendService} Lead, teléfono y espía de WhatsApp.
     */
    private function llegar_y_recibir_el_paso_1(): array
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, self::PRIMER_MENSAJE)->assertStatus(200);

        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();
        $this->assertSame('nuevo', (string) $lead->status, 'Precondición (E2): el lead llega en nuevo.');

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertGreaterThanOrEqual(1, $jobs->count(), 'Precondición (E2): queda el job de la llegada.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->last());
        $this->assertSame([$this->texto_llegada_1(), $this->texto_llegada_2()], $this->cuerpos_enviados($espia), 'Precondición (E2): salieron los dos mensajes de la llegada.');

        return [$lead->fresh(), $telefono, $espia];
    }

    /**
     * Un lead en `contactado` con el paso 1 entregado, un último inbound a la hora dada y el link de la prueba enviado a
     * la hora dada por el camino real (`LeadSuggestionSendService`), que lo pasa a `link_enviado` y programa los pasos.
     *
     * @param string      $inbound_a Hora (de Argentina) del último mensaje del lead.
     * @param string      $link_a    Hora (de Argentina) a la que sale el link.
     * @param string|null $telefono  Teléfono del lead (al azar si es null).
     *
     * @return Lead
     */
    private function lead_con_el_link_enviado(string $inbound_a, string $link_a, ?string $telefono = null): Lead
    {
        $this->ir_a('2026-11-23 10:00:00'); // el lead nace ya «en el flujo» (el interruptor se prendió a las 10:00)
        $lead = $this->crear_lead_de_flujo('contactado', $telefono);
        $this->dejar_entregado_el_paso_1($lead);

        $this->ir_a($inbound_a);
        $this->mensaje_del_lead($lead, 'Dale, quiero probar');

        $this->ir_a($link_a);
        $sugerencia = LeadMessage::create([
            'lead_id' => $lead->id, 'sender' => 'sistema', 'kind' => 'text', 'status' => 'sugerido',
            'content' => 'Genial, creá tu prueba acá: ' . LeadOfertaDePruebaService::link(),
            'is_followup' => false, 'is_status_event' => false, 'requiere_verificacion' => false, 'suggested_lead_status' => null,
        ]);
        (new LeadSuggestionSendService($this->espiar_whatsapp()))->send_suggestion($sugerencia);

        return $lead->fresh();
    }

    /**
     * Una fila de paso del embudo escrita a mano (sin pasar por el servicio de programación), para probar el despacho y el
     * cron viejo de forma aislada.
     *
     * @param Lead   $lead
     * @param string $clave            `paso_embudo`.
     * @param string $estado_al_programar `estado_lead_al_programar`.
     * @param string $cuando           Hora (de Argentina) de envío.
     * @param string $texto            Contenido.
     *
     * @return LeadScheduledMessage
     */
    private function insertar_paso(Lead $lead, string $clave, string $estado_al_programar, string $cuando, string $texto): LeadScheduledMessage
    {
        return LeadScheduledMessage::create([
            'lead_id'                  => $lead->id,
            'scheduled_send_at'        => $this->ar($cuando),
            'mode'                     => LeadScheduledMessage::MODE_TEXTO_LIBRE,
            'content'                  => $texto,
            'cancel_if_lead_replies'   => false,
            'baseline_lead_message_id' => null,
            'status'                   => LeadScheduledMessage::STATUS_PENDIENTE,
            'created_by_admin_id'      => null,
            'paso_embudo'              => $clave,
            'estado_lead_al_programar' => $estado_al_programar,
        ]);
    }

    /**
     * Los pasos del embudo del lead (cualquier estado), en orden de creación.
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Support\Collection
     */
    private function pasos_de(Lead $lead)
    {
        return LeadScheduledMessage::query()->where('lead_id', $lead->id)->whereNotNull('paso_embudo')->orderBy('id')->get();
    }

    /**
     * Las claves de los pasos PENDIENTES del lead, ordenadas (con repetidos: un paso duplicado se nota).
     *
     * @param Lead $lead
     *
     * @return array<int, string>
     */
    private function claves_pendientes(Lead $lead): array
    {
        $claves = $this->pasos_de($lead)->where('status', LeadScheduledMessage::STATUS_PENDIENTE)->pluck('paso_embudo')->all();
        sort($claves);

        return $claves;
    }

    /**
     * El paso pendiente de esa clave, exigiendo que haya exactamente uno.
     *
     * @param Lead   $lead
     * @param string $clave
     *
     * @return LeadScheduledMessage
     */
    private function unico_pendiente(Lead $lead, string $clave): LeadScheduledMessage
    {
        $pasos = $this->pasos_de($lead)->where('status', LeadScheduledMessage::STATUS_PENDIENTE)->where('paso_embudo', $clave);
        $this->assertCount(1, $pasos, "Tiene que haber exactamente un paso {$clave} pendiente.");

        return $pasos->first();
    }

    /**
     * El motivo de cancelación por cambio de estado: constante nueva del modelo (PLAN §2.3). Si no existe, el test falla
     * con un mensaje claro en vez de un «Undefined class constant».
     *
     * @return string
     */
    private function motivo_cambio_de_estado(): string
    {
        return $this->motivo_de_cancelacion('CANCELED_CAMBIO_DE_ESTADO');
    }

    /**
     * El valor de una constante `CANCELED_*` nueva del modelo. Si no existe, el test falla con un mensaje claro.
     *
     * @param string $nombre Nombre de la constante.
     *
     * @return string
     */
    private function motivo_de_cancelacion(string $nombre): string
    {
        $this->assertTrue(
            defined(LeadScheduledMessage::class . '::' . $nombre),
            "Falta la constante LeadScheduledMessage::{$nombre} (PLAN §2.3 / DECISIONES-E3)."
        );

        return (string) constant(LeadScheduledMessage::class . '::' . $nombre);
    }

    /**
     * Deja a un lead en `contactado` con su `conversando_22h` programado (a las 09:00-09:01 del 24/11), por el recorrido real:
     * llegada a las 10:00 y respuesta SIN pregunta a las 10:05.
     *
     * @return array{0: Lead, 1: string, 2: WhatsappSendService}
     */
    private function lead_contactado_con_su_paso(): array
    {
        [$lead, $telefono, $espia] = $this->llegar_y_recibir_el_paso_1();
        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, self::RESPUESTA_SIN_PREGUNTA)->assertStatus(200);
        $this->avanzar(3);
        $this->correr_job($this->jobs_encolados(SendLeadDosFormasJob::class)->first());

        return [$lead, $telefono, $espia];
    }

    /**
     * Fecha y hora de Argentina como Carbon.
     *
     * @param string $fecha `Y-m-d H:i:s`.
     *
     * @return Carbon
     */
    private function ar(string $fecha): Carbon
    {
        return Carbon::parse($fecha, self::AR);
    }

    /**
     * Mueve el reloj fijo a una hora de Argentina.
     *
     * @param string $fecha `Y-m-d H:i:s`.
     *
     * @return void
     */
    private function ir_a(string $fecha): void
    {
        Carbon::setTestNow($this->ar($fecha));
    }

    /**
     * Un instante, en hora de Argentina, como `Y-m-d H:i:s`.
     *
     * @param \DateTimeInterface $momento
     *
     * @return string
     */
    private function hora_ar($momento): string
    {
        return Carbon::instance($momento)->setTimezone(self::AR)->format('Y-m-d H:i:s');
    }

    /**
     * Afirma que el paso quedó en la apertura de la franja de ese día: entre las 09:00:00 y las 09:01:00 (la franja
     * programa los respaldos corridos a las 09:01 y no a las 09:00 en punto: `MARGEN_AL_ABRIR_SEGUNDOS`).
     *
     * @param LeadScheduledMessage $paso
     * @param string               $dia     `Y-m-d`.
     * @param string               $mensaje
     *
     * @return void
     */
    private function assert_en_la_apertura_de_la_franja(LeadScheduledMessage $paso, string $dia, string $mensaje): void
    {
        $hora = $this->hora_ar($paso->scheduled_send_at);
        $this->assertGreaterThanOrEqual($dia . ' 09:00:00', $hora, $mensaje . " (quedó a las {$hora})");
        $this->assertLessThanOrEqual($dia . ' 09:01:00', $hora, $mensaje . " (quedó a las {$hora})");
    }

    /**
     * Plantilla de Meta registrada y aprobada (como queda cuando Meta la aprueba).
     *
     * @param string $nombre
     *
     * @return void
     */
    private function plantilla_aprobada(string $nombre): void
    {
        FollowupTemplate::create([
            'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
            'body_template' => 'Hola {{1}}, ¿pudiste crear tu prueba?', 'language_code' => 'es_AR', 'activa' => true,
            'meta_status' => 'APPROVED', 'meta_category' => 'UTILITY', 'uso' => 'Seguimiento del embudo (test).',
            'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // Armado del cron viejo (mismo criterio que ElCronNoTocaAlLeadQueContestoTest)
    // ------------------------------------------------------------------------------------------

    /**
     * Lead de clic-a-WhatsApp que nunca contestó: entrante automático + presentación nuestra, todo de hace 72 h.
     *
     * @return Lead
     */
    private function lead_de_anuncio_que_nunca_contesto(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) \Illuminate\Support\Str::uuid();
        $lead->phone        = $this->telefono_al_azar();
        $lead->contact_name = 'Marina';
        $lead->status       = 'nuevo';
        $lead->created_at   = Carbon::now()->subHours(72);
        $lead->save();

        foreach ([['lead', '¡Hola! Quiero más información'], ['sistema', 'Hola Marina! Bienvenida a ComercioCity.']] as $indice => $mensaje) {
            $fila             = new LeadMessage();
            $fila->lead_id    = $lead->id;
            $fila->sender     = $mensaje[0];
            $fila->content    = $mensaje[1];
            $fila->status     = 'enviado';
            $fila->created_at = Carbon::now()->subHours(72)->addMinutes($indice + 1);
            $fila->save();
        }

        return $lead;
    }

    /**
     * Regla de seguimiento del cron viejo para un estado (`estado` es único en la tabla).
     *
     * @param string $estado
     * @param int    $horas_espera
     * @param int    $max_followups
     *
     * @return FollowupRule
     */
    private function crear_regla_de_seguimiento(string $estado, int $horas_espera, int $max_followups): FollowupRule
    {
        return FollowupRule::query()->updateOrCreate(
            ['estado' => $estado],
            ['horas_espera' => $horas_espera, 'max_followups' => $max_followups, 'activa' => true]
        );
    }

    /**
     * Plantilla de seguimiento del cron viejo para un estado (apaga las que hubiera para ese estado).
     *
     * @param string $estado
     *
     * @return FollowupTemplate
     */
    private function crear_plantilla_de_seguimiento(string $estado): FollowupTemplate
    {
        FollowupTemplate::query()->where('estado', $estado)->update(['activa' => false]);

        $template                = new FollowupTemplate();
        $template->estado        = $estado;
        $template->dia_numero    = 1;
        $template->template_name = 'cc_seg_prueba_' . $estado . '_d1';
        $template->language_code = 'es_AR';
        $template->body_template = 'Hola {{1}}! Te escribo de ComercioCity.';
        $template->activa        = true;
        $template->save();

        return $template;
    }

    /**
     * Espía de `WhatsappSendService` que guarda las plantillas y los textos que se le pidió mandar, y queda registrado en
     * el contenedor.
     *
     * @return WhatsappSendService
     */
    private function espiar_plantillas(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Plantillas pedidas: `to`, `template_name`, `variables`. */
            public $plantillas = [];

            /** @var array<int, array<string, string>> Textos pedidos: `to`, `body`. */
            public $textos = [];

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                return 'wamid.ESPIA.' . uniqid('', true);
            }

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.ESPIA.' . uniqid('', true);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }
}
