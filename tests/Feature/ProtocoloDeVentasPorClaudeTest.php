<?php

namespace Tests\Feature;

use App\Models\ProtocolEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El alta en lote del PROTOCOLO DE VENTAS (`POST claude/protocol-entries`).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 QUE LA CLAVE DE IDEMPOTENCIA SEA `titulo` Y NO LA TERNA
 *     `(categoria, estado_aplicable, followup_numero)`. Está medido sobre el protocolo real: 46
 *     filas, 46 títulos distintos y sólo 23 ternas. Hay cuatro entradas con
 *     `(etapa_principal, contactado, null)` —las "Etapa 2A/2B/2C/2D"—, así que con la terna como
 *     clave cargar 2B PISARÍA 2A en silencio. El test lo ejercita con dos entradas que comparten
 *     la terna y verifica que quedan las dos.
 *  2. 🔴 QUE `activa` SEA OBLIGATORIO Y EXPLÍCITO. Una entrada nueva no se activa sola:
 *     `LeadSuggestionSendService::record_setter_correction()` crea entradas con `activa => false`
 *     porque son correcciones pendientes de revisión, y un default `true` acá le pisaría el
 *     criterio.
 *  3. 🔴 QUE `dry_run` SEA EL DEFAULT Y NO ESCRIBA NADA.
 *  4. Que sea ADITIVO: un lote parcial no puede llevarse puestas las entradas que ya estaban.
 *  5. 🔴 QUE LA COMPARACIÓN DE TÍTULOS SEA LA MISMA QUE HACE MYSQL. `protocol_entries.titulo` es
 *     `utf8mb4_unicode_ci`: la base no distingue mayúsculas ni acentos, y PHP comparando byte a
 *     byte se desalineaba con eso. De ahí salían los dos agujeros que fijan los dos tests de la
 *     sección "La colación de MySQL": un lote que decía crear dos entradas y dejaba una sola, y —el
 *     peor— un título retipeado sin tilde que pisaba la fila existente BORRÁNDOLE los campos que no
 *     venían en el payload, con un diff que juraba que no pisaba nada. 31 de los 46 títulos reales
 *     del protocolo llevan acento o eñe.
 *  6. 🔴 QUE EL `confirm_token` NO SE PUEDA FORJAR desde el contenido. El título es texto libre y
 *     no se valida contra `:` ni `|`: mientras el token se armó concatenando, un lote de una
 *     entrada podía fabricar el token de otro lote entero.
 *  7. 🔴 QUE NADA QUE PASE LA SIMULACIÓN REVIENTE AL ESCRIBIR. Los tres campos de contenido son
 *     `TEXT`, que son 65.535 BYTES, y el `max` de Laravel cuenta CARACTERES.
 *  8. ⚠️ Que el reintento que el docblock declara seguro devuelva 200 y no 422.
 */
class ProtocoloDeVentasPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-protocol-entries';

    /** Prefijo de los títulos de prueba, para no pisar el protocolo sembrado del slot. */
    const PREFIJO = 'Prueba protocolo Claude — ';

    /**
     * Setea la clave de ingesta: en el `.env.testing` del slot está vacía y el middleware es
     * fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Pega al endpoint.
     *
     * @param array<string, mixed> $payload Body completo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pegar(array $payload)
    {
        return $this->withHeaders($this->headers())->postJson('/api/claude/protocol-entries', $payload);
    }

    /**
     * Simula y después aplica, con el `confirm_count` y el `confirm_token` de la simulación.
     *
     * @param array<int, array<string, mixed>> $entradas Lote.
     *
     * @return \Illuminate\Testing\TestResponse La respuesta de la escritura real.
     */
    private function simular_y_aplicar(array $entradas)
    {
        $simulacion = $this->pegar(['entradas' => $entradas]);
        $simulacion->assertStatus(200);

        return $this->pegar([
            'entradas'      => $entradas,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
            'confirm_token' => $simulacion->json('confirm_token'),
        ]);
    }

    /**
     * 🔴 DOS entradas que COMPARTEN la terna (categoria, estado_aplicable, followup_numero) y se
     * distinguen sólo por el título. Es el caso real de las "Etapa 2A/2B/2C/2D" del protocolo, y es
     * lo que descarta la terna como clave de idempotencia.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dos_de_la_misma_terna(): array
    {
        return [
            [
                'titulo'           => self::PREFIJO . 'Etapa 2A',
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => null,
                'descripcion'      => 'El lead respondió quién es.',
                'mensaje_template' => 'Perfecto, contame un poco más de tu negocio.',
                'activa'           => true,
            ],
            [
                'titulo'           => self::PREFIJO . 'Etapa 2B',
                'categoria'        => 'etapa_principal',
                'estado_aplicable' => 'contactado',
                'followup_numero'  => null,
                'descripcion'      => 'El lead pregunta el precio antes de la demo.',
                'mensaje_template' => 'Antes de hablar de números te propongo que veas la plataforma funcionando.',
                'activa'           => true,
            ],
        ];
    }

    /**
     * Las entradas de prueba que hay hoy en la base.
     *
     * @return int
     */
    private function contar_de_prueba(): int
    {
        return ProtocolEntry::where('titulo', 'like', self::PREFIJO . '%')->count();
    }

    /* ------------------------------------------------------------------------------------------
     | La puerta
     |------------------------------------------------------------------------------------------ */

    /**
     * Sin la clave del header no entra nada.
     *
     * @return void
     */
    public function test_sin_la_clave_el_endpoint_rechaza(): void
    {
        $this->postJson('/api/claude/protocol-entries', ['entradas' => $this->dos_de_la_misma_terna()])
            ->assertStatus(401);

        $this->assertSame(0, $this->contar_de_prueba());
    }

    /* ------------------------------------------------------------------------------------------
     | Camino feliz e idempotencia
     |------------------------------------------------------------------------------------------ */

    /**
     * El camino feliz: simular y aplicar deja las dos entradas cargadas.
     *
     * @return void
     */
    public function test_el_camino_feliz_crea_las_entradas(): void
    {
        $response = $this->simular_y_aplicar($this->dos_de_la_misma_terna());

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', false);
        $response->assertJsonPath('resultados.creadas', 2);
        $response->assertJsonPath('resultados.actualizadas', 0);

        $entrada = ProtocolEntry::where('titulo', self::PREFIJO . 'Etapa 2A')->first();
        $this->assertNotNull($entrada);
        $this->assertSame('etapa_principal', $entrada->categoria);
        $this->assertSame('contactado', $entrada->estado_aplicable);
        $this->assertNull($entrada->followup_numero);
        $this->assertTrue((bool) $entrada->activa);
    }

    /**
     * 🔴 EL TEST CENTRAL DE LA CLAVE: dos entradas que comparten la terna quedan como DOS filas.
     *
     * Si la clave fuera la terna, la segunda pisaría a la primera y quedaría una sola.
     *
     * @return void
     */
    public function test_dos_entradas_de_la_misma_terna_no_se_pisan(): void
    {
        $this->simular_y_aplicar($this->dos_de_la_misma_terna())->assertStatus(200);

        $this->assertSame(2, $this->contar_de_prueba(), 'Dos entradas de la misma terna se pisaron entre ellas.');

        $dos_a = ProtocolEntry::where('titulo', self::PREFIJO . 'Etapa 2A')->first();
        $dos_b = ProtocolEntry::where('titulo', self::PREFIJO . 'Etapa 2B')->first();

        $this->assertNotNull($dos_a, 'Se perdió la Etapa 2A.');
        $this->assertNotNull($dos_b, 'Se perdió la Etapa 2B.');
        $this->assertStringContainsString('respondió quién es', $dos_a->descripcion);
        $this->assertStringContainsString('pregunta el precio', $dos_b->descripcion);
    }

    /**
     * Reenviar el mismo lote no duplica ni escribe: todo queda en `sin_cambio`.
     *
     * @return void
     */
    public function test_reenviar_el_mismo_lote_no_duplica_ni_escribe(): void
    {
        $this->simular_y_aplicar($this->dos_de_la_misma_terna())->assertStatus(200);

        $segunda = $this->pegar(['entradas' => $this->dos_de_la_misma_terna()]);

        $segunda->assertStatus(200);
        $segunda->assertJsonPath('cambiarian', 0);
        $this->assertCount(2, $segunda->json('sin_cambio'));
        $this->assertSame(2, $this->contar_de_prueba(), 'Reenviar el lote duplicó entradas.');
    }

    /**
     * Reenviar con el texto corregido ACTUALIZA la fila, no crea una segunda.
     *
     * @return void
     */
    public function test_reenviar_con_el_texto_corregido_actualiza_la_fila(): void
    {
        $this->simular_y_aplicar($this->dos_de_la_misma_terna())->assertStatus(200);

        $corregido = $this->dos_de_la_misma_terna();
        $corregido[0]['mensaje_template'] = 'Texto corregido para probar la actualización.';

        $response = $this->simular_y_aplicar($corregido);

        $response->assertStatus(200);
        $response->assertJsonPath('resultados.creadas', 0);
        $response->assertJsonPath('resultados.actualizadas', 1);

        $this->assertSame(2, $this->contar_de_prueba());
        $this->assertSame(
            'Texto corregido para probar la actualización.',
            ProtocolEntry::where('titulo', self::PREFIJO . 'Etapa 2A')->first()->mensaje_template
        );
    }

    /**
     * Un lote parcial no borra las entradas que no vinieron en el payload.
     *
     * @return void
     */
    public function test_el_alta_nunca_borra_las_entradas_que_no_vinieron(): void
    {
        $this->simular_y_aplicar($this->dos_de_la_misma_terna())->assertStatus(200);

        $this->simular_y_aplicar([[
            'titulo'           => self::PREFIJO . 'Situación suelta',
            'categoria'        => 'situacion_frecuente',
            'descripcion'      => 'Una situación que aplica a cualquier estado.',
            'mensaje_template' => 'Texto de la situación.',
            'activa'           => false,
        ]])->assertStatus(200);

        $this->assertSame(3, $this->contar_de_prueba(), 'Un lote de una entrada borró las otras.');
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos, uno por uno
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 `dry_run` es el default y NO escribe absolutamente nada.
     *
     * @return void
     */
    public function test_dry_run_es_el_default_y_no_escribe_nada(): void
    {
        $response = $this->pegar(['entradas' => $this->dos_de_la_misma_terna()]);

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', true);
        $response->assertJsonPath('cambiarian', 2);
        $response->assertJsonPath('cambios.0.accion', 'crear');

        $this->assertSame(0, $this->contar_de_prueba(), 'La simulación escribió entradas en la base.');
    }

    /**
     * 🔴 `activa` es obligatorio: una entrada nueva no se activa sola.
     *
     * @return void
     */
    public function test_sin_activa_explicita_es_422(): void
    {
        $entradas = $this->dos_de_la_misma_terna();
        unset($entradas[0]['activa'], $entradas[1]['activa']);

        $this->pegar(['entradas' => $entradas])->assertStatus(422);
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /**
     * Una entrada cargada con `activa => false` queda apagada, no activa.
     *
     * @return void
     */
    public function test_una_entrada_cargada_apagada_queda_apagada(): void
    {
        $entradas = $this->dos_de_la_misma_terna();
        $entradas[0]['activa'] = false;
        $entradas[1]['activa'] = false;

        $this->simular_y_aplicar($entradas)->assertStatus(200);

        $this->assertFalse(
            (bool) ProtocolEntry::where('titulo', self::PREFIJO . 'Etapa 2A')->first()->activa,
            'Una entrada cargada con activa=false quedó activa.'
        );
    }

    /**
     * `confirm_count` que no coincide con la simulación es 422 y no escribe nada.
     *
     * @return void
     */
    public function test_confirm_count_que_no_coincide_es_422(): void
    {
        $simulacion = $this->pegar(['entradas' => $this->dos_de_la_misma_terna()]);

        $response = $this->pegar([
            'entradas'      => $this->dos_de_la_misma_terna(),
            'dry_run'       => false,
            'confirm_count' => 5,
            'confirm_token' => $simulacion->json('confirm_token'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('cambiarian', 2);
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /**
     * Un `confirm_token` que no corresponde al conjunto simulado es 422.
     *
     * @return void
     */
    public function test_confirm_token_que_no_corresponde_es_422(): void
    {
        $response = $this->pegar([
            'entradas'      => $this->dos_de_la_misma_terna(),
            'dry_run'       => false,
            'confirm_count' => 2,
            'confirm_token' => str_repeat('b', 32),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /**
     * Una `categoria` que no es una de las tres reales es 422 CON LA LISTA DE LAS VÁLIDAS.
     *
     * @return void
     */
    public function test_una_categoria_inventada_es_422_con_la_lista_de_las_validas(): void
    {
        $entradas = $this->dos_de_la_misma_terna();
        $entradas[0]['categoria'] = 'objeciones';

        $response = $this->pegar(['entradas' => $entradas]);

        $response->assertStatus(422);
        $this->assertSame(
            ['etapa_principal', 'seguimiento', 'situacion_frecuente'],
            (array) $response->json('categorias_validas')
        );
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /**
     * Un `estado_aplicable` que no es un slug del pipeline es 422 con la lista de los válidos, y
     * dejarlo vacío (= aplica a todos) sí se acepta.
     *
     * @return void
     */
    public function test_un_estado_aplicable_inventado_es_422_pero_vacio_se_acepta(): void
    {
        $entradas = $this->dos_de_la_misma_terna();
        $entradas[0]['estado_aplicable'] = 'estado_que_no_existe';

        $response = $this->pegar(['entradas' => $entradas]);
        $response->assertStatus(422);
        $this->assertNotEmpty((array) $response->json('estados_validos'));

        $sin_estado = $this->dos_de_la_misma_terna();
        $sin_estado[0]['estado_aplicable'] = null;
        $sin_estado[1]['estado_aplicable'] = null;

        $this->simular_y_aplicar($sin_estado)->assertStatus(200);
        $this->assertNull(ProtocolEntry::where('titulo', self::PREFIJO . 'Etapa 2A')->first()->estado_aplicable);
    }

    /**
     * Crear una entrada sin el contenido obligatorio es 422 (las dos columnas son NOT NULL).
     *
     * @return void
     */
    public function test_crear_una_entrada_sin_contenido_es_422(): void
    {
        $response = $this->pegar(['entradas' => [[
            'titulo'    => self::PREFIJO . 'Sin contenido ' . Str::random(4),
            'categoria' => 'seguimiento',
            'activa'    => true,
        ]]]);

        $response->assertStatus(422);
        $this->assertSame(['descripcion', 'mensaje_template'], (array) $response->json('faltantes'));
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /**
     * Un `titulo` repetido dentro del mismo lote es 422: las dos filas se pisarían.
     *
     * @return void
     */
    public function test_un_titulo_repetido_en_el_lote_es_422(): void
    {
        $entradas = $this->dos_de_la_misma_terna();
        $entradas[1]['titulo'] = $entradas[0]['titulo'];

        $this->pegar(['entradas' => $entradas])->assertStatus(422);
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /* ------------------------------------------------------------------------------------------
     | La colación de MySQL: `titulo` es utf8mb4_unicode_ci, o sea que la base compara SIN
     | distinguir mayúsculas ni acentos. Todo lo que PHP compare byte a byte se desalinea con eso.
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Dos títulos que para MySQL SON EL MISMO no pueden pasar el freno del repetido.
     *
     * Medido contra el código anterior a este arreglo: el lote pasaba (`in_array(..., true)` los ve
     * distintos), la simulación decía `cambiarian=2`, la respuesta decía
     * `{"creadas":1,"actualizadas":1}` y en la base quedaba UNA fila — la segunda escritura
     * encontraba a la primera con `where('titulo', ...)` y la pisaba. Es exactamente el modo de
     * fallo que el 422 del repetido dice impedir.
     *
     * @return void
     */
    public function test_dos_titulos_que_para_mysql_son_el_mismo_no_pasan_el_lote(): void
    {
        $base = $this->dos_de_la_misma_terna();

        $entradas    = $base;
        $entradas[0]['titulo'] = self::PREFIJO . 'ZZ Objecion de precio';
        $entradas[1]['titulo'] = self::PREFIJO . 'zz objecion de precio';

        $response = $this->pegar(['entradas' => $entradas]);

        $response->assertStatus(422);
        $this->assertSame(0, $this->contar_de_prueba(), 'La simulación escribió algo.');
    }

    /**
     * 🔴 Y la variante con acento, que es la peligrosa: 31 de los 46 títulos reales del protocolo
     * llevan tilde o eñe, así que comerse un acento al retipear es el error MÁS probable.
     *
     * Medido contra el código anterior: mandar "Como abrir la conversacion" contra la fila
     * "Cómo abrir la conversación" daba simulación `accion: crear` con `actual` todo en null, y
     * después `{"creadas":0,"actualizadas":1}`: pisaba la fila existente Y le borraba
     * `estado_aplicable`, `followup_numero` y `notas_setter`, porque el arrastre de campos ausentes
     * salía de un `$existente` que el código creía inexistente. O sea: borraba contenido mostrando
     * un diff que juraba que no pisaba nada.
     *
     * @return void
     */
    public function test_un_titulo_sin_acentos_actualiza_la_fila_existente_y_no_le_borra_campos(): void
    {
        $con_acentos = [[
            'titulo'           => self::PREFIJO . 'Cómo abrir la conversación',
            'categoria'        => 'etapa_principal',
            'estado_aplicable' => 'contactado',
            'followup_numero'  => 2,
            'descripcion'      => 'Descripción original.',
            'mensaje_template' => 'Mensaje original.',
            'notas_setter'     => 'Notas del setter que no se pueden perder.',
            'activa'           => true,
        ]];

        $this->simular_y_aplicar($con_acentos)->assertStatus(200);

        /* Mismo título retipeado sin acentos, y SIN los campos que no se quieren tocar. */
        $sin_acentos = [[
            'titulo'           => self::PREFIJO . 'Como abrir la conversacion',
            'categoria'        => 'etapa_principal',
            'descripcion'      => 'Descripción corregida.',
            'mensaje_template' => 'Mensaje original.',
            'activa'           => true,
        ]];

        $simulacion = $this->pegar(['entradas' => $sin_acentos]);
        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('cambios.0.accion', 'actualizar');
        $simulacion->assertJsonPath('cambios.0.actual.notas_setter', 'Notas del setter que no se pueden perder.');
        $simulacion->assertJsonPath('cambios.0.actual.estado_aplicable', 'contactado');
        $simulacion->assertJsonPath('cambios.0.titulo_en_base', self::PREFIJO . 'Cómo abrir la conversación');

        $response = $this->simular_y_aplicar($sin_acentos);
        $response->assertStatus(200);
        $response->assertJsonPath('resultados.creadas', 0);
        $response->assertJsonPath('resultados.actualizadas', 1);

        $this->assertSame(1, $this->contar_de_prueba(), 'Quedaron dos filas para lo que la base considera un solo título.');

        $entrada = ProtocolEntry::where('titulo', 'like', self::PREFIJO . '%')->first();
        $this->assertSame('Descripción corregida.', $entrada->descripcion, 'No se aplicó la corrección.');
        $this->assertSame('Notas del setter que no se pueden perder.', $entrada->notas_setter, 'Se borraron las notas del setter.');
        $this->assertSame('contactado', $entrada->estado_aplicable, 'Se borró el estado_aplicable.');
        $this->assertSame(2, (int) $entrada->followup_numero, 'Se borró el followup_numero.');
        $this->assertSame(
            self::PREFIJO . 'Cómo abrir la conversación',
            $entrada->titulo,
            'Se reescribió el título de la fila: una tilde comida no puede renombrar una entrada.'
        );
    }

    /* ------------------------------------------------------------------------------------------
     | El confirm_token
     |------------------------------------------------------------------------------------------ */

    /**
     * Dos entradas CORTAS. El test de abajo mete el JSON de una propuesta adentro de un título, y
     * el título tiene 255 caracteres: cuanto más corto el contenido, más margen hay.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dos_cortas(): array
    {
        return [
            [
                'titulo'           => self::PREFIJO . 'A',
                'categoria'        => 'seguimiento',
                'descripcion'      => 'd',
                'mensaje_template' => 'm',
                'activa'           => true,
            ],
            [
                'titulo'           => self::PREFIJO . 'B',
                'categoria'        => 'seguimiento',
                'descripcion'      => 'd',
                'mensaje_template' => 'm',
                'activa'           => true,
            ],
        ];
    }

    /**
     * 🔴 El `confirm_token` no se puede forjar metiendo los delimitadores adentro del título.
     *
     * `titulo` es texto libre de 255 caracteres y no se valida contra `:` ni `|`. Mientras el token
     * se armó concatenando `"<titulo>:<json>"` pegados con `|`, un lote de UNA entrada cuyo título
     * fuera `'<A>:<json de A>|<B>'` producía EXACTAMENTE la misma cadena que el lote de dos
     * entradas A y B — y por lo tanto el mismo token, con el que se podía escribir un conjunto
     * distinto del que se revisó.
     *
     * @return void
     */
    public function test_el_confirm_token_no_se_puede_forjar_metiendo_delimitadores_en_el_titulo(): void
    {
        $lote       = $this->dos_cortas();
        $simulacion = $this->pegar(['entradas' => $lote]);
        $simulacion->assertStatus(200);

        /* Se reconstruyen las "partes" con las que el token se armaba hasta este arreglo. */
        $partes = [];
        foreach ((array) $simulacion->json('cambios') as $cambio) {
            $partes[] = $cambio['titulo'] . ':' . json_encode($cambio['propuesto']);
        }
        sort($partes);

        /* El título forjado es la primera parte ENTERA más el separador y el título de la segunda:
           así el lote de una sola entrada arma la misma cadena que el de dos. */
        $forjado = $partes[0] . '|' . $lote[1]['titulo'];
        $this->assertLessThanOrEqual(
            255,
            mb_strlen($forjado),
            'El título forjado no entra en la columna: achicá el fixture, no el test.'
        );

        $entrada_forjada           = $lote[1];
        $entrada_forjada['titulo'] = $forjado;

        $forjada = $this->pegar(['entradas' => [$entrada_forjada]]);
        $forjada->assertStatus(200);

        $this->assertNotSame(
            $simulacion->json('confirm_token'),
            $forjada->json('confirm_token'),
            'Un título con ":" y "|" adentro fabricó el mismo confirm_token que otro lote: el token se puede forjar.'
        );
    }

    /* ------------------------------------------------------------------------------------------
     | El 422 legible, siempre: nada que pase la simulación puede reventar al escribir
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Un texto que entra en el `max` de CARACTERES pero no en los BYTES de la columna es 422 en
     * la simulación, no 500 al escribir.
     *
     * `descripcion`, `mensaje_template` y `notas_setter` son `TEXT`, que son 65.535 BYTES. El tope
     * anterior era `max:20000`, que Laravel cuenta en CARACTERES: 20.000 emoji son 20.000
     * caracteres y 80.000 bytes, o sea que pasaban la validación y le explotaban a MySQL en la
     * cara con un 500.
     *
     * @return void
     */
    public function test_un_texto_que_pasa_el_max_de_caracteres_pero_no_entra_en_bytes_es_422(): void
    {
        $entradas = $this->dos_de_la_misma_terna();
        $entradas = [$entradas[0]];
        $entradas[0]['descripcion'] = str_repeat('😀', 20000);

        $this->assertSame(20000, mb_strlen($entradas[0]['descripcion']), 'El fixture dejó de tener 20.000 caracteres.');
        $this->assertGreaterThan(65535, strlen($entradas[0]['descripcion']), 'El fixture dejó de pasarse de bytes.');

        $response = $this->pegar(['entradas' => $entradas]);

        $response->assertStatus(422);
        $this->assertSame(0, $this->contar_de_prueba());
    }

    /**
     * ⚠️ Reintentar la misma llamada cuando ya no hay nada que cambiar da 200 `sin_cambio`, que es
     * lo que el docblock del endpoint promete.
     *
     * Hasta este arreglo la comparación de `confirm_count` corría ANTES del caso "no hay nada que
     * cambiar", así que un reintento con el mismo body (`confirm_count: 1` contra `cambiarian: 0`)
     * daba 422. No duplicaba nada —fallaba del lado seguro— pero el reintento que el docblock
     * declara seguro no era el que devolvía 200.
     *
     * @return void
     */
    public function test_reintentar_cuando_no_hay_nada_que_cambiar_es_200(): void
    {
        $entradas   = $this->dos_de_la_misma_terna();
        $simulacion = $this->pegar(['entradas' => $entradas]);
        $simulacion->assertStatus(200);

        $body = [
            'entradas'      => $entradas,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
            'confirm_token' => $simulacion->json('confirm_token'),
        ];

        $this->pegar($body)->assertStatus(200);

        /* El reintento: el MISMO body, tal cual. Ya no hay nada que cambiar. */
        $reintento = $this->pegar($body);

        $reintento->assertStatus(200);
        $reintento->assertJsonPath('resultados.creadas', 0);
        $reintento->assertJsonPath('resultados.actualizadas', 0);
        $this->assertSame(2, $this->contar_de_prueba(), 'El reintento duplicó entradas.');
    }
}
