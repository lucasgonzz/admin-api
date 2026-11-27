<?php

namespace Tests\Feature\FlujoLead;

use App\Jobs\AutoSendLeadAiSuggestionJob;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ronda 2 de martin-mensaje-puntos (el Revisor rechazó la ronda 1 con dos hallazgos MAYORES).
 *
 * Lo que faltaba:
 *   1. Un marcador de lugar («...») junto con `solicita_disponibilidad: true` y sin bloque de código quedaba tal cual:
 *      con la demo directa (que apaga la segunda llamada), cuando `guardar_email` la anula y cuando la segunda llamada
 *      falla, el «...» terminaba como mensaje enviable o aprobable. Nunca puede quedar un marcador de lugar como mensaje
 *      de una sugerencia.
 *   2. CA2 de punta a punta: el paquete con mensaje vacío + `requiere_intervencion_humana` terminaba en
 *      RuntimeException («Respuesta de Claude incompleta») y no se guardaba nada. La salida es la del gate de respaldo
 *      (`aplicar_gate_de_respaldo`): el mensaje neutro `MENSAJE_DE_ESPERA_SIN_RESPALDO`, `requiere_verificacion` y
 *      `requiere_intervencion_humana`, de modo que el paquete queda retenido para una persona, con el motivo a la vista
 *      («La IA no devolvió un mensaje utilizable»), sin excepción y sin auto-envío.
 *
 * Los tests de la ronda 1 (MensajeDePuntosSuspensivosTest) no se tocan: siguen fijando lo que devuelve
 * `parse_json_response()`. Estos fijan lo que pasa con el paquete hasta la sugerencia guardada, con la IA simulada
 * (`Http::fake` sobre api.anthropic.com) del armado común del flujo.
 *
 * Hoy están EN ROJO (a), (b) y (c); (d) es de no-regresión.
 */
