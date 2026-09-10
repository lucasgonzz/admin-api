<?php

namespace Tests\Feature;

use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\SyncedGithubFile;
use App\Services\KnowledgeGroundingGate;
use App\Services\LeadAiService;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Antes de escalar una respuesta sin respaldo, el sistema le sirve al agente lo que citó y lo
 * hace responder una segunda y última vez.
 *
 * EL CASO QUE LO ORIGINÓ (log de producción, 1/9 al 10/9/2026). El gate frenó 16 respuestas en
 * 10 días y 12 de ellas tenían `recursos_leidos: []`: el agente no pidió ningún recurso y citó
 * `posicionamiento` de memoria, en conversaciones donde ya lo había leído en un turno anterior.
 * El lead 638 se comió dos seguidas el 10/9 a las 11:41 y 11:42. Las respuestas eran correctas;
 * lo que faltaba era la lectura, y la lectura la puede hacer el sistema.
 *
 * Lo que estos tests custodian es el equilibrio: el reintento tiene que recuperar al agente que
 * no releyó, y NO tiene que darle una segunda oportunidad al que citó un archivo inventado.
 */
class SegundoIntentoConRespaldoServidoTest extends TestCase
{
    use DatabaseTransactions;

    /* ==================================================================================
     * Lo que el reintento recupera
     * ================================================================================== */

