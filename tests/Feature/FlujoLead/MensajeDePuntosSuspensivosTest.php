<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Services\LeadAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El mensaje «...» que Martín le mandó a un lead (misión martin-mensaje-puntos, criterios CA1 a CA4 del PLAN; hija de
 * martin-correcciones-ensayo).
 *
 * Lo que pasó (lead 718 «Rubén (ENSAYO)», 27/11/2026, lead_messages id 17448): el modelo devolvió el texto real de la
 * respuesta en un bloque de código FUERA del JSON y dejó `mensaje_sugerido` en «...». `LeadAiService::parse_json_response()`
 * tomó el JSON tal cual y el «...» salió al lead por WhatsApp (status `enviado`). Con un lead real, el lead habría
 * recibido tres puntos.
 *
 * Lo pedido:
 *   - CA1. Si `mensaje_sugerido` es un marcador de lugar (sólo puntuación, vacío, «<mensaje>»…) y la respuesta trae antes
 *     del JSON un bloque ``` ``` con texto real, ese texto pasa a ser el mensaje.
 *   - CA2. Si no hay bloque utilizable, el paquete queda con `requiere_intervencion_humana`, mensaje '' y una nota en el
 *     razonamiento: no se manda nada y el aviso va a una persona.
 *   - CA3. Un mensaje normal, o con puntos suspensivos DENTRO de un texto, no se toca (no-regresión).
 *   - CA4. De punta a punta, con el cuerpo de la respuesta medido en producción: la sugerencia guardada trae el texto y
 *     el link, no «...».
 *
 * Cómo se prueba: `parse_json_response()` es protegido, así que se llama por una subclase anónima que lo expone y no
 * cambia lógica (como hace VerificacionSoloPorEscudoDelLeadTest). El camino completo usa `generate_suggestion` real con
 * la IA simulada (`Http::fake` sobre api.anthropic.com) del armado común del flujo.
 *
 * Hoy están EN ROJO CA1, CA2 y CA4 (el mensaje queda «...»); CA3 es de no-regresión y pasa ya.
 */
