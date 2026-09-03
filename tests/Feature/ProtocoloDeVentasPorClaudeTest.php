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
}
