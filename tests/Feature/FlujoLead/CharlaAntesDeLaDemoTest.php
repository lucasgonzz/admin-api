<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadOrigenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La charla antes de la demo (misión leads-charla-antes-de-la-demo, criterios CA1 a CA4 y CA12 del PLAN).
 *
 * Lo que pasó (lead 722, «Cecilia FEC 5»): llegó del anuncio con «quiero probar», contestó su rubro («almacén y
 * abastecedora») y Martín le tiró enseguida «Tenés 2 formas de arrancar» + la página + el link de la prueba, sin charlar.
 * Lucas pidió que el lead hable con la gente: que le cuenten cómo es el sistema, le pregunten cuántos negocios,
 * qué tipo y cuántos empleados, le saquen por lo menos un dolor y recién ahí le ofrezcan la demo. El link y las dos
 * formas NO salen solos: la prueba la ofrece Lucas a mano después de que Juan lo llame.
 *
 * Lo que se fija acá:
 *  - CA1/CA2 el camino real del webhook (anuncio con «quiero probar» y frase de la web): la llegada pregunta el rubro
 *    sin vender la prueba, y al contestar NO sale ni las dos formas, ni la página, ni el link; contesta la IA.
 *  - CA3 el bloque que lee la IA en cada turno es el de la charla (y con la charla apagada, el de hoy).
 *  - CA4 las guardas: no se borra la pregunta de empleados, y un mensaje con el link de la prueba queda retenido.
 *  - CA12 el interruptor por la API de ajustes de Cuenta (default prendido, opcional en el PUT).
 *
 * Las claves de `admin_settings` y los textos del PLAN van como literales: la constante `KEY_CHARLA` y los defaults
 * todavía no existen y un test que muere por una constante faltante falla por el motivo equivocado.
 *
 * Hoy están EN ROJO los que describen lo nuevo; los de «charla apagada» (CA3 apagada, CA12 con el flujo viejo) son de
 * no-regresión y pasan ya.
 */
class CharlaAntesDeLaDemoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Clave del interruptor de la charla (PLAN §2.1, `LeadFlujoEntradaService::KEY_CHARLA`). */
    private const KEY_CHARLA = 'lead_whatsapp_charla_antes_de_la_demo_activa';

    /** Segundo mensaje de la llegada en modo charla (PLAN §2.2, `DEFAULT_CHARLA_LLEGADA_2`). */
    private const LLEGADA_2_DE_LA_CHARLA = 'Contame un poco de tu negocio, ¿de qué rubro es?';

    /** Primer mensaje del lead 722: el texto precargado de un anuncio, que dice «quiero probar». */
    private const PRIMER_MENSAJE_DE_CECILIA = 'Hola, vi el video de los presupuestos y quiero probar ComercioCity';

    /** Endpoint de ajustes del onboarding (Cuenta). */
    private const ENDPOINT = '/api/admin/settings/lead-whatsapp-onboarding';

    /**
     * Reloj fijo, flujo de entrada y CHARLA prendidos, y el protocolo de leads sembrado para llegar hasta la IA.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true, true, true);
        $this->sembrar_protocolo_de_leads();
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
    // CA1 y CA2 — la llegada y la respuesta del lead por el webhook real
    // ------------------------------------------------------------------------------------------

    /**
     * CA1 (Cecilia, regresión). Con la charla prendida SIN setear la clave (prueba el default '1'): al llegar con el
     * texto del anuncio salen sólo la bienvenida y `whatsapp_entrada_2`, que es la pregunta del rubro sin «gratis» ni
     * «probar». Al contestar «Almacén y abastecedora» no se encola `SendLeadDosFormasJob`, se agenda la IA, no existe
     * ningún mensaje de las dos formas, la página o el link, ningún texto del hilo trae el link de la prueba y el lead
     * queda en `contactado`.
     *
     * @return void
     */
    public function test_lead_del_anuncio_que_quiere_probar_charla_sin_dos_formas_ni_link(): void
    {
        AdminSetting::query()->where('key', self::KEY_CHARLA)->delete();

        [$telefono, $lead] = $this->llegar($this->primer_mensaje_de_cecilia());

        $kinds = $this->mensajes_de_sistema($lead)->pluck('system_message_kind')->all();
        $this->assertSame(['whatsapp_welcome', 'whatsapp_entrada_2'], $kinds, 'La llegada son sólo los dos mensajes del paso 1.');

        $segundo = (string) $this->mensajes_de_sistema($lead)->last()->content;
        $this->assertSame(self::LLEGADA_2_DE_LA_CHARLA, $segundo, 'En modo charla la llegada pregunta el rubro, sin vender la prueba.');
        $this->assertStringNotContainsStringIgnoringCase('gratis', $segundo);
        $this->assertStringNotContainsStringIgnoringCase('probar', $segundo);

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Almacén y abastecedora')->assertStatus(200);

        $this->afirmar_que_nada_vende_la_prueba($lead);
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Contestó la presentación: contactado, no link_enviado.');
    }

    /**
     * CA1 (el motivo de fondo, aislado de la llegada). Con la charla prendida, el lead que contesta el rubro sin
     * preguntar NO dispara el camino literal del paso 2: ni job de las dos formas, ni mensajes de dos formas, página o
     * link; sí se agenda la IA, que es quien charla.
     *
     * @return void
     */
    public function test_contestar_el_rubro_no_dispara_dos_formas_ni_link(): void
    {
        [$telefono, $lead] = $this->llegar($this->primer_mensaje_de_cecilia());

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Almacén y abastecedora')->assertStatus(200);

        $this->afirmar_que_nada_vende_la_prueba($lead);
    }

    /**
     * CA2 (web). Igual que CA1 con el primer mensaje de la landing («Hola, quiero probar ComercioCity»): tampoco recibe
     * las dos formas, la página ni el link, y la llegada es la pregunta del rubro.
     *
     * @return void
     */
    public function test_lead_de_la_web_tambien_charla(): void
    {
        AdminSetting::query()->where('key', self::KEY_CHARLA)->delete();

        [$telefono, $lead] = $this->llegar(LeadOrigenService::FRASE_LANDING_PRUEBA);

        $segundo = (string) $this->mensajes_de_sistema($lead)->last()->content;
        $this->assertSame(self::LLEGADA_2_DE_LA_CHARLA, $segundo, 'El lead de la web también arranca con la charla.');
        $this->assertStringNotContainsStringIgnoringCase('gratis', $segundo);

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Almacén y abastecedora')->assertStatus(200);

        $this->afirmar_que_nada_vende_la_prueba($lead);
        $this->assertSame('contactado', (string) $lead->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — el bloque que lee la IA
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. El contexto del turno de un lead `contactado` con la charla prendida trae el bloque de la charla (qué
     * preguntar, el dolor, ofrecer ver la plataforma, el `acepto_probar`) y NO trae el link de la prueba, ni «Tu
     * objetivo es que elija cómo arrancar», ni «No pidas el nombre, ni cuántos trabajan», ni el bloque «DEMO SÓLO A
     * PEDIDO» de la prueba gratis.
     *
     * @return void
     */
    public function test_bloque_de_la_charla(): void
    {
        $lead     = $this->lead_en_charla('contactado');
        $contexto = $this->contexto_del_turno($lead);

        foreach ([
            'CHARLA ANTES DE LA DEMO',
            'cuántos negocios',
            'cuántas personas trabajan',
            'qué es lo que más le cuesta hoy',
            'si le gustaría ver la plataforma',
            'acepto_probar',
            'NO le mandes el link de la prueba',
        ] as $ancla) {
            $this->assertStringContainsString($ancla, $contexto, "El bloque de la charla tiene que decir «{$ancla}».");
        }

        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $contexto, 'En la charla el link de la prueba no está en el contexto.');
        $this->assertStringNotContainsString('Tu objetivo es que elija cómo arrancar', $contexto);
        $this->assertStringNotContainsString('No pidas el nombre, ni cuántos trabajan', $contexto);
        $this->assertStringNotContainsString('DEMO SÓLO A PEDIDO (el camino de este lead es la prueba gratis', $contexto);
    }

    /**
     * CA3 (no-regresión). Con la charla APAGADA el contexto es el de hoy: el objetivo es que elija cómo arrancar y no
     * aparece el bloque de la charla. Pasa ya.
     *
     * @return void
     */
    public function test_bloque_de_hoy_con_la_charla_apagada(): void
    {
        AdminSetting::set(self::KEY_CHARLA, '0');

        $lead     = $this->lead_en_charla('contactado');
        $contexto = $this->contexto_del_turno($lead);

        $this->assertStringContainsString('Tu objetivo es que elija cómo arrancar', $contexto);
        $this->assertStringNotContainsString('CHARLA ANTES DE LA DEMO', $contexto);
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — las guardas del paquete de la IA
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. Con la charla prendida, la pregunta de calificación («¿Cuántas personas trabajan con vos?») sale idéntica:
     * Lucas pidió preguntar la cantidad de empleados y la guarda vieja la borraba.
     *
     * @return void
     */
    public function test_no_saca_la_pregunta_de_empleados(): void
    {
        $lead = $this->lead_en_charla('contactado');
        $this->espiar_whatsapp();

        $this->fakear_ia([$this->paquete_ia('¡Buenísimo! ¿Cuántas personas trabajan con vos?', 'contactado')]);
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame(
            '¡Buenísimo! ¿Cuántas personas trabajan con vos?',
            trim((string) $mensaje->content),
            'En la charla no se borra la pregunta de empleados.'
        );
    }

    /**
     * CA4 (con la charla apagada). La misma pregunta se saca como hoy: lo cubren los tests viejos con `$charla = false`;
     * acá queda un control mínimo para que el contraste no dependa de ellos. Pasa ya.
     *
     * @return void
     */
    public function test_con_la_charla_apagada_la_pregunta_de_empleados_se_saca(): void
    {
        AdminSetting::set(self::KEY_CHARLA, '0');
        $lead = $this->lead_en_charla('contactado');

        $this->fakear_ia([$this->paquete_ia('¡Buenísimo! ¿Cuántas personas trabajan con vos?', 'contactado')]);
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertStringNotContainsString('¿Cuántas personas trabajan', (string) $mensaje->content, 'Con la charla apagada la guarda vieja sigue sacando la pregunta.');
    }

    /**
     * CA4. Un paquete que lleva el link de la prueba queda retenido para Lucas: `status = sugerido` con
     * `requiere_verificacion = true`, no sale por WhatsApp y el lead no pasa a `link_enviado`.
     *
     * @return void
     */
    public function test_mensaje_con_link_queda_retenido(): void
    {
        $lead   = $this->lead_en_charla('contactado');
        $espia  = $this->espiar_whatsapp();
        $texto  = 'Si querés, creá tu prueba ahora: ' . LeadOfertaDePruebaService::link();

        $this->fakear_ia([$this->paquete_ia($texto, 'contactado')]);
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSame('sugerido', (string) $mensaje->fresh()->status, 'El paquete queda sin enviar.');
        $this->assertTrue((bool) $mensaje->fresh()->requiere_verificacion, 'Y retenido para que lo decida Lucas.');
        $this->assertCount(0, $espia->textos, 'Nada salió por WhatsApp.');
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'El lead no pasó a link_enviado.');
    }

    // ------------------------------------------------------------------------------------------
    // CA12 — el interruptor por la API de ajustes
    // ------------------------------------------------------------------------------------------

    /**
     * CA12. `GET` de ajustes devuelve `charla_antes_de_la_demo_activa: true` sin fila; `PUT` con `false` guarda '0' y el
     * flujo de CA1 vuelve al comportamiento de hoy (se encola `SendLeadDosFormasJob`); un `PUT` sin la clave no cambia
     * el valor (compatible con el SPA de hoy).
     *
     * @return void
     */
    public function test_interruptor_por_ajustes(): void
    {
        AdminSetting::query()->where('key', self::KEY_CHARLA)->delete();
        $admin = $this->crear_admin_con_roles('Admin de Cuenta', []);

        /* Sin fila: prendido. */
        $get = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT);
        $get->assertStatus(200);
        $this->assertTrue($get->json('charla_antes_de_la_demo_activa'), 'Por defecto la charla está prendida.');

        /* Apagarla explícitamente. */
        $put = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['charla_antes_de_la_demo_activa' => false]));
        $put->assertStatus(200);
        $this->assertSame('0', AdminSetting::get(self::KEY_CHARLA), 'El PUT con false guarda 0.');
        $this->assertFalse($put->json('charla_antes_de_la_demo_activa'));

        /* Un PUT de hoy, sin la clave, no la toca. */
        $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, $this->payload_de_hoy())->assertStatus(200);
        $this->assertSame('0', AdminSetting::get(self::KEY_CHARLA), 'Un PUT sin la clave no puede prender ni apagar la charla.');

        /* Con '0', el camino de Cecilia vuelve a ser el de hoy: contestar el rubro encola las dos formas. */
        [$telefono, $lead] = $this->llegar($this->primer_mensaje_de_cecilia());
        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Almacén y abastecedora')->assertStatus(200);
        $this->assertCount(1, $this->jobs_encolados(SendLeadDosFormasJob::class), 'Con la charla apagada se encolan las dos formas, como hoy.');
        $this->assertNotNull($lead->fresh());

        /* Y volver a prenderla. */
        $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['charla_antes_de_la_demo_activa' => true]))->assertStatus(200);
        $this->assertSame('1', AdminSetting::get(self::KEY_CHARLA));
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * @return string El primer mensaje de Cecilia (texto precargado del anuncio con «quiero probar»).
     */
    private function primer_mensaje_de_cecilia(): string
    {
        return self::PRIMER_MENSAJE_DE_CECILIA;
    }

    /**
     * El payload que el SPA de hoy manda al PUT de Cuenta (sin las claves nuevas).
     *
     * @return array<string, mixed>
     */
    private function payload_de_hoy(): array
    {
        return [
            'auto_message_with_name'       => 'Hola {nombre}!',
            'auto_message_without_name'    => 'Hola!',
            'welcome_message_with_name'    => 'Bienvenido {nombre}!',
            'welcome_message_without_name' => 'Bienvenido!',
            'welcome_delay_seconds'        => 60,
            'ai_suggestion_delay_seconds'  => 60,
            'verificacion_agendamiento_auto_send_delay_minutes'     => 30,
            'auto_activar_verificacion_al_solicitar_disponibilidad' => true,
        ];
    }

    /**
     * Lleva a un lead nuevo por el webhook real hasta que recibe la llegada: escribe `$primer_mensaje`, corre el job de
     * la presentación con el espía de WhatsApp (salen los dos mensajes del paso 1) y deja pasar un minuto.
     *
     * @param string $primer_mensaje Lo que escribe al llegar.
     *
     * @return array{0: string, 1: Lead} Teléfono y lead.
     */
    private function llegar(string $primer_mensaje): array
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, $primer_mensaje)->assertStatus(200);
        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertGreaterThanOrEqual(1, $jobs->count(), 'Precondición: tiene que haber un job de la llegada.');
        $this->espiar_whatsapp();
        $this->correr_job($jobs->last());
        $this->assertCount(2, $this->mensajes_de_sistema($lead), 'Precondición: salieron los dos mensajes de la llegada.');

        $this->avanzar(60);

        return [$telefono, $lead->fresh()];
    }

    /**
     * Afirma que, después de contestar el rubro, el sistema no vendió la prueba: ni job de las dos formas, ni mensajes
     * de dos formas, página o link, ni el link en ningún texto del hilo; y que la IA quedó agendada.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function afirmar_que_nada_vende_la_prueba(Lead $lead): void
    {
        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class), 'No se encola el literal de las dos formas.');
        $this->assertCount(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class), 'Contesta la IA: se agenda su turno.');

        $kinds = LeadMessage::query()->where('lead_id', $lead->id)->whereNotNull('system_message_kind')->pluck('system_message_kind')->all();
        foreach (['whatsapp_dos_formas', 'whatsapp_pagina', 'whatsapp_prueba_link'] as $prohibido) {
            $this->assertNotContains($prohibido, $kinds, "No puede existir un mensaje {$prohibido}.");
        }

        $link = LeadOfertaDePruebaService::link();
        foreach (LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'sistema')->get() as $mensaje) {
            $this->assertStringNotContainsString($link, (string) $mensaje->content, 'Ningún mensaje del hilo lleva el link de la prueba.');
        }
    }

    /**
     * Lead del flujo (nacido con el interruptor prendido) en el estado pedido, con la llegada entregada y dos
     * mensajes suyos: el primero (el precargado) y una respuesta, como un lead que ya está charlando.
     *
     * @param string $estado Estado del lead.
     *
     * @return Lead
     */
    private function lead_en_charla(string $estado): Lead
    {
        $lead = $this->crear_lead_de_flujo($estado);
        $this->mensaje_del_lead($lead, $this->primer_mensaje_de_cecilia());
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Almacén y abastecedora');

        return $lead->fresh();
    }
}