class MensajeDePuntosSuspensivosRonda2Test extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Frase que tiene que verse en el motivo para la persona. */
    private const MOTIVO = 'La IA no devolvió un mensaje utilizable';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
        $this->sembrar_protocolo_de_leads();

        /* Frases del botón de la demo como están en producción, para que el entorno no dependa de la base del slot. */
        AdminSetting::set(LeadDemoSettings::KEY_CTA_WHATSAPP_TEXTOS, json_encode([
            'Hola Martín, quiero hacer la demo de ComercioCity',
            'Hola, quiero hacer la demo de ComercioCity',
        ], JSON_UNESCAPED_UNICODE));
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
    // (a) marcador de lugar sin bloque y sin pedido de disponibilidad
    // ------------------------------------------------------------------------------------------

    /**
     * (a) La IA devuelve el JSON solo con `mensaje_sugerido` en un marcador de lugar. `generate_suggestion` NO lanza:
     * guarda una sugerencia retenida con el mensaje de espera, con la derivación a una persona y el motivo a la vista, y
     * no se programa ningún auto-envío ni sale nada por WhatsApp.
     *
     * @dataProvider provider_de_marcadores
     *
     * @param string $marcador Valor de `mensaje_sugerido` que deja el modelo.
     *
     * @return void
     */
    public function test_el_marcador_sin_bloque_queda_retenido_para_una_persona(string $marcador): void
    {
        $lead = $this->lead_en_contactado();
        $espia = $this->espiar_whatsapp();

        $this->fakear_ia([$this->paquete_ia($marcador, 'link_enviado')]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSuggestionRetenida($mensaje, $lead);
        $this->assertCount(0, $espia->textos, 'No sale nada por WhatsApp.');
    }

    /**
     * Marcadores de lugar que deja el modelo.
     *
     * @return array<string, array{0: string}>
     */
    public function provider_de_marcadores(): array
    {
        return [
            'tres puntos'     => ['...'],
            'caracter puntos' => ['…'],
            'rotulo'          => ['<mensaje>'],
            'vacio'           => [''],
        ];
    }

    // ------------------------------------------------------------------------------------------
    // (b) marcador + solicita_disponibilidad con la demo directa (no hay segunda llamada)
    // ------------------------------------------------------------------------------------------

    /**
     * (b) Con la demo directa activa no hay segunda llamada: `solicita_disponibilidad` se apaga y el primer mensaje es el
     * definitivo. Un «...» ahí no puede quedar: mismo resultado que (a).
     *
     * @return void
     */
    public function test_con_demo_directa_el_marcador_y_el_pedido_de_disponibilidad_no_salen(): void
    {
        $this->activar_la_demo_directa();
        $lead = $this->lead_en_contactado();
        $espia = $this->espiar_whatsapp();

        $this->fakear_ia([$this->paquete_ia('...', 'solicita_disponibilidad', 'conversacional', [], ['solicita_disponibilidad' => true])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertCount(1, $this->pedidos_a_la_ia, 'Precondición: con la demo directa no hay segunda llamada a la IA.');
        $this->assertSuggestionRetenida($mensaje, $lead);
        $this->assertCount(0, $espia->textos);
    }

    // ------------------------------------------------------------------------------------------
    // (c) marcador + solicita_disponibilidad con grilla: la segunda llamada falla o se anula
    // ------------------------------------------------------------------------------------------

    /**
     * (c) Con grilla, la segunda llamada (horarios disponibles) falla —la IA simulada ya no tiene respuestas y contesta
     * 500—: el fallback conserva el mensaje de la primera llamada, que es «...». No puede quedar ahí.
     *
     * @return void
     */
    public function test_si_la_segunda_llamada_falla_el_marcador_no_queda(): void
    {
        $lead = $this->lead_en_contactado();
        $espia = $this->espiar_whatsapp();

        $this->fakear_ia([$this->paquete_ia('...', 'solicita_disponibilidad', 'conversacional', [], ['solicita_disponibilidad' => true])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSuggestionRetenida($mensaje, $lead);
        $this->assertCount(0, $espia->textos);
    }

    /**
     * (c) `guardar_email` sin `agendar_demo` anula la segunda llamada (agenda fuera de secuencia): el mensaje de la
     * primera llamada es el definitivo y, siendo «...», tampoco puede quedar.
     *
     * @return void
     */
    public function test_si_guardar_email_anula_la_segunda_llamada_el_marcador_no_queda(): void
    {
        $lead = $this->lead_en_contactado();

        $this->fakear_ia([$this->paquete_ia('...', 'solicita_disponibilidad', 'conversacional', [], [
            'solicita_disponibilidad' => true,
            'guardar_email'           => 'ruben@ejemplo.com',
        ])]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertSuggestionRetenida($mensaje, $lead);
        $this->assertCount(1, $this->pedidos_a_la_ia, 'Precondición: guardar_email sin agendar_demo no hace la segunda llamada.');
    }

    // ------------------------------------------------------------------------------------------
    // (d) no-regresión: el vacío legítimo de solicita_disponibilidad
    // ------------------------------------------------------------------------------------------

    /**
     * (d) Mensaje vacío con `solicita_disponibilidad` (flujo normal de la agenda): `parse_json_response` lo deja igual,
     * sin derivación ni nota. Pasa ya.
     *
     * @return void
     */
    public function test_el_vacio_legitimo_de_la_agenda_sigue_igual(): void
    {
        $servicio = new class extends LeadAiService {
            /**
             * @param string $raw
             *
             * @return array<string, mixed>
             */
            public function parsear(string $raw): array
            {
                return $this->parse_json_response($raw);
            }
        };

        $json = json_encode($this->paquete_ia('', 'solicita_disponibilidad', 'conversacional', [], ['solicita_disponibilidad' => true]), JSON_UNESCAPED_UNICODE);

        $parsed = $servicio->parsear($json);

        $this->assertSame('', $parsed['mensaje_sugerido']);
        $this->assertTrue($parsed['solicita_disponibilidad']);
        $this->assertEmpty($parsed['requiere_intervencion_humana'] ?? false);
        $this->assertSame('Razonamiento de prueba.', $parsed['razonamiento']);
    }

    /**
     * (d) De punta a punta: la primera llamada pide disponibilidad con el mensaje vacío (a propósito) y la segunda, que
     * trae los horarios, devuelve el mensaje real. La sugerencia guardada es ese mensaje, sin derivación ni retención por
     * esto. Pasa ya.
     *
     * @return void
     */
    public function test_pedir_disponibilidad_con_el_mensaje_vacio_y_segunda_llamada_buena_sigue_igual(): void
    {
        $lead = $this->lead_en_contactado();

        $this->fakear_ia([
            $this->paquete_ia('', 'solicita_disponibilidad', 'conversacional', [], ['solicita_disponibilidad' => true]),
            $this->paquete_ia('Mirá, te cuento cómo coordinamos la demo y qué horarios hay.', 'solicita_disponibilidad'),
        ]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $this->assertCount(2, $this->pedidos_a_la_ia, 'Precondición: hubo segunda llamada.');
        $this->assertStringContainsString('te cuento cómo coordinamos la demo', (string) $mensaje->content);
        $this->assertStringNotContainsString(self::MOTIVO, (string) $mensaje->ai_reasoning);
        $this->assertNotSame(LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO, (string) $mensaje->content);
        $this->assertFalse((bool) ($mensaje->pending_actions['requiere_intervencion_humana'] ?? false));
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Exige lo que tiene que valer para una sugerencia que no sale sola: guardada, `sugerido`, con el mensaje de espera
     * (nunca un marcador de lugar), retenida para una persona, con la derivación y el motivo a la vista y sin auto-envío.
     *
     * @param LeadMessage $mensaje Sugerencia que devolvió `generate_suggestion`.
     * @param Lead        $lead    Lead del turno.
     *
     * @return void
     */
    private function assertSuggestionRetenida(LeadMessage $mensaje, Lead $lead): void
    {
        $this->assertSame('sugerido', (string) $mensaje->status, 'Queda como sugerencia, no enviada.');
        $this->assertNull($mensaje->sent_at);
        $this->assertSame(LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO, trim((string) $mensaje->content), 'El texto es el mensaje de espera, nunca un marcador de lugar.');
        $this->assertTrue((bool) $mensaje->requiere_verificacion, 'Retenida para una persona.');
        $this->assertTrue((bool) ($mensaje->pending_actions['requiere_intervencion_humana'] ?? false), 'Derivada a una persona.');
        $this->assertSame(
            LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO,
            trim((string) ($mensaje->pending_actions['mensaje_sugerido'] ?? '')),
            'El paquete retenido tampoco guarda el marcador de lugar.'
        );
        $this->assertStringContainsString(self::MOTIVO, (string) $mensaje->ai_reasoning, 'La persona ve por qué.');
        $this->assertStringContainsString(self::MOTIVO, (string) ($mensaje->pending_actions['motivo_intervencion'] ?? ''));
        $this->assertCount(0, $this->jobs_encolados(AutoSendLeadAiSuggestionJob::class), 'No se programa ningún auto-envío.');
        $this->assertTrue((bool) $lead->fresh()->tiene_sugerencia_pendiente);
    }

    /**
     * Activa la demo directa como en producción: el marcador dentro de `v2/demo_agenda`, y el flujo de entrada apagado
     * para que el lead sea uno común (con el flujo prendido la demo se oculta hasta que el lead la pide).
     *
     * @return void
     */
    private function activar_la_demo_directa(): void
    {
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => LeadAiService::MARCADOR_DEMO_DIRECTA,
            'synced_at' => now(),
        ]);
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
    }

    /**
     * Lead `contactado` que ya recibió la llegada y contestó el rubro, con el flujo de entrada apagado (un lead común).
     *
     * @return Lead
     */
    private function lead_en_contactado(): Lead
    {
        AdminSetting::set('lead_whatsapp_flujo_entrada_activo', '0');
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'almacén, estamos con mi señora nomás');

        return $lead->fresh();
    }
}
