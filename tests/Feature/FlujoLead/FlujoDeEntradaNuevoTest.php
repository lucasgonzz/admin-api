<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Jobs\SendLeadDosFormasJob;
use App\Jobs\SendLeadPresentationMessageJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadOrigenService;
use App\Services\LeadWhatsappOnboardingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Flujo de entrada nuevo de Martín, camino literal (misión embudo-whatsapp-precios, etapa E2, criterios CA9,
 * CA10 y CA16 del PLAN; §2.2).
 *
 * Con el interruptor `lead_whatsapp_flujo_entrada_activo` prendido, a TODO lead nuevo de WhatsApp (del anuncio o
 * de la web) cuyo primer mensaje no pregunta nada le salen dos mensajes de llegada (presentación; rubro y
 * productos), y cuando contesta sin preguntar, las dos formas de arrancar y la página. El link de la prueba no
 * sale en la llegada: sólo con las dos formas, y sólo al lead de la web (P2 = sí). Apagado, todo sigue como hoy.
 *
 * Todo corre por el webhook real (`POST webhook/whatsapp`) y los jobs que quedan en la cola falsa; ningún envío
 * sale de la máquina (el espía de WhatsApp guarda lo que saldría). Los textos son los literales de Lucas (§9).
 *
 * Hoy están EN ROJO: el flujo todavía no existe (el webhook manda el auto, el job de presentación manda la oferta
 * o la variante, no hay job de las dos formas, y las dos claves nuevas del PUT de Cuenta no se guardan).
 */
class FlujoDeEntradaNuevoTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Endpoint de la pantalla de Cuenta → Onboarding de leads. */
    private const ENDPOINT = '/api/admin/settings/lead-whatsapp-onboarding';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
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
    // CA9 — al llegar, sin pregunta
    // ------------------------------------------------------------------------------------------

    /**
     * CA9. El lead del anuncio («Hola, vi el video…», sin pregunta) recibe exactamente dos mensajes de sistema:
     * `llegada_1` (kind de la bienvenida) y `llegada_2` (`whatsapp_entrada_2`), con los textos literales de Lucas
     * y `{dias_prueba}` = 7. No sale el auto, no hay variante A/B y ningún mensaje lleva el link de la prueba.
     *
     * @return void
     */
    public function test_lead_del_anuncio_recibe_los_dos_mensajes_de_llegada_sin_link(): void
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, 'Hola, vi el video de ComercioCity y quiero más info')->assertStatus(200);

        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();
        $this->assertSame(
            0,
            $this->mensajes_de_sistema($lead)->count(),
            'Con el flujo prendido no sale el auto: el primer mensaje de Martín ya es el saludo de llegada.'
        );
        $this->assertNull($lead->fresh()->welcome_variant_id, 'El flujo nuevo no usa la variante A/B.');

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs, 'Tiene que quedar un solo job de la llegada.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());

        $this->assertSame([$this->texto_llegada_1(), $this->texto_llegada_2()], $this->cuerpos_enviados($espia));

        $mensajes = $this->mensajes_de_sistema($lead);
        $this->assertCount(2, $mensajes, 'Exactamente dos mensajes de sistema.');
        $this->assertSame(LeadWhatsappOnboardingService::KIND_WELCOME, $mensajes[0]->system_message_kind, 'llegada_1 va como la bienvenida de siempre.');
        $this->assertSame('whatsapp_entrada_2', $mensajes[1]->system_message_kind);
        $this->assertSame($this->texto_llegada_1(), $mensajes[0]->content);
        $this->assertSame($this->texto_llegada_2(), $mensajes[1]->content);

        foreach ($mensajes as $mensaje) {
            $this->assertStringNotContainsString('comerciocity.com/prueba', (string) $mensaje->content, 'El link de la prueba no sale en la llegada.');
        }
    }

    /**
     * CA9. El lead de la web (frase «Hola, quiero probar ComercioCity») recibe lo mismo que el del anuncio: los
     * dos mensajes de llegada y NADA de la oferta de hoy (ni el link): el link va recién con las dos formas.
     *
     * @return void
     */
    public function test_lead_de_la_web_tambien(): void
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, LeadOrigenService::FRASE_LANDING_PRUEBA)->assertStatus(200);

        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen);
        $this->assertSame(0, $this->mensajes_de_sistema($lead)->count(), 'Sin auto.');

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs);

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());

        $this->assertSame(
            [$this->texto_llegada_1(), $this->texto_llegada_2()],
            $this->cuerpos_enviados($espia),
            'El lead de la web recibe los dos mensajes de llegada, no la oferta de la prueba de hoy.'
        );

        $mensajes = $this->mensajes_de_sistema($lead);
        $this->assertCount(2, $mensajes);
        $this->assertSame(LeadWhatsappOnboardingService::KIND_WELCOME, $mensajes[0]->system_message_kind);
        $this->assertSame('whatsapp_entrada_2', $mensajes[1]->system_message_kind);

        foreach ($mensajes as $mensaje) {
            $this->assertStringNotContainsString('comerciocity.com/prueba', (string) $mensaje->content);
            $this->assertNotSame(LeadWhatsappOnboardingService::KIND_PRUEBA_LINK, $mensaje->system_message_kind);
        }
    }

    /**
     * CA9. Si el job se reintenta no duplica: correrlo dos veces deja los dos mensajes una sola vez. Y si se cayó
     * entre los dos (la primera parte ya salió), el reintento manda SÓLO la que falta.
     *
     * @return void
     */
    public function test_reintento_del_job_no_duplica(): void
    {
        /* Dos corridas del mismo job. */
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, 'Hola, vi el video de ComercioCity y quiero más info')->assertStatus(200);
        $lead  = Lead::query()->where('phone', $telefono)->firstOrFail();
        $job   = $this->jobs_encolados(SendLeadPresentationMessageJob::class)->first();
        $this->assertNotNull($job, 'No quedó encolado el job de la llegada.');
        $espia = $this->espiar_whatsapp();

        $this->correr_job($job);
        $this->correr_job($job);

        $this->assertSame([$this->texto_llegada_1(), $this->texto_llegada_2()], $this->cuerpos_enviados($espia), 'El segundo corrido no tiene que mandar nada.');
        $this->assertCount(2, $this->mensajes_de_sistema($lead));

        /* El job se cayó después de la primera parte: el reintento manda sólo `llegada_2`. */
        $otro_telefono = $this->telefono_al_azar();
        $this->postear_kapso($otro_telefono, 'Hola, vi el video de ComercioCity y quiero más info')->assertStatus(200);
        $otro = Lead::query()->where('phone', $otro_telefono)->firstOrFail();
        $this->mensaje_de_sistema($otro, $this->texto_llegada_1(), LeadWhatsappOnboardingService::KIND_WELCOME);

        $jobs_de_otro = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(2, $jobs_de_otro, 'Un job por lead.');
        $espia_2 = $this->espiar_whatsapp();
        $this->correr_job($jobs_de_otro->last());

        $this->assertSame([$this->texto_llegada_2()], $this->cuerpos_enviados($espia_2), 'El reintento tiene que mandar sólo lo que falta.');
        $this->assertCount(2, $this->mensajes_de_sistema($otro));
    }

    /**
     * Guarda de regresión del CA8 (verde hoy y tiene que seguir verde): con el interruptor APAGADO un lead nuevo
     * que escribe «Hola» recibe el auto y la variante como siempre, y no se encola nada del flujo nuevo.
     *
     * @return void
     */
    public function test_apagado_el_primer_mensaje_sigue_con_el_onboarding_de_hoy(): void
    {
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');

        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, 'Hola')->assertStatus(200);

        $lead     = Lead::query()->where('phone', $telefono)->firstOrFail();
        $mensajes = $this->mensajes_de_sistema($lead);
        $this->assertCount(1, $mensajes, 'Apagado: sale el auto de siempre.');
        $this->assertSame(LeadWhatsappOnboardingService::KIND_AUTO, $mensajes[0]->system_message_kind);

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs);
        $this->assertFalse($jobs->first()->es_oferta_de_prueba());
        $this->assertNotNull($lead->fresh()->welcome_variant_id, 'Apagado: la variante A/B sigue asignándose.');
        $this->assertCount(0, $this->jobs_encolados(SendLeadDosFormasJob::class));
    }

    // ------------------------------------------------------------------------------------------
    // CA10 — cuando contesta, sin pregunta
    // ------------------------------------------------------------------------------------------

    /**
     * CA10. El lead del anuncio ya recibió la llegada y contesta «Ferretería, unos 4000» (sin pregunta): pasa a
     * `contactado` (que el catálogo llama «Conversando») y el job de las dos formas manda `respuesta_1` (con
     * «Tenés dos formas de arrancar:», «1️⃣ Por tu cuenta, gratis:» y «un pago único de $628.400; podés arrancar
     * con la mitad») y `respuesta_2` (que termina en «comerciocity.com»), cada una con su kind y sin ningún link.
     *
     * @return void
     */
    public function test_al_contestar_pasa_a_conversando_y_recibe_las_dos_formas_con_el_precio(): void
    {
        [$telefono, $lead, $espia_llegada] = $this->llegar_y_recibir_la_llegada('Hola, vi el video de ComercioCity y quiero más info');

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Ferretería, unos 4000')->assertStatus(200);

        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Contestar la presentación lo pasa a «Conversando» (slug contactado).');

        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs, 'Tiene que encolarse el job de las dos formas.');

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());

        $cuerpos = $this->cuerpos_enviados($espia);
        $this->assertCount(2, $cuerpos, 'Sin pregunta y sin ser de la web: las dos formas y la página, nada más.');
        $this->assertStringContainsString('Tenés dos formas de arrancar:', $cuerpos[0]);
        $this->assertStringContainsString('1️⃣ Por tu cuenta, gratis:', $cuerpos[0]);
        $this->assertStringContainsString('un pago único de $628.400; podés arrancar con la mitad', $cuerpos[0]);
        $this->assertSame($this->texto_respuesta_1(), $cuerpos[0]);
        $this->assertStringEndsWith('comerciocity.com', $cuerpos[1]);
        $this->assertSame($this->texto_respuesta_2(), $cuerpos[1]);

        $nuevos = $this->mensajes_de_sistema($lead)->slice(2)->values();
        $this->assertCount(2, $nuevos);
        $this->assertSame('whatsapp_dos_formas', $nuevos[0]->system_message_kind);
        $this->assertSame('whatsapp_pagina', $nuevos[1]->system_message_kind);
        foreach ($nuevos as $mensaje) {
            $this->assertStringNotContainsString('comerciocity.com/prueba', (string) $mensaje->content, 'El lead del anuncio no recibe el link: lo pide cuando quiere probar.');
        }
    }

    /**
     * CA10. Ese inbound NO agenda sugerencia de IA (lo que había que decir salió literal), pero el SIGUIENTE sí.
     *
     * @return void
     */
    public function test_ese_turno_no_agenda_ia_y_el_siguiente_si(): void
    {
        [$telefono] = $this->llegar_y_recibir_la_llegada('Hola, vi el video de ComercioCity y quiero más info');

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Ferretería, unos 4000')->assertStatus(200);

        $this->assertCount(1, $this->jobs_encolados(SendLeadDosFormasJob::class), 'Precondición: el inbound encoló las dos formas.');
        $this->assertCount(
            0,
            $this->jobs_encolados(GenerateLeadAiSuggestionJob::class),
            'El inbound que dispara el literal no tiene que agendar turno de IA.'
        );

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Dale, quiero probar')->assertStatus(200);

        $this->assertCount(1, $this->jobs_encolados(GenerateLeadAiSuggestionJob::class), 'El siguiente inbound sí agenda la IA.');
    }

    /**
     * CA10 + P2. Si el primer mensaje del lead fue la frase de la web, con las dos formas sale además el link de
     * la prueba (`KIND_PRUEBA_LINK`) como TERCER mensaje, en este orden: respuesta_1, respuesta_2, link.
     *
     * @return void
     */
    public function test_lead_de_la_web_recibe_el_link_con_las_dos_formas(): void
    {
        [$telefono, $lead] = $this->llegar_y_recibir_la_llegada(LeadOrigenService::FRASE_LANDING_PRUEBA);

        $this->avanzar(120);
        $this->postear_kapso($telefono, 'Ferretería, unos 4000')->assertStatus(200);

        $jobs = $this->jobs_encolados(SendLeadDosFormasJob::class);
        $this->assertCount(1, $jobs);

        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());

        $cuerpos = $this->cuerpos_enviados($espia);
        $this->assertCount(3, $cuerpos, 'Al lead de la web: las dos formas, la página y el link.');
        $this->assertSame($this->texto_respuesta_1(), $cuerpos[0]);
        $this->assertSame($this->texto_respuesta_2(), $cuerpos[1]);
        $this->assertStringContainsString(LeadOfertaDePruebaService::link(), $cuerpos[2]);

        $nuevos = $this->mensajes_de_sistema($lead)->slice(2)->values();
        $this->assertCount(3, $nuevos);
        $this->assertSame('whatsapp_dos_formas', $nuevos[0]->system_message_kind);
        $this->assertSame('whatsapp_pagina', $nuevos[1]->system_message_kind);
        $this->assertSame(LeadWhatsappOnboardingService::KIND_PRUEBA_LINK, $nuevos[2]->system_message_kind);
    }

    // ------------------------------------------------------------------------------------------
    // CA16 — el switch y el textarea de la pantalla de Cuenta
    // ------------------------------------------------------------------------------------------

    /**
     * CA16 (lado API). `GET/PUT settings/lead-whatsapp-onboarding` suman `flujo_entrada_activo` (boolean, default
     * false) y `flujo_textos_precargados` (string): guardarlos los persiste en sus claves de `admin_settings`, y un
     * PUT que NO los manda no los cambia (compatible con el SPA viejo).
     *
     * @return void
     */
    public function test_el_interruptor_se_guarda_y_es_opcional_en_el_put(): void
    {
        AdminSetting::query()->whereIn('key', ['lead_whatsapp_flujo_entrada_activo', 'lead_whatsapp_flujo_textos_precargados'])->delete();
        $admin = $this->crear_admin_con_roles('Admin de Cuenta', []);

        /* Sin nada guardado: apagado y sin textos. */
        $get = $this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT);
        $get->assertStatus(200);
        $this->assertFalse($get->json('flujo_entrada_activo'), 'Por defecto el flujo nuevo está apagado.');
        $this->assertSame('', (string) $get->json('flujo_textos_precargados'));

        /* Guardar los dos. */
        $put = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), [
            'flujo_entrada_activo'     => true,
            'flujo_textos_precargados' => "Hola, vi el video\nQuiero más información",
        ]));
        $put->assertStatus(200);
        $this->assertSame('1', AdminSetting::get('lead_whatsapp_flujo_entrada_activo'));
        $this->assertSame("Hola, vi el video\nQuiero más información", AdminSetting::get('lead_whatsapp_flujo_textos_precargados'));
        $this->assertTrue($put->json('flujo_entrada_activo'));

        /* Un PUT de hoy (sin las dos claves) no las toca. */
        $sin_claves = $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, $this->payload_de_hoy());
        $sin_claves->assertStatus(200);
        $this->assertSame('1', AdminSetting::get('lead_whatsapp_flujo_entrada_activo'), 'Un PUT sin la clave no puede apagar el flujo.');
        $this->assertSame("Hola, vi el video\nQuiero más información", AdminSetting::get('lead_whatsapp_flujo_textos_precargados'));
        $this->assertTrue($sin_claves->json('flujo_entrada_activo'));

        /* Apagarlo explícitamente. */
        $this->actingAs($admin, 'sanctum')->putJson(self::ENDPOINT, array_merge($this->payload_de_hoy(), ['flujo_entrada_activo' => false]))->assertStatus(200);
        $this->assertSame('0', AdminSetting::get('lead_whatsapp_flujo_entrada_activo'));

        $this->assertFalse($this->actingAs($admin, 'sanctum')->getJson(self::ENDPOINT)->json('flujo_entrada_activo'));
    }

    // ------------------------------------------------------------------------------------------
    // Helpers propios
    // ------------------------------------------------------------------------------------------

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
     * Lleva a un lead nuevo hasta «ya recibió la llegada»: webhook con su primer mensaje, y corre el job de la
     * llegada con el espía. Deja el reloj un minuto adelante.
     *
     * @param string $primer_mensaje Lo que escribe al llegar (sin pregunta).
     *
     * @return array{0: string, 1: Lead, 2: object} Teléfono, lead y espía de la llegada.
     */
    private function llegar_y_recibir_la_llegada(string $primer_mensaje): array
    {
        $telefono = $this->telefono_al_azar();
        $this->postear_kapso($telefono, $primer_mensaje)->assertStatus(200);
        $lead = Lead::query()->where('phone', $telefono)->firstOrFail();

        $jobs = $this->jobs_encolados(SendLeadPresentationMessageJob::class);
        $this->assertCount(1, $jobs, 'Precondición: tiene que haber un job de la llegada.');
        $espia = $this->espiar_whatsapp();
        $this->correr_job($jobs->first());
        $this->assertCount(2, $this->mensajes_de_sistema($lead), 'Precondición: tienen que haber salido los dos mensajes de la llegada.');

        $this->avanzar(60);

        return [$telefono, $lead, $espia];
    }
}
