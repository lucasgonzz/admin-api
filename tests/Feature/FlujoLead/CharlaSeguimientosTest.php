<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadScheduledMessage;
use App\Services\LeadAiService;
use App\Services\PruebaPlantillasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\TableroDeJuan\ArmadoDelTableroDeJuan;
use Tests\TestCase;

/**
 * Los seguimientos del embudo en modo charla (misión leads-charla-antes-de-la-demo, criterio CA9 del PLAN, §2.7).
 *
 * Los pasos de seguimiento de hoy (`LeadEmbudoSeguimientosService`) empujan la prueba: `nuevo_22h` repite las dos formas
 * de arrancar, `conversando_22h` pregunta «¿arrancás por tu cuenta con la prueba gratis o preferís que te lo dejemos
 * cargado?» y el día 3 de «conversando» es una plantilla de Meta que termina «¿Querés que te pase el link?». En la charla
 * el lead tiene que seguir hablando de su negocio, así que:
 *  - los textos de `nuevo_3h`, `nuevo_22h` y `conversando_22h` son de charla (sin link, sin «gratis», sin el precio de
 *    la implementación);
 *  - `conversando_dia_3` no se programa aunque haya plantilla configurada;
 *  - al pasar a `acepto_probar` (lo lleva Juan) se cancelan los pasos pendientes y no se programa ninguno.
 * Con la charla apagada todo queda como hoy.
 *
 * Todo con reloj fijo (10:00 del lunes 23/11/2026 de Argentina), el webhook real de Kapso y el espía de WhatsApp del
 * trait: nada sale de la máquina. Los textos van como literales del PLAN (los defaults de charla todavía no existen).
 *
 * Hoy están EN ROJO los de charla prendida; `test_apagada_igual_que_hoy` es de no-regresión y pasa ya.
 */
class CharlaSeguimientosTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;
    use ArmadoDelTableroDeJuan;

    /** Clave del interruptor de la charla (PLAN §2.1). */
    private const KEY_CHARLA = 'lead_whatsapp_charla_antes_de_la_demo_activa';

    /** Primer mensaje del lead: el texto precargado de un anuncio. */
    private const PRIMER_MENSAJE = 'Hola, vi el video de los presupuestos y quiero probar ComercioCity';

    /** Primer mensaje de un lead del anuncio SIN «quiero probar» (el camino literal de hoy no le manda el link). */
    private const PRIMER_MENSAJE_SIN_PROBAR = 'Hola, vi el video de ComercioCity y quiero más info';

    /** Texto de charla de `nuevo_3h` (PLAN §2.7). */
    private const TEXTO_NUEVO_3H = '¿Pudiste ver mi mensaje? Contame de qué rubro es tu negocio y te cuento cómo te puede ayudar ComercioCity.';

    /** Texto de charla de `nuevo_22h` (PLAN §2.7). */
    private const TEXTO_NUEVO_22H = 'Te escribo de nuevo por si te quedó pendiente: contame qué tipo de negocio tenés y qué es lo que más te cuesta hoy (stock, precios, caja…), así te cuento cómo lo resolvemos.';

    /** Texto de charla de `conversando_22h` (PLAN §2.7). */
    private const TEXTO_CONVERSANDO_22H = '¿Seguimos? Contame qué es lo que más te complica hoy en el negocio y te cuento cómo lo resolvemos con ComercioCity.';

    /** Nombre de la plantilla del día 3 de «conversando» (la que ofrece el link: no puede salir en la charla). */
    private const PLANTILLA_CONVERSANDO_DIA_3 = 'cc_embudo_conversando_dia_3';

    /**
     * Reloj fijo de las 10:00 del lunes 23/11/2026 (hora de Argentina), flujo y charla prendidos, y los textos y
     * plantillas del embudo sin pisar (rigen los defaults).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* La propiedad estática es del trait y cada clase que lo usa tiene la suya: acá se pisa sólo la de este test. */
        self::$AHORA_DEL_FLUJO = '2026-11-23 10:00:00';

        $this->preparar_flujo_de_entrada(true, true, true);

        foreach (['nuevo_3h', 'nuevo_22h', 'conversando_22h', 'link_2h', 'link_22h'] as $paso) {
            AdminSetting::set('lead_embudo_paso_' . $paso, '');
            AdminSetting::set('lead_embudo_paso_charla_' . $paso, '');
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
    // CA9 (a) — nuevo
    // ------------------------------------------------------------------------------------------

    /**
     * CA9 (a). Al salir la llegada a un lead en `nuevo` se programan `nuevo_3h` y `nuevo_22h` y sus textos son los de
     * charla: no llevan el link de la prueba, «gratis» ni el precio de la implementación.
     *
     * @return void
     */
    public function test_pasos_de_nuevo_con_texto_de_charla(): void
    {
        [$lead] = $this->llegar_y_recibir_el_paso_1();

        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($lead), 'Al salir la llegada quedan programados los dos pasos de nuevo.');

        $tres_horas = (string) $this->unico_pendiente($lead, 'nuevo_3h')->content;
        $veintidos  = (string) $this->unico_pendiente($lead, 'nuevo_22h')->content;

        $this->assertSame(self::TEXTO_NUEVO_3H, $tres_horas);
        $this->assertSame(self::TEXTO_NUEVO_22H, $veintidos);

        foreach ([$tres_horas, $veintidos] as $texto) {
            $this->assertStringNotContainsString('comerciocity.com/prueba', $texto, 'Los pasos de la charla no llevan el link.');
            $this->assertStringNotContainsStringIgnoringCase('gratis', $texto);
            $this->assertStringNotContainsString('628.400', $texto, 'Ni el precio de la implementación.');
            $this->assertStringNotContainsString('dos formas', $texto);
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA9 (b) — conversando
    // ------------------------------------------------------------------------------------------

    /**
     * CA9 (b). Un lead que contesta (pasa a `contactado`) con `lead_embudo_plantilla_conversando_dia_3` configurada (y
     * aprobada) tiene `conversando_22h` con el texto de charla y NO tiene `conversando_dia_3`: esa plantilla ofrece el
     * link y no se puede editar.
     *
     * @return void
     */
    public function test_conversando_sin_dia_3(): void
    {
        AdminSetting::set('lead_embudo_plantilla_conversando_dia_3', self::PLANTILLA_CONVERSANDO_DIA_3);
        $this->plantilla_aprobada(self::PLANTILLA_CONVERSANDO_DIA_3);

        [$lead, $telefono] = $this->llegar_y_recibir_el_paso_1();
        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, 'Almacén y abastecedora')->assertStatus(200);

        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Precondición: contestar la presentación lo pasa a contactado.');
        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead), 'Sólo conversando_22h: el día 3 con plantilla no se programa en la charla.');
        $this->assertSame(self::TEXTO_CONVERSANDO_22H, (string) $this->unico_pendiente($lead, 'conversando_22h')->content);
        $this->assertStringNotContainsStringIgnoringCase('gratis', (string) $this->unico_pendiente($lead, 'conversando_22h')->content);
    }

    // ------------------------------------------------------------------------------------------
    // CA9 (c) — acepto_probar
    // ------------------------------------------------------------------------------------------

    /**
     * CA9 (c). Al pasar a `acepto_probar` (el sí, por el turno real del agente) todos sus pasos pendientes quedan
     * cancelados y no se programa ninguno nuevo: de ahí en más lo lleva Juan.
     *
     * @return void
     */
    public function test_acepto_cancela_los_pasos(): void
    {
        $this->sembrar_el_tablero();
        $this->crear_a_juan();
        $this->sembrar_protocolo_de_leads();

        [$lead, $telefono] = $this->llegar_y_recibir_el_paso_1();
        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, 'Almacén y abastecedora')->assertStatus(200);
        $this->assertSame(['conversando_22h'], $this->claves_pendientes($lead), 'Precondición: el lead que conversa tiene su paso pendiente.');

        $this->avanzar(60);
        $this->fakear_ia([$this->paquete_ia('¡Genial! Te va a llamar Juan, del equipo, para mostrarte la plataforma.', 'acepto_probar')]);
        app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame('acepto_probar', (string) $lead->fresh()->status, 'Precondición: el sí se aplicó.');
        $this->assertSame([], $this->claves_pendientes($lead), 'Al pasar a acepto_probar no queda ningún paso pendiente.');
        $this->assertSame(
            0,
            LeadScheduledMessage::query()->where('lead_id', $lead->id)->where('estado_lead_al_programar', 'acepto_probar')->count(),
            'Y acepto_probar no programa pasos propios.'
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA9 — apagada
    // ------------------------------------------------------------------------------------------

    /**
     * CA9 (apagada, no-regresión). Con la charla apagada los pasos son los de hoy: `nuevo_22h` es las dos formas de
     * arrancar, tal cual, y el lead que contesta (por el camino literal) tiene `conversando_22h` y, con plantilla
     * configurada, también `conversando_dia_3`. Pasa ya.
     *
     * @return void
     */
    public function test_apagada_igual_que_hoy(): void
    {
        AdminSetting::set(self::KEY_CHARLA, '0');
        AdminSetting::set('lead_embudo_plantilla_conversando_dia_3', self::PLANTILLA_CONVERSANDO_DIA_3);
        $this->plantilla_aprobada(self::PLANTILLA_CONVERSANDO_DIA_3);

        [$lead, $telefono] = $this->llegar_y_recibir_el_paso_1(self::PRIMER_MENSAJE_SIN_PROBAR);

        $this->assertSame(['nuevo_22h', 'nuevo_3h'], $this->claves_pendientes($lead));
        $this->assertSame($this->texto_respuesta_1(), (string) $this->unico_pendiente($lead, 'nuevo_22h')->content, 'Apagada: nuevo_22h son las dos formas, como hoy.');

        $this->avanzar(300); // 10:05
        $this->postear_kapso($telefono, 'Ferretería, unos 4000 productos')->assertStatus(200);
        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'Precondición: sin charla, contestar sin preguntar encola las dos formas.');
        $this->avanzar(3);
        $this->correr_job($jobs->first());

        $this->assertSame(['conversando_22h', 'conversando_dia_3'], $this->claves_pendientes($lead), 'Apagada: conversando_22h y el día 3 con plantilla.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead del anuncio que escribe, recibe los dos mensajes de la llegada por el job real y queda en `nuevo` con el
     * paso 1 entregado (sus pasos de seguimiento ya programados).
     *
     * @param string $primer_mensaje Lo que escribe el lead al llegar (por defecto, el texto precargado de un anuncio).
     *
     * @return array{0: Lead, 1: string} Lead y teléfono.
     */
    private function llegar_y_recibir_el_paso_1(string $primer_mensaje = self::PRIMER_MENSAJE): array
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, $primer_mensaje)->assertStatus(200);

        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();
        $this->assertSame('nuevo', (string) $lead->status, 'Precondición: el lead llega en nuevo.');

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertGreaterThanOrEqual(1, $jobs->count(), 'Precondición: queda el job de la llegada.');
        $this->espiar_whatsapp();
        $this->correr_job($jobs->last());
        $this->assertCount(2, $this->mensajes_de_sistema($lead), 'Precondición: salieron los dos mensajes de la llegada.');

        return [$lead->fresh(), $telefono];
    }

    /**
     * Todos los pasos del embudo del lead (cualquier estado), en orden de creación.
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
     * Deja una plantilla de seguimiento aprobada por Meta, como cuando Meta la aprueba (sin ella el paso de plantilla no
     * se programa).
     *
     * @param string $nombre Nombre de la plantilla.
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
}