    /**
     * 🔴 El caso del lead 638: citó `posicionamiento` sin pedirlo. El sistema se lo sirve, el
     * agente responde de nuevo, y esta vez el mensaje le llega al lead.
     *
     * @return void
     */
    public function test_el_que_cito_sin_leer_consigue_su_respaldo_y_el_mensaje_sale()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('El sistema tiene ecommerce integrado.', ['posicionamiento'])),
            $this->respuesta_final($this->paquete('El sistema tiene ecommerce integrado.', ['posicionamiento'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString(
            'ecommerce integrado',
            (string) $mensaje->content,
            'El mensaje del agente no sobrevivió al segundo intento.'
        );

        $this->assertStringNotContainsString(
            'Dame un momento',
            (string) $mensaje->content,
            'Quedó el mensaje de espera: el segundo intento no recuperó la respuesta.'
        );

        $this->assertFalse(
            (bool) $lead->refresh()->requiere_intervencion_humana,
            'El lead quedó derivado a una persona aunque el segundo intento estaba respaldado.'
        );

        $this->assertSame(2, $this->llamadas_a_claude(), 'No hubo exactamente un reintento.');
    }

    /**
     * El segundo pedido lleva el contenido REAL del recurso y el motivo del rechazo. Sin esas
     * dos cosas el reintento sería la misma llamada otra vez, y la lectura seguiría sin ocurrir.
     *
     * @return void
     */
    public function test_el_segundo_pedido_lleva_el_recurso_servido_y_el_motivo()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])),
            $this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])),
        ]);

        app(LeadAiService::class)->generate_suggestion($this->crear_lead(), false);

        $cuerpos = $this->cuerpos_enviados_a_claude();

        $this->assertCount(2, $cuerpos);

        $this->assertStringNotContainsString(
            'Contenido de prueba del recurso posicionamiento',
            $cuerpos[0],
            'El primer pedido ya traía el recurso servido: el reintento tiene que ser la excepción, no el default.'
        );

        $this->assertStringContainsString(
            'Contenido de prueba del recurso posicionamiento',
            $cuerpos[1],
            'El segundo pedido no llevó el contenido del recurso que el agente citó.'
        );

        $this->assertStringContainsString(
            'TU RESPUESTA ANTERIOR NO SALIÓ',
            $cuerpos[1],
            'El segundo pedido no le dice al agente qué falló.'
        );

        $this->assertStringContainsString(
            'no llegó a leer en esta consulta',
            $cuerpos[1],
            'El segundo pedido no lleva el motivo que redactó el gate.'
        );
    }

    /**
     * Un recurso servido cuenta como leído, así que el agente puede citarlo aunque en el
     * segundo intento tampoco use la tool. Es la mitad que hace que el reintento sirva de algo.
     *
     * @return void
     */
    public function test_lo_servido_por_el_sistema_cuenta_como_leido()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento', 'precios']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Arranca en tanto por mes.', ['posicionamiento', 'precios'])),
            $this->respuesta_final($this->paquete('Arranca en tanto por mes.', ['posicionamiento', 'precios'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Arranca en tanto por mes', (string) $mensaje->content);
        $this->assertFalse((bool) $lead->refresh()->requiere_intervencion_humana);
    }

    /* ==================================================================================
     * Lo que el reintento NO recupera
     * ================================================================================== */

    /**
     * 🔴 Si el segundo intento tampoco queda respaldado, escala igual que siempre: mensaje de
     * espera, verificación e intervención humana. Y no hay un tercer intento.
     *
     * @return void
     */
    public function test_si_el_segundo_intento_tampoco_queda_respaldado_se_escala()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Los presupuestos se emiten en dólares.', ['posicionamiento'])),
            /* En el reintento cita algo que nadie le sirvió: sigue sin respaldo. */
            $this->respuesta_final($this->paquete('Los presupuestos se emiten en dólares.', ['reglas'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringNotContainsString(
            'en dólares',
            (string) $mensaje->content,
            'La afirmación sin respaldo le llegó al lead después del reintento.'
        );

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $mensaje->requiere_verificacion);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);

        $this->assertSame(2, $this->llamadas_a_claude(), 'Hubo más de un reintento.');
    }

    /**
     * 🔴 EL AGUJERO QUE ESTE TEST CUSTODIA. El gate solo exige respaldo cuando el agente declara
     * `afirmacion_del_sistema`: `aclaracion` y `conversacional` pasan sin que se les mire una
     * sola fuente. Y al segundo intento se lo llama diciéndole, en mayúsculas, que su mensaje no
     * salió. Sin la guarda, al agente le queda un camino trivial para pasar sin conseguir el
     * respaldo: devolver el MISMO mensaje reetiquetado. Eso dejaría al lead PEOR que antes del
     * cambio —una afirmación sin respaldo saliendo por una puerta que el escalado del primer
     * intento ya tenía cerrada—, que es exactamente lo contrario de lo que el reintento viene a
     * hacer.
     *
     * @return void
     */
    public function test_reetiquetar_el_segundo_intento_no_lo_deja_pasar()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $afirmacion = 'Los presupuestos se emiten en dólares.';

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete($afirmacion, ['posicionamiento'])),
            /* Mismo texto, misma afirmación, pero declarada como charla para saltear el gate. */
            $this->respuesta_final(array_merge(
                $this->paquete($afirmacion, []),
                ['tipo_respuesta' => KnowledgeGroundingGate::TIPO_CONVERSACIONAL]
            )),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringNotContainsString(
            'en dólares',
            (string) $mensaje->content,
            'La afirmación salió reetiquetada como conversacional: el reintento abrió una puerta.'
        );

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
    }

    /**
     * Lo mismo con `aclaracion`, que es la otra puerta que el gate deja pasar sin verificar.
     *
     * @return void
     */
    public function test_reetiquetar_a_aclaracion_tampoco_lo_deja_pasar()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Sí, factura en dólares.', ['posicionamiento'])),
            $this->respuesta_final(array_merge(
                $this->paquete('Sí, factura en dólares.', []),
                ['tipo_respuesta' => KnowledgeGroundingGate::TIPO_ACLARACION]
            )),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringNotContainsString('en dólares', (string) $mensaje->content);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
    }

    /**
     * 🔴 El borde que obliga a que la guarda sea lista blanca y no lista negra. El gate deja
     * pasar `escalado` sin mirar una sola fuente, porque asume que el agente que pide escalar no
     * está afirmando nada. Un paquete que se declara `escalado` pero trae
     * requiere_intervencion_humana en false —y la afirmación intacta adentro— usaría esa puerta:
     * no lo frena el gate, y una lista negra de tipos tampoco.
     *
     * @return void
     */
    public function test_declararse_escalado_sin_escalar_de_verdad_no_lo_deja_pasar()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Los presupuestos se emiten en dólares.', ['posicionamiento'])),
            $this->respuesta_final(array_merge(
                $this->paquete('Los presupuestos se emiten en dólares.', []),
                [
                    'tipo_respuesta'               => KnowledgeGroundingGate::TIPO_ESCALADO,
                    'requiere_intervencion_humana' => false,
                ]
            )),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringNotContainsString(
            'en dólares',
            (string) $mensaje->content,
            'La afirmación salió declarándose escalado sin escalar de verdad.'
        );

        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
    }

    /**
     * El otro lado de la misma moneda: si el segundo intento reconoce que no puede respaldarlo y
     * pide intervención humana DE VERDAD, ese paquete sí se acepta. Es la salida que el bloque de
     * corrección le ofrece explícitamente, y rechazarla sería contradecir lo que se le pidió.
     *
     * @return void
     */
    public function test_el_segundo_intento_puede_pedir_intervencion_humana()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Los presupuestos se emiten en dólares.', ['posicionamiento'])),
            $this->respuesta_final(array_merge(
                $this->paquete('No tengo ese dato acá.', []),
                [
                    'tipo_respuesta'               => KnowledgeGroundingGate::TIPO_ESCALADO,
                    'requiere_intervencion_humana' => true,
                    'motivo_intervencion'          => 'Pregunta si se factura en dólares y ningún recurso lo dice.',
                ]
            )),
        ]);

        $lead = $this->crear_lead();

        app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
        $this->assertSame(2, $this->llamadas_a_claude(), 'El reintento no llegó a ocurrir.');
    }

    /**
     * 🔴 Si el gate frenó porque el `tipo_respuesta` faltaba o era inventado, lo que falló fue el
     * formato, no la lectura. Servirle recursos no arregla eso, y darle otra vuelta sería
     * regalarle la chance de volver con un tipo que el gate no verifica. No se reintenta.
     *
     * @return void
     */
    public function test_si_el_gate_freno_por_el_tipo_no_hay_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $sin_tipo = $this->paquete('Sí, tiene ecommerce.', ['posicionamiento']);

        unset($sin_tipo['tipo_respuesta']);

        /* La segunda respuesta está cargada A PROPÓSITO y sale perfecta: si el reintento llegara
         * a dispararse, se la comería y el mensaje saldría. Que el lead termine con el escalado
         * es lo que prueba que la segunda llamada nunca ocurrió. */
        $this->fakear_secuencia([
            $this->respuesta_final($sin_tipo),
            $this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringNotContainsString(
            'tiene ecommerce',
            (string) $mensaje->content,
            'Se reintentó una respuesta que el gate frenó por el tipo, no por la lectura.'
        );

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);

        $this->assertSame(
            1,
            $this->llamadas_a_claude(),
            'Se reintentó una respuesta que el gate frenó por el tipo, no por la lectura.'
        );
    }

    /**
     * 🔴 Una fuente que no existe no da derecho a reintento. Citar un archivo inventado no es
     * "no releí": es inventar, y ahí el escalado es la respuesta correcta.
     *
     * @return void
     */
    public function test_una_fuente_inventada_no_da_derecho_a_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Lo dice el catálogo.', ['catalogo_secreto'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
        $this->assertSame(1, $this->llamadas_a_claude(), 'Se reintentó con una fuente que no existe.');
    }

    /**
     * Si una de las fuentes citadas existe pero no está sincronizada, tampoco se reintenta: el
     * agente quedaría respondiendo con la mitad del respaldo delante.
     *
     * @return void
     */
    public function test_un_recurso_no_sincronizado_no_da_derecho_a_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        /* Ningún recurso sembrado: getRecurso() devuelve vacío, igual que en una instalación
         * recién armada o con el sync caído. */

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertSame(1, $this->llamadas_a_claude(), 'Se reintentó sin poder servir el recurso.');
    }

    /**
     * Afirmar sin citar nada no se puede recuperar: no hay material que servir.
     *
     * @return void
     */
    public function test_afirmar_sin_citar_nada_no_dispara_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Sí, se puede.', [])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
        $this->assertSame(1, $this->llamadas_a_claude(), 'Se reintentó sin ninguna fuente citada.');
    }

    /**
     * Citar cinco recursos de una no es memoria floja: es barrer el índice. Ahí tampoco se
     * reintenta.
     *
     * @return void
     */
    public function test_citar_demasiadas_fuentes_no_dispara_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento', 'precios', 'reglas', 'calificacion', 'referidos']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Te cuento todo junto.', [
                'posicionamiento', 'precios', 'reglas', 'calificacion', 'referidos',
            ])),
        ]);

        $lead = $this->crear_lead();
        app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertSame(1, $this->llamadas_a_claude(), 'Se reintentó con cinco fuentes citadas.');
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
    }

    /* ==================================================================================
     * Que el reintento no rompa lo que ya andaba
     * ================================================================================== */

    /**
     * 🔴 El reintento no puede dejar al lead peor que sin reintento: si la segunda llamada
     * falla, queda el escalado del primer intento y no se propaga ninguna excepción.
     *
     * @return void
     */
    public function test_si_la_segunda_llamada_falla_queda_el_escalado_del_primero()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])), 200)
                ->push(['error' => 'overloaded'], 529),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $mensaje->requiere_verificacion);
        $this->assertTrue((bool) $lead->refresh()->requiere_intervencion_humana);
    }

    /**
     * Una respuesta que ya venía respaldada sigue saliendo en una sola llamada: el reintento no
     * se mete en el camino normal ni le agrega latencia.
     *
     * @return void
     */
    public function test_una_respuesta_respaldada_no_dispara_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_tool_use('posicionamiento'),
            $this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('ecommerce', (string) $mensaje->content);
        $this->assertFalse((bool) $lead->refresh()->requiere_intervencion_humana);

        foreach ($this->cuerpos_enviados_a_claude() as $cuerpo) {
            $this->assertStringNotContainsString(
                'TU RESPUESTA ANTERIOR NO SALIÓ',
                $cuerpo,
                'Se disparó el reintento sobre una respuesta que ya estaba respaldada.'
            );
        }
    }

    /**
     * Lo conversacional no pasa por el gate, así que tampoco por el reintento.
     *
     * @return void
     */
    public function test_lo_conversacional_no_dispara_reintento()
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $paquete                   = $this->paquete('Dale, ¿cómo te llamás?', []);
        $paquete['tipo_respuesta'] = KnowledgeGroundingGate::TIPO_CONVERSACIONAL;

        $this->fakear_secuencia([$this->respuesta_final($paquete)]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('cómo te llamás', (string) $mensaje->content);
        $this->assertSame(1, $this->llamadas_a_claude());
    }

    /**
     * Con el protocolo viejo (sin el contrato de fuentes en el prompt) el gate está inerte, así
     * que no hay nada que reintentar.
     *
     * @return void
     */
    public function test_con_el_protocolo_viejo_no_hay_reintento()
    {
        $this->sembrar_protocolo_de_leads(false);
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('Sí, tiene ecommerce.', ['posicionamiento'])),
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('ecommerce', (string) $mensaje->content);
        $this->assertSame(1, $this->llamadas_a_claude());
    }

    /* ==================================================================================
     * Helpers
     * ================================================================================== */

    /**
     * Paquete mínimo que devuelve el agente, declarado como afirmación del sistema.
     *
     * @param string             $mensaje Texto sugerido para el lead.
     * @param array<int, string> $fuentes Lo que el agente dice haber usado como respaldo.
     *
     * @return array<string, mixed>
     */
    private function paquete(string $mensaje, array $fuentes): array
    {
        return [
            'mensaje_sugerido' => $mensaje,
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
            'tipo_respuesta'   => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'       => $fuentes,
        ];
    }

    /**
     * Respuesta final de Claude, sin más tool use.
     *
     * @param array<string, mixed> $paquete JSON que devuelve el modelo.
     *
     * @return array<string, mixed>
     */
    private function respuesta_final(array $paquete): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [[
                'type' => 'text',
                'text' => json_encode($paquete, JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    /**
     * Respuesta de Claude que pausa para pedir un recurso via tool use.
     *
     * @param string $recurso Nombre del recurso pedido.
     *
     * @return array<string, mixed>
     */
    private function respuesta_tool_use(string $recurso): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'tool_' . Str::random(8),
                'name'  => 'get_protocolo_recurso',
                'input' => ['nombre' => $recurso],
            ]],
        ];
    }

    /**
     * Fakea la API de Anthropic con una secuencia de respuestas.
     *
     * @param array<int, array<string, mixed>> $respuestas Respuestas en orden.
     *
     * @return void
     */
    private function fakear_secuencia(array $respuestas): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $secuencia = Http::sequence();

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta);
        }

        Http::fake([
            'api.anthropic.com/*' => $secuencia,
            '*'                   => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Cuántos pedidos se le hicieron a la API de Anthropic.
     *
     * @return int
     */
    private function llamadas_a_claude(): int
    {
        return count($this->cuerpos_enviados_a_claude());
    }

    /**
     * Cuerpos de los pedidos a la API de Anthropic, en orden, con los no-ASCII legibles.
     *
     * 🔴 El body viaja como JSON y `json_encode()` escapa cada acento (`SALIÓ`), así que
     * buscar 'SALIÓ' en el crudo no puede dar nunca — aunque el texto esté ahí y el modelo lo
     * reciba perfecto. Eso no es una aserción exigente de más: es una medición rota, y calla
     * justo en el contenido en castellano, que es todo el que le importa a estos tests. Se
     * reencodea con JSON_UNESCAPED_UNICODE para comparar contra lo que el agente lee de verdad.
     *
     * @return array<int, string>
     */
    private function cuerpos_enviados_a_claude(): array
    {
        $cuerpos = [];

        Http::recorded(function ($request) use (&$cuerpos) {
            if (strpos($request->url(), 'api.anthropic.com') !== false) {
                $cuerpos[] = $this->con_acentos_legibles((string) $request->body());
            }

            return false;
        });

        return $cuerpos;
    }

    /**
     * Devuelve el mismo cuerpo con los escapes `\uXXXX` resueltos a UTF-8.
     *
     * Si el cuerpo no es JSON válido se devuelve tal cual: la medición nunca puede tirar el dato
     * que venía a mirar.
     *
     * @param string $cuerpo Cuerpo crudo del pedido.
     *
     * @return string
     */
    private function con_acentos_legibles(string $cuerpo): string
    {
        $decodificado = json_decode($cuerpo, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $cuerpo;
        }

        return json_encode($decodificado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Deja en base el system prompt y el system base que `build_system_prompt()` exige.
     *
     * @param bool $con_contrato Si el system base menciona el contrato de fuentes, que es lo que
     *                           enciende el gate.
     *
     * @return void
     */
    private function sembrar_protocolo_de_leads(bool $con_contrato = true): void
    {
        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);

        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => $con_contrato
                ? 'System base de prueba. Completá fuentes_kb con los recursos que pediste.'
                : 'System base de prueba, sin el contrato nuevo.',
            'synced_at' => now(),
        ]);
    }

    /**
     * Deja en base el contenido de los recursos, para que se puedan servir de verdad.
     *
     * @param array<int, string> $recursos Nombres de los recursos a sembrar.
     *
     * @return void
     */
    private function sembrar_recursos(array $recursos): void
    {
        foreach ($recursos as $nombre) {
            SyncedGithubFile::create([
                'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX . $nombre,
                'repo_path' => "agentes/lead/recursos/{$nombre}.md",
                'content'   => "Contenido de prueba del recurso {$nombre}.",
                'synced_at' => now(),
            ]);
        }
    }

    /**
     * Lead mínimo.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'calificado';
        $lead->save();

        return $lead->refresh();
    }
}
