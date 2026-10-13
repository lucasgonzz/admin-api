<?php

namespace Tests\Feature\Recursos;

use App\Models\RecursosEncuesta;
use App\Models\RecursosPregunta;
use App\Models\RecursosPreguntaMeGusta;
use App\Models\RecursosProgreso;
use App\Models\RecursosVoto;

/**
 * Lo que consume la página del centro de recursos (contrato C2 de la misión
 * centro-de-recursos-clientes, 13/10/2026): catálogo público, un video con sus preguntas, `yo`, el
 * avance, los votos, las preguntas con sus me gusta y la encuesta.
 *
 * Lo que más importa:
 *  1. 🔴 Las preguntas: las respondidas las ven todos, con el NOMBRE DE PILA del autor y sin su
 *     negocio; las sin responder, solo su autor; las ocultas, nadie (decisión de Lucas, 13/10/2026).
 *  2. 🔴 El avance cuenta solo lo reproducido: saltar no suma, y "terminado" (90 %) no se pierde.
 *  3. Sin sesión el catálogo y los videos se ven (catálogo público).
 */
class PaginaDelCentroTest extends BaseDelCentroDeRecursos
{
    /* ------------------------------------------------------------------------------------------
     | Catálogo público
     |----------------------------------------------------------------------------------------- */

    public function test_el_catalogo_es_publico_ordenado_y_sin_inactivos_ni_transcripcion(): void
    {
        $this->crear_video('T2.1', ['orden' => 3, 'carpeta' => 2, 'carpeta_nombre' => 'Ventas', 'relacionados' => ['T1.1', 'T9.9']]);
        $this->crear_video('T1.1', ['orden' => 1]);
        $this->crear_video('T1.2', ['orden' => 2, 'activo' => false]);

        $respuesta = $this->getJson('/api/recursos/catalogo');

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson([
            'carpetas' => [
                ['nro' => 1, 'nombre' => 'Tus artículos (Listado)'],
                ['nro' => 2, 'nombre' => 'Ventas'],
            ],
            'videos' => [
                [
                    'codigo' => 'T1.1', 'titulo' => 'Video T1.1', 'carpeta' => 1, 'seccion' => 'A · Lo básico', 'orden' => 1,
                    'para_que' => 'Para qué sirve T1.1', 'cuando_no' => 'Cuándo no conviene T1.1', 'relacionados' => [],
                    'duracion' => 60.0, 'url' => 'https://videos.test/recursos/T1.1-abc12345.mp4',
                    'miniatura_url' => 'https://videos.test/recursos/T1.1-abc12345.jpg',
                ],
                [
                    // El relacionado que no existe (T9.9) no se ofrece.
                    'codigo' => 'T2.1', 'titulo' => 'Video T2.1', 'carpeta' => 2, 'seccion' => 'A · Lo básico', 'orden' => 3,
                    'para_que' => 'Para qué sirve T2.1', 'cuando_no' => 'Cuándo no conviene T2.1', 'relacionados' => ['T1.1'],
                    'duracion' => 60.0, 'url' => 'https://videos.test/recursos/T2.1-abc12345.mp4',
                    'miniatura_url' => 'https://videos.test/recursos/T2.1-abc12345.jpg',
                ],
            ],
        ]);

        $this->assertStringNotContainsString('Lo que dice el video', $this->cuerpo($respuesta));
    }

    public function test_el_indice_del_buscador_trae_las_transcripciones_de_los_activos(): void
    {
        $this->crear_video('T1.1', ['transcripcion' => 'cómo cargar un artículo']);
        $this->crear_video('T1.2', ['transcripcion' => 'oculto', 'activo' => false]);

        $this->getJson('/api/recursos/buscar-indice')
            ->assertStatus(200)
            ->assertExactJson(['T1.1' => 'cómo cargar un artículo']);
    }