class MensajeDePuntosSuspensivosTest extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Link de la prueba tal como lo manda Martín al lead del flujo. */
    private const LINK_DE_LA_PRUEBA = 'https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin';

    /** Texto real que el modelo escribió en el bloque de código (caso 718), con sus burbujas separadas por `---`. */
    private const TEXTO_DEL_BLOQUE = "Un almacén, bárbaro... te cuento que ComercioCity te lleva el fiado del cliente de siempre sin libreta.\n"
        . "---\n"
        . "Pensalo así: ya no tenés que acordarte quién te debe, la cuenta de cada cliente queda anotada.\n"
        . "---\n"
        . "Lo más simple es que lo pruebes vos, gratis 7 días y con tu almacén. Te dejo el link para arrancar:\n"
        . 'https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
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
    // CA1 — el texto real estaba en un bloque de código fuera del JSON
    // ------------------------------------------------------------------------------------------

    /**
     * CA1. `mensaje_sugerido` es un marcador de lugar y ANTES del JSON viene un bloque de código sin JSON adentro: el
     * mensaje pasa a ser el texto del bloque, sin las vallas y recortado. El resto del paquete queda como vino.
     *
     * @dataProvider provider_de_marcadores_de_lugar
     *
     * @param string $marcador Valor de `mensaje_sugerido` que dejó el modelo.
     *
     * @return void
     */
    public function test_el_texto_del_bloque_reemplaza_al_marcador_de_lugar(string $marcador): void
    {
        $respuesta = $this->respuesta_con_bloque(self::TEXTO_DEL_BLOQUE, $marcador);

        $parsed = $this->parsear($respuesta);

        $this->assertSame(self::TEXTO_DEL_BLOQUE, $parsed['mensaje_sugerido'], 'El mensaje es el texto del bloque, sin vallas ni espacios de más.');
        $this->assertSame('link_enviado', $parsed['estado_sugerido'], 'El resto del paquete no se toca.');
        $this->assertEmpty($parsed['requiere_intervencion_humana'] ?? false, 'Con texto recuperado no se deriva a una persona.');
    }

    /**
     * Marcadores de lugar que deja el modelo en `mensaje_sugerido`: tres puntos, el carácter «…», puntos con espacios,
     * el rótulo entre signos, vacío y sólo espacios.
     *
     * @return array<string, array{0: string}>
     */
    public function provider_de_marcadores_de_lugar(): array
    {
        return [
            'tres puntos'              => ['...'],
            'el caracter de puntos'    => ['…'],
            'puntos con espacios'      => ['. . .'],
            'cuatro puntos y espacios' => ["  ....  "],
            'rotulo entre menor/mayor' => ['<mensaje>'],
            'rotulo entre corchetes'   => ['[mensaje]'],
            'vacio'                    => [''],
            'sólo espacios'            => ['   '],
        ];
    }

    /**
     * CA1. Con varios bloques sin JSON, se toma el PRIMERO que tiene texto real: un bloque que es otro marcador de lugar
     * (o está vacío) no cuenta.
     *
     * @return void
     */
    public function test_se_toma_el_primer_bloque_con_texto_real(): void
    {
        $respuesta = "**Mensaje sugerido:**\n\n```\n...\n```\n\n```\n\n```\n\n```\nPrimer texto real del bloque.\n```\n\n```\nOtro bloque con texto real.\n```\n\n"
            . $this->json_con_marcador('...');

        $parsed = $this->parsear($respuesta);

        $this->assertSame('Primer texto real del bloque.', $parsed['mensaje_sugerido']);
    }

    /**
     * CA1. El bloque que trae JSON adentro no es el texto del mensaje: se ignora y se toma el otro. Una valla con
     * etiqueta de lenguaje (```json) tampoco confunde.
     *
     * @return void
     */
    public function test_un_bloque_con_json_no_es_el_mensaje(): void
    {
        $respuesta = "```json\n" . $this->json_con_marcador('…') . "\n```\n\n```\nEl texto de verdad.\n```";

        $parsed = $this->parsear($respuesta);

        $this->assertSame('El texto de verdad.', $parsed['mensaje_sugerido']);
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — no hay texto utilizable: no se manda nada y se deriva a una persona
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. Marcador de lugar y la respuesta es el JSON solo (sin bloque de código): el paquete queda con
     * `requiere_intervencion_humana`, mensaje vacío y la nota en el razonamiento, además del motivo para la persona que
     * recibe el aviso.
     *
     * @dataProvider provider_de_marcadores_sin_bloque
     *
     * @param string $marcador Valor de `mensaje_sugerido` que dejó el modelo.
     *
     * @return void
     */
    public function test_sin_bloque_utilizable_se_deriva_a_una_persona(string $marcador): void
    {
        $parsed = $this->parsear($this->json_con_marcador($marcador));

        $this->assertTrue((bool) ($parsed['requiere_intervencion_humana'] ?? false), 'Se deriva a una persona.');
        $this->assertSame('', $parsed['mensaje_sugerido'], 'No queda ningún texto que pueda salir al lead.');
        $this->assertStringContainsString('La IA no devolvió un mensaje utilizable', (string) $parsed['razonamiento']);
        $this->assertStringContainsString('Rubén contó el rubro', (string) $parsed['razonamiento'], 'La nota se suma al razonamiento, no lo pisa.');
        $this->assertNotSame('', trim((string) ($parsed['motivo_intervencion'] ?? '')), 'La persona avisada tiene que ver el motivo.');
    }

    /**
     * Marcadores que no tienen salida: el JSON solo, o con bloques de código que no sirven (vacíos, otro marcador de
     * lugar o con el JSON adentro).
     *
     * @return array<string, array{0: string}>
     */
    public function provider_de_marcadores_sin_bloque(): array
    {
        return [
            'tres puntos'      => ['...'],
            'caracter puntos'  => ['…'],
            'rotulo'           => ['<mensaje>'],
            'vacio'            => [''],
        ];
    }

    /**
     * CA2. Los bloques de código que no sirven (vacío, otro marcador, el propio JSON) no alcanzan: se deriva igual.
     *
     * @return void
     */
    public function test_con_bloques_que_no_sirven_se_deriva_a_una_persona(): void
    {
        $respuesta = "```\n\n```\n\n```\n. . .\n```\n\n```json\n" . $this->json_con_marcador('...') . "\n```";

        $parsed = $this->parsear($respuesta);

        $this->assertTrue((bool) ($parsed['requiere_intervencion_humana'] ?? false));
        $this->assertSame('', $parsed['mensaje_sugerido']);
        $this->assertStringContainsString('La IA no devolvió un mensaje utilizable', (string) $parsed['razonamiento']);
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — no-regresión: lo que no es un marcador de lugar no se toca
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. Un mensaje normal sale tal cual, aunque haya un bloque de código antes del JSON (no se lo pisa), y no se
     * marca ninguna derivación. Pasa ya.
     *
     * @return void
     */
    public function test_un_mensaje_normal_no_se_toca(): void
    {
        $respuesta = $this->respuesta_con_bloque('Texto de un bloque que no tiene que pisar nada.', 'Hola Rubén, te cuento cómo arrancar.');

        $parsed = $this->parsear($respuesta);

        $this->assertSame('Hola Rubén, te cuento cómo arrancar.', $parsed['mensaje_sugerido']);
        $this->assertEmpty($parsed['requiere_intervencion_humana'] ?? false);
        $this->assertStringNotContainsString('La IA no devolvió un mensaje utilizable', (string) $parsed['razonamiento']);
    }

    /**
     * CA3. Puntos suspensivos DENTRO de un texto, signos o cifras sueltas: no son un marcador de lugar (sacando espacios y
     * puntuación queda alguna letra o número). Pasa ya.
     *
     * @dataProvider provider_de_mensajes_validos
     *
     * @param string $mensaje Valor de `mensaje_sugerido`.
     *
     * @return void
     */
    public function test_los_puntos_suspensivos_dentro_de_un_texto_no_son_un_marcador(string $mensaje): void
    {
        $parsed = $this->parsear($this->respuesta_con_bloque('Texto de un bloque que no tiene que pisar nada.', $mensaje));

        $this->assertSame($mensaje, $parsed['mensaje_sugerido']);
        $this->assertEmpty($parsed['requiere_intervencion_humana'] ?? false);
    }

    /**
     * Mensajes que parecen raros pero son texto válido: puntos suspensivos entre palabras, un número, una sola letra,
     * un emoji con palabras, un corchete que no envuelve todo el texto.
     *
     * @return array<string, array{0: string}>
     */
    public function provider_de_mensajes_validos(): array
    {
        return [
            'puntos entre palabras'      => ['Un almacén... bárbaro'],
            'puntos al final'            => ['Dale, te cuento...'],
            'un numero'                  => ['1'],
            'una sola letra'             => ['Sí'],
            'corchete dentro del texto'  => ['[Hola] ¿cómo andás?'],
            'varias burbujas'            => ["Hola.\n---\nTe cuento cómo funciona..."],
        ];
    }

    /**
     * CA3. El mensaje vacío con `solicita_disponibilidad` es intencional (el sistema hace la segunda llamada con los
     * horarios): no se lo deriva ni se le toca el paquete. Pasa ya.
     *
     * @return void
     */
    public function test_el_mensaje_vacio_al_pedir_disponibilidad_no_se_toca(): void
    {
        $json = json_encode([
            'mensaje_sugerido'        => '',
            'estado_sugerido'         => 'solicita_disponibilidad',
            'razonamiento'            => 'Pide horarios.',
            'tipo_respuesta'          => 'conversacional',
            'fuentes_kb'              => [],
            'solicita_disponibilidad' => true,
        ], JSON_UNESCAPED_UNICODE);

        $parsed = $this->parsear($json);

        $this->assertSame('', $parsed['mensaje_sugerido']);
        $this->assertEmpty($parsed['requiere_intervencion_humana'] ?? false, 'El vacío es parte del flujo de la agenda.');
        $this->assertSame('Pide horarios.', $parsed['razonamiento']);
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — de punta a punta con la respuesta medida en producción (caso 718)
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. Lead del flujo en `contactado` que contó «almacén, estamos con mi señora nomás»; la IA responde como en el log
     * del 27/11 (texto en un bloque de código y `mensaje_sugerido: "..."`). La sugerencia guardada trae el texto real con
     * el link de la prueba, no «...», sigue en `link_enviado` y no se deriva a una persona.
     *
     * @return void
     */
    public function test_la_sugerencia_guardada_trae_el_texto_y_el_link_no_los_puntos(): void
    {
        $lead = $this->lead_del_ensayo();

        $this->fakear_ia([]);
        $this->cola_ia[] = [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $this->respuesta_con_bloque(self::TEXTO_DEL_BLOQUE, '...')]],
        ];

        $mensaje = app(LeadAiService::class)->generate_suggestion($lead->fresh(), false);

        $contenido = trim((string) $mensaje->content);
        $this->assertNotSame('...', $contenido, 'Lo que sale al lead no puede ser el marcador de lugar.');
        $this->assertStringContainsString('Un almacén, bárbaro', $contenido, 'Sale el texto que escribió el modelo.');
        $this->assertStringContainsString(self::LINK_DE_LA_PRUEBA, $contenido, 'Con el link para arrancar.');
        $this->assertSame('link_enviado', (string) $mensaje->suggested_lead_status, 'El estado sugerido sigue siendo link_enviado.');
        $this->assertFalse((bool) $lead->fresh()->requiere_intervencion_humana, 'Había texto: no se deriva a una persona.');
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Corre `parse_json_response()` (protegido) a través de una subclase anónima que lo expone sin cambiar lógica.
     *
     * @param string $raw Respuesta cruda del modelo.
     *
     * @return array<string, mixed>
     */
    private function parsear(string $raw): array
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

        return $servicio->parsear($raw);
    }

    /**
     * JSON del paquete con el `mensaje_sugerido` pedido y lo demás como el del caso 718.
     *
     * @param string $mensaje Valor de `mensaje_sugerido`.
     *
     * @return string
     */
    private function json_con_marcador(string $mensaje): string
    {
        return json_encode([
            'mensaje_sugerido' => $mensaje,
            'estado_sugerido'  => 'link_enviado',
            'razonamiento'     => 'Rubén contó el rubro (almacén) y trabaja con su señora; el camino es la prueba gratis.',
            'tipo_respuesta'   => 'afirmacion_del_sistema',
            'fuentes_kb'       => ['precios_vigentes'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Respuesta con la forma del log de producción: un rótulo, el texto real en un bloque de código y después el JSON
     * (también en un bloque) con el `mensaje_sugerido` pedido.
     *
     * @param string $texto_del_bloque Texto que va dentro del primer bloque de código.
     * @param string $mensaje          Valor de `mensaje_sugerido` dentro del JSON.
     *
     * @return string
     */
    private function respuesta_con_bloque(string $texto_del_bloque, string $mensaje): string
    {
        return "**Mensaje sugerido:**\n\n```\n" . $texto_del_bloque . "\n```\n\n```\n" . $this->json_con_marcador($mensaje) . "\n```";
    }

    /**
     * Lead del flujo en `contactado` que ya recibió la llegada y contestó el rubro, como el del ensayo.
     *
     * @return Lead
     */
    private function lead_del_ensayo(): Lead
    {
        $lead = $this->crear_lead_de_flujo('contactado');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'almacén, estamos con mi señora nomás');

        return $lead->fresh();
    }
}