    public function test_un_video_se_ve_sin_sesion_con_su_transcripcion(): void
    {
        $this->crear_video('T1.1');
        $this->crear_video('T1.2', ['activo' => false]);

        $this->getJson('/api/recursos/videos/T1.1')
            ->assertStatus(200)
            ->assertJsonPath('video.codigo', 'T1.1')
            ->assertJsonPath('video.transcripcion', 'Lo que dice el video T1.1')
            ->assertJsonPath('preguntas', []);

        $this->getJson('/api/recursos/videos/T1.2')->assertStatus(404)->assertExactJson(['error' => 'video_no_encontrado']);
        $this->getJson('/api/recursos/videos/NO.EXISTE')->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Preguntas: quién ve qué
     |----------------------------------------------------------------------------------------- */

    public function test_la_visibilidad_de_las_preguntas_respondidas_propias_y_ocultas(): void
    {
        $video = $this->crear_video('T1.1');

        $cliente_a = $this->crear_cliente('api-a.test', ['company_name' => 'Negocio Secreto A']);
        $cliente_b = $this->crear_cliente('api-b.test', ['company_name' => 'Negocio Secreto B']);

        list($autora, $token_autora) = $this->usuario_con_sesion($cliente_a, ['nombre' => 'Marcela Gómez Ibáñez']);
        list(, $token_otro)          = $this->usuario_con_sesion($cliente_b, ['nombre' => 'Pedro Ruiz']);

        $respondida = RecursosPregunta::create([
            'recursos_video_id' => $video->id, 'recursos_usuario_id' => $autora->id, 'texto' => '¿Se puede importar desde Excel?',
            'respuesta' => 'Sí, desde Listado → Importar.', 'respondido_at' => now(),
        ]);
        $pendiente = RecursosPregunta::create([
            'recursos_video_id' => $video->id, 'recursos_usuario_id' => $autora->id, 'texto' => '¿Y con códigos de barras?',
        ]);
        $oculta = RecursosPregunta::create([
            'recursos_video_id' => $video->id, 'recursos_usuario_id' => $autora->id, 'texto' => 'Algo que se ocultó',
            'respuesta' => 'Respuesta oculta', 'respondido_at' => now(), 'oculta' => true,
        ]);

        // Invitado: solo la respondida.
        $invitado = $this->getJson('/api/recursos/videos/T1.1');
        $this->assertSame([$respondida->id], array_column($invitado->json('preguntas'), 'id'));

        // Otro usuario: solo la respondida, con el nombre de pila de la autora y sin su negocio.
        $otro = $this->getJson('/api/recursos/videos/T1.1', $this->con_sesion($token_otro));
        $this->assertSame([$respondida->id], array_column($otro->json('preguntas'), 'id'));
        $otro->assertJsonPath('preguntas.0.autor', 'Marcela');
        $otro->assertJsonPath('preguntas.0.es_mia', false);
        $otro->assertJsonPath('preguntas.0.respuesta.texto', 'Sí, desde Listado → Importar.');

        $texto = $this->cuerpo($otro);
        $this->assertStringNotContainsString('Gómez', $texto);
        $this->assertStringNotContainsString('Negocio Secreto', $texto);

        // La autora: la respondida y su pendiente, pero no la oculta.
        $propia = $this->getJson('/api/recursos/videos/T1.1', $this->con_sesion($token_autora));
        $ids    = array_column($propia->json('preguntas'), 'id');
        sort($ids);
        $this->assertSame([$respondida->id, $pendiente->id], $ids);
        $this->assertNotContains($oculta->id, $ids);

        foreach ($propia->json('preguntas') as $pregunta) {
            $this->assertTrue($pregunta['es_mia']);
            $this->assertSame('Marcela', $pregunta['autor']);
            $this->assertSame(['id', 'codigo', 'autor', 'es_mia', 'texto', 'fecha', 'me_gusta', 'me_gusta_mio', 'respuesta'], array_keys($pregunta));
        }

        $this->assertStringNotContainsString('Negocio Secreto', $this->cuerpo($propia));
    }

    public function test_publicar_una_pregunta(): void
    {
        $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($usuario, $token) = $this->usuario_con_sesion($client, ['nombre' => 'Lucía Fernández']);

        $respuesta = $this->postJson('/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => "  ¿Cómo hago un ajuste\nde stock?  "], $this->con_sesion($token));

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('codigo', 'T1.1');
        $respuesta->assertJsonPath('autor', 'Lucía');
        $respuesta->assertJsonPath('es_mia', true);
        $respuesta->assertJsonPath('texto', "¿Cómo hago un ajuste\nde stock?");
        $respuesta->assertJsonPath('me_gusta', 0);
        $respuesta->assertJsonPath('respuesta', null);

        $this->assertSame(1, RecursosPregunta::where('recursos_usuario_id', $usuario->id)->count());

        // Vacía, larga o sobre un video que no existe: no.
        $this->postJson('/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => '   '], $this->con_sesion($token))
            ->assertStatus(422)->assertJsonPath('error', 'datos_invalidos');
        $this->postJson('/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => str_repeat('a', 2001)], $this->con_sesion($token))
            ->assertStatus(422);
        $this->postJson('/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => str_repeat('á', 2000)], $this->con_sesion($token))
            ->assertStatus(201);
        $this->postJson('/api/recursos/preguntas', ['codigo' => 'T7.7', 'texto' => 'hola'], $this->con_sesion($token))
            ->assertStatus(404)->assertExactJson(['error' => 'video_no_encontrado']);

        // Sin sesión, no se pregunta.
        $this->postJson('/api/recursos/preguntas', ['codigo' => 'T1.1', 'texto' => 'hola'])->assertStatus(401);
    }

    public function test_el_me_gusta_alterna_y_solo_sobre_preguntas_visibles(): void
    {
        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($autor)         = $this->usuario_con_sesion($client);
        list($yo, $token_yo) = $this->usuario_con_sesion($client);

        $respondida = RecursosPregunta::create(['recursos_video_id' => $video->id, 'recursos_usuario_id' => $autor->id, 'texto' => 'a', 'respuesta' => 'b', 'respondido_at' => now()]);
        $ajena_sin_responder = RecursosPregunta::create(['recursos_video_id' => $video->id, 'recursos_usuario_id' => $autor->id, 'texto' => 'c']);

        $this->postJson('/api/recursos/preguntas/' . $respondida->id . '/me-gusta', [], $this->con_sesion($token_yo))
            ->assertStatus(200)->assertExactJson(['me_gusta' => true, 'total' => 1]);

        $this->getJson('/api/recursos/videos/T1.1', $this->con_sesion($token_yo))
            ->assertJsonPath('preguntas.0.me_gusta', 1)
            ->assertJsonPath('preguntas.0.me_gusta_mio', true);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token_yo))->assertJsonPath('me_gusta', [$respondida->id]);

        $this->postJson('/api/recursos/preguntas/' . $respondida->id . '/me-gusta', [], $this->con_sesion($token_yo))
            ->assertStatus(200)->assertExactJson(['me_gusta' => false, 'total' => 0]);

        // Una pregunta que este usuario no puede ver no existe para él.
        $this->postJson('/api/recursos/preguntas/' . $ajena_sin_responder->id . '/me-gusta', [], $this->con_sesion($token_yo))
            ->assertStatus(404)->assertExactJson(['error' => 'pregunta_no_encontrada']);
        $this->postJson('/api/recursos/preguntas/999999999/me-gusta', [], $this->con_sesion($token_yo))->assertStatus(404);

        $this->assertSame(0, RecursosPreguntaMeGusta::where('recursos_usuario_id', $yo->id)->count());
    }

    public function test_las_respuestas_nuevas_figuran_hasta_que_el_autor_abre_el_video(): void
    {
        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($autor, $token) = $this->usuario_con_sesion($client);

        $pregunta = RecursosPregunta::create(['recursos_video_id' => $video->id, 'recursos_usuario_id' => $autor->id, 'texto' => 'a']);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertJsonPath('respuestas_nuevas', []);

        $pregunta->update(['respuesta' => 'b', 'respondido_at' => now()]);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))
            ->assertJsonPath('respuestas_nuevas', [['codigo' => 'T1.1', 'pregunta_id' => $pregunta->id]]);

        $this->getJson('/api/recursos/videos/T1.1', $this->con_sesion($token))->assertStatus(200);

        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertJsonPath('respuestas_nuevas', []);
    }

    /* ------------------------------------------------------------------------------------------
     | Avance
     |----------------------------------------------------------------------------------------- */

    public function test_el_avance_marca_los_tramos_y_termina_al_90_por_ciento(): void
    {
        $this->crear_video('T1.1', ['duracion' => 100.0]);
        $client = $this->crear_cliente('api-a.test');
        list($usuario, $token) = $this->usuario_con_sesion($client);

        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 30, 'tramos' => [[0, 10], [10, 30]]], $this->con_sesion($token))
            ->assertStatus(200)
            ->assertExactJson(['porcentaje' => 30.0, 'terminado' => false]);

        // Repetir lo ya visto no suma.
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 20, 'tramos' => [[5, 20]]], $this->con_sesion($token))
            ->assertExactJson(['porcentaje' => 30.0, 'terminado' => false]);

        // 🔴 Saltar al final con la barra no suma: la posición se guarda, los segundos no se marcan.
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 95, 'tramos' => []], $this->con_sesion($token))
            ->assertExactJson(['porcentaje' => 30.0, 'terminado' => false]);

        $yo = $this->getJson('/api/recursos/yo', $this->con_sesion($token))->json();
        // assertEquals y no assertSame: un número entero sale del JSON como int (30 y no 30.0).
        $this->assertEquals(['porcentaje' => 30, 'posicion' => 95, 'terminado' => false], $yo['progreso']['T1.1']);

        // Llega a 89 %: todavía no.
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 89, 'tramos' => [[30, 59], [59, 89]]], $this->con_sesion($token))
            ->assertExactJson(['porcentaje' => 89.0, 'terminado' => false]);

        // 90 %: terminado.
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 90, 'tramos' => [[89, 90]]], $this->con_sesion($token))
            ->assertExactJson(['porcentaje' => 90.0, 'terminado' => true]);

        $progreso = RecursosProgreso::where('recursos_usuario_id', $usuario->id)->first();
        $this->assertNotNull($progreso->terminado_at);
        $this->assertSame(100, strlen($progreso->vistos));
        $this->assertSame(str_repeat('1', 90) . str_repeat('0', 10), $progreso->vistos);
        $terminado_at = $progreso->terminado_at->toDateTimeString();

        // Si el video se recarga más largo y el porcentaje baja, sigue terminado.
        \App\Models\RecursosVideo::where('codigo', 'T1.1')->update(['duracion' => 200.0]);

        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 91, 'tramos' => [[90, 91]]], $this->con_sesion($token))
            ->assertExactJson(['porcentaje' => 45.5, 'terminado' => true]);

        $this->assertSame($terminado_at, $progreso->fresh()->terminado_at->toDateTimeString());
    }

    public function test_un_tramo_que_llega_al_final_completa_el_ultimo_segundo(): void
    {
        $this->crear_video('T1.1', ['duracion' => 9.4]);
        $client = $this->crear_cliente('api-a.test');
        list(, $token) = $this->usuario_con_sesion($client);

        // 10 segundos (ceil 9.4); el reproductor puede pasarse unas décimas del final.
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 9.4, 'tramos' => [[0, 9.6]]], $this->con_sesion($token))
            ->assertExactJson(['porcentaje' => 100.0, 'terminado' => true]);
    }

    public function test_los_tramos_invalidos_son_422_y_no_marcan_nada(): void
    {
        $this->crear_video('T1.1', ['duracion' => 60.0]);
        $client = $this->crear_cliente('api-a.test');
        list($usuario, $token) = $this->usuario_con_sesion($client);

        $invalidos = [
            'no es lista'          => 'hola',
            'no es par'            => [[1, 2, 3]],
            'no numérico'          => [['a', 'b']],
            'negativo'             => [[-1, 5]],
            'al revés'             => [[10, 5]],
            'más allá del final'   => [[50, 62]],
            'más de 30 s'          => [[0, 31]],
            'más de 50 tramos'     => array_fill(0, 51, [0, 1]),
        ];

        foreach ($invalidos as $caso => $tramos) {
            $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => 1, 'tramos' => $tramos], $this->con_sesion($token))
                ->assertStatus(422)
                ->assertJsonPath('error', 'tramos_invalidos');
        }

        $this->postJson('/api/recursos/progreso', ['codigo' => 'T1.1', 'posicion' => -3, 'tramos' => []], $this->con_sesion($token))
            ->assertStatus(422)->assertJsonPath('error', 'datos_invalidos');
        $this->postJson('/api/recursos/progreso', ['codigo' => 'T9.9', 'posicion' => 1, 'tramos' => []], $this->con_sesion($token))
            ->assertStatus(404)->assertExactJson(['error' => 'video_no_encontrado']);

        $this->assertSame(0, RecursosProgreso::where('recursos_usuario_id', $usuario->id)->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Votos y encuesta
     |----------------------------------------------------------------------------------------- */

    public function test_el_voto_se_cambia_y_se_saca(): void
    {
        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($usuario, $token) = $this->usuario_con_sesion($client);

        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 1], $this->con_sesion($token))
            ->assertStatus(200)->assertExactJson(['codigo' => 'T1.1', 'valor' => 1]);
        $this->assertSame(['T1.1' => 1], $this->getJson('/api/recursos/yo', $this->con_sesion($token))->json('votos'));

        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => -1], $this->con_sesion($token))->assertStatus(200);
        $this->assertSame(-1, (int) RecursosVoto::where('recursos_usuario_id', $usuario->id)->value('valor'));
        $this->assertSame(1, RecursosVoto::where('recursos_usuario_id', $usuario->id)->count());

        $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => 0], $this->con_sesion($token))->assertStatus(200);
        $this->assertSame(0, RecursosVoto::where('recursos_usuario_id', $usuario->id)->count());

        foreach ([2, 'si', true, [1], null] as $malo) {
            $this->postJson('/api/recursos/votos', ['codigo' => 'T1.1', 'valor' => $malo], $this->con_sesion($token))
                ->assertStatus(422)->assertJsonPath('error', 'datos_invalidos');
        }

        $this->assertSame($video->id, $video->fresh()->id);
    }

    public function test_la_encuesta_se_guarda_se_actualiza_y_vacia_es_422(): void
    {
        $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($usuario, $token) = $this->usuario_con_sesion($client);

        $this->postJson('/api/recursos/encuestas', ['codigo' => 'T1.1', 'util' => 'si', 'entendio' => 'mas_o_menos', 'mejora' => null], $this->con_sesion($token))
            ->assertStatus(200)
            ->assertExactJson(['codigo' => 'T1.1', 'util' => 'si', 'entendio' => 'mas_o_menos', 'mejora' => null]);

        $this->postJson('/api/recursos/encuestas', ['codigo' => 'T1.1', 'util' => 'no', 'entendio' => null, 'mejora' => '  Más lento, por favor.  '], $this->con_sesion($token))
            ->assertStatus(200)
            ->assertExactJson(['codigo' => 'T1.1', 'util' => 'no', 'entendio' => null, 'mejora' => 'Más lento, por favor.']);

        $this->assertSame(1, RecursosEncuesta::where('recursos_usuario_id', $usuario->id)->count());
        $this->getJson('/api/recursos/yo', $this->con_sesion($token))->assertJsonPath('encuestas', ['T1.1']);

        $this->postJson('/api/recursos/encuestas', ['codigo' => 'T1.1', 'util' => null, 'entendio' => null, 'mejora' => '   '], $this->con_sesion($token))
            ->assertStatus(422)->assertJsonPath('error', 'encuesta_vacia');
        $this->postJson('/api/recursos/encuestas', ['codigo' => 'T1.1'], $this->con_sesion($token))
            ->assertStatus(422)->assertJsonPath('error', 'encuesta_vacia');
        $this->postJson('/api/recursos/encuestas', ['codigo' => 'T1.1', 'util' => 'quizas'], $this->con_sesion($token))
            ->assertStatus(422)->assertJsonPath('error', 'datos_invalidos');
        $this->postJson('/api/recursos/encuestas', ['codigo' => 'T1.1', 'entendio' => 'mucho'], $this->con_sesion($token))
            ->assertStatus(422)->assertJsonPath('error', 'datos_invalidos');
    }

    public function test_yo_trae_al_usuario_con_su_negocio_y_mapas_vacios_como_objetos(): void
    {
        $client = $this->crear_cliente('api-a.test', ['company_name' => 'Kiosco La Esquina']);
        list(, $token) = $this->usuario_con_sesion($client, ['nombre' => 'Ana María López', 'es_dueno' => true]);

        $respuesta = $this->getJson('/api/recursos/yo', $this->con_sesion($token));

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('usuario', ['nombre' => 'Ana María López', 'nombre_pila' => 'Ana', 'es_dueno' => true, 'negocio' => 'Kiosco La Esquina']);

        // Los mapas vacíos salen `{}` (la página los lee como objeto), las listas `[]`.
        $this->assertStringContainsString('"progreso":{}', $respuesta->getContent());
        $this->assertStringContainsString('"votos":{}', $respuesta->getContent());
        $this->assertStringContainsString('"encuestas":[]', $respuesta->getContent());
        $this->assertStringContainsString('"me_gusta":[]', $respuesta->getContent());
        $this->assertStringContainsString('"respuestas_nuevas":[]', $respuesta->getContent());
    }
}
